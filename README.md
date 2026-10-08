# Laravel Audit Toolkit

`digit7s/laravel-audit-toolkit` is a Laravel-native audit event recorder with conservative privacy defaults and a read-oriented query API.

## Status

This package provides explicit recording and opt-in Eloquent lifecycle auditing. Audit writes remain synchronous; the optional queue integration propagates attribution context only and never queues audit persistence.

## Installation

```bash
composer require digit7s/laravel-audit-toolkit
php artisan migrate
```

The service provider is discovered automatically by Laravel.

## Recording an event

```php
use App\Models\Order;
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

Anonymous and system events are supported by omitting actor and subject. Writes are synchronous and use the active database transaction. The default failure mode is `fail_closed`; configure `audit-toolkit.failure_mode` to `fail_open` only for explicitly best-effort telemetry.

Manual call arguments win over execution context. When an argument is omitted, the active execution context may supply actor, guard, request/correlation/batch identifiers, and impersonation attribution.

When a subject model is supplied, the recorder selects that model's resolved connection unless `connection` is explicitly provided. For a manual event without a subject, set `connection: 'name'` (or `audit-toolkit.connections.default`) when it must share a business transaction. `connections.strict_atomicity` can be enabled to reject events whose connection is ambiguous. The package never claims atomicity across independent connections.

## Opt-in model auditing

Automatic auditing is never enabled globally. Add the concern and an explicit field allowlist to each model that should be audited:

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

The observer records `model.created`, `model.updated`, `model.deleted`, `model.restored`, and `model.force_deleted`. A soft delete is `model.deleted` with safe metadata `deletion_mode=soft`; a non-soft delete is `hard`; force deletion is `force`. Updates include only meaningful allowlisted changes, and no-op updates are omitted. A create event is still recorded when the allowlist has no populated fields, with an empty before/after set.

`saveQuietly()`, `deleteQuietly()`, `restoreQuietly()`, `forceDeleteQuietly()`, and `withoutEvents()` suppress automatic events because they suppress Eloquent model events. Query-builder writes, mass updates/deletes, raw SQL, database cascades, and pivot operations are outside this observer's boundary.

## Privacy behavior

Old and new values are allowlist-first. With the default configuration, no value fields are persisted unless `allowedValueKeys` or `audit-toolkit.privacy.values_allowed_keys` is supplied. Sensitive-looking keys are removed recursively after all values have been normalized into a safe intermediate structure. Metadata has its own conservative allowlist. Request bodies, credentials, tokens, and arbitrary model attributes are never captured automatically.

Allowlist paths use dot notation. `payload.safe` stores only that leaf and traverses `payload` without including unapproved siblings. Allowing `payload` stores all recursively safe children beneath that key. A parent allowlist intentionally takes precedence over narrower child entries.

Numeric list indexes are transparent while traversing a dotted path, so `payload.items.safe` applies to each list element. A list parent such as `payload.items` includes every recursively safe element; there is no numeric-index wildcard syntax.

Automatic values use Eloquent's dirty/original APIs, then pass through the same serializer and recursive sensitive-key filtering as manual events. Supported scalar values, enums, dates, arrays, and JSON casts are normalized safely. Unsupported objects, Eloquent models, relationship values, recursive structures, and unsafe custom cast outputs are rejected; they are never stringified or dumped.

Direct `AuditEvent::create()` is rejected; records must use the recorder pipeline.

## Automatic context

Automatic events resolve one authenticated actor from the configured guard priority. Set `audit-toolkit.context.guard_priority` when an application has multiple guards. If more than one guard is active, the actor is left unresolved rather than attributed to the wrong user. Console and unauthenticated work is recorded as anonymous/system context. Request and correlation IDs are only stored when they are valid UUIDs from the configured headers. IP and user-agent metadata are disabled by default and can be enabled explicitly.

Model hooks such as `auditActor()`, `auditGuard()`, `auditMetadata()`, `auditSource()`, `auditCategory()`, `auditCorrelationId()`, and `auditRequestId()` override resolved values for that model. Manual `recordEvent()` arguments remain explicit and take precedence over any host context. Incoming request headers are accepted only when they are valid UUIDs; arbitrary headers and request bodies are never persisted. HTTP requests generate one package correlation UUID per request when no valid host correlation is supplied; set `context.generate_correlation_id` to `false` to opt out.

The precedence order is: call-level value, explicit execution-scoped context, configured resolver, safe framework context, then anonymous/system. `request_id` identifies the request when the host supplies a valid value; `correlation_id` groups related work; `batch_id` is an explicit workflow/batch identifier. Generated identifiers are package values, not host-trusted claims.

### Queue context

Queue propagation is explicit and safe. Capture a minimal envelope when dispatching and attach the middleware to that job:

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

The envelope contains typed actor references and safe identifiers only. It never serializes a user/model, token, cookie, session, credential, or request payload. The worker uses `queue` as the current execution source, retains the original source for attribution, and clears the context in `finally`, including failed attempts. Retries receive the same explicitly serialized envelope; a job with no middleware has no inherited audit context.

### Authentication events

Native authentication listeners are disabled by default. Enable only the Laravel event names your application has reviewed:

```php
'authentication' => [
    'enabled' => true,
    'events' => ['login', 'logout', 'failed', 'password_reset', 'email_verified'],
],
```

Supported native events are `login`, `logout`, `failed`, `password_reset`, `email_verified`, `current_device_logout`, and `other_device_logout`. Failed login records intentionally omit credentials and raw login identifiers and are capped at one per HTTP request by default (`authentication.max_failed_per_request`). Applications may lower this to zero or set a reviewed higher limit for their own rate/volume policy. The package does not replace or deduplicate host listeners, and it does not infer provider-specific security events.

### Impersonation attribution

Hosts that already implement account switching can scope attribution without giving the package control of authentication:

```php
audit_context()->withImpersonation($administrator, function () use ($effectiveUser): void {
    audit()->recordEvent('order.approved', actor: $effectiveUser, subject: $order);
}, $effectiveUser);
```

The effective actor remains `actor_*`; the initiating administrator is stored in additive `original_actor_*` columns. Nested scopes restore their parent in `finally`. Start/end actions are explicit host events; the package does not implement account switching or infer impersonation from request input.

The event model rejects normal update and delete operations through Eloquent, but this is not a database-level immutability guarantee. Database users with write access can still modify or delete rows. Retention and tamper-evidence are future features.

## Querying

```php
use Digit7s\AuditToolkit\Contracts\AuditQuery;

$events = app(AuditQuery::class)
    ->newQuery()
    ->where('event', 'order.status.changed')
    ->latest('occurred_at')
    ->paginate();
```

The query contract is intended for read paths such as administration UIs. It does not provide a mutation API.

## Scope

Automatic callbacks run synchronously in the same database connection and transaction as the model save when both use that connection. A fail-closed audit exception can therefore roll back an enclosing business transaction. An after-event outside a transaction cannot undo a business write that has already committed. Cross-connection atomicity is not provided.

The package deliberately excludes automatic pivot interception, bulk SQL auditing, retention deletion, exports, asynchronous audit persistence, restoration/revert operations, and hash-chain integrity. Eloquent lifecycle records are append-only by model guard, not immutable at the database privilege level. Auth listeners are opt-in as described above. Atomicity is guaranteed only when the audit write and business write use the same database connection; distributed transactions are unsupported.

## Compatibility

The Phase 3 implementation is tested against PHP 8.5.10 in the core Testbench environment, PHP 8.5.5 in the Filament Testbench environment, Laravel/Illuminate 13, Filament 5.9, Livewire 4, and SQLite. PHP 8.4 is installed but not compatible with this package's current PHP `^8.5` constraint, and no running isolated MySQL/PostgreSQL service was available during verification; those engines are not claimed here.

## Phase 4 verification

The package requires PHP `^8.5` and Illuminate 13. Phase 4 uses Larastan 3.13 with PHPStan 2.3 at analysis level 5. Run `composer validate --no-check-publish`, `composer check-platform-reqs`, `composer lint`, `composer analyse`, and `composer test` before a pilot.

The optional `composer benchmark` command runs against disposable in-memory SQLite data. Set `AUDIT_BENCHMARK_EVENTS=1000`, `10000`, or `100000` to choose the synthetic volume. Its results are local SQLite baselines only. The manual `.github/workflows/database-matrix.yml` workflow is the compatibility path for isolated MySQL 8.4 and PostgreSQL 16 verification; workflow configuration is not itself compatibility evidence.

This is a controlled-pilot development line, not a production-readiness or compliance certification. See [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).

## License

MIT. See [LICENSE](LICENSE).
