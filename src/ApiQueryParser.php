<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;

/**
 * API query parser.
 *
 * Extract API parameters supplied within the query string of a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ApiQueryParser
{
    /** @var array */
    protected array $parameters = [];

    /**
     * Returns a list of fields set with the URL modifiers.
     *  - e.g. ?fields['user']=first_name,last_name.
     *
     * @param  string|null  $resource
     * @return array|null
     */
    public function getFields(?string $resource = null): ?array
    {
        $fields = $this->getParameters('fields', $resource);

        return is_array($fields) ? array_map('trim', $fields) : $fields;
    }

    /**
     * Returns a list of relation counts set with the URL modifiers.
     * - e.g. ?counts['user']=memberships.
     *
     * @param  string|null  $resource
     * @return array|null
     */
    public function getCounts(?string $resource = null): ?array
    {
        $counts = $this->getParameters('counts', $resource);

        return is_array($counts) ? array_map('trim', $counts) : $counts;
    }

    /**
     * Returns a list of relation sums set with the URL modifiers.
     * - e.g. ?sums['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array|null
     */
    public function getSums(?string $resource = null): ?array
    {
        return $this->getParameters('sums', $resource);
    }

    /**
     * Returns a list of relation averages set with the URL modifiers.
     * - e.g. ?averages['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array|null
     */
    public function getAverages(?string $resource = null): ?array
    {
        return $this->getParameters('averages', $resource);
    }

    /**
     * Returns a list of filters set with the URL modifiers.
     *
     * @return array|null
     */
    public function getFilters(): ?array
    {
        return $this->getParameters('filters') ?? [];
    }

    /**
     * Returns the desired order set with the URL modifiers.
     *  - e.g. ?order=first_name,last_name:desc
     *  - e.g. ?order=random.
     *
     * @return array
     */
    public function getOrder(): array
    {
        return $this->getParameters('order') ?? [];
    }

    /**
     * Returns the desired limit set with the URL modifiers.
     *  - e.g. ?limit=x.
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        $limit = (int) $this->getParameters('limit');

        return $limit > 0 ? $limit : null;
    }

    /**
     * Returns the current page set with the URL modifiers.
     *  - e.g. ?page=4.
     *
     * @return int|null
     */
    public function getPage(): ?int
    {
        $page = (int) $this->getParameters('page');

        return $page > 0 ? $page : 1;
    }

    /**
     * Returns the current page cursor.
     *  - e.g. ?cursor=eyJpZCI6MTAwfQ==.
     *
     * @return string|null
     */
    public function getCursor(): ?string
    {
        return (string) $this->getParameters('cursor');
    }

    /**
     * Parse the given query string to obtain resource and value information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function parse(Request $request): void
    {
        $this->validate($request->all());

        $this->parameters = $this->extractParameters($request);
    }

    /**
     * Extract and parse all parameters from the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    private function extractParameters(Request $request): array
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
            if ($request->has($key)) {
                $parameters[$key] = $parser($request->input($key));
            }
        }

        return $parameters;
    }

    /**
     * Extract the specified parameter.
     *
     * @param  string  $option
     * @param  string|null  $resource
     * @return mixed
     */
    private function getParameters(string $option, ?string $resource = null): mixed
    {
        if ($resource) {
            return $this->parameters[$option][$resource] ?? null;
        }

        return $this->parameters[$option] ?? null;
    }

    /**
     * Validate the incoming request.
     *
     * @param  array  $parameters
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
     * Extract the field parameters from the query string.
     *
     * @param  array|string  $query
     * @return array
     */
    private function parseFields(array|string $query): array
    {
        return $this->parseCommaSeparatedValues($query);
    }

    /**
     * Extract the count parameters from the query string.
     *
     * @param  array|string  $query
     * @return array<int, string>
     */
    private function parseCounts(array|string $query): array
    {
        return $this->parseCommaSeparatedValues($query);
    }

    /**
     * Parse comma-separated values from query parameters.
     *
     * @param  array|string  $query
     * @return array
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
     * @return array
     */
    private function splitAndTrim(string $value): array
    {
        return array_map('trim', explode(',', $value));
    }

    /**
     * Extract the sum parameters from the query string.
     *
     * @param  array  $query
     * @return array
     */
    private function parseSums(array $query): array
    {
        return $this->parseAggregations($query);
    }

    /**
     * Extract the average parameters from the query string.
     *
     * @param  array  $query
     * @return array
     */
    private function parseAverages(array $query): array
    {
        return $this->parseAggregations($query);
    }

    /**
     * Parse aggregation parameters (sums, averages) from the query string.
     *
     * @param  array  $query
     * @return array
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
     * @param  array  $relations
     * @return array
     */
    private function parseRelationFields(array $relations): array
    {
        return array_map(fn ($fields) => $this->normalizeFields($fields), $relations);
    }

    /**
     * Normalize field values into an array format.
     *
     * @param  mixed  $fields
     * @return array
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
     * @return array
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidInputException
     */
    private function parseFilters(string $query): array
    {
        try {
            return json_decode($query, true) ?? [];
        } catch (\Throwable $exception) {
            throw new InvalidInputException;
        }
    }

    /**
     * Extract the order parameters from the query string.
     *
     * @param  string  $query
     * @return array
     */
    private function parseOrder(string $query): array
    {
        $order  = [];
        $fields = explode(',', $query);

        if (!empty(array_filter($fields))) {
            foreach ($fields as $field) {
                $order_parts    = explode(':', $field, 2);
                $column         = $order_parts[0];
                $direction      = $order_parts[1] ?? 'asc';
                $order[$column] = $direction;
            }
        }

        return $order;
    }

    /**
     * Build the validation rules from the given parameters.
     *
     * @param  array  $parameters
     * @return array
     */
    private function buildValidationRulesFromParameters(array $parameters): array
    {
        $rules = $this->getBaseValidationRules();

        $this->applyArrayValidationRules($rules, $parameters, 'fields', ['fields.*' => 'string']);
        $this->applyArrayValidationRules($rules, $parameters, 'counts', ['counts.*' => 'string']);
        $this->applyArrayValidationRules($rules, $parameters, 'sums', [
            'sums.*'   => 'array',
            'sums.*.*' => 'string',
        ]);
        $this->applyArrayValidationRules($rules, $parameters, 'averages', [
            'averages.*'   => 'array',
            'averages.*.*' => 'string',
        ]);

        return $rules;
    }

    /**
     * Get the base validation rules for all parameters.
     *
     * @return array
     */
    private function getBaseValidationRules(): array
    {
        return [
            'fields'  => 'string',
            'filters' => 'json',
            'order'   => 'string',
            'page'    => 'integer|min:1',
            'limit'   => 'integer|min:1',
            'cursor'  => 'string',
        ];
    }

    /**
     * Apply validation rules for array parameters.
     *
     * @param  array  $rules
     * @param  array  $parameters
     * @param  string  $key
     * @param  array  $array_rules
     * @return void
     */
    private function applyArrayValidationRules(array &$rules, array $parameters, string $key, array $array_rules): void
    {
        if (!isset($parameters[$key]) || !is_array($parameters[$key])) {
            return;
        }

        $rules[$key] = 'array';

        foreach ($array_rules as $rule_key => $rule_value) {
            $rules[$rule_key] = $rule_value;
        }
    }
}
