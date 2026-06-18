<?php

use Dkc\LaravelQuickPaginator\Tests\User;
use Illuminate\Pagination\LengthAwarePaginator;

it('runs one count query on eloquent cache miss and returns a length aware paginator', function (): void {
    $this->seedUsers();
    $countQueries = listenForCountQueries();

    $paginator = User::query()->where('active', true)->quickPaginate(2);

    expect($paginator)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(3)
        ->and($countQueries())->toBe(1);
});

it('does not run a count query on eloquent cache hit', function (): void {
    $this->seedUsers();

    User::query()->where('active', true)->quickPaginate(2);
    $countQueries = listenForCountQueries();

    $paginator = User::query()->where('active', true)->quickPaginate(2);

    expect($paginator->total())->toBe(3)
        ->and($countQueries())->toBe(0);
});

it('supports model static calls and paginator chaining', function (): void {
    $this->seedUsers();

    $paginator = User::quickPaginate(2)->withQueryString();

    expect($paginator)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->total())->toBe(4);
});
