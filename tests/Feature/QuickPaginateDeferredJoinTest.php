<?php

use Dkc\LaravelQuickPaginator\Tests\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

function loggedQueries(): callable
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    return fn (): array => collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (string $q): string => strtolower($q))
        ->all();
}

it('fetches the page rows via a key-only inner query', function (): void {
    $this->seedUsers();

    $queries = loggedQueries();
    User::query()->where('active', true)->quickPaginate(2);
    $sql = $queries();

    // inner key-only query: selects users.id with a limit, never "select *"
    $inner = collect($sql)->first(fn (string $q): bool => str_contains($q, 'limit'));
    expect($inner)->toContain('"users"."id"')
        ->and($inner)->not->toContain('"users".*');
});

it('matches native paginate items, order and total across pages including a deep offset', function (): void {
    User::query()->insert(collect(range(1, 50))->map(fn (int $i): array => [
        'name' => "User {$i}",
        'active' => true,
        'role' => 'member',
        'score' => $i,
    ])->all());

    foreach ([1, 2, 5] as $page) {
        $native = User::query()->where('active', true)->orderBy('score', 'desc')->paginate(7, page: $page);
        $cached = User::query()->where('active', true)->orderBy('score', 'desc')->quickPaginate(7, page: $page);

        expect($cached->total())->toBe($native->total())
            ->and($cached->pluck('id')->all())->toBe($native->pluck('id')->all());
    }
});

it('orders correctly by a raw expression', function (): void {
    $this->seedUsers();

    $native = User::query()->orderByRaw('score * 2 desc')->paginate(2);
    $cached = User::query()->orderByRaw('score * 2 desc')->quickPaginate(2);

    expect($cached->pluck('id')->all())->toBe($native->pluck('id')->all());
});

it('orders correctly by a computed select alias', function (): void {
    $this->seedUsers();

    $native = User::query()
        ->selectRaw('users.*, score * 2 as weighted')
        ->orderBy('weighted', 'desc')
        ->paginate(2);

    $cached = User::query()
        ->selectRaw('users.*, score * 2 as weighted')
        ->orderBy('weighted', 'desc')
        ->quickPaginate(2);

    expect($cached->pluck('id')->all())->toBe($native->pluck('id')->all());
});

it('does not confuse a select alias with a longer-named alias', function (): void {
    $this->seedUsers();

    // ordering by `weighted` must not match a `weighted_total` select expression.
    $native = User::query()
        ->selectRaw('users.*, score as weighted, score * 100 as weighted_total')
        ->orderBy('weighted', 'desc')
        ->paginate(2);

    $cached = User::query()
        ->selectRaw('users.*, score as weighted, score * 100 as weighted_total')
        ->orderBy('weighted', 'desc')
        ->quickPaginate(2);

    expect($cached->pluck('id')->all())->toBe($native->pluck('id')->all());
});

it('handles an aliased table on the query builder', function (): void {
    $this->seedUsers();

    $native = DB::table('users as u')->where('u.active', true)->orderBy('u.score')->paginate(2);
    $cached = DB::table('users as u')->where('u.active', true)->orderBy('u.score')->quickPaginate(2);

    expect($cached->total())->toBe($native->total())
        ->and(collect($cached->items())->pluck('id')->all())
        ->toBe(collect($native->items())->pluck('id')->all());
});

it('handles an aliased table on the eloquent builder', function (): void {
    $this->seedUsers();

    $cached = User::query()->from('users as u')->where('u.active', true)->orderBy('u.score')->quickPaginate(2);
    $native = User::query()->from('users as u')->where('u.active', true)->orderBy('u.score')->paginate(2);

    expect($cached->total())->toBe($native->total())
        ->and($cached->pluck('id')->all())->toBe($native->pluck('id')->all());
});

it('returns an empty page without issuing an outer whereIn', function (): void {
    $this->seedUsers();

    $queries = loggedQueries();
    $paginator = User::query()->where('active', true)->quickPaginate(2, page: 99);

    expect($paginator->total())->toBe(3)
        ->and($paginator->items())->toBe([])
        ->and(collect($queries())->filter(fn (string $q): bool => str_contains($q, 'in ('))->count())->toBe(0);
});

it('keeps eager loads on the outer query', function (): void {
    $this->seedUsers();

    $paginator = User::query()->where('active', true)->with('posts')->quickPaginate(2);

    expect($paginator->first()->relationLoaded('posts'))->toBeTrue();
});

it('falls back to the native row fetch for grouped/having queries', function (): void {
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

    expect($cached->total())->toBe($native->total())
        ->and($cached->items())->toEqual($native->items());
});

it('honors an explicit primary key on the query builder', function (): void {
    $this->seedUsers();

    $paginator = DB::table('users')->where('active', true)->orderBy('score')->quickPaginate(2, primaryKey: 'id');

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(3)
        ->and($paginator->count())->toBe(2);
});
