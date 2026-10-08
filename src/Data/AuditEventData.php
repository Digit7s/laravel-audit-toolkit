<?php

namespace Digit7s\AuditToolkit\Data;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AuditEventData
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     * @param  array<string>  $allowedValueKeys
     */
    public function __construct(
        public string $event,
        public ?string $category,
        public ?string $description,
        public ?AuditReference $actor,
        public ?AuditReference $subject,
        public array $oldValues,
        public array $newValues,
        public array $metadata,
        public DateTimeInterface $occurredAt,
        public ?string $source,
        public ?string $guard,
        public ?string $correlationId,
        public ?string $batchId,
        public ?string $requestId,
        public array $allowedValueKeys = [],
        public readonly ?string $connection = null,
        public readonly ?AuditReference $originalActor = null,
        public readonly bool $inheritContextActor = true,
    ) {
        $event = trim($this->event);

        if ($event === '' || mb_strlen($event) > 150) {
            throw new InvalidArgumentException('Audit event names must contain between 1 and 150 characters.');
        }

        if (preg_match('/[^a-zA-Z0-9._:-]/', $event) === 1) {
            throw new InvalidArgumentException('Audit event names may only contain letters, numbers, dots, underscores, colons, and hyphens.');
        }

        if ($this->category !== null && mb_strlen($this->category) > 80) {
            throw new InvalidArgumentException('Audit categories may not exceed 80 characters.');
        }

        foreach (['description' => $this->description, 'source' => $this->source, 'guard' => $this->guard] as $field => $value) {
            $limit = $field === 'description' ? 255 : ($field === 'source' ? 40 : 80);

            if ($value !== null && mb_strlen($value) > $limit) {
                throw new InvalidArgumentException(sprintf('Audit %s exceeds its storage length.', $field));
            }
        }

        foreach (['correlationId' => $this->correlationId, 'batchId' => $this->batchId, 'requestId' => $this->requestId] as $field => $value) {
            if ($value !== null && ! Str::isUuid($value)) {
                throw new InvalidArgumentException(sprintf('Audit %s must be a valid UUID.', $field));
            }
        }

        if ($this->connection !== null && trim($this->connection) === '') {
            throw new InvalidArgumentException('Audit connection may not be empty.');
        }
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     * @param  array<string>  $allowedValueKeys
     */
    public static function make(
        string $event,
        ?AuditReference $actor = null,
        ?AuditReference $subject = null,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
        ?string $category = null,
        ?string $description = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $source = 'manual',
        ?string $guard = null,
        ?string $correlationId = null,
        ?string $batchId = null,
        ?string $requestId = null,
        array $allowedValueKeys = [],
        ?string $connection = null,
        ?AuditReference $originalActor = null,
        bool $inheritContextActor = true,
    ): self {
        return new self(
            event: trim($event),
            category: $category,
            description: $description,
            actor: $actor,
            subject: $subject,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            occurredAt: $occurredAt ?? new DateTimeImmutable,
            source: $source,
            guard: $guard,
            correlationId: $correlationId,
            batchId: $batchId,
            requestId: $requestId,
            allowedValueKeys: $allowedValueKeys,
            connection: $connection,
            originalActor: $originalActor,
            inheritContextActor: $inheritContextActor,
        );
    }
}
