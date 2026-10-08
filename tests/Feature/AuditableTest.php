<?php

use Digit7s\AuditToolkit\Tests\Fixtures\AuditableSoftSubject;
use Digit7s\AuditToolkit\Tests\Fixtures\AuditableSubject;
use Digit7s\AuditToolkit\Tests\Fixtures\TestSubject;
use Digit7s\AuditToolkit\Tests\Fixtures\TestUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('audits opt-in lifecycle events and only allowlisted changes', function (): void {
    $actor = TestUser::create(['name' => 'Lifecycle Actor']);
    $this->actingAs($actor);

    $subject = AuditableSubject::create([
        'name' => 'First',
        'status' => 'draft',
        'settings' => ['visible' => true, 'password' => 'never-store'],
    ]);

    $subject->update(['name' => 'Second', 'status' => 'published', 'settings' => ['visible' => false, 'token' => 'secret']]);
    $subject->update(['name' => 'Second']);

    $events = $subject->auditHistory()->oldest('occurred_at')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->event)->toBe('model.created')
        ->and($events[0]->actor_id)->toBe((string) $actor->getKey())
        ->and($events[1]->event)->toBe('model.updated')
        ->and($events[1]->old_values)->toMatchArray([
            'name' => 'First',
            'status' => 'draft',
            'settings' => ['visible' => true],
        ])
        ->and($events[1]->new_values)->toMatchArray([
            'name' => 'Second',
            'status' => 'published',
            'settings' => ['visible' => false],
        ])
        ->and($events[1]->new_values)->not->toHaveKey('token');
});

it('applies the same privacy pipeline to automatic metadata values', function (): void {
    config()->set('audit-toolkit.privacy.metadata_allowed_keys', ['payload']);

    $subject = new class extends AuditableSubject
    {
        public function getAttribute($key)
        {
            $value = parent::getAttribute($key);

            if ($key === 'settings' && $value !== null) {
                return new class implements JsonSerializable
                {
                    public function jsonSerialize(): array
                    {
                        return [
                            'safe' => 'automatic-value-safe',
                            'password' => 'automatic-value-secret',
                        ];
                    }
                };
            }

            return $value;
        }

        public function auditMetadata(): array
        {
            return [
                'payload' => new class implements JsonSerializable
                {
                    public function jsonSerialize(): array
                    {
                        return [
                            'safe' => 'automatic-safe',
                            'credentials' => ['token' => 'automatic-secret'],
                        ];
                    }
                },
            ];
        }
    };
    $subject->setTable('audit_test_auditable_subjects');
    $subject->fill([
        'name' => 'Automatic privacy',
        'status' => 'draft',
        'settings' => ['safe' => true],
    ])->save();

    $event = $subject->auditHistory()->latest('occurred_at')->firstOrFail();
    $persisted = json_encode([
        'metadata' => $event->metadata,
        'new_values' => $event->new_values,
    ], JSON_THROW_ON_ERROR);

    expect($persisted)->toContain('automatic-safe')
        ->and($persisted)->toContain('automatic-value-safe')
        ->and($persisted)->not->toContain('automatic-secret')
        ->and($persisted)->not->toContain('automatic-value-secret');
});

it('audits soft, restored, hard and force deletion semantics', function (): void {
    $soft = AuditableSoftSubject::create(['name' => 'Soft', 'status' => 'active']);
    $soft->delete();
    $soft->restore();
    $soft->delete();
    $soft->forceDelete();

    $events = DB::table('audit_events')->pluck('event')->all();

    expect($events)->toHaveCount(5)
        ->and($events)->toContain('model.created', 'model.deleted', 'model.restored', 'model.force_deleted')
        ->and($events)->not->toContain('model.updated');

    $deletedEvents = DB::table('audit_events')->where('event', 'model.deleted')->get()->all();

    expect(array_filter($deletedEvents, fn (object $event): bool => array_key_exists('deletion_mode', json_decode((string) $event->metadata, true) ?? [])))
        ->toHaveCount(count($deletedEvents));

    $deleted = DB::table('audit_events')->where('event', 'model.deleted')->orderBy('id')->first();
    $restored = DB::table('audit_events')->where('event', 'model.restored')->first();

    expect(json_decode((string) $deleted->old_values, true)['deleted_at'] ?? null)->toBeNull()
        ->and(json_decode((string) $deleted->new_values, true)['deleted_at'] ?? null)->not->toBeNull()
        ->and(json_decode((string) $restored->old_values, true)['deleted_at'] ?? null)->not->toBeNull()
        ->and(json_decode((string) $restored->new_values, true)['deleted_at'] ?? null)->toBeNull();
});

it('does not audit non-opt-in models or quiet event suppression', function (): void {
    $normal = new TestSubject(['name' => 'Normal', 'status' => 'draft']);
    $normal->save();

    $subject = AuditableSubject::create(['name' => 'Quiet', 'status' => 'draft']);
    $createdCount = DB::table('audit_events')->count();

    $subject->updateQuietly(['status' => 'published']);
    $subject->saveQuietly();
    AuditableSubject::withoutEvents(fn () => $subject->update(['status' => 'archived']));

    expect(DB::table('audit_events')->count())->toBe($createdCount)
        ->and(DB::table('audit_events')->where('subject_type', $normal->getMorphClass())->count())->toBe(0);
});

it('rolls automatic records back with the business transaction', function (): void {
    expect(fn () => DB::transaction(function (): void {
        AuditableSubject::create(['name' => 'Rollback', 'status' => 'draft']);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);

    expect(DB::table('audit_test_auditable_subjects')->where('name', 'Rollback')->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('new_values->name', 'Rollback')->exists())->toBeFalse();
});

it('keeps nested transaction savepoints aligned with automatic records', function (): void {
    $subject = null;

    DB::transaction(function () use (&$subject): void {
        $subject = AuditableSubject::create(['name' => 'Nested', 'status' => 'draft']);

        try {
            DB::transaction(function () use ($subject): void {
                $subject->update(['status' => 'published']);
                throw new RuntimeException('nested rollback');
            });
        } catch (RuntimeException) {
            // The savepoint rollback is intentional.
        }
    });

    expect($subject?->fresh()->status)->toBe('draft')
        ->and($subject?->auditHistory()->where('event', 'model.updated')->count())->toBe(0)
        ->and($subject?->auditHistory()->where('event', 'model.created')->count())->toBe(1);
});

it('allows explicit model context to override automatic context', function (): void {
    $actor = TestUser::create(['name' => 'Explicit Actor']);
    $automatic = TestUser::create(['name' => 'Automatic Actor']);
    $this->actingAs($automatic);

    $subject = new class extends AuditableSubject
    {
        public function auditActor(): ?Model
        {
            return TestUser::query()->where('name', 'Explicit Actor')->first();
        }

        public function auditMetadata(): array
        {
            return ['channel' => 'explicit'];
        }
    };
    $subject->setTable('audit_test_auditable_subjects');
    $subject->fill(['name' => 'Override', 'status' => 'draft'])->save();

    $event = $subject->auditHistory()->latest('occurred_at')->firstOrFail();

    expect($event->actor_id)->toBe((string) $actor->getKey())
        ->and($event->metadata)->toMatchArray(['channel' => 'explicit']);
});

it('preserves meaningful null, boolean, date and casted JSON changes', function (): void {
    $subject = AuditableSubject::create([
        'name' => 'Typed',
        'status' => null,
        'settings' => ['enabled' => true],
        'published_at' => null,
        'enabled' => false,
    ]);

    $subject->update([
        'status' => 'published',
        'settings' => ['enabled' => false],
        'published_at' => '2026-10-08 12:00:00',
        'enabled' => true,
    ]);

    $event = $subject->auditHistory()->where('event', 'model.updated')->firstOrFail();

    expect($event->old_values)->toMatchArray([
        'status' => null,
        'settings' => ['enabled' => true],
        'enabled' => false,
    ])
        ->and($event->new_values)->toMatchArray([
            'status' => 'published',
            'settings' => ['enabled' => false],
            'enabled' => true,
        ])
        ->and($event->new_values['published_at'])->toBe('2026-10-08T12:00:00+00:00');
});

it('refuses ambiguous automatic guard attribution', function (): void {
    $this->app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    $actor = TestUser::create(['name' => 'Ambiguous']);
    $this->actingAs($actor, 'web');
    $this->actingAs($actor, 'admin');

    $subject = AuditableSubject::create(['name' => 'Ambiguous', 'status' => 'draft']);

    expect($subject->auditHistory()->latest('occurred_at')->value('actor_id'))->toBeNull();
});

it('honors automatic fail-open and fail-closed persistence modes', function (): void {
    $this->app['config']->set('audit-toolkit.table', 'missing_audit_events');
    $this->app['config']->set('audit-toolkit.failure_mode', 'fail_open');

    $subject = AuditableSubject::create(['name' => 'Best effort', 'status' => 'draft']);

    expect($subject->exists)->toBeTrue();

    $this->app['config']->set('audit-toolkit.failure_mode', 'fail_closed');

    expect(fn () => DB::transaction(function (): void {
        AuditableSubject::create(['name' => 'Required', 'status' => 'draft']);
    }))->toThrow(QueryException::class);

    expect(DB::table('audit_test_auditable_subjects')->where('name', 'Required')->exists())->toBeFalse();
});

it('captures configured valid request and correlation identifiers', function (): void {
    $this->app['config']->set('audit-toolkit.context.guard_priority', ['web']);
    $requestId = '11111111-1111-4111-8111-111111111111';
    $correlationId = '22222222-2222-4222-8222-222222222222';
    $this->app->instance('request', Request::create('/', 'GET', [], [], [], [
        'HTTP_X_REQUEST_ID' => $requestId,
        'HTTP_X_CORRELATION_ID' => $correlationId,
    ]));
    $this->actingAs(TestUser::create(['name' => 'Request Actor']));

    $subject = AuditableSubject::create(['name' => 'Request context', 'status' => 'draft']);
    $event = $subject->auditHistory()->latest('occurred_at')->firstOrFail();

    expect($event->request_id)->toBe($requestId)
        ->and($event->correlation_id)->toBe($correlationId)
        ->and($event->metadata['execution_source'])->toBe('console');
});
