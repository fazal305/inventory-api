<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\ApiException;

/**
 * Validates GET /products query parameters: search, category, sort,
 * page, limit. Separate from ProductValidator because these shape a
 * SELECT, not a resource body — different rules, different failure modes.
 */
final class ProductQueryValidator
{
    /**
     * The only column names ORDER BY is ever allowed to use. Prepared-statement
     * placeholders can bind values, not identifiers — a column name has to be
     * concatenated into the SQL string somewhere, so it must come from this
     * fixed list, never from the raw query string (see Phase-1 sort design note).
     */
    private const SORTABLE_COLUMNS = ['name', 'price', 'quantity', 'created_at'];

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public static function validate(array $query): array
    {
        $errors = [];

        $search = self::nullableString($query['search'] ?? null);
        $categorySlug = self::nullableString($query['category'] ?? null);

        $sortColumn = 'created_at';
        $sortDirection = 'DESC';
        $rawSort = self::nullableString($query['sort'] ?? null);
        if ($rawSort !== null) {
            $sortDirection = str_starts_with($rawSort, '-') ? 'DESC' : 'ASC';
            $column = ltrim($rawSort, '-');
            if (!in_array($column, self::SORTABLE_COLUMNS, true)) {
                $errors['sort'] = 'sort must be one of: ' . implode(', ', self::SORTABLE_COLUMNS)
                    . ' (prefix with - for descending, e.g. -price).';
            } else {
                $sortColumn = $column;
            }
        }

        $page = 1;
        $rawPage = $query['page'] ?? null;
        if ($rawPage !== null && $rawPage !== '') {
            if (!ctype_digit((string) $rawPage) || (int) $rawPage < 1) {
                $errors['page'] = 'page must be a positive integer.';
            } else {
                $page = (int) $rawPage;
            }
        }

        $limit = self::DEFAULT_LIMIT;
        $rawLimit = $query['limit'] ?? null;
        if ($rawLimit !== null && $rawLimit !== '') {
            if (!ctype_digit((string) $rawLimit) || (int) $rawLimit < 1) {
                $errors['limit'] = 'limit must be a positive integer.';
            } else {
                // A client asking for an oversized page is a normal, predictable
                // request — capped rather than rejected, unlike a malformed value.
                $limit = min((int) $rawLimit, self::MAX_LIMIT);
            }
        }

        if ($errors !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid query parameters.', 422, $errors);
        }

        return [
            'search' => $search,
            'category_slug' => $categorySlug,
            'sort_column' => $sortColumn,
            'sort_direction' => $sortDirection,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
