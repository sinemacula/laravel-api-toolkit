<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Validates the shape of incoming API query parameters.
 *
 * An oversized page size is refused, not clamped, so a short page is never
 * mistaken for the end of the result set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryParameterValidator
{
    /** @var string Config key for the page-size ceiling, also used as the rejection reason */
    public const string MAX_LIMIT = 'max_limit';

    /** @var int The ceiling applied when the configured value is absent or unreadable */
    public const int DEFAULT_MAX_LIMIT = 100;

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
        $this->guardPageSize($parameters['limit'] ?? null);
    }

    /**
     * Resolve the hard ceiling on a client-supplied page size.
     *
     * An absent or unreadable key falls back to the default; only an explicit
     * null or zero disables the ceiling.
     *
     * @return int
     */
    public static function pageSizeCeiling(): int
    {
        $key = 'api-toolkit.parser.' . self::MAX_LIMIT;

        if (!Config::has($key)) {
            return self::DEFAULT_MAX_LIMIT;
        }

        $ceiling = Config::get($key);

        return match (true) {
            $ceiling === null    => 0,
            is_numeric($ceiling) => (int) $ceiling,
            default              => self::DEFAULT_MAX_LIMIT,
        };
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
     * Runs after shape validation so malformed JSON keeps its own failure.
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
     * Reject a page size above the configured ceiling.
     *
     * Runs after shape validation so a non-integer keeps its own failure.
     *
     * @param  mixed  $limit
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function guardPageSize(mixed $limit): void
    {
        $ceiling   = self::pageSizeCeiling();
        $requested = is_numeric($limit) ? (int) $limit : 0;

        if ($ceiling > 0 && $requested > $ceiling) {
            throw QueryTooExpensiveException::exceeded('limit', '', self::MAX_LIMIT, $ceiling, $requested);
        }
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
            'search'  => 'string',
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
