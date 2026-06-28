<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Contracts;

/**
 * Outbound port exposing the toolkit's metadata surfaces to the OpenAPI
 * emission context.
 *
 * Provides the registered resource map, the full filter operator vocabulary
 * (registered tokens plus structural operators), and the error catalogue (one
 * descriptor per defined error code with its HTTP status and title/detail
 * strings).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface MetadataCatalogue
{
    /**
     * Return the registered resource map (model class → resource class).
     *
     * The map is sourced from
     * `Config::get('api-toolkit.resources.resource_map')` and preserves
     * registry order.
     *
     * @return array<class-string, class-string>
     */
    public function getResourceMap(): array;

    /**
     * Return the registered filter operator tokens.
     *
     * Reads the bound OperatorRegistry so any application-registered additions
     * or overrides are reflected.
     *
     * @return array<int, string>
     */
    public function getOperatorTokens(): array;

    /**
     * Return the structural filter operators recognised by the filter applier.
     *
     * These four tokens are applied outside the OperatorRegistry and represent
     * the logical/relational layer of the filter grammar.
     *
     * @return array<int, string>
     */
    public function getStructuralOperators(): array;

    /**
     * Return one error descriptor per defined error code.
     *
     * Each descriptor carries the HTTP status resolved from the owning
     * ApiException subclass and the title/detail sourced from the package
     * language file.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    public function getErrorCatalogue(): array;
}
