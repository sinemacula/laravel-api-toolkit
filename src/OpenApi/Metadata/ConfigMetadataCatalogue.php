<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Config-backed adapter for the MetadataCatalogue port.
 *
 * Reads the registered resource map from the toolkit config, operator tokens
 * from the bound OperatorRegistry (so application-registered additions are
 * reflected), and delegates error-catalogue resolution to ErrorCatalogueReader
 * and query-surface resolution to QuerySurfaceReader. The query and search
 * bounds are resolved through the same objects the request-time gates resolve
 * them through, so a documented bound is the bound a request is held to.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ConfigMetadataCatalogue implements MetadataCatalogue
{
    /** @var string The bound naming the shortest word a search term may carry */
    private const string MIN_WORD_LENGTH = 'min_word_length';

    /** @var string The bound naming the longest term a search may carry */
    private const string MAX_LENGTH = 'max_length';

    /** @var string The bound naming the most words a search term may carry */
    private const string MAX_WORDS = 'max_words';

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
        return $this->surfaceReader->read($this->getResourceMap());
    }

    /**
     * Return every structural cap the query cost limits resolve, keyed by cap
     * name and in the order the caps are declared.
     *
     * @return array<string, int>
     */
    #[\Override]
    public function getQueryLimits(): array
    {
        $limits = QueryCostLimits::fromConfig();
        $caps   = [];

        foreach (array_keys(QueryCostLimits::DEFAULTS) as $cap) {
            $caps[$cap] = $limits->limit($cap);
        }

        return $caps;
    }

    /**
     * Return the bounds a free-text search term is held to, read from the term
     * itself so the floor the shortest word is held at is the reported one.
     *
     * @return array<string, int>
     */
    #[\Override]
    public function getSearchBounds(): array
    {
        return [
            self::MIN_WORD_LENGTH => SearchTerm::minimumWordLength(),
            self::MAX_LENGTH      => SearchTerm::maximumLength(),
            self::MAX_WORDS       => SearchTerm::maximumWords(),
        ];
    }
}
