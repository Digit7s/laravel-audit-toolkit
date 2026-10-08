<?php

namespace Digit7s\AuditToolkit\Support;

use Digit7s\AuditToolkit\Contracts\AuditContextResolver;
use Digit7s\AuditToolkit\Data\AuditContext;

final class RuntimeAuditContextResolver implements AuditContextResolver
{
    public function __construct(
        private readonly AuditContextResolver $hostResolver,
        private readonly AuditContextStore $store,
    ) {}

    public function resolve(): AuditContext
    {
        $host = $this->hostResolver->resolve();
        $execution = $this->store->current();

        if ($execution === null) {
            return $host;
        }

        return new AuditContext(
            actor: $execution->actor ?? $host->actor,
            originalActor: $execution->originalActor ?? $host->originalActor,
            guard: $execution->guard ?? $host->guard,
            source: $execution->source ?? $host->source,
            originalSource: $execution->originalSource ?? $host->originalSource,
            correlationId: $execution->correlationId ?? $host->correlationId,
            requestId: $execution->requestId ?? $host->requestId,
            batchId: $execution->batchId ?? $host->batchId,
            metadata: array_merge($host->metadata, $execution->metadata),
        );
    }
}
