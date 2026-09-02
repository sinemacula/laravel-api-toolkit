<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Per-resource compiled search plan.
 *
 * Holds the columns a resource declared searchable and the match shape each was
 * declared with, so the request path never re-reads a schema to answer what a
 * search may touch. One plan is built per resource class and memoised for the
 * life of the worker process, alongside the other schema-derived caches.
 *
 * A resource that declared nothing yields an empty plan rather than a plan over
 * every column: a search is served only where the schema asked for one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class SearchPlan
{
    /** @var array<string, self> */
    private static array $cache = [];

    /**
     * Constructor.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $columns
     * @return void
     */
    private function __construct(

        /** Declared searchable column names mapped to their match strategy */
        private readonly array $columns,
    ) {}

    /**
     * Build and cache the search plan for the given resource class.
     *
     * @param  string  $resourceClass
     * @return self
     */
    public static function for(string $resourceClass): self
    {
        return self::$cache[$resourceClass] ??= self::build(SchemaCompiler::compile($resourceClass));
    }

    /**
     * Build a search plan directly from a compiled schema.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return self
     */
    public static function build(CompiledSchema $schema): self
    {
        return new self($schema->getSearchableColumns());
    }

    /**
     * Clear the per-resource-class search plan cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Determine whether the resource declared no searchable column.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /**
     * Return every declared searchable column name.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        return array_keys($this->columns);
    }

    /**
     * Return the distinct strategies the plan declares, in declaration order.
     *
     * @return array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>
     */
    public function strategies(): array
    {
        $strategies = [];

        foreach ($this->columns as $strategy) {

            if (in_array($strategy, $strategies, true)) {
                continue;
            }

            $strategies[] = $strategy;
        }

        return $strategies;
    }

    /**
     * Return the columns declared with the given strategy.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return array<int, string>
     */
    public function columnsFor(SearchStrategy $strategy): array
    {
        return array_keys($this->columns, $strategy, true);
    }
}
