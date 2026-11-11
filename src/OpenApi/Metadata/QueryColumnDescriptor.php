<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Immutable descriptor for what one column of a resource may be queried by.
 *
 * Operators are carried, not derived, so a token the registry no longer binds
 * is absent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryColumnDescriptor
{
    /**
     * Create a new query column descriptor.
     *
     * @param  string  $property
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Enums\Capability|null  $capability
     * @param  array<int, string>  $operators
     * @param  bool  $sortable
     * @param  string|null  $unindexedReason
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy|null  $strategy
     */
    public function __construct(

        /** The property the response presents the column under */
        public string $property,

        /** The column name a filter, an order, or a search names */
        public string $column,

        /** The capability the column was declared filterable with */
        public ?Capability $capability = null,

        /** The operator tokens the column answers a filter with */
        public array $operators = [],

        /** Whether the column was declared sortable */
        public bool $sortable = false,

        /** Reason recorded for leaving the column's order unindexed */
        public ?string $unindexedReason = null,

        /** The strategy a free-text search matches the column by */
        public ?SearchStrategy $strategy = null,
    ) {}
}
