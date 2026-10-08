<?php

namespace Digit7s\AuditToolkit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** @property string|null $status */
class TestSubject extends Model
{
    protected $table = 'audit_test_subjects';

    protected $guarded = [];
}
