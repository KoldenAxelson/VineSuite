<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

class CapacityExceededException extends DomainException
{
    public function __construct(
        public readonly string $vesselId,
        public readonly string $vesselName,
        public readonly float $capacity,
        public readonly float $currentVolume,
        public readonly float $attemptedAdd,
    ) {
        $this->errorCode = 'CAPACITY_EXCEEDED';
        $totalVolume = $this->currentVolume + $this->attemptedAdd;
        $message = "Vessel '{$vesselName}' would exceed capacity. Capacity: {$capacity}, Current: {$currentVolume}, Attempted add: {$attemptedAdd}, Total: {$totalVolume}";
        parent::__construct($message);
        $this->context = [
            'vesselId' => $this->vesselId,
            'vesselName' => $this->vesselName,
            'capacity' => $this->capacity,
            'currentVolume' => $this->currentVolume,
            'attemptedAdd' => $this->attemptedAdd,
        ];
    }
}
