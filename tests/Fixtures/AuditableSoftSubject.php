<?php

namespace Digit7s\AuditToolkit\Tests\Fixtures;

use Digit7s\AuditToolkit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string|null $name
 * @property string|null $status
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $deleted_at
 */
class AuditableSoftSubject extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'audit_test_soft_subjects';

    protected $guarded = [];

    protected function auditInclude(): array
    {
        return ['name', 'status', 'settings', 'deleted_at'];
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'deleted_at' => 'datetime'];
    }
}
