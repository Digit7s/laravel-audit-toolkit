<?php

namespace Digit7s\AuditToolkit\Support;

use Closure;
use Digit7s\AuditToolkit\Data\AuditContext;
use Digit7s\AuditToolkit\Data\AuditContextEnvelope;
use Digit7s\AuditToolkit\Data\AuditReference;
use Illuminate\Database\Eloquent\Model;

/** Execution-scoped context stack. Never persists context outside the call stack. */
final class AuditContextStore
{
    /** @var list<AuditContext> */
    private array $stack = [];

    public function current(): ?AuditContext
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    public function capture(AuditContext $context): AuditContextEnvelope
    {
        return AuditContextEnvelope::fromContext($this->current() ?? $context);
    }

    public function push(AuditContext $context): void
    {
        $this->stack[] = $context;
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    public function run(AuditContext $context, Closure $callback): mixed
    {
        $this->push($context);

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    /**
     * Mark an execution as impersonated without switching accounts or storing
     * model objects. Nested scopes restore the prior attribution in finally.
     */
    public function withImpersonation(
        AuditReference|Model $originalActor,
        Closure $callback,
        AuditReference|Model|null $effectiveActor = null,
    ): mixed {
        $original = $originalActor instanceof Model
            ? AuditReference::fromModel($originalActor)
            : $originalActor;
        $effective = $effectiveActor instanceof Model
            ? AuditReference::fromModel($effectiveActor)
            : $effectiveActor;
        $current = $this->current() ?? new AuditContext;

        return $this->run(new AuditContext(
            actor: $effective ?? $current->actor,
            originalActor: $original,
            guard: $current->guard,
            source: $current->source,
            originalSource: $current->originalSource,
            correlationId: $current->correlationId,
            requestId: $current->requestId,
            batchId: $current->batchId,
            metadata: $current->metadata,
        ), $callback);
    }

    public function clear(): void
    {
        $this->stack = [];
    }
}
