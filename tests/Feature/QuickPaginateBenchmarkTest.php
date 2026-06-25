<?php

use Illuminate\Support\Facades\DB;

function seedBenchmarkUsers(int $total): void
{
    foreach (range(1, $total) as $start) {
        if (($start - 1) % 1000 !== 0) {
            continue;
        }

        $end = min($start + 999, $total);

        DB::table('users')->insert(
            collect(range($start, $end))
                ->map(fn (int $i): array => [
                    'name' => "Benchmark User {$i}",
                    'active' => true,
                    'role' => $i % 2 === 0 ? 'member' : 'admin',
                    'score' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all()
        );
    }
}

function measurePagination(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $startedAt = hrtime(true);
    $paginator = $callback();
    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return [
        'paginator' => $paginator,
        'elapsedMs' => $elapsedMs,
        'queryCount' => count($queries),
        'countQueryCount' => collect($queries)
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'count('))
            ->count(),
    ];
}

it('benchmarks native paginate against quickPaginate with 1000000 rows', function (): void {
    if (getenv('RUN_QUICK_PAGINATE_BENCHMARK') !== '1') {
        $this->markTestSkipped('Set RUN_QUICK_PAGINATE_BENCHMARK=1 to run the 1000000-row pagination benchmark.');
    }

    $totalRows = 1_000_000;
    $perPage = 50;
    $page = 1900;

    seedBenchmarkUsers($totalRows);

    // Warm the package total cache so this compares Laravel's repeated count +
    // row fetch against quickPaginate's cached total + deferred-join row fetch.
    DB::table('users')
        ->orderBy('id')
        ->quickPaginate($perPage, page: 1, cacheKey: 'benchmark-users');

    $native = measurePagination(fn () => DB::table('users')
        ->orderBy('id')
        ->paginate($perPage, page: $page));

    $quick = measurePagination(fn () => DB::table('users')
        ->orderBy('id')
        ->quickPaginate($perPage, page: $page, cacheKey: 'benchmark-users'));

    fwrite(STDERR, sprintf(
        PHP_EOL.'Pagination benchmark (%d rows, page %d, per page %d):'.PHP_EOL
        .'  native paginate: %.2f ms, %d queries, %d count queries'.PHP_EOL
        .'  quickPaginate:   %.2f ms, %d queries, %d count queries'.PHP_EOL,
        $totalRows,
        $page,
        $perPage,
        $native['elapsedMs'],
        $native['queryCount'],
        $native['countQueryCount'],
        $quick['elapsedMs'],
        $quick['queryCount'],
        $quick['countQueryCount'],
    ));

    expect($quick['paginator']->total())->toBe($native['paginator']->total())
        ->and($quick['paginator']->pluck('id')->all())->toBe($native['paginator']->pluck('id')->all())
        ->and($quick['countQueryCount'])->toBe(0);
})->group('benchmark');
