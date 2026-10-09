<?php

use Digit7s\AuditToolkit\AuditServiceProvider;
use Illuminate\Support\ServiceProvider;

it('registers publishable configuration and migration resources', function (): void {
    expect(ServiceProvider::pathsToPublish(AuditServiceProvider::class, 'audit-toolkit-config'))
        ->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(AuditServiceProvider::class, 'audit-toolkit-migrations'))
        ->not->toBeEmpty();
});
