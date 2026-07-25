<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;

/**
 * Assembles a per-audience OpenAPI 3.1 document.
 *
 * Composes the builders into one document for a target audience: the path
 * builder walks the route table for the audience and posture, and its
 * operations drive which resource schemas survive. The components block carries
 * the shared query-parameter vocabulary, error responses, and total-count
 * header globally, but its schemas are filtered to only those reachable from
 * the audience's paths (via the transitive reference closure) plus the
 * always-shared pagination and error-envelope schemas, so an internal-only
 * resource never leaks into another audience's document. When no route is
 * documented the paths object is emitted empty, keeping the document
 * schema-valid.
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

    /** @var \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder */
    private readonly EnvelopeBuilder $envelopeBuilder;

    /** @var \SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver */
    private readonly ReachableSchemaResolver $reachability;

    /** @var \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration */
    private readonly AudienceConfiguration $audiences;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder  $schemaBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder  $parameterBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder  $responseBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder  $pathBuilder
     */
    public function __construct(

        /** The builder for resource component schemas. */
        private readonly ResourceSchemaBuilder $schemaBuilder,

        /** The builder for reusable query parameter definitions. */
        private readonly QueryParameterBuilder $parameterBuilder,

        /** The builder for shared error response definitions. */
        private readonly ErrorResponseBuilder $responseBuilder,

        /** The builder for the per-audience paths object. */
        private readonly PathBuilder $pathBuilder,
    ) {
        $this->envelopeBuilder = new EnvelopeBuilder;
        $this->reachability    = new ReachableSchemaResolver;
        $this->audiences       = new AudienceConfiguration;
    }

    /**
     * Assemble the OpenAPI 3.1 document for the given audience, defaulting to
     * the configured default audience when none is named.
     *
     * @param  string|null  $audience
     * @return array<string, mixed>
     */
    public function assemble(?string $audience = null): array
    {
        $audience = $audience === null || $audience === '' ? $this->audiences->defaultAudience() : $audience;
        $posture  = $this->audiences->postureFor($audience);
        $paths    = $this->pathBuilder->build($audience, $posture);

        return [
            'openapi'    => self::OPENAPI_VERSION,
            'info'       => $this->buildInfo(),
            'paths'      => $paths === [] ? (object) [] : $paths,
            'components' => $this->buildComponents($paths),
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
     * Build the components block, filtering the schemas to those the audience's
     * paths reach while keeping the parameters, responses, and headers global.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, mixed>
     */
    private function buildComponents(array $paths): array
    {
        return [
            'schemas'    => $this->buildSchemas($paths),
            'parameters' => $this->parameterBuilder->build(),
            'responses'  => $this->responseBuilder->build(),
            'headers'    => $this->envelopeBuilder->buildHeaders(),
        ];
    }

    /**
     * Build the schemas block and reduce it to the schemas reachable from the
     * audience's paths plus the always-shared pagination and error-envelope
     * schemas.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, array<string, mixed>>
     */
    private function buildSchemas(array $paths): array
    {
        $schemas = array_merge(
            $this->schemaBuilder->build(),
            [ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME => $this->responseBuilder->buildEnvelopeSchema()],
            $this->envelopeBuilder->buildSchemas(),
        );

        return $this->reachability->reachable($schemas, $paths, $this->sharedSchemaNames());
    }

    /**
     * List the schema names always retained regardless of path reachability:
     * the pagination meta/links (and cursor) schemas and the error envelope the
     * shared responses reference.
     *
     * @return list<string>
     */
    private function sharedSchemaNames(): array
    {
        return [
            ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME,
            ...array_keys($this->envelopeBuilder->buildSchemas()),
        ];
    }
}
