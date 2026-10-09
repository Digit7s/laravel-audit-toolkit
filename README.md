# Laravel Audit Toolkit

`digit7s/laravel-audit-toolkit` is a Laravel-native audit engine for synchronous event recording, opt-in Eloquent lifecycle auditing, privacy-first value capture, and read-only queries. Filament is not required.

## Features

- Explicit business-event recording through `audit()->recordEvent()`.
- Opt-in `Digit7s\AuditToolkit\Concerns\Auditable` model auditing.
- Allowlist-first values and metadata with recursive sensitive-key filtering.
- Actor, subject, guard, request, correlation, batch, and impersonation attribution.
- Synchronous persistence with configurable fail-closed or explicitly best-effort fail-open behavior.
- Safe, explicit queue-context propagation without serializing models, credentials, or request payloads.
- A read-only `AuditQuery` contract for administration and reporting interfaces.

## Requirements

- PHP `^8.5`
- Laravel / Illuminate `^13.0`
- SQLite, MySQL 8.4, and PostgreSQL 16 are verified in the package's GitHub Actions database matrix.

## Installation

Install the stable package from Packagist:

```bash
composer require digit7s/laravel-audit-toolkit
```

Publish the package configuration and migrations, then migrate:

```bash
php artisan vendor:publish --tag=audit-toolkit-config
php artisan vendor:publish --tag=audit-toolkit-migrations
php artisan migrate
```

Laravel discovers `Digit7s\AuditToolkit\AuditServiceProvider` automatically. The migration creates the `audit_events` table, including original-actor attribution columns.

For development against an unreleased checkout, use a temporary VCS repository and a development alias. This is not needed for the tagged release:

```bash
composer config repositories.digit7s-audit-toolkit vcs https://github.com/Digit7s/laravel-audit-toolkit.git
composer require 'digit7s/laravel-audit-toolkit:dev-main as 0.1.0'
```

## Quick Start

```php
use Illuminate\Support\Facades\Auth;

audit()->recordEvent(
    event: 'order.status.changed',
    actor: Auth::user(),
    subject: $order,
    oldValues: ['status' => 'pending'],
    newValues: ['status' => 'paid'],
    allowedValueKeys: ['status'],
    category: 'business',
    source: 'http',
);
```

The `audit()` and `audit_context()` helpers are provided by the package. Anonymous or system events may omit actor and subject. Manual arguments take precedence over resolved execution context.

## Manual Audit Recording

`AuditManager::recordEvent()` accepts the event name, actor, subject, before/after values, metadata, category, description, timestamp, source, guard, correlation/batch/request IDs, an explicit value allowlist, connection, original actor, and context-inheritance control. For lower-level composition, use `AuditEventData::make()` with the `AuditRecorder` contract.

Event names are limited to letters, numbers, dots, underscores, colons, and hyphens. UUID context identifiers are validated before persistence. A subject selects its resolved Eloquent connection; a subject-less event can set `connection: 'name'` or `audit-toolkit.connections.default`.

Writes are synchronous and use the active transaction. The default `failure_mode` is `fail_closed`, so a persistence failure is raised to the caller. `fail_open` is available only for explicitly best-effort telemetry and returns `null` after logging a warning.

## Automatic Eloquent Auditing

Auditing is opt-in per model:

```php
use Digit7s\AuditToolkit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use Auditable;

    protected function auditInclude(): array
    {
        return ['title', 'status', 'published_at'];
    }
}
```

The observer records `model.created`, `model.updated`, `model.deleted`, `model.restored`, and `model.force_deleted`. Soft deletes include safe `deletion_mode` metadata. No-op updates are omitted and only meaningful allowlisted changes are stored.

`saveQuietly()`, `deleteQuietly()`, `restoreQuietly()`, `forceDeleteQuietly()`, and `withoutEvents()` suppress these lifecycle events. Query-builder writes, mass updates/deletes, raw SQL, database cascades, and pivot operations are outside the observer boundary; they are not automatically audited.

## Field Allowlists and Privacy

Value capture is allowlist-first. By default, no old or new value fields are persisted unless `allowedValueKeys` or `audit-toolkit.privacy.values_allowed_keys` is configured. Metadata uses its own allowlist. Sensitive-looking keys are filtered recursively after safe normalization; credentials, tokens, request bodies, arbitrary model attributes, unsupported objects, recursive structures, and unsafe custom cast outputs are not captured.

Allowlist paths use dot notation. For example, `payload.safe` captures that leaf without unapproved siblings, while allowing `payload` captures all recursively safe children beneath it. Numeric list indexes are traversed transparently; there is no numeric-index wildcard syntax.

Direct `AuditEvent::create()` is rejected. Events must go through the recorder pipeline.

## Actor and Subject Attribution

`AuditReference` stores stable type/id references for actors and subjects, so deleted models remain identifiable without serializing model instances. A missing actor is intentionally anonymous; the package does not guess a system identity.

Model hooks include `auditActor()`, `auditGuard()`, `auditMetadata()`, `auditSource()`, `auditCategory()`, `auditCorrelationId()`, and `auditRequestId()`. Explicit call arguments win over all context resolution.

## Multi-Guard Authentication

`context.guard_priority` can specify an ordered guard set. If more than one configured guard is authenticated and the identity is ambiguous, the package leaves the actor unresolved rather than attributing the event to the wrong user. Request and correlation headers are accepted only when they contain valid UUIDs. IP and user-agent metadata are disabled by default.

## Request and Correlation Context

The `DefaultAuditContextResolver` safely resolves framework context. HTTP requests can receive one package-generated correlation UUID when no valid host value is supplied. Set `context.generate_correlation_id` to `false` to opt out. `request_id` identifies a request, `correlation_id` groups related work, and `batch_id` is an explicit workflow identifier.

The precedence order is: call-level value, explicit execution-scoped context, configured resolver, safe framework context, then anonymous/system. Generated identifiers are package values, not trusted claims.

## Queue Context Propagation

Queue propagation is explicit and carries only typed actor references and safe identifiers:

```php
use Digit7s\AuditToolkit\Queue\AuditContextMiddleware;

final class RebuildSearchIndex
{
    public function middleware(): array
    {
        return [AuditContextMiddleware::fromCurrent()];
    }
}
```

The middleware sets `queue` as the current execution source, preserves the original source, and clears its context in `finally`, including failed attempts. A job without this middleware inherits no audit context.

## Optional Authentication Auditing

The `AuthenticationAuditListener` is disabled by default. Enable only reviewed native Laravel events in published configuration:

```php
'authentication' => [
    'enabled' => true,
    'events' => ['login', 'logout', 'failed', 'password_reset', 'email_verified'],
],
```

Supported names are `login`, `logout`, `failed`, `password_reset`, `email_verified`, `current_device_logout`, and `other_device_logout`. Failed-login records omit credentials and raw login identifiers and default to one event per HTTP request. Host-specific security events are not inferred or deduplicated.

## Impersonation Attribution

Hosts that already implement account switching can annotate a scope without giving the package control of authentication:

```php
audit_context()->withImpersonation($administrator, function () use ($effectiveUser, $order): void {
    audit()->recordEvent('order.approved', actor: $effectiveUser, subject: $order);
}, $effectiveUser);
```

The effective actor remains in `actor_*`; the initiating administrator is stored in `original_actor_*`. Nested scopes restore their parent in `finally`. Account switching and request-input-based impersonation inference are outside this package.

## Transaction and Connection Behavior

Automatic callbacks run synchronously on the same connection and transaction as the model save when both use that connection. In fail-closed mode, an audit exception can roll back the enclosing business transaction. The package does not claim atomicity across independent connections or distributed transactions. `connections.strict_atomicity` can reject ambiguous manual writes.

## Querying Audit Events

Use the read-only `AuditQuery` contract for administration UIs and reports:

```php
use Digit7s\AuditToolkit\Contracts\AuditQuery;

$events = app(AuditQuery::class)
    ->newQuery()
    ->where('event', 'order.status.changed')
    ->latest('occurred_at')
    ->paginate();
```

The `AuditEvent` model exposes read relationships and guarded mutation behavior. The package does not provide a mutation API for audit records.

## Configuration

Publish `config/audit-toolkit.php` before changing defaults. Important settings include:

- `failure_mode`: `fail_closed` or explicitly best-effort `fail_open`.
- `connections.default` and `connections.strict_atomicity`.
- `context.guard_priority`, correlation/request headers, generated correlation IDs, IP, and user-agent capture.
- `privacy.values_allowed_keys`, `privacy.metadata_allowed_keys`, and `privacy.redacted_value`.
- `authentication.enabled`, reviewed `authentication.events`, and `authentication.max_failed_per_request`.

Configuration cannot recover values excluded at write time. Host authorization is still required for every audit reader, including the optional Filament companion.

## Security and Limitations

The package is privacy-first but is not a compliance certification. It deliberately does not provide automatic pivot interception, bulk SQL auditing, exports, asynchronous audit persistence, restore/revert operations, retention or pruning UI, or hash-chain tamper evidence.

Eloquent guards reject ordinary update and delete operations on stored events, but database-level immutability is not guaranteed. Database principals with write access can still modify or delete rows. Automated retention and pruning are not available in `v0.1.0`; a future release may add controlled, dry-run cleanup with protected event handling.

## Database Compatibility

The package requires PHP 8.5 and Illuminate 13. GitHub Actions verifies the package suite and static analysis on isolated SQLite, MySQL 8.4, and PostgreSQL 16 services. SQLite is also used for local Testbench and benchmark runs. Check the [database matrix workflow](.github/workflows/database-matrix.yml) for the reproducible verification path.

## Related Filament Plugin

For an optional read-only UI, install [`digit7s/filament-audit-toolkit`](https://github.com/Digit7s/filament-audit-toolkit). The Laravel package does not require Filament; it owns recording, privacy, context, storage, and the query contract.

## Contributing, Security and License

Run `composer validate --no-check-publish`, `composer check-platform-reqs`, `composer lint`, `composer analyse`, and `composer test` before submitting changes. See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).

MIT licensed. See [LICENSE](LICENSE).
