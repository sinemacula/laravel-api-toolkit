<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Resolves resource metadata without static calls on the resource classes.
 *
 * @phpstan-import-type AggregateLoad from \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ResourceMetadataProvider
{
    /**
     * Get the resource type identifier for the given resource class.
     *
     * @param  string  $resourceClass
     * @return string
     */
    public function getResourceType(string $resourceClass): string;

    /**
     * Resolve the requested fields for the resource class, or its defaults.
     *
     * @param  string  $resourceClass
     * @return array<int, string>
     */
    public function resolveFields(string $resourceClass): array;

    /**
     * Get all non-metric field keys for the given resource class.
     *
     * @param  string  $resourceClass
     * @return array<int, string>
     */
    public function getAllFields(string $resourceClass): array;

    /**
     * Build the eager-load map for the given resource class and fields.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
    public function eagerLoadMapFor(string $resourceClass, array $fields): array;

    /**
     * Build the eager-load counts map for the given resource class and
     * requested aliases.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>|null  $requestedAliases
     * @return array<int|string, mixed>
     */
    public function eagerLoadCountsFor(string $resourceClass, ?array $requestedAliases = null): array;

    /**
     * Build the eager-load sums entry list for the given resource class and
     * requested relation-column map.
     *
     * @param  string  $resourceClass
     * @param  array<string, mixed>|null  $requestedSums
     * @return list<AggregateLoad>
     */
    public function eagerLoadSumsFor(string $resourceClass, ?array $requestedSums = null): array;

    /**
     * Build the eager-load averages entry list for the given resource class and
     * requested relation-column map.
     *
     * @param  string  $resourceClass
     * @param  array<string, mixed>|null  $requestedAverages
     * @return list<AggregateLoad>
     */
    public function eagerLoadAveragesFor(string $resourceClass, ?array $requestedAverages = null): array;
}
