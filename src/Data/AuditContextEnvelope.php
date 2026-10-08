<?php

namespace Digit7s\AuditToolkit\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A deliberately small, serializable queue boundary for audit attribution.
 *
 * It contains references and identifiers only. It must never contain an
 * Authenticatable instance, request, session, cookie, credential, or payload.
 */
final readonly class AuditContextEnvelope
{
    public function __construct(
        public ?AuditReference $actor = null,
        public ?AuditReference $originalActor = null,
        public ?string $guard = null,
        public ?string $requestId = null,
        public ?string $correlationId = null,
        public ?string $batchId = null,
        public ?string $originalExecutionSource = null,
        public ?string $currentExecutionSource = null,
    ) {
        foreach ([
            'guard' => [$this->guard, 80],
            'requestId' => [$this->requestId, 36],
            'correlationId' => [$this->correlationId, 36],
            'batchId' => [$this->batchId, 36],
            'originalExecutionSource' => [$this->originalExecutionSource, 40],
            'currentExecutionSource' => [$this->currentExecutionSource, 40],
        ] as $field => [$value, $maxLength]) {
            if ($value !== null && (trim($value) === '' || mb_strlen($value) > $maxLength)) {
                throw new InvalidArgumentException("Audit context {$field} is invalid.");
            }
        }

        foreach (['requestId' => $this->requestId, 'correlationId' => $this->correlationId, 'batchId' => $this->batchId] as $field => $value) {
            if ($value !== null && ! Str::isUuid($value)) {
                throw new InvalidArgumentException("Audit context {$field} must be a valid UUID.");
            }
        }
    }

    public function toContext(): AuditContext
    {
        return new AuditContext(
            actor: $this->actor,
            originalActor: $this->originalActor,
            guard: $this->guard,
            source: $this->currentExecutionSource,
            originalSource: $this->originalExecutionSource,
            correlationId: $this->correlationId,
            requestId: $this->requestId,
            batchId: $this->batchId,
        );
    }

    public static function fromContext(AuditContext $context): self
    {
        return new self(
            actor: $context->actor,
            originalActor: $context->originalActor,
            guard: $context->guard,
            requestId: $context->requestId,
            correlationId: $context->correlationId,
            batchId: $context->batchId,
            originalExecutionSource: $context->originalSource,
            currentExecutionSource: $context->source,
        );
    }
}
