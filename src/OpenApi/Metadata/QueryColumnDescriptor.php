<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Immutable descriptor for what one column of a resource may be queried by.
 *
 * Carries the property the response presents the column under, the column name
 * a filter, an order, or a search has to name it by, and what each of those
 * three answers: the capability the column is filterable with together with the
 * operator tokens it still answers, whether it may be ordered by and any reason
 * the resource recorded for leaving that order unindexed, and the strategy a
 * free-text search matches it by. A part the column does not answer reads as
 * null, so a descriptor exists only for a column answering at least one of
 * them. The operators are carried rather than derived from the capability so a
 * token the operator registry no longer binds is absent from both.
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
