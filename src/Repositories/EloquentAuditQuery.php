<?php

namespace Digit7s\AuditToolkit\Repositories;

use Digit7s\AuditToolkit\Contracts\AuditQuery;
use Digit7s\AuditToolkit\Models\AuditEvent;
use Illuminate\Database\Eloquent\Builder;

final class EloquentAuditQuery implements AuditQuery
{
    public function __construct(
        private readonly AuditEvent $model,
    ) {}

    /**
     * @return Builder<AuditEvent>
     */
    public function newQuery(): Builder
    {
        return $this->model->newQuery()->latest('occurred_at');
    }

    public function find(string $id): ?AuditEvent
    {
        return $this->newQuery()->whereKey($id)->first();
    }
}
