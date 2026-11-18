<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Validation\Rejection;

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
    /** @var string The reason a page size above the ceiling is refused for */
    public const string MAX_LIMIT = 'max_limit';

    /** @var array<int, string> The parameters keyed by resource type */
    public const array SELECTIONS = ['fields', 'aggregates'];

    /** @var array<string, string> The shape each parameter must take */
    private const array RULES = [
        'fields.*'     => 'nullable|string',
        'aggregates.*' => 'nullable|string',
        'filters'      => 'json',
        'order'        => 'string',
        'page'         => 'integer|min:1',
        'limit'        => 'integer|min:1',
        'cursor'       => 'string',
        'search'       => 'string',
        'trashed'      => 'filled|in:with,only',
    ];

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
        $this->guardEncoding($parameters);
        $this->assertSelectionContainers($parameters);
        $this->assertParameterShapes($parameters);
        $this->guardFilterNesting($filters, $limits);
        $this->guardPageSize($parameters['limit'] ?? null);
    }

    /**
     * Reject each parameter the parser reads whose keys or values are not valid
     * UTF-8, naming the parameter without echoing its bytes.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function guardEncoding(array $parameters): void
    {
        $malformed = [];

        foreach (QueryParameterExtractor::PARAMETERS as $parameter) {

            $value = $parameters[$parameter] ?? null;

            if ((!is_string($value) && !is_array($value)) || mb_check_encoding($value, 'UTF-8')) { // @phpstan-ignore argument.type
                continue;
            }

            $malformed[$parameter] = Rejection::message('query.malformed_encoding', ['parameter' => $parameter]);
        }

        if ($malformed !== []) {
            throw Rejection::withMessages($malformed);
        }
    }

    /**
     * Assert that every selection parameter present is keyed by resource type.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function assertSelectionContainers(array $parameters): void
    {
        foreach (self::SELECTIONS as $parameter) {
            if (array_key_exists($parameter, $parameters) && !is_array($parameters[$parameter])) {
                throw Rejection::at($parameter, $parameter . '.not_keyed');
            }
        }
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
        $validator = Validator::make($parameters, self::RULES);

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
        $ceiling   = QuerySettings::maxLimit();
        $requested = is_numeric($limit) ? (int) $limit : 0;

        if ($ceiling > 0 && $requested > $ceiling) {
            throw QueryTooExpensiveException::exceeded('limit', '', self::MAX_LIMIT, $ceiling, $requested);
        }
    }

    /**
     * Measure the levels the given document nests, objects and lists alike.
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
}
