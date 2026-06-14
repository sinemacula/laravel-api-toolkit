<?php

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;

/**
 * Config-backed adapter for the MetadataCatalogue port.
 *
 * Reads the registered resource map from the toolkit config, operator tokens
 * from the bound OperatorRegistry (so application-registered additions are
 * reflected), and delegates error-catalogue resolution to ErrorCatalogueReader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ConfigMetadataCatalogue implements MetadataCatalogue
{
    /** @var array<int, string> The four structural filter operators */
    private const array STRUCTURAL_OPERATORS = ['$and', '$or', '$has', '$hasnt'];

    /**
     * Create a new config metadata catalogue.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader  $errorReader
     */
    public function __construct(
        private readonly OperatorRegistry $registry,
        private readonly ErrorCatalogueReader $errorReader,
    ) {}

    /**
     * Return the registered resource map (model class → resource class).
     *
     * @return array<class-string, class-string>
     */
    #[\Override]
    public function getResourceMap(): array
    {
        $resourceMap = Config::get('api-toolkit.resources.resource_map');

        if (!is_array($resourceMap)) {
            return [];
        }

        /** @var array<class-string, class-string> $resourceMap */
        return $resourceMap;
    }

    /**
     * Return the registered filter operator tokens from the OperatorRegistry.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function getOperatorTokens(): array
    {
        return $this->registry->tokens();
    }

    /**
     * Return the four structural filter operators.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function getStructuralOperators(): array
    {
        return self::STRUCTURAL_OPERATORS;
    }

    /**
     * Return one error descriptor per defined error code.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>
     */
    #[\Override]
    public function getErrorCatalogue(): array
    {
        return $this->errorReader->read();
    }
}
