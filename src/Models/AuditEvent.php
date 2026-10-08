<?php

namespace Digit7s\AuditToolkit\Models;

use Digit7s\AuditToolkit\AuditManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $event
 * @property string|null $category
 * @property string|null $description
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $original_actor_type
 * @property string|null $original_actor_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $occurred_at
 * @property string|null $source
 * @property string|null $guard
 * @property string|null $correlation_id
 * @property string|null $batch_id
 * @property string|null $request_id
 */
class AuditEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(static function (): void {
            $recorderIsPersisting = false;

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === AuditManager::class && $frame['function'] === 'record') {
                    $recorderIsPersisting = true;
                    break;
                }
            }

            if (! $recorderIsPersisting) {
                throw new LogicException('Audit events must be recorded through the audit recorder.');
            }
        });

        static::updating(static function (): never {
            throw new LogicException('Audit events are append-only through the package API.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit events cannot be deleted through the public model.');
        });
    }

    public function getTable(): string
    {
        return (string) config('audit-toolkit.table', 'audit_events');
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function originalActor(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'original_actor_type', 'original_actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForActor(Builder $query, Model $actor): Builder
    {
        return $query
            ->where('actor_type', $actor->getMorphClass())
            ->where('actor_id', (string) $actor->getKey());
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey());
    }

    public function scopeForOriginalActor(Builder $query, Model $actor): Builder
    {
        return $query
            ->where('original_actor_type', $actor->getMorphClass())
            ->where('original_actor_id', (string) $actor->getKey());
    }
}
