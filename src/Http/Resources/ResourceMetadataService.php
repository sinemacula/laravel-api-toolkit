<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;

/**
 * Default resource metadata provider.
 *
 * Delegates each metadata query to the corresponding static method on the
 * given resource class, maintaining behavioural equivalence with direct static
 * calls while enabling substitution via the container.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ResourceMetadataService implements ResourceMetadataProvider
{
    /**
     * Get the resource type identifier for the given resource class.
     *
     * @param  string  $resourceClass
     * @return string
     */
    #[\Override]
    public function getResourceType(string $resourceClass): string
    {
        return $resourceClass::getResourceType();
    }

    /**
     * Resolve the active fields for the given resource class.
     *
     * @param  string  $resourceClass
     * @return array<int, string>
     */
    #[\Override]
    public function resolveFields(string $resourceClass): array
    {
        return $resourceClass::resolveFields();
    }

    /**
     * Get all non-metric field keys for the given resource class.
     *
     * @param  string  $resourceClass
     * @return array<int, string>
     */
    #[\Override]
    public function getAllFields(string $resourceClass): array
    {
        return $resourceClass::getAllFields();
    }

    /**
     * Build the eager-load map for the given resource class and fields.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
    #[\Override]
    public function eagerLoadMapFor(string $resourceClass, array $fields): array
    {
        return $resourceClass::eagerLoadMapFor($fields);
    }

    /**
     * Build the eager-load counts map for the given resource class and
     * requested aliases.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>|null  $requestedAliases
     * @return array<int|string, mixed>
     */
    #[\Override]
    public function eagerLoadCountsFor(string $resourceClass, ?array $requestedAliases = null): array
    {
        return $resourceClass::eagerLoadCountsFor($requestedAliases);
    }
}
