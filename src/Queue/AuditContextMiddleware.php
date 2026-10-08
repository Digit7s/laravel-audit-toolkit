<?php

namespace Digit7s\AuditToolkit\Queue;

use Closure;
use Digit7s\AuditToolkit\Contracts\AuditContextResolver;
use Digit7s\AuditToolkit\Data\AuditContext;
use Digit7s\AuditToolkit\Data\AuditContextEnvelope;
use Digit7s\AuditToolkit\Support\AuditContextStore;

/**
 * Opt-in queue middleware. Use AuditContextMiddleware::fromCurrent() when
 * constructing a job's middleware at dispatch time.
 */
final class AuditContextMiddleware
{
    public function __construct(
        private readonly ?AuditContextEnvelope $envelope = null,
    ) {}

    public static function fromCurrent(): self
    {
        $resolver = app(AuditContextResolver::class);

        return new self(app(AuditContextStore::class)->capture($resolver->resolve()));
    }

    public function handle(object $job, Closure $next): mixed
    {
        $store = app(AuditContextStore::class);

        if ($this->envelope === null) {
            return $next($job);
        }

        $captured = $this->envelope->toContext();
        $store->push(new AuditContext(
            actor: $captured->actor,
            originalActor: $captured->originalActor,
            guard: $captured->guard,
            source: 'queue',
            originalSource: $captured->originalSource ?? $captured->source,
            correlationId: $captured->correlationId,
            requestId: $captured->requestId,
            batchId: $captured->batchId,
            metadata: $captured->metadata,
        ));

        try {
            return $next($job);
        } finally {
            $store->pop();
        }
    }
}
