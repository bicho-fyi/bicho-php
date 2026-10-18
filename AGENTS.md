# Bicho PHP

Explicit PHP exception/error reporting over OTLP/HTTP JSON. Read README.md, source and relevant tests before editing.

- PHP 8.2+, native cURL and Composer PSR-4 under Bicho\. Keep third-party runtime packages out unless needed.
- Preserve exact serialized payloads and occurrence IDs across retries, including failures while reading acknowledgments. Never retry populated partial success or follow redirects with credentials.
- Ingest credentials authorize capture only. Never accept an admin token. Keep credentials out of debug output, response errors, logs and Git.
- Do not collect function arguments, request data or arbitrary object properties. Filter bounded evidence before sending.
- Do not install global handlers, a background queue or framework hooks implicitly. Preserve the application's original error handling.
- Verify with composer validate --strict --no-check-version, composer check and composer test. Exercise real loopback requests and inspect Composer archives; keep sibling-dependent core probes separate.
- Put scratch files in ignored _temp/. Commit/push only with task-specific "yolo" approval; publishing requires separate approval.
- Preserve the complete Apache-2.0 license and attribution.
