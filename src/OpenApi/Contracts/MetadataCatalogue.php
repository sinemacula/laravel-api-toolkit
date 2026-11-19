<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\Query\PageSize;

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
     * Return the registered filter operator tokens a column declaring the
     * capability answers.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @return array<int, string>
     */
    public function getOperatorsFor(Capability $capability): array;

    /**
     * Return the shape of the value the registered token's handler accepts, or
     * null when it declares none.
     *
     * @param  string  $token
     * @return \SineMacula\ApiToolkit\Enums\FilterValueShape|null
     */
    public function getOperatorShape(string $token): ?FilterValueShape;

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
     * Return the query surface the resource declares over the model, or null
     * unless they are an API resource and a model.
     *
     * @param  string|null  $resourceClass
     * @param  string|null  $modelClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor|null
     */
    public function getQuerySurface(?string $resourceClass, ?string $modelClass): ?QuerySurfaceDescriptor;

    /**
     * Return the bounds a single query is held to, keyed by bound name.
     *
     * A bound resolving to zero is disabled.
     *
     * @return array<string, int>
     */
    public function getQueryLimits(): array;

    /**
     * Return the page size bounds the given resource is held to, or the
     * configured ones for null.
     *
     * @param  string|null  $resourceClass
     * @return \SineMacula\ApiToolkit\Query\PageSize
     */
    public function getPageSize(?string $resourceClass): PageSize;

    /**
     * Return the bounds a free-text search term is held to, keyed by bound
     * name.
     *
     * @return array<string, int>
     */
    public function getSearchBounds(): array;
}
