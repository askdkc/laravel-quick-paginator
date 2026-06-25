<?php

namespace Dkc\LaravelQuickPaginator;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider;

class CachedPaginationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cached-pagination.php', 'cached-pagination');

        $this->app->singleton(CacheKeyFactory::class);
        $this->app->singleton(CachedPaginationManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/cached-pagination.php' => config_path('cached-pagination.php'),
        ], 'cached-pagination-config');

        QueryBuilder::macro('quickPaginate', function (
            $perPage = 15,
            $columns = ['*'],
            $pageName = 'page',
            $page = null,
            ?int $ttl = null,
            bool $fresh = false,
            ?string $cacheKey = null,
            ?string $primaryKey = null,
        ) {
            return app(CachedPaginationManager::class)
                ->paginate($this, $perPage, $columns, $pageName, $page, $ttl, $fresh, $cacheKey, $primaryKey);
        });

        EloquentBuilder::macro('quickPaginate', function (
            $perPage = null,
            $columns = ['*'],
            $pageName = 'page',
            $page = null,
            ?int $ttl = null,
            bool $fresh = false,
            ?string $cacheKey = null,
            ?string $primaryKey = null,
        ) {
            return app(CachedPaginationManager::class)
                ->paginate($this, $perPage, $columns, $pageName, $page, $ttl, $fresh, $cacheKey, $primaryKey);
        });
    }
}
