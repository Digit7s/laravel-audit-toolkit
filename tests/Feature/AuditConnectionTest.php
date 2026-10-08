<?php

use Digit7s\AuditToolkit\Models\AuditEvent;
use Digit7s\AuditToolkit\Tests\Fixtures\AuditableSubject;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('uses the subject connection for automatic audit events', function (): void {
    $subject = new AuditableSubject;
    $subject->setConnection('secondary');
    $subject->fill(['name' => 'Secondary', 'status' => 'draft'])->save();

    expect(AuditEvent::on('secondary')->where('subject_id', (string) $subject->getKey())->count())->toBe(1)
        ->and(AuditEvent::query()->where('subject_id', (string) $subject->getKey())->count())->toBe(0);
});

it('rolls back secondary business and audit writes together', function (): void {
    expect(fn () => DB::connection('secondary')->transaction(function (): void {
        $subject = new AuditableSubject;
        $subject->setConnection('secondary');
        $subject->fill(['name' => 'Secondary rollback', 'status' => 'draft'])->save();

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);

    expect(DB::connection('secondary')->table('audit_test_auditable_subjects')->where('name', 'Secondary rollback')->exists())->toBeFalse()
        ->and(AuditEvent::on('secondary')->where('event', 'model.created')->count())->toBe(0);
});

it('keeps nested secondary savepoints aligned with automatic audits', function (): void {
    $subject = null;

    DB::connection('secondary')->transaction(function () use (&$subject): void {
        $subject = new AuditableSubject;
        $subject->setConnection('secondary');
        $subject->fill(['name' => 'Secondary nested', 'status' => 'draft'])->save();

        try {
            DB::connection('secondary')->transaction(function () use ($subject): void {
                $subject->update(['status' => 'published']);
                throw new RuntimeException('nested rollback');
            });
        } catch (RuntimeException) {
            // The savepoint rollback is intentional.
        }
    });

    expect($subject?->fresh()->status)->toBe('draft')
        ->and(AuditEvent::on('secondary')->where('subject_id', (string) $subject?->getKey())->where('event', 'model.created')->count())->toBe(1)
        ->and(AuditEvent::on('secondary')->where('subject_id', (string) $subject?->getKey())->where('event', 'model.updated')->count())->toBe(0);
});

it('supports explicit manual connection selection', function (): void {
    $event = audit()->recordEvent('manual.secondary', connection: 'secondary');

    expect($event->getConnectionName())->toBe('secondary')
        ->and(AuditEvent::on('secondary')->where('id', $event->getKey())->count())->toBe(1)
        ->and(AuditEvent::query()->where('id', $event->getKey())->count())->toBe(0);
});

it('rejects connection-ambiguous writes when strict atomicity is enabled', function (): void {
    config()->set('audit-toolkit.connections.strict_atomicity', true);

    expect(fn () => audit()->recordEvent('strict.connection'))
        ->toThrow(LogicException::class);

    $event = audit()->recordEvent('strict.explicit', connection: 'secondary');

    expect($event->getConnectionName())->toBe('secondary');
});

it('rolls back automatic persistence failures on the subject connection', function (): void {
    config()->set('audit-toolkit.table', 'missing_secondary_audit_events');

    expect(fn () => DB::connection('secondary')->transaction(function (): void {
        $subject = new AuditableSubject;
        $subject->setConnection('secondary');
        $subject->fill(['name' => 'Audit failure', 'status' => 'draft'])->save();
    }))->toThrow(QueryException::class);

    expect(DB::connection('secondary')->table('audit_test_auditable_subjects')->where('name', 'Audit failure')->exists())->toBeFalse();
});
