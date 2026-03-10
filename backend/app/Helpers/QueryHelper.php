<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Builder;

class QueryHelper
{
    /**
     * Escape special characters for a LIKE query to prevent injection.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    /**
     * Apply a safe LIKE search filter to a query builder.
     */
    public static function whereLike(Builder $query, string $column, string $value): Builder
    {
        return $query->where($column, 'LIKE', '%' . self::escapeLike($value) . '%');
    }

    /**
     * Apply dynamic sorting to a query builder with column whitelisting.
     *
     * @param Builder $query
     * @param string|null $sortBy Column to sort by
     * @param string $direction 'asc' or 'desc'
     * @param array $allowedColumns Whitelist of sortable columns
     * @param string $defaultColumn Fallback sort column
     * @return Builder
     */
    public static function applySorting(
        Builder $query,
        ?string $sortBy,
        string $direction = 'asc',
        array $allowedColumns = [],
        string $defaultColumn = 'created_at'
    ): Builder {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        if ($sortBy && in_array($sortBy, $allowedColumns, true)) {
            return $query->orderBy($sortBy, $direction);
        }

        return $query->orderBy($defaultColumn, $direction);
    }

    /**
     * Apply date range filtering to a query.
     */
    public static function whereDateRange(
        Builder $query,
        string $column,
        ?string $from = null,
        ?string $to = null
    ): Builder {
        if ($from) {
            $query->where($column, '>=', $from);
        }

        if ($to) {
            $query->where($column, '<=', $to);
        }

        return $query;
    }
}
