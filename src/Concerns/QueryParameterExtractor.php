<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Search\SearchTerm;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Extracts and parses API query parameters from a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryParameterExtractor
{
    /**
     * Extract and parse all parameters from the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function extract(Request $request): array
    {
        $parameters = [];

        $parsers = [
            'page'       => fn ($value) => trim($value),
            'limit'      => fn ($value) => trim($value),
            'cursor'     => fn ($value) => $value,
            'fields'     => fn ($value) => $this->parseFields($value),
            'aggregates' => fn ($value) => $this->parseAggregates($value),
            'filters'    => fn ($value) => $this->parseFilters($value),
            'search'     => fn ($value) => SearchTerm::from(is_string($value) ? $value : ''),
            'order'      => fn ($value) => $this->parseOrder($value),
            'trashed'    => fn ($value) => is_string($value) ? strtolower(trim($value)) : '',
        ];

        foreach ($parsers as $key => $parser) {
            if (!$request->has($key)) {
                continue;
            }

            $parameters[$key] = $parser($request->input($key));
        }

        return $parameters;
    }

    /**
     * Extract the field parameters from the query string.
     *
     * @param  array<string, string>|string  $query
     * @return array<int, string>|array<string, array<int, string>>
     */
    private function parseFields(array|string $query): array
    {
        return $this->parseCommaSeparatedValues($query);
    }

    /**
     * Extract the aggregate parameters from the query string.
     *
     * @param  array<string, string>|string  $query
     * @return array<int, string>|array<string, array<int, string>>
     */
    private function parseAggregates(array|string $query): array
    {
        return $this->parseCommaSeparatedValues($query);
    }

    /**
     * Parse comma-separated values from query parameters.
     *
     * @param  array<string, string>|string  $query
     * @return array<int, string>|array<string, array<int, string>>
     */
    private function parseCommaSeparatedValues(array|string $query): array
    {
        if (!is_array($query)) {
            return $this->splitAndTrim($query);
        }

        return array_map(fn ($value) => $this->splitAndTrim($value), $query);
    }

    /**
     * Split a string by comma and trim each value.
     *
     * @param  string  $value
     * @return array<int, string>
     */
    private function splitAndTrim(string $value): array
    {
        return array_map('trim', explode(',', $value));
    }

    /**
     * Extract the filter parameters from the query string.
     *
     * Anything but a JSON object is rejected, since coercing it to an empty set
     * would silently return the unfiltered table.
     *
     * @param  string  $query
     * @return array<string, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function parseFilters(string $query): array
    {
        $filters = json_decode($query, true);

        if (!is_array($filters) || ($filters !== [] && array_is_list($filters))) {
            throw Rejection::at('filters', 'filters.not_object');
        }

        return $filters;
    }

    /**
     * Extract the order parameters from the query string.
     *
     * @param  string  $query
     * @return array<string, 'asc'|'desc'>
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function parseOrder(string $query): array
    {
        $order = [];

        foreach (explode(',', $query) as $field) {
            $parts  = explode(':', $field, 2);
            $column = trim($parts[0]);

            if ($column === '') {
                continue;
            }

            $order[$column] = $this->parseDirection($column, $parts[1] ?? 'asc');
        }

        return $order;
    }

    /**
     * Normalise an order direction, rejecting anything but asc or desc.
     *
     * Dropping an unreadable direction would silently return an unsorted page.
     *
     * @param  string  $column
     * @param  string  $suffix
     * @return 'asc'|'desc'
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function parseDirection(string $column, string $suffix): string
    {
        $direction = strtolower(trim($suffix));

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw Rejection::at('order.' . $column, 'order.unpermitted_direction', ['direction' => trim($suffix), 'key' => $column, 'accepts' => 'asc, desc']);
        }

        return $direction;
    }
}
