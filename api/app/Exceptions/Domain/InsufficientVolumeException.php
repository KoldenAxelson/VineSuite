<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

class InsufficientVolumeException extends DomainException
{
    public function __construct(
        public readonly string $lotId,
        public readonly string $lotName,
        public readonly float $available,
        public readonly float $requested,
    ) {
        $this->errorCode = 'INSUFFICIENT_VOLUME';
        $message = "Lot '{$lotName}' has insufficient volume. Available: {$available}, Requested: {$requested}";
        parent::__construct($message);
        $this->context = [
            'lotId' => $this->lotId,
            'lotName' => $this->lotName,
            'available' => $this->available,
            'requested' => $this->requested,
        ];
    }
}
