<?php

declare(strict_types=1);

namespace GroupPayments;

final class ApiError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly ?string $param = null,
        public readonly ?string $docUrl = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }
}
