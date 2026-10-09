<?php

namespace Digit7s\AuditToolkit;

use Digit7s\AuditToolkit\Authentication\AuthenticationAuditListener;
use Digit7s\AuditToolkit\Context\DefaultAuditContextResolver;
use Digit7s\AuditToolkit\Contracts\AuditContextResolver;
use Digit7s\AuditToolkit\Contracts\AuditQuery;
use Digit7s\AuditToolkit\Contracts\AuditRecorder;
use Digit7s\AuditToolkit\Models\AuditEvent;
use Digit7s\AuditToolkit\Privacy\AuditPrivacyPolicy;
use Digit7s\AuditToolkit\Privacy\SafeValueSerializer;
use Digit7s\AuditToolkit\Repositories\EloquentAuditQuery;
use Digit7s\AuditToolkit\Support\AuditChangeSet;
use Digit7s\AuditToolkit\Support\AuditContextStore;
use Digit7s\AuditToolkit\Support\RuntimeAuditContextResolver;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-toolkit.php', 'audit-toolkit');

        $this->app->singleton(SafeValueSerializer::class);
        $this->app->singleton(AuditPrivacyPolicy::class);
        $this->app->singleton(AuditManager::class);
        $this->app->singleton(AuditChangeSet::class);
        $this->app->singleton(AuditContextStore::class);
        $this->app->singleton(AuditContextResolver::class, function ($app): AuditContextResolver {
            $resolver = config('audit-toolkit.context.resolver', DefaultAuditContextResolver::class);

            return new RuntimeAuditContextResolver($app->make($resolver), $app->make(AuditContextStore::class));
        });
        $this->app->singleton(AuthenticationAuditListener::class);
        $this->app->alias(AuditManager::class, AuditRecorder::class);
        $this->app->singleton(AuditQuery::class, function (): EloquentAuditQuery {
            return new EloquentAuditQuery(new AuditEvent);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/audit-toolkit.php' => config_path('audit-toolkit.php'),
            ], 'audit-toolkit-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'audit-toolkit-migrations');
        }

        if ((bool) config('audit-toolkit.authentication.enabled', false)) {
            $this->app->make(AuthenticationAuditListener::class)->subscribe($this->app['events']);
        }
    }
}
