<?php

namespace App\Domain\Handheld\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The legacy PDA list shape: `{data: [...], current_page, last_page, per_page, total}` (the controller wraps it in
 * `{success: true, data: …}`). `per_page` defaults to `handheld.per_page` (25), at most `handheld.max_per_page` (100).
 */
final class PdaPage
{
    public static function perPage(mixed $requested): int
    {
        $default = (int) config('handheld.per_page', 25);
        $max = (int) config('handheld.max_per_page', 100);

        return is_numeric($requested) ? max(1, min($max, (int) $requested)) : $default;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(Collection<int, TModel>): list<array<string, mixed>>  $rows  maps the page's models
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public static function of(Builder $query, int $perPage, int $page, callable $rows): array
    {
        /** @var LengthAwarePaginator<int, TModel> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => $rows(collect($paginator->items())),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
