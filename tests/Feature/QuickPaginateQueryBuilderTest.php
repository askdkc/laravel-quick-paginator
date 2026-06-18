<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function listenForCountQueries(): callable
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    return fn (): int => collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'count('))
        ->count();
}

it('runs one count query on query builder cache miss and returns a length aware paginator', function (): void {
    $this->seedUsers();
    $countQueries = listenForCountQueries();

    $paginator = DB::table('users')->where('active', true)->quickPaginate(2);

    expect($paginator)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(3)
        ->and($countQueries())->toBe(1);
});

it('does not run a count query on query builder cache hit', function (): void {
    $this->seedUsers();

    DB::table('users')->where('active', true)->quickPaginate(2);
    $countQueries = listenForCountQueries();

    $paginator = DB::table('users')->where('active', true)->quickPaginate(2);

    expect($paginator->total())->toBe(3)
        ->and($countQueries())->toBe(0);
});

it('reuses the same query builder total for page one and page two', function (): void {
    $this->seedUsers();

    DB::table('users')->where('active', true)->quickPaginate(2, page: 1);
    $countQueries = listenForCountQueries();

    $paginator = DB::table('users')->where('active', true)->quickPaginate(2, page: 2);

    expect($paginator->total())->toBe(3)
        ->and($countQueries())->toBe(0);
});

it('uses different query builder cache keys for different where bindings', function (): void {
    $this->seedUsers();

    DB::table('users')->where('role', 'admin')->quickPaginate(2);
    $countQueries = listenForCountQueries();

    DB::table('users')->where('role', 'member')->quickPaginate(2);

    expect($countQueries())->toBe(1);
});

it('respects a cached zero total', function (): void {
    Cache::put('empty-users', 0, 300);
    $countQueries = listenForCountQueries();

    $paginator = DB::table('users')->where('active', true)->quickPaginate(2, cacheKey: 'empty-users');

    expect($paginator->total())->toBe(0)
        ->and($countQueries())->toBe(0);
});

it('bypasses and refreshes the cached total when fresh is true', function (): void {
    $this->seedUsers();
    Cache::put('active-users', 99, 300);
    $countQueries = listenForCountQueries();

    $paginator = DB::table('users')->where('active', true)->quickPaginate(2, fresh: true, cacheKey: 'active-users');

    expect($paginator->total())->toBe(3)
        ->and($countQueries())->toBe(1)
        ->and(Cache::get('active-users'))->toBe(3);
});

it('honors a custom cache key', function (): void {
    $this->seedUsers();
    Cache::put('known-total', 7, 300);
    $countQueries = listenForCountQueries();

    $paginator = DB::table('users')->where('active', true)->quickPaginate(2, cacheKey: 'known-total');

    expect($paginator->total())->toBe(7)
        ->and($countQueries())->toBe(0);
});

it('keeps Laravel paginator metadata in JSON output', function (): void {
    $this->seedUsers();

    $json = DB::table('users')->where('active', true)->quickPaginate(2)->toArray();

    expect($json)
        ->toHaveKeys(['total', 'last_page', 'links'])
        ->and($json['total'])->toBe(3)
        ->and($json['last_page'])->toBe(2);
});

it('computes the same total as native paginate for grouped queries', function (): void {
    $this->seedUsers();

    $native = DB::table('users')
        ->select('role', DB::raw('count(*) as aggregate_count'))
        ->groupBy('role')
        ->havingRaw('count(*) > ?', [1])
        ->paginate(1);

    $cached = DB::table('users')
        ->select('role', DB::raw('count(*) as aggregate_count'))
        ->groupBy('role')
        ->havingRaw('count(*) > ?', [1])
        ->quickPaginate(1);

    expect($cached->total())->toBe($native->total());
});
