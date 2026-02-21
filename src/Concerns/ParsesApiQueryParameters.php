<?php

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;

/**
 * Parses API query parameters.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
trait ParsesApiQueryParameters
{
    /**
     * Normalize field-like resource selections.
     *
     * @param  mixed  $selection
     * @return array<int, string>|array<string, array<int, string>>|null
     */
    private function normalizeResourceSelection(mixed $selection): ?array
    {
        if (!is_array($selection)) {
            return null;
        }

        if (array_is_list($selection)) {
            return $this->normalizeFields($selection);
        }

        $normalized = [];

        foreach ($selection as $resource_name => $resource_fields) {
            if (!is_string($resource_name)) {
                continue;
            }

            $normalized[$resource_name] = $this->normalizeFields($resource_fields);
        }

        return $normalized;
    }

    /**
     * Resolve an aggregation option.
     *
     * @param  string  $option
     * @param  string|null  $resource
     * @return array<string, array<int, string>>|array<string, array<string, array<int, string>>>|null
     */
    private function getAggregationParameter(string $option, ?string $resource = null): ?array
    {
        $aggregation = $this->getParameters($option, $resource);

        if (!is_array($aggregation)) {
            return null;
        }

        return $resource === null
            ? $this->normalizeAggregationsByResource($aggregation)
            : $this->normalizeAggregationsByRelation($aggregation);
    }

    /**
     * Normalize aggregations for a single resource.
     *
     * @param  array<string, mixed>  $relations
     * @return array<string, array<int, string>>
     */
    private function normalizeAggregationsByRelation(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $relation => $fields) {
            if (is_string($relation)) {
                $normalized[$relation] = $this->normalizeFields($fields);
            }
        }

        return $normalized;
    }

    /**
     * Normalize aggregations for multiple resources.
     *
     * @param  array<string, mixed>  $aggregations
     * @return array<string, array<string, array<int, string>>>
     */
    private function normalizeAggregationsByResource(array $aggregations): array
    {
        $normalized = [];

        foreach ($aggregations as $resource_name => $relations) {
            if (!is_string($resource_name) || !is_array($relations)) {
                continue;
            }

            $normalized[$resource_name] = $this->normalizeAggregationsByRelation($relations);
        }

        return $normalized;
    }

    /**
     * Extract and parse all parameters from the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    private function extractParameters(Request $request): array
    {
        $parameters = [];

        foreach (['page', 'limit', 'cursor', 'fields', 'counts', 'sums', 'averages', 'filters', 'order'] as $key) {
            if (!$request->has($key)) {
                continue;
            }

            $parameters[$key] = $this->parseInputValue($key, $request->input($key));
        }

        return $parameters;
    }

    /**
     * Parse a single input value for a known parameter key.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    private function parseInputValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'page', 'limit' => is_scalar($value) ? trim((string) $value) : '',
            'cursor' => $value,
            'fields', 'counts' => is_array($value) || is_string($value)
                ? $this->parseCommaSeparatedValues($value)
                : [],
            'sums', 'averages' => is_array($value)
                ? $this->parseAggregations($value)
                : [],
            'filters' => is_string($value) ? $this->parseFilters($value) : [],
            'order'   => is_string($value) ? $this->parseOrder($value) : [],
            default   => null,
        };
    }

    /**
     * Validate the incoming request.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validate(array $parameters): void
    {
        $validator = Validator::make($parameters, $this->buildValidationRulesFromParameters($parameters));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Parse comma-separated values from query parameters.
     *
     * @param  array<string, mixed>|string  $query
     * @return array<int, string>|array<string, array<int, string>>
     */
    private function parseCommaSeparatedValues(array|string $query): array
    {
        if (!is_array($query)) {
            return $this->splitAndTrim($query);
        }

        $normalized = [];

        foreach ($query as $resource_name => $value) {
            if (is_string($resource_name)) {
                $normalized[$resource_name] = $this->splitAndTrim(is_scalar($value) ? (string) $value : '');
            }
        }

        return $normalized;
    }

    /**
     * Split a string by comma and trim each value.
     *
     * @param  string  $value
     * @return array<int, string>
     */
    private function splitAndTrim(string $value): array
    {
        return array_values(
            array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $field): bool => $field !== '',
            ),
        );
    }

    /**
     * Parse aggregation parameters from the query string.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, array<string, array<int, string>>>
     */
    private function parseAggregations(array $query): array
    {
        $aggregations = [];

        foreach ($query as $resource => $relations) {
            if (!is_string($resource) || !is_array($relations) || $resource === '') {
                continue;
            }

            $aggregations[$resource] = $this->normalizeAggregationsByRelation($relations);
        }

        return $aggregations;
    }

    /**
     * Normalize field values into an array format.
     *
     * @param  mixed  $fields
     * @return array<int, string>
     */
    private function normalizeFields(mixed $fields): array
    {
        if (is_array($fields)) {
            return array_values(
                array_filter(
                    array_map(
                        static fn (mixed $value): ?string => is_scalar($value) ? trim((string) $value) : null,
                        $fields,
                    ),
                    static fn (?string $value): bool => $value !== null && $value !== '',
                ),
            );
        }

        if (!is_scalar($fields)) {
            return [];
        }

        $normalized = trim((string) $fields);

        return $normalized === '' ? [] : [$normalized];
    }

    /**
     * Extract the filter parameters from the query string.
     *
     * @param  string  $query
     * @return array<string, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidInputException
     */
    private function parseFilters(string $query): array
    {
        try {
            $filters = json_decode($query, true, 512, JSON_THROW_ON_ERROR);

            return is_array($filters) ? $filters : [];
        } catch (\JsonException $exception) {
            throw new InvalidInputException;
        }
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
            $field = trim($field);

            if ($field === '') {
                continue;
            }

            $order_parts = explode(':', $field, 2);
            $column      = trim($order_parts[0]);

            if ($column !== '') {
                $order[$column] = strtolower(trim($order_parts[1] ?? 'asc'));
            }
        }

        return $order;
    }

    /**
     * Build the validation rules from the given parameters.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    private function buildValidationRulesFromParameters(array $parameters): array
    {
        $rules = [
            'fields'  => 'string',
            'filters' => 'json',
            'order'   => 'string',
            'page'    => 'integer|min:1',
            'limit'   => 'integer|min:1',
            'cursor'  => 'string',
        ];

        foreach (
            [
                'fields'   => ['fields.*' => 'string'],
                'counts'   => ['counts.*' => 'string'],
                'sums'     => ['sums.*' => 'array', 'sums.*.*' => 'string'],
                'averages' => ['averages.*' => 'array', 'averages.*.*' => 'string'],
            ] as $key => $array_rules
        ) {
            if (!isset($parameters[$key]) || !is_array($parameters[$key])) {
                continue;
            }

            $rules[$key] = 'array';

            foreach ($array_rules as $rule_key => $rule_value) {
                $rules[$rule_key] = $rule_value;
            }
        }

        return $rules;
    }
}
