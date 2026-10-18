<?php
declare(strict_types=1);

namespace Bicho;

final class Client
{
    public const VERSION = Event::VERSION;

    public function __construct(private readonly Config $config)
    {
        if (!extension_loaded('curl')) {
            throw new \LogicException('Bicho requires the PHP cURL extension');
        }
    }

    public function captureMessage(string $message, array $tags = [], array $contexts = []): string
    {
        return $this->send($this->decorate(Event::message($message), $tags, $contexts));
    }

    public function captureException(\Throwable $error, array $tags = [], array $contexts = []): string
    {
        return $this->send($this->decorate(Event::exception($error), $tags, $contexts));
    }

    private function decorate(Event $event, array $tags, array $contexts): Event
    {
        foreach ($tags as $key => $value) {
            $event = $event->withTag((string) $key, $value);
        }
        foreach ($contexts as $key => $value) {
            $event = $event->withContext((string) $key, $value);
        }
        return $event;
    }

    public function send(Event $event): string
    {
        $payload = $event->payload($this->config);
        $deadline = hrtime(true) / 1e9 + $this->config->timeout;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new DeliveryException('Bicho delivery deadline exceeded', retryable: true);
            }
            try {
                $this->attempt($payload, min(5.0, $remaining));
                return $event->uid();
            } catch (DeliveryException $error) {
                if (!$error->retryable || $attempt === 2) {
                    throw $error;
                }
                $delay = $error->retryAfter ?? 0.25 * (2 ** $attempt);
                if ($delay >= $deadline - hrtime(true) / 1e9) {
                    throw $error;
                }
                if ($delay > 0) {
                    usleep((int) ceil($delay * 1000000));
                }
            }
        }
        throw new \LogicException('Unreachable Bicho retry state');
    }

    private function attempt(string $payload, float $timeout): void
    {
        $body = '';
        $headers = [];
        $headerBytes = 0;
        $oversized = false;
        $handle = curl_init($this->config->endpoint);
        if ($handle === false) {
            throw new DeliveryException('Could not initialize Bicho transport');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $this->config->headers(),
            CURLOPT_USERAGENT => 'bicho.php/' . self::VERSION,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($timeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeout * 1000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$oversized): int {
                if (strlen($body) + strlen($chunk) > 64 * 1024) {
                    $oversized = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$headerBytes, &$oversized): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 16 * 1024) {
                    $oversized = true;
                    return 0;
                }
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $completed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($oversized) {
            throw new DeliveryException('Bicho response exceeds the size limit', $status ?: null);
        }
        if ($status !== 0 && $status !== 200) {
            throw new DeliveryException('Bicho returned HTTP ' . $status, $status, self::retryAfter($headers['retry-after'] ?? null), in_array($status, [429, 502, 503, 504], true));
        }
        if ($completed === false) {
            throw new DeliveryException('Bicho request could not be completed', $status ?: null, retryable: true);
        }
        if ($status !== 200 || strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') {
            throw new DeliveryException('Bicho returned an invalid OTLP response', $status ?: null);
        }
        self::acknowledge($body);
    }

    private static function retryAfter(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (ctype_digit($value)) {
            return (float) $value;
        }
        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('D, d M Y H:i:s \G\M\T') === $value ? max(0.0, (float) $date->getTimestamp() - microtime(true)) : null;
    }

    private static function acknowledge(string $body): void
    {
        try {
            $data = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DeliveryException('Bicho returned an invalid OTLP response', 200);
        }
        if (!$data instanceof \stdClass || array_diff(array_keys(get_object_vars($data)), ['partialSuccess']) !== []) {
            throw new DeliveryException('Bicho returned an invalid OTLP response', 200);
        }
        if (!property_exists($data, 'partialSuccess')) {
            return;
        }
        $partial = $data->partialSuccess;
        if (!$partial instanceof \stdClass || array_diff(array_keys(get_object_vars($partial)), ['rejectedLogRecords', 'errorMessage']) !== []) {
            throw new DeliveryException('Bicho returned an invalid OTLP response', 200);
        }
        $rejected = $partial->rejectedLogRecords ?? '0';
        $message = $partial->errorMessage ?? '';
        if (!(is_int($rejected) && $rejected >= 0 || is_string($rejected) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $rejected)) || !is_string($message)
            || property_exists($partial, 'rejectedLogRecords') && $partial->rejectedLogRecords === null
            || property_exists($partial, 'errorMessage') && $partial->errorMessage === null) {
            throw new DeliveryException('Bicho returned an invalid OTLP response', 200);
        }
        if ((string) $rejected !== '0' || $message !== '') {
            throw new DeliveryException('Bicho reported OTLP partial success; do not retry this event', 200);
        }
    }

    public function __debugInfo(): array
    {
        return ['config' => '[Filtered]'];
    }
}
