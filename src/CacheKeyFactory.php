<?php

namespace Dkc\LaravelQuickPaginator;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Stringable;
use JsonException;
use UnitEnum;

class CacheKeyFactory
{
    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     *
     * @throws JsonException
     */
    public function make(EloquentBuilder|QueryBuilder $builder, string $pageName): string
    {
        $baseBuilder = $this->baseBuilder($builder);
        $keyBuilder = $this->withoutPageWindow($baseBuilder);

        $payload = [
            'connection' => $this->connectionName($keyBuilder->getConnection()),
            'builder' => $builder::class,
            'model' => $builder instanceof EloquentBuilder ? $builder->getModel()::class : null,
            'from' => $keyBuilder->from,
            'sql' => $keyBuilder->toSql(),
            'bindings' => $this->normalize($keyBuilder->getBindings()),
            'pageName' => $pageName,
        ];

        return rtrim((string) config('cached-pagination.prefix', 'cached-pagination-total'), ':')
            .':'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     */
    private function baseBuilder(EloquentBuilder|QueryBuilder $builder): QueryBuilder
    {
        return $builder instanceof EloquentBuilder ? $builder->toBase() : $builder;
    }

    private function withoutPageWindow(QueryBuilder $builder): QueryBuilder
    {
        $clone = clone $builder;

        $clone->limit = null;
        $clone->offset = null;
        $clone->unionLimit = null;
        $clone->unionOffset = null;

        return $clone;
    }

    private function connectionName(ConnectionInterface $connection): ?string
    {
        return $connection instanceof Connection ? $connection->getName() : null;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        if ($value instanceof Arrayable) {
            return $this->normalize($value->toArray());
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $value;
    }
}
