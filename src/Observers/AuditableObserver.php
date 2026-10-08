<?php

namespace Digit7s\AuditToolkit\Observers;

use Digit7s\AuditToolkit\AuditManager;
use Digit7s\AuditToolkit\Contracts\AuditContextResolver;
use Digit7s\AuditToolkit\Data\AuditEventData;
use Digit7s\AuditToolkit\Data\AuditReference;
use Digit7s\AuditToolkit\Support\AuditChangeSet;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;

final class AuditableObserver
{
    public function __construct(
        private readonly AuditManager $manager,
        private readonly AuditContextResolver $contextResolver,
        private readonly AuditChangeSet $changeSet,
    ) {}

    public function created(Model $model): void
    {
        $this->record($model, 'created', $this->changeSet->forCreate($model, $this->fields($model)));
    }

    public function updated(Model $model): void
    {
        if ($this->isRestorationUpdate($model)) {
            return;
        }

        $changes = $this->changeSet->forUpdate($model, $this->fields($model));

        if ($changes['old'] === [] && $changes['new'] === []) {
            return;
        }

        $this->record($model, 'updated', $changes);
    }

    public function deleting(Model $model): void
    {
        $this->hook($model, 'auditCaptureLifecycleState', null, [
            'deleting',
            $this->changeSet->current($model, $this->fields($model)),
        ]);
    }

    public function deleted(Model $model): void
    {
        if ($this->isForceDeleting($model)) {
            return;
        }

        $this->record(
            $model,
            'deleted',
            [
                'old' => $this->hook($model, 'auditConsumeLifecycleState', null, ['deleting']) ?? [],
                'new' => $this->usesSoftDeletes($model) ? $this->changeSet->current($model, $this->fields($model)) : [],
            ],
            ['deletion_mode' => $this->usesSoftDeletes($model) ? 'soft' : 'hard'],
        );
    }

    public function restoring(Model $model): void
    {
        $this->hook($model, 'auditCaptureLifecycleState', null, [
            'restoring',
            $this->changeSet->current($model, $this->fields($model)),
        ]);
    }

    public function restored(Model $model): void
    {
        $this->record(
            $model,
            'restored',
            [
                'old' => $this->hook($model, 'auditConsumeLifecycleState', null, ['restoring']) ?? [],
                'new' => $this->changeSet->current($model, $this->fields($model)),
            ],
            ['deletion_mode' => 'soft'],
        );
    }

    public function forceDeleted(Model $model): void
    {
        $this->record(
            $model,
            'force_deleted',
            ['old' => $this->hook($model, 'auditConsumeLifecycleState', null, ['deleting']) ?? [], 'new' => []],
            ['deletion_mode' => 'force'],
        );
    }

    /**
     * @param  array{old: array<string, mixed>, new: array<string, mixed>}  $changes
     * @param  array<string, mixed>  $lifecycleMetadata
     */
    private function record(Model $model, string $action, array $changes, array $lifecycleMetadata = []): void
    {
        $context = $this->contextResolver->resolve();
        $explicitMetadata = $this->hook($model, 'auditMetadata', []);
        $metadata = array_merge($context->metadata, $lifecycleMetadata, is_array($explicitMetadata) ? $explicitMetadata : []);

        if ($context->source !== null) {
            $metadata['execution_source'] ??= $context->source;
        }

        $actor = $this->hook($model, 'auditActor');

        $event = $this->hook($model, 'auditEventName', "model.{$action}", [$action]);

        $category = $this->hook($model, 'auditCategory', 'model');
        $source = $this->hook($model, 'auditSource');
        $guard = $this->hook($model, 'auditGuard');
        $correlationId = $this->hook($model, 'auditCorrelationId');
        $requestId = $this->hook($model, 'auditRequestId');
        $actorReference = $actor instanceof AuditReference
            ? $actor
            : ($actor instanceof Model ? AuditReference::fromModel($actor) : $context->actor);

        $this->manager->record(AuditEventData::make(
            event: $event,
            actor: $actorReference,
            subject: AuditReference::fromModel($model),
            oldValues: $changes['old'],
            newValues: $changes['new'],
            metadata: $metadata,
            category: $category,
            source: $source ?? 'model',
            guard: $guard ?? $context->guard,
            correlationId: $correlationId ?? $context->correlationId,
            requestId: $requestId ?? $context->requestId,
            originalActor: $context->originalActor,
            allowedValueKeys: $this->fields($model),
            connection: $model->getConnection()->getName(),
        ));
    }

    /**
     * @return array<string>
     */
    private function fields(Model $model): array
    {
        $fields = $this->hook($model, 'auditInclude', []);

        return array_values(array_filter($fields, 'is_string'));
    }

    /**
     * Model hooks may intentionally be protected, as in the documented API.
     * Reflection keeps the public model surface small without bypassing the
     * model's explicit opt-in contract.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function hook(Model $model, string $method, mixed $default = null, array $arguments = []): mixed
    {
        if (! method_exists($model, $method)) {
            return $default;
        }

        $reflection = new ReflectionMethod($model, $method);

        return $reflection->invokeArgs($model, $arguments);
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive($model), true);
    }

    private function isForceDeleting(Model $model): bool
    {
        return $this->usesSoftDeletes($model)
            && method_exists($model, 'isForceDeleting')
            && (bool) $model->isForceDeleting();
    }

    private function isRestorationUpdate(Model $model): bool
    {
        if (! $this->usesSoftDeletes($model)) {
            return false;
        }

        if (! method_exists($model, 'getDeletedAtColumn')) {
            return false;
        }

        $deletedAt = $model->getDeletedAtColumn();

        return array_key_exists($deletedAt, $model->getDirty())
            && $model->getAttribute($deletedAt) === null
            && $model->getRawOriginal($deletedAt) !== null;
    }
}
