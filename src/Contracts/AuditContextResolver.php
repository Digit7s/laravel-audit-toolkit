<?php

namespace Digit7s\AuditToolkit\Contracts;

use Digit7s\AuditToolkit\Data\AuditContext;

interface AuditContextResolver
{
    public function resolve(): AuditContext;
}
