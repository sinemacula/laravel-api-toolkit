<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;

/**
 * Assembles the complete OpenAPI 3.1 components document.
 *
 * Composes the three builders into a single components-only document: one
 * schema per resource plus the shared Error envelope under components.schemas,
 * the shared query-parameter vocabulary under components.parameters, and one
 * response per error code under components.responses. The package emits
 * reusable components only and never declares path operations, so the document
 * carries an empty paths object that the consuming application completes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OpenApiAssembler
{
    /** The emitted OpenAPI specification version */
    private const string OPENAPI_VERSION = '3.1.0';

    /** The default document title */
    private const string INFO_TITLE = 'API Components';

    /** The default document version */
    private const string INFO_VERSION = '1.0.0';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder  $schemaBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder  $parameterBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder  $responseBuilder
     */
    public function __construct(
        private readonly ResourceSchemaBuilder $schemaBuilder,
        private readonly QueryParameterBuilder $parameterBuilder,
        private readonly ErrorResponseBuilder $responseBuilder,
    ) {}

    /**
     * Assemble the full OpenAPI 3.1 components document.
     *
     * @return array<string, mixed>
     */
    public function assemble(): array
    {
        return [
            'openapi'    => self::OPENAPI_VERSION,
            'info'       => $this->buildInfo(),
            'paths'      => (object) [],
            'components' => $this->buildComponents(),
        ];
    }

    /**
     * Build the minimal info block.
     *
     * @return array<string, mixed>
     */
    private function buildInfo(): array
    {
        return [
            'title'   => self::INFO_TITLE,
            'version' => self::INFO_VERSION,
        ];
    }

    /**
     * Build the components block from the three builders.
     *
     * @return array<string, mixed>
     */
    private function buildComponents(): array
    {
        return [
            'schemas'    => $this->buildSchemas(),
            'parameters' => $this->parameterBuilder->build(),
            'responses'  => $this->responseBuilder->build(),
        ];
    }

    /**
     * Build the schemas block: one schema per resource plus the shared error
     * envelope referenced by every error response.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSchemas(): array
    {
        return array_merge(
            $this->schemaBuilder->build(),
            [ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME => $this->responseBuilder->buildEnvelopeSchema()],
        );
    }
}
