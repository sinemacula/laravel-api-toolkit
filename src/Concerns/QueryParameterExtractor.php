<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Extracts and parses API query parameters from a request.
 *
 * Walks the supported query keys (page, limit, cursor, fields, counts, sums,
 * averages, filters, search, order, trashed) and normalises each raw value into
 * its parsed representation.
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
            'page'     => fn ($value) => trim($value),
            'limit'    => fn ($value) => trim($value),
            'cursor'   => fn ($value) => $value,
            'fields'   => fn ($value) => $this->parseFields($value),
            'counts'   => fn ($value) => $this->parseCounts($value),
            'sums'     => fn ($value) => $this->parseSums($value),
            'averages' => fn ($value) => $this->parseAverages($value),
            'filters'  => fn ($value) => $this->parseFilters($value),
            'search'   => fn ($value) => SearchTerm::from(is_string($value) ? $value : ''),
            'order'    => fn ($value) => $this->parseOrder($value),
            'trashed'  => fn ($value) => is_string($value) ? strtolower(trim($value)) : '',
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
     * A document that fails to decode - malformed, or nested beyond the decoder
     * depth limit - and one that decodes to a scalar or a populated
     * numeric-keyed list are both rejected. Coercing either to an empty set
     * would drop the filter and answer with the unfiltered table. An empty
     * document carries no filter to drop and is accepted.
     *
     * @param  string  $query
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function parseFilters(string $query): array
    {
        $filters = json_decode($query, true);

        if (!is_array($filters) || ($filters !== [] && array_is_list($filters))) {
            throw ValidationException::withMessages(['filters' => 'The filters parameter must be a JSON object.']);
        }

        return $filters;
    }

    /**
     * Extract the order parameters from the query string.
     *
     * @param  string  $query
     * @return array<string, string>
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

            $order[$column] = $parts[1] ?? 'asc';
        }

        return $order;
    }
}
