<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

abstract class DomainException extends RuntimeException
{
    protected string $errorCode;

    /** @var array<string, mixed> */
    protected array $context = [];

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    /** @return array<string, mixed> */
    public function toApiError(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'meta' => $this->context,
        ];
    }
}
