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
     * Paginate with a cached total and a deferred-join row fetch.
     *
     * The total count is cached (so the per-request count(*) is skipped on a
     * hit), and the page's rows are fetched via the fast-paginate deferred-join
     * technique (an index-only inner query for the page's keys, then the full
     * rows for just those keys) so a deep-offset page also avoids the expensive
     * `select * ... offset N`. Order-column derivation adapted from
     * hammerstone/fast-paginate (MIT, © Aaron Francis).
     *
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
        ?string $primaryKey = null,
    ): LengthAwarePaginator {
        if (! config('cached-pagination.enabled', true)) {
            return $builder->paginate($perPage, $columns, $pageName, $page);
        }

        $page ??= Paginator::resolveCurrentPage($pageName);
        $total = $this->cachedTotal($builder, $pageName, $ttl, $fresh, $cacheKey);

        $base = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;

        // Deferred join needs one row per key; bail to the native row fetch when
        // that doesn't hold (grouped/aggregated/distinct rows), or when the FROM
        // is a subquery/expression we can't qualify a key against. Still a cache win.
        if (filled($base->havings) || filled($base->groups) || $base->distinct || ! is_string($base->from)) {
            return $builder->paginate($perPage, $columns, $pageName, $page, $total);
        }

        return $this->deferredJoinPaginate($builder, $base, $total, $perPage, $columns, $pageName, $page, $primaryKey);
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     * @param  array<int, string>  $columns
     * @return LengthAwarePaginator<int, mixed>
     */
    private function deferredJoinPaginate(
        EloquentBuilder|QueryBuilder $builder,
        QueryBuilder $base,
        int $total,
        mixed $perPage,
        array $columns,
        string $pageName,
        mixed $page,
        ?string $primaryKey,
    ): LengthAwarePaginator {
        $isEloquent = $builder instanceof EloquentBuilder;

        if ($isEloquent) {
            $model = $builder->getModel();
            $keyName = $primaryKey ?? $model->getKeyName();
            $perPage ??= $model->getPerPage();
            $useIntKeys = in_array($model->getKeyType(), ['int', 'integer'], true);
        } else {
            $keyName = $primaryKey ?? 'id';
            $perPage ??= 15;
            // ponytail: no model to infer key type from; whereIn is always correct.
            $useIntKeys = false;
        }
        $perPage = (int) $perPage;
        // Qualify against the table's alias ("users as u" → "u.id"), not the raw FROM.
        $qualifiedKey = $this->tableAlias($base).'.'.$keyName;

        // Inner, key-only query for just this page's ids (no count, no eager loads).
        $inner = clone $builder;
        if ($isEloquent) {
            $inner->setEagerLoads([]);
        }
        $inner->select($this->innerSelectColumns($base, $qualifiedKey))->forPage($page, $perPage);

        $ids = $isEloquent
            ? $inner->get()->modelKeys()
            : $inner->get()->pluck($keyName)->all();

        if ($ids === []) {
            $items = collect();
        } else {
            if ($useIntKeys) {
                $builder->whereIntegerInRaw($qualifiedKey, $ids);
            } else {
                $builder->whereIn($qualifiedKey, $ids);
            }

            // Outer query keeps its own ordering + eager loads, so rows stay correctly sorted.
            $items = $builder->get($columns);
        }

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     */
    private function cachedTotal(
        EloquentBuilder|QueryBuilder $builder,
        string $pageName,
        ?int $ttl,
        bool $fresh,
        ?string $cacheKey,
    ): int {
        $cacheKey ??= $this->cacheKeyFactory->make($builder, $pageName);
        $cache = $this->cache();

        $missing = new \stdClass();
        $cached = $fresh ? $missing : $cache->get($cacheKey, $missing);

        if ($cached !== $missing) {
            return (int) $cached;
        }

        $total = $this->countForPagination($builder);
        $cache->put($cacheKey, $total, $ttl ?? config('cached-pagination.ttl', 300));

        return $total;
    }

    /**
     * The primary key, plus any original select expressions that *define* an
     * order-by alias.
     *
     * Adapted from hammerstone/fast-paginate (MIT, © Aaron Francis): a plain
     * `orderBy('score')` references a real table column and stays valid in the
     * inner select without being listed, but `orderBy('weighted')` against
     * `selectRaw('... as weighted')` must carry that select expression — selecting
     * the bare alias name would be an unknown column and order the wrong page.
     *
     * @return array<int, mixed>
     */
    private function innerSelectColumns(QueryBuilder $base, string $qualifiedKey): array
    {
        $columns = [$qualifiedKey];

        $orders = collect($base->orders ?? [])
            ->pluck('column')
            ->filter(fn (mixed $column): bool => is_string($column))
            ->all();

        if ($orders === [] || empty($base->columns)) {
            return $columns;
        }

        $grammar = $base->getGrammar();

        foreach ($base->columns as $select) {
            $sql = (string) $grammar->getValue($select);

            foreach ($orders as $order) {
                if ($this->selectDefinesAlias($sql, $order)) {
                    $columns[] = $select;

                    break;
                }
            }
        }

        return $columns;
    }

    /**
     * Whether a select SQL fragment defines the given alias, e.g. `... as weighted`
     * (optionally quoted), without matching a longer alias like `weighted_total`.
     */
    private function selectDefinesAlias(string $sql, string $alias): bool
    {
        $pattern = '/\bas\s+["`\[]?'.preg_quote($alias, '/').'["`\]]?(?!\w)/i';

        return (bool) preg_match($pattern, $sql);
    }

    /**
     * The table's alias if one is set ("users as u" → "u"), else the table name.
     * Caller guarantees a string FROM.
     */
    private function tableAlias(QueryBuilder $base): string
    {
        $from = (string) $base->from;

        if (preg_match('/\s+as\s+/i', $from) === 1) {
            $parts = preg_split('/\s+as\s+/i', $from);

            return trim((string) end($parts));
        }

        return trim($from);
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
