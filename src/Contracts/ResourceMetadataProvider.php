<?php

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Resource metadata provider interface.
 *
 * Decouples the repository criteria layer from resource class statics,
 * allowing metadata to be resolved without direct static calls.
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
     * Resolve the active fields for the given resource class.
     *
     * Returns requested fields from the API query, or defaults if none
     * requested.
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
}
