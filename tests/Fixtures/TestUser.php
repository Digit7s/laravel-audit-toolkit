<?php

namespace Digit7s\AuditToolkit\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class TestUser extends Authenticatable
{
    protected $table = 'audit_test_users';

    protected $guarded = [];
}
