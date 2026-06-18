# Laravel Quick Paginator

Laravel Quick Paginator caches only the `total` value used by Laravel's length-aware pagination. On later page requests, it passes that cached total into Laravel's native `paginate()` method so Laravel can skip the repeated pagination `count(*)` query.

It does not cache result rows, replace paginator classes, or change the normal `LengthAwarePaginator` response shape.

## Installation

```bash
composer require askdkc/laravel-quick-paginator
```

Publish the config when you need to change the cache store, prefix, or TTL:

```bash
php artisan vendor:publish --tag=cached-pagination-config
```

## Usage

Use `quickPaginate()` where you would normally use `paginate()`.

```php
$users = User::query()
    ->where('active', true)
    ->quickPaginate(50);
```

Query builder usage is supported too:

```php
$users = DB::table('users')
    ->where('active', true)
    ->quickPaginate(50);
```

The method signature stays close to Laravel's paginator:

```php
quickPaginate(
    $perPage = null,
    $columns = ['*'],
    $pageName = 'page',
    $page = null,
    ?int $ttl = null,
    bool $fresh = false,
    ?string $cacheKey = null,
)
```

## Configuration

Default config:

```php
return [
    'enabled' => true,
    'store' => null,
    'prefix' => 'cached-pagination-total',
    'ttl' => 300,
];
```

The default TTL is intentionally short because the package trades total freshness for fewer count queries. Set `store` to use a non-default Laravel cache store.

## Refreshing Totals

Pass `fresh: true` to bypass the cached total, run the count once, and replace the cache entry:

```php
$users = User::query()->quickPaginate(50, fresh: true);
```

You may also provide a custom cache key:

```php
$users = User::query()
    ->where('active', true)
    ->quickPaginate(50, cacheKey: 'users.active.total');
```

## Notes

Laravel Quick Paginator supports `Model::query()->quickPaginate()` and `DB::table(...)->quickPaginate()` in v1.

Relation-specific paginator methods are intentionally not promised yet because some relation classes do not expose Laravel's fifth `paginate()` `$total` argument directly. Cursor pagination and simple pagination are also out of scope because they do not use total counts in the same way.

## Development

Install dependencies:

```bash
composer install
```

Run the Pest test suite:

```bash
composer test
```

Run Larastan static analysis:

```bash
composer analyse
```
