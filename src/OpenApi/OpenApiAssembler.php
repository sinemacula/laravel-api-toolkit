<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Builder\EnumSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\SchemaObjectNormaliser;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;

/**
 * Assembles a per-audience OpenAPI 3.1 document.
 *
 * Schemas are pruned to those the audience's paths reach, so an internal
 * resource never leaks into another audience.
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

    /** @var \SineMacula\ApiToolkit\OpenApi\Schema\SchemaObjectNormaliser */
    private SchemaObjectNormaliser $schemaObjects;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder  $schemaBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder  $parameterBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder  $responseBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder  $pathBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver  $security
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnumSchemaBuilder  $enumBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler  $manual
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

        /** The builder for referenced enum component schemas. */
        private EnumSchemaBuilder $enumBuilder,

        /** The registry collecting enum classes referenced by a $ref. */
        private EnumSchemaRegistry $enums,

        /** The assembler of the committed Markdown manual. */
        private DocManualAssembler $manual,
    ) {
        $this->envelopeBuilder = new EnvelopeBuilder;
        $this->reachability    = new ReachableSchemaResolver;
        $this->audiences       = new AudienceConfiguration;
        $this->schemaObjects   = new SchemaObjectNormaliser;
    }

    /**
     * Assemble the OpenAPI 3.1 document for the given audience.
     *
     * @param  string|null  $audience
     * @return array<string, mixed>
     */
    public function assemble(?string $audience = null): array
    {
        $this->enums->reset();

        $audience = $audience === null || $audience === '' ? $this->audiences->defaultAudience() : $audience;
        $posture  = $this->audiences->postureFor($audience);
        $paths    = $this->pathBuilder->build($audience, $posture);

        $document = [
            'openapi' => self::OPENAPI_VERSION,
            'info'    => $this->buildInfo($audience),
        ];

        $servers = $this->audiences->serversFor($audience);

        if ($servers !== []) {
            $document['servers'] = $servers;
        }

        $document['paths']      = $paths === [] ? (object) [] : $paths;
        $document['components'] = $this->buildComponents($paths);

        return $this->schemaObjects->normalise($document);
    }

    /**
     * Build the info block for the audience, injecting its Markdown manual.
     *
     * @param  string  $audience
     * @return array<string, mixed>
     */
    private function buildInfo(string $audience): array
    {
        $info = $this->audiences->infoFor($audience);

        $description = $this->composeDescription(
            is_string($info['description'] ?? null) ? $info['description'] : null,
            $this->manual->assemble($audience),
        );

        if ($description === '') {
            unset($info['description']);

            return $info;
        }

        $info['description'] = $description;

        return $info;
    }

    /**
     * Compose the info description from the configured description and the
     * assembled manual.
     *
     * @param  string|null  $configured
     * @param  string  $manual
     * @return string
     */
    private function composeDescription(?string $configured, string $manual): string
    {
        $parts = array_filter(
            [trim($configured ?? ''), trim($manual)],
            static fn (string $part): bool => $part !== '',
        );

        return implode("\n\n", $parts);
    }

    /**
     * Build the components block, pruning only the schemas by reachability.
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
     * Build the securitySchemes block from the scheme names the paths
     * reference.
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
     * Build the schemas block, reduced to those reachable from the paths.
     *
     * Resource schemas are built first so their enum references are registered.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, array<string, mixed>>
     */
    private function buildSchemas(array $paths): array
    {
        $resourceSchemas = $this->schemaBuilder->build();
        $envelopeSchemas = array_merge(
            [ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME => $this->responseBuilder->buildEnvelopeSchema()],
            $this->envelopeBuilder->buildSchemas(),
        );

        $reserved = $this->schemaBuilder->claims();
        $guard    = new SchemaNameCollisionGuard;

        foreach (array_keys($envelopeSchemas) as $name) {
            $guard->claim($reserved, $name, EnvelopeBuilder::class);
        }

        $schemas = array_merge(
            $resourceSchemas,
            $envelopeSchemas,
            $this->enumBuilder->build($this->enums->classes(), $reserved),
        );

        return $this->reachability->reachable($schemas, $paths, $this->sharedSchemaNames());
    }

    /**
     * List the schema names the shared responses reference, always retained.
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
