<?php

namespace Digit7s\AuditToolkit\Concerns;

use Digit7s\AuditToolkit\Data\AuditReference;
use Digit7s\AuditToolkit\Models\AuditEvent;
use Digit7s\AuditToolkit\Observers\AuditableObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $auditToolkitLifecycleState = [];

    public static function bootAuditable(): void
    {
        foreach (['created', 'updated', 'deleting', 'deleted', 'restoring', 'restored', 'forceDeleted'] as $event) {
            static::registerModelEvent($event, static function (Model $model) use ($event): void {
                app(AuditableObserver::class)->{$event}($model);
            });
        }
    }

    /**
     * @return array<string>
     */
    protected function auditInclude(): array
    {
        return [];
    }

    /** @return MorphMany<AuditEvent, $this> */
    public function auditHistory(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'subject');
    }

    public function auditActor(): Model|AuditReference|null
    {
        return null;
    }

    public function auditGuard(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [];
    }

    public function auditSource(): ?string
    {
        return 'model';
    }

    public function auditCategory(): ?string
    {
        return 'model';
    }

    public function auditEventName(string $action): string
    {
        return "model.{$action}";
    }

    public function auditCorrelationId(): ?string
    {
        return null;
    }

    public function auditRequestId(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function auditCaptureLifecycleState(string $lifecycle, array $values): void
    {
        $this->auditToolkitLifecycleState[$lifecycle] = $values;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function auditConsumeLifecycleState(string $lifecycle): ?array
    {
        $values = $this->auditToolkitLifecycleState[$lifecycle] ?? null;
        unset($this->auditToolkitLifecycleState[$lifecycle]);

        return $values;
    }
}
