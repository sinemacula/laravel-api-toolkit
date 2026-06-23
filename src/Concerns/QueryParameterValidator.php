<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates incoming API query parameters.
 *
 * Builds the validation rule set from the supplied parameters and rejects
 * requests whose query modifiers do not match the expected shapes.
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
     */
    public function validate(array $parameters): void
    {
        $validator = Validator::make($parameters, $this->buildValidationRulesFromParameters($parameters));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
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
     * @param  array<string, string>  $array_rules
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
