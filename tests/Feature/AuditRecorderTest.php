<?php

use Digit7s\AuditToolkit\AuditManager;
use Digit7s\AuditToolkit\Contracts\AuditQuery;
use Digit7s\AuditToolkit\Data\AuditEventData;
use Digit7s\AuditToolkit\Data\AuditReference;
use Digit7s\AuditToolkit\Exceptions\UnsafeAuditValueException;
use Digit7s\AuditToolkit\Models\AuditEvent;
use Digit7s\AuditToolkit\Tests\Fixtures\TestSubject;
use Digit7s\AuditToolkit\Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\DB;

it('records explicit events with actor and subject references', function (): void {
    $actor = TestUser::query()->create(['name' => 'Operator']);
    $subject = TestSubject::query()->create(['name' => 'Draft', 'status' => 'draft']);

    $event = audit()->recordEvent(
        event: 'subject.status.changed',
        actor: $actor,
        subject: $subject,
        oldValues: ['status' => 'draft', 'password' => 'never-store'],
        newValues: ['status' => 'published', 'token' => 'never-store'],
        metadata: ['source' => 'test', 'channel' => 'admin', 'secret' => 'never-store'],
        category: 'business',
        allowedValueKeys: ['status'],
    );

    expect($event)->toBeInstanceOf(AuditEvent::class)
        ->and($event->actor_type)->toBe($actor->getMorphClass())
        ->and($event->actor_id)->toBe((string) $actor->getKey())
        ->and($event->subject_type)->toBe($subject->getMorphClass())
        ->and($event->subject_id)->toBe((string) $subject->getKey())
        ->and($event->old_values)->toBe(['status' => 'draft'])
        ->and($event->new_values)->toBe(['status' => 'published'])
        ->and($event->metadata)->toBe(['source' => 'test', 'channel' => 'admin']);
});

it('supports anonymous and system events', function (): void {
    $event = audit()->recordEvent(
        event: 'system.maintenance.started',
        category: 'system',
        source: 'console',
    );

    expect($event->actor_type)->toBeNull()
        ->and($event->actor_id)->toBeNull()
        ->and($event->subject_type)->toBeNull()
        ->and($event->subject_id)->toBeNull()
        ->and($event->source)->toBe('console');
});

it('filters events through the read-only query interface', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Draft', 'status' => 'draft']);

    audit()->recordEvent('subject.created', subject: $subject, category: 'business');
    audit()->recordEvent('security.login.failed', category: 'security');

    $results = app(AuditQuery::class)->newQuery()
        ->where('category', 'business')
        ->where('subject_id', (string) $subject->getKey())
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->event)->toBe('subject.created');
});

it('commits and rolls back with the surrounding transaction', function (): void {
    DB::transaction(function (): void {
        audit()->recordEvent('transaction.committed');
    });

    expect(AuditEvent::query()->where('event', 'transaction.committed')->count())->toBe(1);

    expect(fn () => DB::transaction(function (): void {
        audit()->recordEvent('transaction.rolled_back');
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);

    expect(AuditEvent::query()->where('event', 'transaction.rolled_back')->count())->toBe(0);
});

it('rejects invalid event names before persistence', function (): void {
    expect(fn () => audit()->recordEvent('contains spaces'))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('supports safe scalar and structured values', function (): void {
    $event = audit()->recordEvent(
        event: 'structured.example',
        oldValues: [
            'count' => 1,
            'active' => true,
            'nested' => ['state' => 'draft'],
            'occurred_at' => new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ],
        allowedValueKeys: ['count', 'active', 'nested', 'occurred_at'],
    );

    expect($event->old_values)->toMatchArray([
        'count' => 1,
        'active' => true,
        'nested' => ['state' => 'draft'],
        'occurred_at' => '2026-01-01T00:00:00+00:00',
    ]);
});

it('normalizes JsonSerializable values before recursive privacy filtering', function (): void {
    $unsafe = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return [
                'safe' => 'keep-me',
                'password' => 'sentinel-password',
                'nested' => [
                    'token' => 'sentinel-token',
                    'credentials' => ['secret' => 'sentinel-credentials'],
                    'safe' => true,
                ],
            ];
        }
    };

    config()->set('audit-toolkit.privacy.metadata_allowed_keys', ['context']);

    $event = audit()->recordEvent(
        event: 'json.serializable.privacy',
        oldValues: ['payload' => $unsafe],
        newValues: ['payload' => $unsafe],
        metadata: ['context' => $unsafe],
        allowedValueKeys: ['payload'],
    );

    $persisted = json_encode([
        $event->old_values,
        $event->new_values,
        $event->metadata,
    ], JSON_THROW_ON_ERROR);

    expect($persisted)->toContain('keep-me')
        ->and($persisted)->not->toContain('sentinel-password')
        ->and($persisted)->not->toContain('sentinel-token')
        ->and($persisted)->not->toContain('sentinel-credentials')
        ->and($event->old_values)->toMatchArray(['payload' => ['safe' => 'keep-me', 'nested' => ['safe' => true]]]);
});

it('supports narrow dotted allowlists without exposing sibling fields', function (): void {
    $event = audit()->recordEvent(
        event: 'nested.allowlist',
        newValues: [
            'payload' => [
                'safe' => 'keep-me',
                'sibling' => 'do-not-store',
                'password' => 'sentinel-password',
                'nested' => [
                    'safe' => true,
                    'token' => 'sentinel-token',
                ],
            ],
        ],
        allowedValueKeys: ['payload.safe', 'payload.nested.safe'],
    );

    expect($event->new_values)->toBe([
        'payload' => [
            'safe' => 'keep-me',
            'nested' => ['safe' => true],
        ],
    ]);
});

it('treats numeric list indexes as transparent for nested allowlists', function (): void {
    $event = audit()->recordEvent(
        event: 'nested.list.allowlist',
        newValues: [
            'payload' => [
                'items' => [
                    ['safe' => 'first', 'token' => 'sentinel-token'],
                    ['safe' => 'second', 'password' => 'sentinel-password'],
                ],
            ],
        ],
        allowedValueKeys: ['payload.items.safe'],
    );

    expect($event->new_values)->toBe([
        'payload' => [
            'items' => [
                ['safe' => 'first'],
                ['safe' => 'second'],
            ],
        ],
    ]);
});

it('rejects unsupported objects instead of dumping them into audit storage', function (): void {
    $unsupported = new class {};

    expect(fn () => audit()->recordEvent(
        event: 'unsupported.object',
        newValues: ['payload' => $unsupported],
        allowedValueKeys: ['payload'],
    ))->toThrow(UnsafeAuditValueException::class);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('rejects recursive JsonSerializable structures', function (): void {
    $recursive = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return $this;
        }
    };

    expect(fn () => audit()->recordEvent(
        event: 'recursive.object',
        newValues: ['payload' => $recursive],
        allowedValueKeys: ['payload'],
    ))->toThrow(UnsafeAuditValueException::class);
});

it('prevents direct AuditEvent model creation outside the recorder', function (): void {
    expect(fn () => AuditEvent::create(['event' => 'bypass']))
        ->toThrow(LogicException::class);
});

it('validates persisted identifier formats and storage lengths', function (): void {
    expect(fn () => audit()->recordEvent(
        event: 'invalid.uuid',
        correlationId: 'not-a-uuid',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => audit()->recordEvent(
        event: 'invalid.source',
        source: str_repeat('x', 41),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new AuditReference(str_repeat('x', 256), '1'))
        ->toThrow(InvalidArgumentException::class);
});

it('supports explicit fail-open behavior', function (): void {
    config()->set('audit-toolkit.failure_mode', 'fail_open');
    config()->set('audit-toolkit.table', 'missing_audit_events');

    expect(audit()->recordEvent('best.effort.telemetry'))->toBeNull();
});

it('rejects public mutation and deletion of stored events', function (): void {
    $event = audit()->recordEvent('immutable.example');

    expect(fn () => $event->update(['description' => 'changed']))
        ->toThrow(LogicException::class);

    expect(fn () => $event->delete())
        ->toThrow(LogicException::class);
});

it('prevents recursive recording', function (): void {
    $manager = app(AuditManager::class);
    $reflection = new ReflectionClass($manager);
    $property = $reflection->getProperty('isRecording');
    $property->setValue(null, true);

    expect(fn () => $manager->record(AuditEventData::make('recursive.example')))
        ->toThrow(LogicException::class);

    $property->setValue(null, false);
});

it('supports direct immutable references', function (): void {
    $reference = new AuditReference(TestUser::class, '42');
    $event = audit()->record(AuditEventData::make('reference.example', actor: $reference));

    expect($event->actor_type)->toBe(TestUser::class)
        ->and($event->actor_id)->toBe('42');
});
