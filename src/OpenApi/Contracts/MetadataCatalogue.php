<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Exposes the toolkit's metadata surfaces to the OpenAPI exporter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface MetadataCatalogue
{
    /**
     * Return the registered resource map (model class -> resource class).
     *
     * @return array<class-string, class-string>
     */
    public function getResourceMap(): array;

    /**
     * Return the registered filter operator tokens.
     *
     * @return array<int, string>
     */
    public function getOperatorTokens(): array;

    /**
     * Return the structural filter operators applied outside the registry.
     *
     * @return array<int, string>
     */
    public function getStructuralOperators(): array;

    /**
     * Return one error descriptor per defined error code.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    public function getErrorCatalogue(): array;

    /**
     * Return one query surface descriptor per registered resource.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>
     */
    public function getQuerySurfaces(): array;

    /**
     * Return the bounds a single query is held to, keyed by bound name.
     *
     * A bound resolving to zero is disabled.
     *
     * @return array<string, int>
     */
    public function getQueryLimits(): array;

    /**
     * Return the bounds a free-text search term is held to, keyed by bound
     * name.
     *
     * @return array<string, int>
     */
    public function getSearchBounds(): array;
}
