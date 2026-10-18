# Acknowledgements

Bicho PHP uses the [OpenTelemetry Protocol](https://opentelemetry.io/docs/specs/otlp/) JSON mapping and [exception log conventions](https://opentelemetry.io/docs/specs/semconv/exceptions/exceptions-logs/). It is an error-reporting client, not a complete OpenTelemetry SDK.

Transport uses PHP's cURL extension and its linked libcurl implementation. Those components retain their own licenses. No OpenTelemetry schema files, Sentry implementation or third-party PHP packages are vendored in this SDK.
