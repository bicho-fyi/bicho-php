<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Bicho\Config;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test(string $name, callable $run): void {
    $run();
    echo "PASS $name\n";
}

test('configuration validates endpoints and keeps credentials out of debug output', function (): void {
    $config = new Config('https://collector.example/v1/logs', 'private-key', secret: 'private-secret');
    ob_start();
    var_dump($config);
    $debug = ob_get_clean();
    check(!str_contains($debug, 'private-key') && !str_contains($debug, 'private-secret'), 'Config debug output leaked credentials');
    foreach (['http://example.com/v1/logs', 'https://user:secret@example.com/v1/logs', 'https://example.com/v1/logs?key=x', 'https://example.com/v1/logs#x', 'https://example.com/x/../v1/logs', 'https://example.com/api', "https://example.com\\v1/logs"] as $endpoint) {
        try { new Config($endpoint, 'public'); } catch (InvalidArgumentException) { continue; }
        throw new RuntimeException('Accepted invalid endpoint');
    }
    foreach (["bad\nkey", '', str_repeat('x', 513)] as $key) {
        try { new Config('https://collector.example/v1/logs', $key); } catch (InvalidArgumentException) { continue; }
        throw new RuntimeException('Accepted invalid ingest key');
    }
    check((new Config('http://127.0.0.1:8080/v1/logs', 'public'))->timeout === 15.0, 'Loopback configuration failed');
});

test('events retain identity and normalize safe OTLP evidence', function (): void {
    $event = Bicho\Event::message('failed https://name:hidden@example.test/path?token=hidden#hidden')
        ->withTag('operation', 'checkout')
        ->withTag('authorization', 'hidden')
        ->withTag('log.record.uid', 'override')
        ->withContext('cart', ['password' => 'hidden', 'items' => 2, 'headers' => [['X-Api-Key', 'hidden']]]);
    $config = new Config('https://collector.example/v1/logs', 'public', serviceName: 'php-app', environment: 'test', release: 'v1');
    $body = $event->payload($config);
    check($body === $event->payload($config), 'Event payload changed on retry');
    check(!str_contains($body, 'hidden') && !str_contains($body, 'override'), 'Credentials or protocol override leaked');
    $record = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
    $attrs = array_column($record['attributes'], 'value', 'key');
    check($attrs['log.record.uid']['stringValue'] === $event->uid(), 'Occurrence identity changed');
    check($record['severityNumber'] === 17 && ctype_digit($record['timeUnixNano']), 'Invalid OTLP severity or timestamp');
    check(json_decode($attrs['bicho.contexts']['stringValue'], true)['cart']['items'] === 2, 'Safe context was lost');
    check(Bicho\Event::message('ééé')->payload($config) !== '', 'UTF-8 text failed');
    try { Bicho\Event::message('ordinary')->withLevel('warning')->payload($config); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Low-severity plain message was accepted');
});

test('native exception capture preserves trace locations without argument values', function (): void {
    $make = function (string $argument): void { throw new RuntimeException('failure', previous: new LogicException('root cause')); };
    try { $make('private-function-argument'); } catch (Throwable $error) { $event = Bicho\Event::exception($error); }
    $body = $event->payload(new Config('https://collector.example/v1/logs', 'public'));
    $record = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
    $attrs = array_column($record['attributes'], 'value', 'key');
    check($attrs['exception.type']['stringValue'] === RuntimeException::class, 'Wrong exception type');
    check(str_contains($attrs['exception.stacktrace']['stringValue'], __FILE__), 'Exception location was lost');
    check(!str_contains($body, 'private-function-argument'), 'Stack arguments leaked');
    check(str_contains($attrs['bicho.contexts']['stringValue'], 'root cause'), 'Exception cause was lost');
});

test('client configuration and debug output stay isolated', function (): void {
    $first = new Bicho\Client(new Config('https://collector.example/v1/logs', 'private-key', secret: 'private-secret'));
    $second = new Bicho\Client(new Config('https://other.example/v1/logs', 'another-private-key'));
    ob_start(); var_dump($first, $second); $debug = ob_get_clean();
    check(!str_contains($debug, 'private-key') && !str_contains($debug, 'private-secret'), 'Client debug output leaked credentials');
    check(Bicho\Client::VERSION === '1.0.0', 'SDK version is not 1.0.0');
});

test('numeric tag names and bounded UTF-8 contexts remain valid OTLP', function (): void {
    $config = new Config('https://collector.example/v1/logs', 'public');
    $event = Bicho\Event::message("failed \xff")
        ->withTag('123', 'numeric-name')
        ->withContext('data', ['value' => str_repeat('é', 2000)]);
    $body = $event->payload($config);
    $record = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
    $attrs = array_column($record['attributes'], 'value', 'key');
    check($attrs['123']['stringValue'] === 'numeric-name', 'Numeric tag name failed');
    $context = json_decode($attrs['bicho.contexts']['stringValue'], true, flags: JSON_THROW_ON_ERROR);
    check(strlen($context['data']['value']) <= 2048, 'Context string exceeded the byte limit');
    $object = new class implements JsonSerializable { public function jsonSerialize(): mixed { throw new RuntimeException('must not execute'); } };
    check(!str_contains(Bicho\Event::message('safe')->withContext('object', $object)->payload($config), 'must not execute'), 'Custom serialization executed');
});

require __DIR__ . '/transport.php';

test('context snapshots preserve null and do not follow later array changes', function (): void {
    $context = ['safe' => 1];
    $event = Bicho\Event::message('context snapshot')->withContext('nullable', null)->withContext('data', $context);
    $context['safe'] = 99;
    $record = json_decode($event->payload(new Config('https://collector.example/v1/logs', 'public')), true, flags: JSON_THROW_ON_ERROR)['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
    $attributes = array_column($record['attributes'], 'value', 'key');
    $captured = json_decode($attributes['bicho.contexts']['stringValue'], true, flags: JSON_THROW_ON_ERROR);
    check(array_key_exists('nullable', $captured) && $captured['nullable'] === null, 'Safe null context was changed');
    check($captured['data']['safe'] === 1, 'Context changed after capture');
});

test('Retry-After HTTP dates preserve the remaining backoff without delaying past the deadline', function (): void {
    $fixture = new TransportFixture([['status' => 429, 'headers' => ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3)]]]);
    try {
        try { $fixture->client(timeout: 0.2)->captureMessage('date backoff'); } catch (Bicho\DeliveryException $error) {
            check($error->status === 429 && $error->retryAfter > 1.0, 'HTTP-date retry metadata was lost');
            sameTransportAttempts($fixture, 1);
            return;
        }
        throw new RuntimeException('Long HTTP-date backoff did not reject');
    } finally { $fixture->close(); }
});
