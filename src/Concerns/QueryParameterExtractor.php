<?php

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Http\Request;

/**
 * Extracts and parses API query parameters from a request.
 *
 * Walks the supported query keys (page, limit, cursor, fields, counts, sums,
 * averages, filters, order) and normalises each raw value into its parsed
 * representation.
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
     */
    public function extract(Request $request): array
    {
        $parameters = [];

        $parsers = [
            'page'     => fn ($value) => trim($value),
            'limit'    => fn ($value) => trim($value),
            'cursor'   => fn ($value) => $value,
            'fields'   => fn ($value) => $this->parseFields($value),
            'counts'   => fn ($value) => $this->parseCounts($value),
            'sums'     => fn ($value) => $this->parseSums($value),
            'averages' => fn ($value) => $this->parseAverages($value),
            'filters'  => fn ($value) => $this->parseFilters($value),
            'order'    => fn ($value) => $this->parseOrder($value),
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
     * Extract the count parameters from the query string.
     *
     * @param  array<string, string>|string  $query
     * @return array<int, string>|array<string, array<int, string>>
     */
    private function parseCounts(array|string $query): array
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
     * Extract the sum parameters from the query string.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function parseSums(array $query): array
    {
        return $this->parseAggregations($query);
    }

    /**
     * Extract the average parameters from the query string.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function parseAverages(array $query): array
    {
        return $this->parseAggregations($query);
    }

    /**
     * Parse aggregation parameters (sums, averages) from the query string.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function parseAggregations(array $query): array
    {
        $aggregations = [];

        foreach ($query as $resource => $relations) {
            if (!is_array($relations)) {
                continue;
            }

            $aggregations[$resource] = $this->parseRelationFields($relations);
        }

        return $aggregations;
    }

    /**
     * Parse relation fields for aggregations.
     *
     * @param  array<mixed>  $relations
     * @return array<array<mixed>>
     */
    private function parseRelationFields(array $relations): array
    {
        return array_map(fn ($fields) => $this->normalizeFields($fields), $relations);
    }

    /**
     * Normalize field values into an array format.
     *
     * @param  mixed  $fields
     * @return array<mixed>
     */
    private function normalizeFields(mixed $fields): array
    {
        if (is_string($fields)) {
            return $this->splitAndTrim($fields);
        }

        if (is_array($fields)) {
            return $fields;
        }

        return [$fields];
    }

    /**
     * Extract the filter parameters from the query string.
     *
     * @param  string  $query
     * @return array<string, mixed>
     */
    private function parseFilters(string $query): array
    {
        /** @var array<string, mixed>|null $filters */
        $filters = json_decode($query, true);

        return $filters ?? [];
    }

    /**
     * Extract the order parameters from the query string.
     *
     * @param  string  $query
     * @return array<string, string>
     */
    private function parseOrder(string $query): array
    {
        $order  = [];
        $fields = explode(',', $query);

        if (!empty(array_filter($fields, static fn (string $value): bool => (bool) $value))) {
            foreach ($fields as $field) {
                $order_parts    = explode(':', $field, 2);
                $column         = $order_parts[0];
                $direction      = $order_parts[1] ?? 'asc';
                $order[$column] = $direction;
            }
        }

        return $order;
    }
}
