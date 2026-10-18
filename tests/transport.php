<?php
declare(strict_types=1);

use Bicho\Client;
use Bicho\Config;
use Bicho\DeliveryException;
use Bicho\Event;

final class TransportFixture
{
    public readonly string $endpoint;
    private string $log;
    private mixed $process = null;
    private array $pipes = [];

    public function __construct(array $responses)
    {
        $directory = dirname(__DIR__) . '/_temp';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $this->log = $directory . '/transport-' . bin2hex(random_bytes(12)) . '.jsonl';
        touch($this->log);
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Could not start HTTP fixture');
        }
        try {
            fwrite($this->pipes[0], json_encode(['log' => $this->log, 'responses' => $responses], JSON_THROW_ON_ERROR) . "\n");
            fclose($this->pipes[0]);
            $read = [$this->pipes[1]];
            $write = $except = [];
            check(stream_select($read, $write, $except, 3) === 1, 'HTTP fixture startup timed out');
            $port = trim((string) fgets($this->pipes[1]));
            check(ctype_digit($port) && (int) $port > 0, 'HTTP fixture did not report a port');
            $this->endpoint = "http://127.0.0.1:$port/v1/logs";
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function client(string $key = 'fixture-key', ?string $secret = 'fixture-secret', float $timeout = 4): Client
    {
        return new Client(new Config($this->endpoint, $key, serviceName: 'php-transport', environment: 'test', release: 'transport-v1', secret: $secret, timeout: $timeout));
    }

    public function requests(): array
    {
        $lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            $end = microtime(true) + 0.5;
            while (proc_get_status($this->process)['running'] && microtime(true) < $end) {
                usleep(10000);
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 9);
            }
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

function deliveryFailure(callable $send, ?int $status, bool $retryable, ?float $retryAfter = null): DeliveryException
{
    try {
        $send();
    } catch (DeliveryException $error) {
        check($error instanceof RuntimeException, 'Delivery error must extend RuntimeException');
        check($error->status === $status, 'Wrong delivery status');
        check($error->retryable === $retryable, 'Wrong retryable flag');
        check($error->retryAfter === $retryAfter, 'Wrong Retry-After value');
        foreach (['fixture-key', 'fixture-secret', 'response-private-marker', 'event-private-marker'] as $private) {
            check(!str_contains($error->getMessage(), $private), 'Delivery error leaked private evidence');
        }
        return $error;
    }
    throw new RuntimeException('Delivery unexpectedly succeeded');
}

function sameTransportAttempts(TransportFixture $fixture, int $count): array
{
    $requests = $fixture->requests();
    check(count($requests) === $count, 'Unexpected HTTP attempt count');
    check(count(array_unique(array_column($requests, 'body'))) === 1, 'Retry changed serialized bytes');
    return $requests;
}

test('real HTTP sends JSON and auth headers with stable event identity', function (): void {
    $fixture = new TransportFixture([[]]);
    try {
        $client = $fixture->client();
        $event = Event::message('transport message')->withTag('operation', 'send')->withContext('cart', ['items' => 2]);
        check($client->send($event) === $event->uid(), 'send returned a different UID');
        $messageUid = $client->captureMessage('capture message', ['operation' => 'message'], ['cart' => ['items' => 3]]);
        $exceptionUid = $client->captureException(new LogicException('capture exception'), ['operation' => 'exception'], ['cart' => ['items' => 4]]);
        $requests = $fixture->requests();
        check(count($requests) === 3, 'Capture helpers did not send exactly once');
        foreach ($requests as $index => $request) {
            check($request['line'] === 'POST /v1/logs HTTP/1.1', 'Wrong request method or path');
            check($request['headers']['x-bicho-key'] === 'fixture-key', 'Missing ingest key');
            check($request['headers']['x-bicho-secret'] === 'fixture-secret', 'Missing ingest secret');
            check(str_starts_with($request['headers']['content-type'], 'application/json'), 'Wrong content type');
            $json = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
            $record = $json['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
            $attributes = array_column($record['attributes'], 'value', 'key');
            check($attributes['log.record.uid']['stringValue'] === [$event->uid(), $messageUid, $exceptionUid][$index], 'Returned UID differs from transmitted UID');
            check($attributes['operation']['stringValue'] === ['send', 'message', 'exception'][$index], 'Capture tags were not forwarded');
            check(json_decode($attributes['bicho.contexts']['stringValue'], true)['cart']['items'] === [2, 3, 4][$index], 'Capture contexts were not forwarded');
        }
    } finally {
        $fixture->close();
    }
});

test('transient HTTP and lost or truncated acknowledgments retry exact bytes', function (): void {
    foreach ([['status' => 503], ['drop' => true], ['body' => '{', 'length' => 30]] as $response) {
        $fixture = new TransportFixture([$response, []]);
        try {
            $event = Event::message('retry identity');
            check($fixture->client()->send($event) === $event->uid(), 'Retry lost event identity');
            sameTransportAttempts($fixture, 2);
        } finally {
            $fixture->close();
        }
    }
});

test('429 Retry-After delays retries and preserves bytes', function (): void {
    $fixture = new TransportFixture([['status' => 429, 'headers' => ['Retry-After' => '1']], []]);
    try {
        $fixture->client()->captureMessage('rate limited');
        $requests = sameTransportAttempts($fixture, 2);
        check($requests[1]['time'] - $requests[0]['time'] >= 0.95, 'Retried before Retry-After elapsed');
    } finally {
        $fixture->close();
    }
});

test('partial success, malformed success, forbidden, redirects and oversized responses are terminal', function (): void {
    $target = new TransportFixture([[]]);
    try {
        foreach ([
            ['body' => '{"partialSuccess":{"rejectedLogRecords":"1","errorMessage":"response-private-marker"}}'],
            ['body' => 'response-private-marker'],
            ['status' => 403, 'body' => 'response-private-marker'],
            ['status' => 302, 'headers' => ['Location' => $target->endpoint], 'body' => 'response-private-marker'],
            ['body' => '{}' . str_repeat(' ', 65535)],
        ] as $response) {
            $fixture = new TransportFixture([$response, []]);
            try {
                deliveryFailure(fn () => $fixture->client()->captureMessage('event-private-marker'), $response['status'] ?? 200, false);
                sameTransportAttempts($fixture, 1);
                check($target->requests() === [], 'Redirect forwarded ingest credentials');
            } finally {
                $fixture->close();
            }
        }
    } finally {
        $target->close();
    }
});

test('retryable responses stop after three attempts and expose safe error fields', function (): void {
    foreach ([503, 429] as $status) {
        $fixture = new TransportFixture([['status' => $status, 'headers' => ['Retry-After' => '0'], 'body' => 'response-private-marker']]);
        try {
            deliveryFailure(fn () => $fixture->client()->captureMessage('event-private-marker'), $status, true, 0.0);
            sameTransportAttempts($fixture, 3);
        } finally {
            $fixture->close();
        }
    }
});

test('overall deadline bounds response reads and Retry-After sleep', function (): void {
    foreach ([['delay' => 1.2], ['bodyDelay' => 1.2], ['status' => 429, 'headers' => ['Retry-After' => '1']]] as $response) {
        $fixture = new TransportFixture([$response, []]);
        try {
            $start = microtime(true);
            try {
                $fixture->client(timeout: 0.2)->captureMessage('event-private-marker');
                throw new RuntimeException('Deadline unexpectedly succeeded');
            } catch (DeliveryException $error) {
                check($error->retryable, 'Deadline must remain retryable');
                check(!str_contains($error->getMessage(), 'event-private-marker'), 'Deadline leaked event');
                if (isset($response['status'])) {
                    check($error->status === 429 && $error->retryAfter === 1.0, 'Deadline lost HTTP retry metadata');
                }
            }
            check(microtime(true) - $start < 0.85, 'Configured overall deadline was exceeded');
            check(count($fixture->requests()) === 1, 'Deadline allowed another HTTP request');
        } finally {
            $fixture->close();
        }
    }
});

test('clients retain isolated credentials and configuration', function (): void {
    $first = new TransportFixture([[]]);
    $second = new TransportFixture([[]]);
    try {
        $a = $first->client('fixture-client-a', 'fixture-secret-a');
        $b = $second->client('fixture-client-b', null);
        $a->captureMessage('first');
        $b->captureMessage('second');
        $a->captureMessage('first again');
        $requests = $first->requests();
        check(count($requests) === 2 && count($second->requests()) === 1, 'Client endpoint state was shared');
        foreach ($requests as $request) {
            check($request['headers']['x-bicho-key'] === 'fixture-client-a' && $request['headers']['x-bicho-secret'] === 'fixture-secret-a', 'Client credentials were overwritten');
        }
        $headers = $second->requests()[0]['headers'];
        check($headers['x-bicho-key'] === 'fixture-client-b' && !isset($headers['x-bicho-secret']), 'Optional secret leaked between clients');
    } finally {
        $first->close();
        $second->close();
    }
});
