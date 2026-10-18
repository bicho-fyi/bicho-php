<?php
declare(strict_types=1);

namespace Bicho;

final class DeliveryException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?float $retryAfter = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public function __debugInfo(): array
    {
        return ['message' => $this->getMessage(), 'status' => $this->status, 'retryAfter' => $this->retryAfter, 'retryable' => $this->retryable];
    }
}
