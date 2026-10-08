<?php

use Digit7s\AuditToolkit\Contracts\AuditQuery;
use Digit7s\AuditToolkit\Privacy\AuditPrivacyPolicy;
use Digit7s\AuditToolkit\Tests\Fixtures\AuditableSubject;
use Digit7s\AuditToolkit\Tests\TestCase;
use Illuminate\Support\Facades\DB;

uses(TestCase::class);

it('runs the optional disposable SQLite audit benchmark', function (): void {
    $events = max(1, (int) (getenv('AUDIT_BENCHMARK_EVENTS') ?: 1000));
    $payload = [
        'payload' => [
            'safe' => 'benchmark',
            'nested' => ['safe' => true, 'token' => 'never-store'],
            'password' => 'never-store',
        ],
    ];
    $started = hrtime(true);
    $queryCount = 0;
    DB::listen(static function () use (&$queryCount): void {
        $queryCount++;
    });
    $sampleEvery = max(1, intdiv($events, 1000));
    $latencies = [];

    for ($index = 0; $index < $events; $index++) {
        $singleStarted = hrtime(true);
        audit()->recordEvent(
            event: 'benchmark.write',
            oldValues: $payload,
            newValues: $payload,
            metadata: ['channel' => 'benchmark', 'token' => 'never-store'],
            allowedValueKeys: ['payload'],
        );

        if ($index % $sampleEvery === 0) {
            $latencies[] = (hrtime(true) - $singleStarted) / 1_000_000;
        }
    }

    $writeSeconds = (hrtime(true) - $started) / 1_000_000_000;
    $automaticStarted = hrtime(true);

    for ($index = 0; $index < $events; $index++) {
        AuditableSubject::query()->create([
            'name' => "Benchmark {$index}",
            'status' => 'created',
        ]);
    }

    $automaticSeconds = (hrtime(true) - $automaticStarted) / 1_000_000_000;
    $queryStarted = hrtime(true);
    $page = app(AuditQuery::class)
        ->newQuery()
        ->where('event', 'benchmark.write')
        ->paginate(50);
    $querySeconds = (hrtime(true) - $queryStarted) / 1_000_000_000;

    $serializationStarted = hrtime(true);
    app(AuditPrivacyPolicy::class)->sanitizeValues($payload, ['payload']);
    $serializationSeconds = (hrtime(true) - $serializationStarted) / 1_000_000_000;
    $result = [
        'events' => $events,
        'write_seconds' => round($writeSeconds, 6),
        'writes_per_second' => round($events / max($writeSeconds, 0.000001), 2),
        'write_p50_ms' => round(benchmark_percentile($latencies, 0.50), 4),
        'write_p95_ms' => round(benchmark_percentile($latencies, 0.95), 4),
        'automatic_lifecycle_seconds' => round($automaticSeconds, 6),
        'serialization_redaction_seconds' => round($serializationSeconds, 6),
        'page_query_seconds' => round($querySeconds, 6),
        'page_count' => $page->count(),
        'query_count' => $queryCount,
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ];

    fwrite(STDOUT, 'BENCHMARK '.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);

    expect($page->total())->toBe($events);
});

/** @param  list<float>  $values */
function benchmark_percentile(array $values, float $quantile): float
{
    sort($values);

    return $values === [] ? 0.0 : $values[(int) floor((count($values) - 1) * $quantile)];
}
