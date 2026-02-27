<?php

namespace SineMacula\ApiToolkit\Concerns;

/**
 * Parses query parameters trait.
 *
 * Provides methods for parsing raw HTTP query string values into structured
 * arrays suitable for use by the API query parser.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ParsesQueryParameters
{
    /**
     * Extract the field parameters from the query string.
     *
     * @param  array<int|string, mixed>|string  $query
     * @return array<int|string, mixed>
     */
    private function parseFields(array|string $query): array
    {
        return $this->parseCommaSeparatedValues($query);
    }

    /**
     * Extract the count parameters from the query string.
     *
     * @param  array<int|string, mixed>|string  $query
     * @return array<int, string>
     */
    private function parseCounts(array|string $query): array
    {
        return $this->parseCommaSeparatedValues($query);
    }

    /**
     * Parse comma-separated values from query parameters.
     *
     * @param  array<int|string, mixed>|string  $query
     * @return array<int|string, mixed>
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
     * @param  array<int|string, mixed>  $query
     * @return array<int|string, mixed>
     */
    private function parseSums(array $query): array
    {
        return $this->parseAggregations($query);
    }

    /**
     * Extract the average parameters from the query string.
     *
     * @param  array<int|string, mixed>  $query
     * @return array<int|string, mixed>
     */
    private function parseAverages(array $query): array
    {
        return $this->parseAggregations($query);
    }

    /**
     * Parse aggregation parameters (sums, averages) from the query string.
     *
     * @param  array<int|string, mixed>  $query
     * @return array<int|string, mixed>
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
     * @param  array<int|string, mixed>  $relations
     * @return array<int|string, mixed>
     */
    private function parseRelationFields(array $relations): array
    {
        return array_map(fn ($fields) => $this->normalizeFields($fields), $relations);
    }

    /**
     * Normalize field values into an array format.
     *
     * @param  mixed  $fields
     * @return array<int|string, mixed>
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
     * @return array<int|string, mixed>
     */
    private function parseFilters(string $query): array
    {
        return json_decode($query, true) ?? [];
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

        if (!empty(array_filter($fields, static fn ($f) => $f !== ''))) {

            foreach ($fields as $field) {
                $order_parts    = explode(':', $field, 2);
                $column         = trim($order_parts[0]);
                $direction      = trim($order_parts[1] ?? 'asc');
                $order[$column] = $direction;
            }
        }

        return $order;
    }
}
