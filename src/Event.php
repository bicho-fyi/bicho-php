<?php
declare(strict_types=1);

namespace Bicho;

final class Event
{
    public const VERSION = '1.0.0';
    private string $uid;
    private string $timestamp;
    private string $level = 'error';
    private ?string $exceptionType = null;
    private ?string $stacktrace = null;
    private array $tags = [];
    private array $contexts = [];

    private function __construct(private readonly string $message)
    {
        $this->uid = bin2hex(random_bytes(16));
        $time = gettimeofday();
        $this->timestamp = $time['sec'] . str_pad((string) $time['usec'], 6, '0', STR_PAD_LEFT) . '000';
    }

    public static function message(string $message): self
    {
        return new self($message);
    }

    public static function exception(\Throwable $error): self
    {
        $event = new self($error->getMessage());
        $event->exceptionType = get_class($error);
        $stack = ['#0 ' . $error->getFile() . '(' . $error->getLine() . '): throw'];
        foreach (array_slice($error->getTrace(), 0, 99) as $index => $frame) {
            $stack[] = '#' . ($index + 1) . ' ' . ($frame['file'] ?? '[internal]') . '(' . ($frame['line'] ?? 0) . '): '
                . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '[unknown]');
        }
        $event->stacktrace = implode("\n", $stack);
        $previous = $error->getPrevious();
        $causes = [];
        while ($previous !== null && count($causes) < 8) {
            $causes[] = ['type' => get_class($previous), 'message' => Privacy::text($previous->getMessage())];
            $previous = $previous->getPrevious();
        }
        if ($causes !== []) {
            $event->contexts['error_chain'] = $causes;
        }
        return $event;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function withTag(string $key, string|int|float|bool $value): self
    {
        if ($key === '' || strlen($key) > 128 || preg_match('//u', $key) !== 1 || is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('Tags require bounded UTF-8 keys and finite scalar values');
        }
        $copy = clone $this;
        $copy->tags[$key] = $value;
        return $copy;
    }

    public function withContext(string $key, mixed $value): self
    {
        if ($key === '' || strlen($key) > 128 || preg_match('//u', $key) !== 1) {
            throw new \InvalidArgumentException('Context keys must be bounded UTF-8 strings');
        }
        $copy = clone $this;
        $copy->contexts[$key] = Privacy::sensitive($key) ? '[Filtered]' : Privacy::normalize($value);
        return $copy;
    }

    public function withLevel(string $level): self
    {
        if (!in_array($level, ['debug', 'info', 'warning', 'error', 'fatal'], true)) {
            throw new \InvalidArgumentException('Invalid Bicho severity');
        }
        $copy = clone $this;
        $copy->level = $level;
        return $copy;
    }

    public function withStacktrace(string $stacktrace): self
    {
        $copy = clone $this;
        $copy->stacktrace = $stacktrace;
        return $copy;
    }

    public function payload(Config $config): string
    {
        if ($this->exceptionType === null && !in_array($this->level, ['error', 'fatal'], true)) {
            throw new \InvalidArgumentException('Plain messages must use error or fatal severity');
        }
        if (count($this->tags) > 100 || count($this->contexts) > 50) {
            throw new \InvalidArgumentException('Too many tags or contexts');
        }
        $attribute = static function (string $key, string|int|float|bool $value): array {
            $field = match (true) { is_int($value) => 'intValue', is_float($value) => 'doubleValue', is_bool($value) => 'boolValue', default => 'stringValue' };
            return ['key' => $key, 'value' => [$field => is_int($value) ? (string) $value : $value]];
        };
        $resource = [$attribute('service.name', Privacy::text($config->serviceName, 512))];
        foreach (['deployment.environment.name' => $config->environment, 'service.version' => $config->release] as $key => $value) {
            if ($value !== null) {
                $resource[] = $attribute($key, Privacy::text($value, 512));
            }
        }
        $attrs = [];
        foreach ($this->tags as $key => $value) {
            $key = (string) $key;
            if (preg_match('/\A(?:log\.record\.uid\z|exception\.|sdk\.|bicho\.|service\.|deployment\.)/', $key)) {
                continue;
            }
            $attrs[] = $attribute($key, Privacy::sensitive($key) ? '[Filtered]' : (is_string($value) ? Privacy::text($value, 512) : $value));
        }
        $attrs[] = $attribute('log.record.uid', $this->uid);
        $attrs[] = $attribute('sdk.name', 'bicho.php');
        $attrs[] = $attribute('sdk.version', self::VERSION);
        if ($this->exceptionType !== null) {
            $attrs[] = $attribute('exception.type', Privacy::text($this->exceptionType, 512));
            $attrs[] = $attribute('exception.message', Privacy::text($this->message, 16384));
        }
        if ($this->stacktrace !== null) {
            $attrs[] = $attribute('exception.stacktrace', Privacy::text($this->stacktrace, 16384));
        }
        if ($this->contexts !== []) {
            $attrs[] = $attribute('bicho.contexts', json_encode(Privacy::normalize($this->contexts), JSON_THROW_ON_ERROR));
        }
        $body = json_encode(['resourceLogs' => [['resource' => ['attributes' => $resource], 'scopeLogs' => [[
            'scope' => ['name' => 'bicho.php', 'version' => self::VERSION],
            'logRecords' => [['timeUnixNano' => $this->timestamp, 'severityNumber' => ['debug' => 5, 'info' => 9, 'warning' => 13, 'error' => 17, 'fatal' => 21][$this->level], 'body' => ['stringValue' => Privacy::text($this->message, 16384)], 'attributes' => $attrs]],
        ]]]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > 60 * 1024) {
            throw new \InvalidArgumentException('Bicho event exceeds 60 KiB');
        }
        return $body;
    }

    public function __debugInfo(): array
    {
        return ['uid' => $this->uid];
    }
}
