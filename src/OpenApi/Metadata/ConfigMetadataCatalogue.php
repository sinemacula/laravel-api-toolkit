<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
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
final readonly class ConfigMetadataCatalogue implements MetadataCatalogue
{
    /**
     * Create a new config metadata catalogue.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader  $errorReader
     * @param  \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery  $discovery
     */
    public function __construct(

        /** Registry of filter operator tokens (incl. app additions) */
        private OperatorRegistry $registry,

        /** Reader that resolves the error catalogue metadata */
        private ErrorCatalogueReader $errorReader,

        /** Discovery of attribute-bound resources outside the config map */
        private ResourceDiscovery $discovery,
    ) {}

    /**
     * Return the full resource map (model class → resource class).
     *
     * Unions the explicitly configured resource map with the resources found by
     * attribute discovery, resolved at call time. Configured entries keep their
     * existing order and always win on a model collision, so the static map
     * stays the canonical tiebreak; discovered bindings for models absent from
     * the config are appended. Discovery is resolved directly rather than read
     * from the merged config because the boot-time merge is skipped under a
     * cached config, so the full set is returned regardless of cache state.
     *
     * @return array<class-string, class-string>
     */
    #[\Override]
    public function getResourceMap(): array
    {
        $configured = Config::get('api-toolkit.resources.resource_map');
        $configured = is_array($configured) ? $configured : [];

        /** @var array<class-string, class-string> $configured */
        return $configured + $this->discovery->discover();
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
     * Return the structural filter operators, read from the filter engine so
     * the documented grammar cannot drift from what the engine dispatches.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function getStructuralOperators(): array
    {
        return FilterApplier::STRUCTURAL_OPERATORS;
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
