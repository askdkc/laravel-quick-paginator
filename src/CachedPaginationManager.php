<?php

namespace Dkc\LaravelQuickPaginator;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CachedPaginationManager
{
    public function __construct(
        private readonly CacheKeyFactory $cacheKeyFactory,
    ) {
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     * @param  array<int, string>  $columns
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(
        EloquentBuilder|QueryBuilder $builder,
        mixed $perPage = null,
        array $columns = ['*'],
        string $pageName = 'page',
        mixed $page = null,
        ?int $ttl = null,
        bool $fresh = false,
        ?string $cacheKey = null,
    ): LengthAwarePaginator {
        if (! config('cached-pagination.enabled', true)) {
            return $builder->paginate($perPage, $columns, $pageName, $page);
        }

        $page ??= Paginator::resolveCurrentPage($pageName);
        $cacheKey ??= $this->cacheKeyFactory->make($builder, $pageName);
        $cache = $this->cache();

        if (! $fresh && $cache->has($cacheKey)) {
            return $builder->paginate($perPage, $columns, $pageName, $page, (int) $cache->get($cacheKey));
        }

        $total = $this->countForPagination($builder);

        $cache->put($cacheKey, $total, $ttl ?? config('cached-pagination.ttl', 300));

        return $builder->paginate($perPage, $columns, $pageName, $page, $total);
    }

    private function cache(): CacheRepository
    {
        return cache()->store(config('cached-pagination.store'));
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     */
    private function countForPagination(EloquentBuilder|QueryBuilder $builder): int
    {
        if ($builder instanceof EloquentBuilder) {
            return (int) $builder->toBase()->getCountForPagination();
        }

        return (int) $builder->getCountForPagination();
    }
}
