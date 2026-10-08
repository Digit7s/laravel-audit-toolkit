<?php

namespace Digit7s\AuditToolkit\Contracts;

use Digit7s\AuditToolkit\Models\AuditEvent;
use Illuminate\Database\Eloquent\Builder;

interface AuditQuery
{
    /**
     * Return a read-scoped Eloquent query for audit events.
     *
     * The returned model rejects update and delete operations. Consumers should
     * use this builder only for filtering, ordering, and reading.
     *
     * @return Builder<AuditEvent>
     */
    public function newQuery(): Builder;

    public function find(string $id): ?AuditEvent;
}
