<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Config-backed adapter for the MetadataCatalogue port.
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
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader  $surfaceReader
     */
    public function __construct(

        /** Registry of filter operator tokens (incl. app additions) */
        private OperatorRegistry $registry,

        /** Reader that resolves the error catalogue metadata */
        private ErrorCatalogueReader $errorReader,

        /** Discovery of attribute-bound resources outside the config map */
        private ResourceDiscovery $discovery,

        /** Reader that resolves the query surface each resource declares */
        private QuerySurfaceReader $surfaceReader,
    ) {}

    /**
     * Return the full resource map (model class -> resource class).
     *
     * Discovery runs here because the boot-time merge is skipped while the
     * config cache is built.
     *
     * @return array<class-string, class-string>
     */
    #[\Override]
    public function getResourceMap(): array
    {
        return ResourceSettings::bindings() + $this->discovery->discover();
    }

    /**
     * Return the registered filter operator tokens.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function getOperatorTokens(): array
    {
        return $this->registry->tokens();
    }

    /**
     * Return the registered filter operator tokens a column declaring the
     * capability answers.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @return array<int, string>
     */
    #[\Override]
    public function getOperatorsFor(Capability $capability): array
    {
        return $this->registry->tokensFor($capability);
    }

    /**
     * Return the structural filter operators the filter engine dispatches.
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

    /**
     * Return one query surface descriptor per registered resource.
     *
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    #[\Override]
    public function getQuerySurfaces(): array
    {
        return $this->surfaceReader->read($this->getResourceMap(), $this->registry);
    }

    /**
     * Return every bound one request is held to, keyed by refusal reason.
     *
     * @return array<string, int>
     */
    #[\Override]
    public function getQueryLimits(): array
    {
        $limits = QueryCostLimits::fromConfig();
        $caps   = [];

        foreach (QueryCostLimits::NAMES as $cap) {
            $caps[$cap] = $limits->limit($cap);
        }

        $caps[QueryParameterValidator::MAX_LIMIT] = $this->getPageSize(null)->ceiling;

        return $caps;
    }

    /**
     * Return the page size bounds the given resource is held to.
     *
     * @param  string|null  $resourceClass
     * @return \SineMacula\ApiToolkit\Query\PageSize
     *
     * @throws \LogicException
     */
    #[\Override]
    public function getPageSize(?string $resourceClass): PageSize
    {
        return PageSize::for($resourceClass);
    }

    /**
     * Return the bounds a free-text search term is held to.
     *
     * @return array<string, int>
     */
    #[\Override]
    public function getSearchBounds(): array
    {
        return [
            SearchTerm::MIN_WORD_LENGTH_KEY => SearchTerm::minimumWordLength(),
            SearchTerm::MAX_LENGTH_KEY      => SearchTerm::maximumLength(),
            SearchTerm::MAX_WORDS_KEY       => SearchTerm::maximumWords(),
        ];
    }
}
