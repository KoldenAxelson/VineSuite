<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

class InvalidStatusTransitionException extends DomainException
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $currentStatus,
        public readonly string $attemptedStatus,
    ) {
        $this->errorCode = 'INVALID_STATUS_TRANSITION';
        $message = "Cannot transition {$entityType} '{$entityId}' from status '{$currentStatus}' to '{$attemptedStatus}'";
        parent::__construct($message);
        $this->context = [
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'currentStatus' => $this->currentStatus,
            'attemptedStatus' => $this->attemptedStatus,
        ];
    }
}
