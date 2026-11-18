<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Query;

use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;

/**
 * Configurable structural caps on the cost of an incoming API query.
 *
 * A cap of zero disables that dimension.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryCostLimits
{
    /** @var string Bounds the byte length of the filter document */
    public const string MAX_BYTES = 'max_bytes';

    /** @var string Bounds the levels the filter document nests, objects and lists alike */
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

    /** @var list<string> Every cap, in the order the config file lists them */
    public const array NAMES = [
        self::MAX_BYTES,
        self::MAX_PARSE_DEPTH,
        self::MAX_DEPTH,
        self::MAX_NODES,
        self::MAX_IN_ITEMS,
        self::MAX_ORDER_KEYS,
        self::MAX_AGGREGATES,
        self::MAX_OFFSET,
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
     * @return self
     */
    public static function fromConfig(): self
    {
        $limits = [];

        foreach (self::NAMES as $cap) {
            $limits[$cap] = QuerySettings::costCap($cap);
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
