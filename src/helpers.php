<?php

use Digit7s\AuditToolkit\AuditManager;
use Digit7s\AuditToolkit\Support\AuditContextStore;

if (! function_exists('audit')) {
    function audit(): AuditManager
    {
        return app(AuditManager::class);
    }
}

if (! function_exists('audit_context')) {
    function audit_context(): AuditContextStore
    {
        return app(AuditContextStore::class);
    }
}
