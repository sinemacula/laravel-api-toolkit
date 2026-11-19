<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use SineMacula\ApiToolkit\Enums\AggregateFunction;

/**
 * Typed representation of a single compiled relation aggregate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CompiledAggregateDefinition
{
    /** @var string The prefix of the attribute the aggregate is loaded under */
    public const string ATTRIBUTE_PREFIX = 'aggregate_';

    /**
     * Create a new compiled aggregate definition.
     *
     * @param  string  $presentKey
     * @param  string  $relation
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string|null  $column
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null  $constraint
     * @param  bool  $isDefault
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @param  array<int, callable(mixed): bool>  $callerGuards
     * @param  \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema|null  $openApi
     * @param  string|null  $unindexedReason
     */
    public function __construct(

        /** The key used in the request and the response */
        public string $presentKey,

        /** The Eloquent relation to aggregate */
        public string $relation,

        /** The SQL function the aggregate applies */
        public AggregateFunction $function,

        /** The related column to aggregate, or null for count and exists */
        public ?string $column,

        /** Optional query constraint for the aggregate */
        public ?\Closure $constraint,

        /** Whether this aggregate is included by default */
        public bool $isDefault,

        /** Row guard closures that control visibility */
        public array $guards,

        /** Value transformer closures */
        public array $transformers = [],

        /** Caller guard closures that control visibility */
        public array $callerGuards = [],

        /** Declared OpenAPI contract for this aggregate; read by the exporter */
        public ?OpenApiFieldSchema $openApi = null,

        /** Why the aggregate's order may run unindexed; null when not sortable */
        public ?string $unindexedReason = null,
    ) {}

    /**
     * Return the attribute the aggregate is selected and loaded under.
     *
     * @return string
     */
    public function attribute(): string
    {
        return self::ATTRIBUTE_PREFIX . $this->presentKey;
    }
}
