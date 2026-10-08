<?php

namespace Digit7s\AuditToolkit\Tests;

use Digit7s\AuditToolkit\AuditServiceProvider;
use Digit7s\AuditToolkit\Tests\Fixtures\TestUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = (string) (getenv('AUDIT_TEST_DB_DRIVER') ?: 'sqlite');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->test_database_connection($driver, false));
        $app['config']->set('database.connections.secondary', $this->test_database_connection($driver, true));
        $app['config']->set('audit-toolkit.privacy.values_allowed_keys', []);
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => TestUser::class]);
    }

    /** @return array<string, mixed> */
    private function test_database_connection(string $driver, bool $secondary): array
    {
        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        $database = $secondary
            ? (getenv('AUDIT_TEST_DB_SECONDARY_DATABASE') ?: 'audit_toolkit_test_secondary')
            : (getenv('AUDIT_TEST_DB_DATABASE') ?: 'audit_toolkit_test');

        return [
            'driver' => $driver,
            'host' => getenv('AUDIT_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('AUDIT_TEST_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => $database,
            'username' => getenv('AUDIT_TEST_DB_USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => getenv('AUDIT_TEST_DB_PASSWORD') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
            'prefix' => '',
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach ([
            'audit_test_soft_subjects',
            'audit_test_auditable_subjects',
            'audit_test_subjects',
            'audit_test_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('audit_events')) {
            DB::connection('testing')->table('audit_events')->delete();
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('audit_test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('audit_test_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('audit_test_auditable_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('enabled')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_test_soft_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->json('settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $secondary = Schema::connection('secondary');
        $secondary->dropIfExists('audit_test_auditable_subjects');
        $secondary->dropIfExists('audit_events');

        $secondary->create('audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event', 150);
            $table->string('category', 80)->nullable();
            $table->string('description')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('original_actor_type')->nullable();
            $table->string('original_actor_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at', precision: 6);
            $table->string('source', 40)->nullable();
            $table->string('guard', 80)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->uuid('batch_id')->nullable();
            $table->uuid('request_id')->nullable();
        });

        $secondary->create('audit_test_auditable_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('enabled')->nullable();
            $table->timestamps();
        });
    }
}
