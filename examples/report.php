<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Bicho\Client;
use Bicho\Config;
use Bicho\Event;

$endpoint = getenv('BICHO_ENDPOINT');
$key = getenv('BICHO_PUBLIC_KEY');
if (!$endpoint || !$key) {
    throw new RuntimeException('Set BICHO_ENDPOINT and BICHO_PUBLIC_KEY before running this example');
}
$client = new Client(new Config($endpoint, $key,
    serviceName: 'php-sdk-example',
    environment: 'test',
    release: 'php-sdk-example@' . Client::VERSION,
    secret: getenv('BICHO_INGEST_SECRET') ?: null,
));
try {
    throw new RuntimeException('Explicit PHP SDK test');
} catch (Throwable $error) {
    $event = Event::exception($error)
        ->withTag('operation', 'sdk-example')
        ->withContext('example', ['password' => 'private-fixture', 'safe' => true]);
    $client->send($event);
    $client->send($event);
    echo $event->uid() . "\n";
}
