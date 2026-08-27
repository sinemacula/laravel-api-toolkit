<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Validates incoming API query parameters.
 *
 * Builds the validation rule set from the supplied parameters and rejects
 * requests whose query modifiers do not match the expected shapes. The filter
 * document is additionally bounded by size and by nesting, so a document that
 * is too large to be worth interpreting is refused before it is interpreted.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryParameterValidator
{
    /**
     * Validate the incoming request parameters.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function validate(array $parameters): void
    {
        $limits  = QueryCostLimits::fromConfig();
        $filters = $parameters['filters'] ?? null;

        $this->guardFilterSize($filters, $limits);
        $this->assertParameterShapes($parameters);
        $this->guardFilterNesting($filters, $limits);
    }

    /**
     * Assert that every supplied parameter matches its expected shape.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertParameterShapes(array $parameters): void
    {
        $validator = Validator::make($parameters, $this->buildValidationRulesFromParameters($parameters));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Reject a filter document larger than the configured byte cap.
     *
     * @param  mixed  $filters
     * @param  \SineMacula\ApiToolkit\Query\QueryCostLimits  $limits
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function guardFilterSize(mixed $filters, QueryCostLimits $limits): void
    {
        if (!is_string($filters)) {
            return;
        }

        $limits->enforce(QueryCostLimits::MAX_BYTES, strlen($filters), 'filters');
    }

    /**
     * Reject a filter document nested beyond the configured level cap.
     *
     * Runs once the document is known to be valid JSON, so a malformed value
     * keeps its own validation failure rather than being reported as a cost.
     *
     * @param  mixed  $filters
     * @param  \SineMacula\ApiToolkit\Query\QueryCostLimits  $limits
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function guardFilterNesting(mixed $filters, QueryCostLimits $limits): void
    {
        if (!is_string($filters)) {
            return;
        }

        $decoded = json_decode($filters, true);

        if (!is_array($decoded)) {
            return;
        }

        $limits->enforce(QueryCostLimits::MAX_PARSE_DEPTH, $this->measureNesting($decoded), 'filters');
    }

    /**
     * Measure how many object levels the given document nests.
     *
     * @param  array<mixed>  $document
     * @return int
     */
    private function measureNesting(array $document): int
    {
        $levels = 1;

        foreach ($document as $value) {

            if (!is_array($value)) {
                continue;
            }

            $levels = max($levels, 1 + $this->measureNesting($value));
        }

        return $levels;
    }

    /**
     * Build the validation rules from the given parameters.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
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
     * @return array<string, string>
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
     * @param  array<string, string>  $rules
     * @param  array<string, mixed>  $parameters
     * @param  string  $key
     * @param  array<string, string>  $arrayRules
     * @return void
     */
    private function applyArrayValidationRules(array &$rules, array $parameters, string $key, array $arrayRules): void
    {
        if (!isset($parameters[$key]) || !is_array($parameters[$key])) {
            return;
        }

        $rules[$key] = 'array';

        foreach ($arrayRules as $ruleKey => $ruleValue) {
            $rules[$ruleKey] = $ruleValue;
        }
    }
}
