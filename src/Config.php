<?php
declare(strict_types=1);

namespace Bicho;

final class Config
{
    public function __construct(
        public readonly string $endpoint,
        private readonly string $key,
        public readonly string $serviceName = 'app/php',
        public readonly ?string $environment = null,
        public readonly ?string $release = null,
        private readonly ?string $secret = null,
        public readonly float $timeout = 15.0,
    ) {
        if (!preg_match('~\Ahttps?://[^/?#\s\\\\@]+/v1/logs\z~D', $endpoint)) {
            throw new \InvalidArgumentException('Expected an HTTP(S) /v1/logs endpoint without credentials, query or fragment');
        }
        $url = parse_url($endpoint);
        $host = trim($url['host'] ?? '', '[]');
        $loopback = $host === 'localhost' || $host === '::1'
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
        if (!$url || !isset($url['host']) || isset($url['user'], $url['pass']) || ($url['scheme'] === 'http' && !$loopback)) {
            throw new \InvalidArgumentException('HTTPS is required except on loopback');
        }
        foreach ([$key, $secret] as $credential) {
            if ($credential !== null && (!preg_match('/\A[\x21-\x7e]{1,512}\z/D', $credential))) {
                throw new \InvalidArgumentException('Invalid ingest credential');
            }
        }
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 60 || $serviceName === '') {
            throw new \InvalidArgumentException('Invalid service name or delivery timeout (maximum 60 seconds)');
        }
        foreach ([$serviceName, $environment, $release] as $value) {
            if ($value !== null && (strlen($value) > 512 || preg_match('//u', $value) !== 1)) {
                throw new \InvalidArgumentException('Metadata must be valid UTF-8 and at most 512 bytes');
            }
        }
    }

    public function headers(): array
    {
        $headers = ['Content-Type: application/json', 'X-Bicho-Key: ' . $this->key];
        if ($this->secret !== null) {
            $headers[] = 'X-Bicho-Secret: ' . $this->secret;
        }
        return $headers;
    }

    public function __debugInfo(): array
    {
        return ['credentials' => '[Filtered]'];
    }
}
