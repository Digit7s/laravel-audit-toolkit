<?php

namespace Digit7s\AuditToolkit\Tests\Fixtures;

use Digit7s\AuditToolkit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string|null $name
 * @property string|null $status
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $published_at
 * @property bool|null $enabled
 */
class AuditableSubject extends Model
{
    use Auditable;

    protected $table = 'audit_test_auditable_subjects';

    protected $guarded = [];

    protected function auditInclude(): array
    {
        return ['name', 'status', 'settings', 'published_at', 'enabled'];
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'published_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }
}
