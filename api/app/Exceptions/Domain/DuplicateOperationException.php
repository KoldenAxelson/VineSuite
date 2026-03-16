<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

class DuplicateOperationException extends DomainException
{
    public function __construct(
        public readonly string $operationType,
        public readonly string $entityId,
        string $message = '',
    ) {
        $this->errorCode = 'DUPLICATE_OPERATION';
        if (empty($message)) {
            $message = "{$operationType} has already been completed for entity {$entityId}.";
        }
        parent::__construct($message);
        $this->context = [
            'operationType' => $this->operationType,
            'entityId' => $this->entityId,
        ];
    }
}
