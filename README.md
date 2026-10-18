# Bicho PHP

[![License: Apache-2.0](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![Version: 1.0.0](https://img.shields.io/badge/version-1.0.0-blue.svg)](composer.json)
[![PHP: 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg?logo=php)](composer.json)
[![OTLP/HTTP JSON](https://img.shields.io/badge/OTLP-HTTP%20JSON-4de034.svg)](https://opentelemetry.io/docs/specs/otlp/)

Report PHP exceptions and error messages to [Bicho](https://bicho.fyi) over OTLP/HTTP JSON. The SDK uses the native cURL extension and has no third-party runtime packages. It requires PHP 8.2 or newer.

## Install

The Composer package is `bicho-fyi/php`. It is not published to Packagist yet. To use a local checkout, add a path repository to your application's composer.json:

```json
{
  "repositories": [{ "type": "path", "url": "../bicho-php" }],
  "require": { "bicho-fyi/php": "^1.0" }
}
```

Then run `composer update bicho-fyi/php` in your application. Both the cURL and JSON PHP extensions must be available.

## Configure

Use the generated `otlp.endpoint` and `otlp.headers['X-Bicho-Key']` from Bicho project registration:

```php
use Bicho\Client;
use Bicho\Config;

$tracker = new Client(new Config(
    endpoint: 'https://collector.example/v1/logs',
    key: 'YOUR_PUBLIC_KEY',
    serviceName: 'storefront/php',
    environment: 'production',
    release: 'storefront@1.0.0',
));
```

Load Composer's `vendor/autoload.php` from your application bootstrap. Config accepts optional `secret` and `timeout` named arguments. Use `secret` only for a backend project that requires an additional `X-Bicho-Secret` header. Never use the core admin token for capture or expose backend secrets to a browser.

The endpoint must use HTTPS, except on loopback for local tests, and end at the exact `/v1/logs` path. URL credentials, query strings, fragments and path normalization tricks are rejected. Redirects are not followed.

## Report errors

```php
use Bicho\DeliveryException;

try {
    processOrder();
} catch (Throwable $error) {
    try {
        $tracker->captureException($error,
            tags: ['operation' => 'checkout'],
            contexts: ['checkout' => ['stage' => 'payment']],
        );
    } catch (DeliveryException $delivery) {
        error_log($delivery->getMessage());
    }
    throw $error;
}
```

`captureException` records the native exception class, message, failure location, trace locations and up to eight previous exception messages. Stack frames omit function arguments and object values. Arbitrary messages and paths can still contain sensitive information; review what you send.

`captureMessage('Catalog refresh failed')` reports an ERROR message. Tags accept finite scalar values. Contexts accept bounded arrays and scalar data; arbitrary objects become class labels without invoking custom serialization. The SDK does not automatically collect requests, headers, cookies, sessions, user identities or environment variables.

Capture is explicit. No global exception/error/shutdown handlers are installed, and no framework or WordPress hooks are registered. Use your application's existing error handler. Reporting does not replace normal exception handling or native crash behavior.

## Keep retry identity

```php
use Bicho\Event;

$event = Event::message('Catalog refresh failed')
    ->withTag('operation', 'catalog_refresh')
    ->withContext('catalog', ['source' => 'import']);

$uid = $tracker->send($event);
```

Events are immutable through their fluent methods and keep their original occurrence UID and timestamp. Automatic retries reuse exactly the same serialized payload. If you explicitly retry an ambiguous delivery, reuse the same Event; calling a capture method again creates another occurrence. A returned UID confirms a valid acknowledgment, but is not Bicho's stored event ID. The core derives that ID from the UID and authenticated project.

`withLevel` accepts `debug`, `info`, `warning`, `error` or `fatal`. Plain messages must use error or fatal; exceptions may use any of these levels. `withStacktrace` supplies explicit stack text. Protocol-owned attributes (`log.record.uid`, `exception.*`, `sdk.*`, `bicho.*`, `service.*`, `deployment.*`) cannot be overridden through tags.

## Delivery and limits

Sending is synchronous. Each call blocks until delivery succeeds or fails; there is no queue, background worker or flush method. Capture during a web request adds reporting latency. The overall deadline defaults to 15 seconds and can be configured to a positive value up to 60 seconds. Each HTTP attempt is limited to five seconds or the remaining deadline, whichever is shorter.

Network failures and HTTP 429/502/503/504 responses allow at most two retries. Retry-After accepts seconds and standard HTTP dates; the SDK does not retry before that delay. If the delay would exceed the deadline, it returns the failure instead. Redirects, other HTTP failures, invalid acknowledgments and populated OTLP partial success are terminal. A lost or truncated HTTP acknowledgment is retryable with the same occurrence ID.

HTTP 200 must contain a valid OTLP JSON acknowledgment. A populated `partialSuccess` response raises DeliveryException and must not be retried. DeliveryException exposes `status`, `retryAfter` and `retryable` for diagnostics. Its retryable flag describes the failure; the SDK may already have exhausted its internal attempt/deadline budget. Error messages never contain upstream response bodies or credential values.

Events are limited to 60 KiB serialized, responses to 64 KiB and response headers to 16 KiB. Messages and stack text stop at 16 KiB, metadata/tag strings at 512 bytes. Context normalization stops at five nested levels, 50 entries per collection and 300 visited values, with strings limited to 2048 bytes. At most 100 tags and 50 contexts are accepted. Contexts are stored in the `bicho.contexts` OTLP string attribute. Invalid UTF-8 is replaced and byte clipping preserves valid output.

Credential-like keys in tags and contexts, Basic/Bearer strings and HTTP(S) URL credentials/query/fragment values are filtered before sending. Config/client/event debug output omits captured secrets. Filtering is best effort, not a guarantee that arbitrary prose or source code contains no private data. Explicit stack text is your responsibility.

There is no persistent offline queue or delivery guarantee. Traces, metrics, sessions, replay, attachments, automatic fatal-error reporting and source-map uploads are outside this SDK.

## Development

```sh
composer install
composer validate --strict --no-check-version
composer check
composer test
composer archive --format=tar --dir=_temp
```

Tests use native PHP loopback HTTP fixtures and do not require a hosted collector. A separate local core interoperability probe can verify server persistence and retry deduplication without making a sibling checkout part of the portable suite.

## License

Copyright 2026 Alex Zappa (FreshJuice). Licensed under Apache-2.0. See [LICENSE](LICENSE) and [ACKNOWLEDGEMENTS.md](ACKNOWLEDGEMENTS.md).
