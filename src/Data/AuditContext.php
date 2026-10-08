<?php

namespace Digit7s\AuditToolkit\Data;

final readonly class AuditContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?AuditReference $actor = null,
        public ?AuditReference $originalActor = null,
        public ?string $guard = null,
        public ?string $source = null,
        public ?string $originalSource = null,
        public ?string $correlationId = null,
        public ?string $requestId = null,
        public ?string $batchId = null,
        public array $metadata = [],
    ) {}
}
