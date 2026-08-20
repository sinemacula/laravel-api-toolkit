<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Query;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;

/**
 * Operator-tunable structural caps for an incoming API query.
 *
 * Each cap bounds one dimension of a request whose parts are individually cheap
 * and declared but multiply into an expensive query: the size and nesting of
 * the filter document, the nodes and value-list items it dispatches, the sort
 * keys and relation aggregates it asks for, and how deep into a result set it
 * pages. A cap of zero or less disables that dimension.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryCostLimits
{
    /** @var string Bounds the byte length of the filter document */
    public const string MAX_BYTES = 'max_bytes';

    /** @var string Bounds the nested object levels within the filter document */
    public const string MAX_PARSE_DEPTH = 'max_parse_depth';

    /** @var string Bounds the levels the filter dispatcher may descend */
    public const string MAX_DEPTH = 'max_depth';

    /** @var string Bounds the keys the filter dispatcher may visit */
    public const string MAX_NODES = 'max_nodes';

    /** @var string Bounds the items in a single operator value list */
    public const string MAX_IN_ITEMS = 'max_in_items';

    /** @var string Bounds the columns a single request may sort by */
    public const string MAX_ORDER_KEYS = 'max_order_keys';

    /** @var string Bounds the relation aggregates a single request may ask for */
    public const string MAX_AGGREGATES = 'max_aggregates';

    /** @var string Bounds the page number a paginated request may start at */
    public const string MAX_OFFSET = 'max_offset';

    /** @var array<string, int> The shipped default for every cap, mirrored by the published config file and used as the fallback when that file predates the caps */
    public const array DEFAULTS = [
        self::MAX_BYTES       => 8192,
        self::MAX_PARSE_DEPTH => 16,
        self::MAX_DEPTH       => 3,
        self::MAX_NODES       => 100,
        self::MAX_IN_ITEMS    => 500,
        self::MAX_ORDER_KEYS  => 3,
        self::MAX_AGGREGATES  => 5,
        self::MAX_OFFSET      => 10000,
    ];

    /**
     * Constructor.
     *
     * @param  array<string, int>  $limits
     * @return void
     */
    private function __construct(

        /** The resolved value of every cap, keyed by cap name */
        private array $limits,
    ) {}

    /**
     * Resolve every cap from configuration.
     *
     * A cap configured as null is disabled rather than defaulted, so the two
     * documented ways of turning a dimension off agree. A cap the published
     * config never declares falls back to its shipped default instead.
     *
     * @return self
     */
    public static function fromConfig(): self
    {
        $limits = [];

        foreach (self::DEFAULTS as $cap => $default) {

            $value = Config::get('api-toolkit.query_cost.' . $cap, $default);

            $limits[$cap] = match (true) {
                $value === null    => 0,
                is_numeric($value) => (int) $value,
                default            => $default,
            };
        }

        return new self($limits);
    }

    /**
     * Return the configured value of the given cap.
     *
     * @param  string  $cap
     * @return int
     */
    public function limit(string $cap): int
    {
        return $this->limits[$cap] ?? 0;
    }

    /**
     * Reject the request when the given measurement exceeds its cap.
     *
     * @param  string  $cap
     * @param  int  $actual
     * @param  string  $parameter
     * @param  string  $pointer
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function enforce(string $cap, int $actual, string $parameter, string $pointer = ''): void
    {
        $limit = $this->limit($cap);

        if ($limit > 0 && $actual > $limit) {
            throw QueryTooExpensiveException::exceeded($parameter, $pointer, $cap, $limit, $actual);
        }
    }
}
