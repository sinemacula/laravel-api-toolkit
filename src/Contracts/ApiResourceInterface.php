<?php

namespace SineMacula\ApiToolkit\Contracts;

/**
 * API resource interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ApiResourceInterface
{
    /**
     * Get the resource type.
     *
     * @return string
     */
    public static function getResourceType(): string;

    /**
     * Get the default fields for this resource.
     *
     * @return array<int, string>
     */
    public static function getDefaultFields(): array;

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array;

    /**
     * Get all non-metric field keys for this resource.
     *
     * @return array<int, string>
     */
    public static function getAllFields(): array;

    /**
     * Resolve the active fields for this resource from the API query or
     * defaults.
     *
     * @return array<int, string>
     */
    public static function resolveFields(): array;

    /**
     * Build a with()-ready eager-load map for the provided fields.
     *
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
    public static function eagerLoadMapFor(array $fields): array;

    /**
     * Build a withCount-ready array for this resource.
     *
     * @param  array<int, string>|null  $requestedAliases
     * @return array<int|string, mixed>
     */
    public static function eagerLoadCountsFor(?array $requestedAliases = null): array;

    /**
     * Resolve the resource to an array.
     *
     * @param  mixed  $request
     * @return array<string, mixed>
     */
    public function resolve(mixed $request = null): array;

    /**
     * Override the default fields and any requested fields.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    public function withFields(?array $fields = null): static;

    /**
     * Remove certain fields from the response.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    public function withoutFields(?array $fields = null): static;

    /**
     * Force the response to include all available fields.
     *
     * @return static
     */
    public function withAll(): static;
}
