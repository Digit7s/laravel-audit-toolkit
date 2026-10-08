<?php

namespace Digit7s\AuditToolkit;

use DateTimeInterface;
use Digit7s\AuditToolkit\Contracts\AuditContextResolver;
use Digit7s\AuditToolkit\Contracts\AuditRecorder;
use Digit7s\AuditToolkit\Data\AuditContext;
use Digit7s\AuditToolkit\Data\AuditEventData;
use Digit7s\AuditToolkit\Data\AuditReference;
use Digit7s\AuditToolkit\Models\AuditEvent;
use Digit7s\AuditToolkit\Privacy\AuditPrivacyPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class AuditManager implements AuditRecorder
{
    private static bool $isRecording = false;

    public function __construct(
        private readonly AuditPrivacyPolicy $privacyPolicy,
        private readonly ?AuditContextResolver $contextResolver = null,
    ) {}

    public function record(
        AuditEventData $event,
    ): ?AuditEvent {
        $context = $this->contextResolver?->resolve() ?? new AuditContext;
        $event = $this->applyContext($event, $context);

        if (config('audit-toolkit.connections.strict_atomicity', false) && $event->connection === null) {
            throw new LogicException('Strict audit atomicity requires an explicit audit database connection.');
        }

        if (self::$isRecording) {
            throw new LogicException('Recursive audit recording is not permitted.');
        }

        self::$isRecording = true;

        try {
            $metadataAllowedKeys = config('audit-toolkit.privacy.metadata_allowed_keys', []);
            $allowedValueKeys = $event->allowedValueKeys !== []
                ? $event->allowedValueKeys
                : config('audit-toolkit.privacy.values_allowed_keys', []);

            $model = new AuditEvent;

            if ($event->connection !== null) {
                $model->setConnection($event->connection);
            }

            $model->forceFill([
                'id' => (string) Str::ulid(),
                'event' => $event->event,
                'category' => $event->category,
                'description' => $event->description,
                'actor_type' => $event->actor?->type,
                'actor_id' => $event->actor?->id,
                'original_actor_type' => $event->originalActor?->type,
                'original_actor_id' => $event->originalActor?->id,
                'subject_type' => $event->subject?->type,
                'subject_id' => $event->subject?->id,
                'old_values' => $this->privacyPolicy->sanitizeValues($event->oldValues, $allowedValueKeys),
                'new_values' => $this->privacyPolicy->sanitizeValues($event->newValues, $allowedValueKeys),
                'metadata' => $this->privacyPolicy->sanitizeMetadata($event->metadata, $metadataAllowedKeys),
                'occurred_at' => $event->occurredAt,
                'source' => $event->source,
                'guard' => $event->guard,
                'correlation_id' => $event->correlationId,
                'batch_id' => $event->batchId,
                'request_id' => $event->requestId,
            ]);
            $model->save();

            return $model;
        } catch (Throwable $exception) {
            if (config('audit-toolkit.failure_mode', 'fail_closed') !== 'fail_open') {
                throw $exception;
            }

            Log::warning('Audit event persistence failed in fail-open mode.', [
                'event' => $event->event,
                'exception' => $exception::class,
            ]);

            return null;
        } finally {
            self::$isRecording = false;
        }
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     * @param  array<string>  $allowedValueKeys
     */
    public function recordEvent(
        string $event,
        Model|AuditReference|null $actor = null,
        ?Model $subject = null,
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
    ): ?AuditEvent {
        $connection ??= $subject?->getConnection()->getName();
        $connection ??= config('audit-toolkit.connections.default');

        return $this->record(AuditEventData::make(
            event: $event,
            actor: $actor instanceof AuditReference ? $actor : ($actor === null ? null : AuditReference::fromModel($actor)),
            subject: $subject === null ? null : AuditReference::fromModel($subject),
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            category: $category,
            description: $description,
            occurredAt: $occurredAt,
            source: $source,
            guard: $guard,
            correlationId: $correlationId,
            batchId: $batchId,
            requestId: $requestId,
            allowedValueKeys: $allowedValueKeys,
            connection: $connection,
            originalActor: $originalActor,
            inheritContextActor: $inheritContextActor,
        ));
    }

    private function applyContext(AuditEventData $event, AuditContext $context): AuditEventData
    {
        $metadata = array_merge($context->metadata, $event->metadata);

        if ($event->source !== 'manual' && $context->source !== null) {
            $metadata['execution_source'] ??= $context->source;
        }

        if ($event->source !== 'manual' && $context->originalSource !== null) {
            $metadata['original_execution_source'] ??= $context->originalSource;
        }

        return new AuditEventData(
            event: $event->event,
            category: $event->category,
            description: $event->description,
            actor: $event->actor ?? ($event->inheritContextActor ? $context->actor : null),
            subject: $event->subject,
            oldValues: $event->oldValues,
            newValues: $event->newValues,
            metadata: $metadata,
            occurredAt: $event->occurredAt,
            source: $event->source ?? $context->source,
            guard: $event->guard ?? $context->guard,
            correlationId: $event->correlationId ?? $context->correlationId,
            batchId: $event->batchId ?? $context->batchId,
            requestId: $event->requestId ?? $context->requestId,
            allowedValueKeys: $event->allowedValueKeys,
            connection: $event->connection,
            originalActor: $event->originalActor ?? $context->originalActor,
            inheritContextActor: $event->inheritContextActor,
        );
    }
}
