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
     * Get the fields every response of the given resource class includes.
     *
     * @param  string  $resourceClass
     * @return array<int, string>
     */
    public function getFixedFields(string $resourceClass): array;

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
     * Build the eager-load aggregate entry list for the given resource class
     * and requested aggregate keys.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>|null  $requested
     * @return list<AggregateLoad>
     */
    public function eagerLoadAggregatesFor(string $resourceClass, ?array $requested = null): array;
}
