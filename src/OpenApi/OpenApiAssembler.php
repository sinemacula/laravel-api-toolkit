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
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;

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
final readonly class OpenApiAssembler
{
    /** The emitted OpenAPI specification version */
    private const string OPENAPI_VERSION = '3.1.0';

    /** @var \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder */
    private EnvelopeBuilder $envelopeBuilder;

    /** @var \SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver */
    private ReachableSchemaResolver $reachability;

    /** @var \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration */
    private AudienceConfiguration $audiences;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder  $schemaBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder  $parameterBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder  $responseBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder  $pathBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver  $security
     */
    public function __construct(

        /** The builder for resource component schemas. */
        private ResourceSchemaBuilder $schemaBuilder,

        /** The builder for reusable query parameter definitions. */
        private QueryParameterBuilder $parameterBuilder,

        /** The builder for shared error response definitions. */
        private ErrorResponseBuilder $responseBuilder,

        /** The builder for the per-audience paths object. */
        private PathBuilder $pathBuilder,

        /** The resolver of referenced security scheme definitions. */
        private SecuritySchemeResolver $security,
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
            'info'       => $this->buildInfo($audience),
            'paths'      => $paths === [] ? (object) [] : $paths,
            'components' => $this->buildComponents($paths),
        ];
    }

    /**
     * Build the info block for the audience from the audience configuration.
     *
     * @param  string  $audience
     * @return array<string, mixed>
     */
    private function buildInfo(string $audience): array
    {
        return $this->audiences->infoFor($audience);
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
        $components = [
            'schemas'    => $this->buildSchemas($paths),
            'parameters' => $this->parameterBuilder->build(),
            'responses'  => $this->responseBuilder->build(),
            'headers'    => $this->envelopeBuilder->buildHeaders(),
        ];

        $securitySchemes = $this->buildSecuritySchemes($paths);

        if ($securitySchemes !== []) {
            $components['securitySchemes'] = $securitySchemes;
        }

        return $components;
    }

    /**
     * Build the securitySchemes block from the scheme names the assembled paths
     * reference, so the block carries exactly the referenced schemes with no
     * orphans and no omissions.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, array<string, mixed>>
     */
    private function buildSecuritySchemes(array $paths): array
    {
        $schemes = [];

        foreach ($this->referencedSchemeNames($paths) as $name) {

            $definition = $this->security->definitionFor($name);

            if ($definition === null) {
                continue;
            }

            $schemes[$name] = $definition;
        }

        return $schemes;
    }

    /**
     * Collect the distinct security scheme names referenced across every
     * operation's security requirements.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return list<string>
     */
    private function referencedSchemeNames(array $paths): array
    {
        $names = [];

        foreach ($this->operations($paths) as $operation) {
            foreach ($this->schemeNamesIn($operation) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Yield each operation object across the assembled paths.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return iterable<array<mixed, mixed>>
     */
    private function operations(array $paths): iterable
    {
        foreach ($paths as $operations) {
            foreach ($operations as $operation) {

                if (!is_array($operation)) {
                    continue;
                }

                yield $operation;
            }
        }
    }

    /**
     * List the security scheme names named by a single operation's security
     * requirements.
     *
     * @param  array<mixed, mixed>  $operation
     * @return list<string>
     */
    private function schemeNamesIn(array $operation): array
    {
        $names = [];

        foreach ((array) ($operation['security'] ?? []) as $requirement) {

            if (!is_array($requirement)) {
                continue;
            }

            $names = array_merge($names, array_map('strval', array_keys($requirement)));
        }

        return $names;
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
