<?php

use Digit7s\AuditToolkit\Authentication\AuthenticationAuditListener;
use Digit7s\AuditToolkit\Contracts\AuditContextResolver;
use Digit7s\AuditToolkit\Data\AuditContext;
use Digit7s\AuditToolkit\Data\AuditContextEnvelope;
use Digit7s\AuditToolkit\Data\AuditReference;
use Digit7s\AuditToolkit\Models\AuditEvent;
use Digit7s\AuditToolkit\Queue\AuditContextMiddleware;
use Digit7s\AuditToolkit\Support\AuditContextStore;
use Digit7s\AuditToolkit\Tests\Fixtures\TestUser;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

it('propagates only a minimal explicit context through a job and cleans it up', function (): void {
    $actor = TestUser::create(['name' => 'Queue Actor']);
    $correlationId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $requestId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $batchId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $middleware = new AuditContextMiddleware(new AuditContextEnvelope(
        actor: AuditReference::fromModel($actor),
        guard: 'web',
        requestId: $requestId,
        correlationId: $correlationId,
        batchId: $batchId,
        originalExecutionSource: 'http',
        currentExecutionSource: 'dispatch',
    ));

    $middleware->handle(new stdClass, function (): void {
        audit()->recordEvent('job.completed', source: 'job');
    });

    $event = AuditEvent::query()->where('event', 'job.completed')->firstOrFail();

    expect($event->actor_id)->toBe((string) $actor->getKey())
        ->and($event->guard)->toBe('web')
        ->and($event->request_id)->toBe($requestId)
        ->and($event->correlation_id)->toBe($correlationId)
        ->and($event->batch_id)->toBe($batchId)
        ->and($event->metadata)->toMatchArray([
            'execution_source' => 'queue',
            'original_execution_source' => 'http',
        ])
        ->and(app(AuditContextStore::class)->current())->toBeNull();
});

it('restores nested contexts and clears them after failures', function (): void {
    $store = app(AuditContextStore::class);
    $outer = new AuditContext(correlationId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $inner = new AuditContext(correlationId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

    expect(fn () => $store->run($outer, function () use ($store, $inner): void {
        expect($store->current()?->correlationId)->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $store->run($inner, function () use ($store): void {
            expect($store->current()?->correlationId)->toBe('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
            throw new RuntimeException('job failed');
        });
    }))->toThrow(RuntimeException::class);

    expect($store->current())->toBeNull();
});

it('does not serialize credentials or models in the queue envelope', function (): void {
    $envelope = new AuditContextEnvelope(
        actor: new AuditReference(TestUser::class, '42'),
        correlationId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    );

    $serialized = serialize($envelope);

    expect($serialized)->toContain('42')
        ->and($serialized)->not->toContain('password')
        ->and($serialized)->not->toContain('token')
        ->and($serialized)->not->toContain('session');
});

it('supports impersonation attribution without leaking across scopes', function (): void {
    $administrator = TestUser::create(['name' => 'Administrator']);
    $effective = TestUser::create(['name' => 'Effective User']);

    audit_context()->withImpersonation($administrator, function () use ($effective): void {
        audit()->recordEvent('impersonation.action', actor: $effective, source: 'model');
    }, $effective);

    $event = AuditEvent::query()->where('event', 'impersonation.action')->firstOrFail();

    expect($event->actor_id)->toBe((string) $effective->getKey())
        ->and($event->original_actor_id)->toBe((string) $administrator->getKey())
        ->and(app(AuditContextStore::class)->current())->toBeNull();
});

it('keeps authentication listeners disabled until explicitly enabled', function (): void {
    $user = TestUser::create(['name' => 'Login User']);

    event(new Login('web', $user, false));

    expect(AuditEvent::query()->where('event', 'auth.login')->count())->toBe(0);

    config()->set('audit-toolkit.authentication.events', ['login', 'failed']);
    app(AuthenticationAuditListener::class)->subscribe(app('events'));

    event(new Login('web', $user, false));
    event(new Failed('web', null, ['email' => 'sensitive@example.test', 'password' => 'never-store']));

    $events = AuditEvent::query()->orderBy('occurred_at')->get();

    expect($events)->toHaveCount(2)
        ->and($events->first()->actor_id)->toBe((string) $user->getKey())
        ->and($events->last()->actor_id)->toBeNull()
        ->and(json_encode($events->last()->metadata))->not->toContain('sensitive@example.test')
        ->and(json_encode($events->last()->metadata))->not->toContain('never-store');
});

it('limits failed authentication audit volume per request', function (): void {
    $request = Request::create('/', 'POST');
    $this->app->instance('request', $request);
    config()->set('audit-toolkit.authentication.events', ['failed']);
    config()->set('audit-toolkit.authentication.max_failed_per_request', 1);

    app(AuthenticationAuditListener::class)->subscribe(app('events'));

    event(new Failed('web', null, ['email' => 'not-recorded@example.test']));
    event(new Failed('web', null, ['email' => 'not-recorded-again@example.test']));

    expect(AuditEvent::query()->where('event', 'auth.failed')->count())->toBe(1);
});

it('validates host correlation headers and isolates generated values per request', function (): void {
    $firstRequest = Request::create('/', 'GET', [], [], [], [
        'HTTP_X_CORRELATION_ID' => 'not-a-uuid',
    ]);
    $this->app->instance('request', $firstRequest);

    $first = app(AuditContextResolver::class)->resolve();
    $sameRequest = app(AuditContextResolver::class)->resolve();

    $secondRequest = Request::create('/', 'GET', [], [], [], [
        'HTTP_X_CORRELATION_ID' => ['malformed', 'header'],
    ]);
    $this->app->instance('request', $secondRequest);
    $second = app(AuditContextResolver::class)->resolve();

    expect($first->correlationId)->toBeString()
        ->and($first->correlationId)->toBe($sameRequest->correlationId)
        ->and($second->correlationId)->toBeString()
        ->and($second->correlationId)->not->toBe($first->correlationId);
});
