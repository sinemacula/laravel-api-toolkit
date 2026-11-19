<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Builder\DefinedSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\EnumSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler;
use SineMacula\ApiToolkit\OpenApi\Docs\OperationDescriptions;
use SineMacula\ApiToolkit\OpenApi\Docs\TagList;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRouteCollector;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
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
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry  $definedSchemas
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\DefinedSchemaBuilder  $definedSchemaBuilder
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder  $errorExamples
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRouteCollector  $routes
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\OperationDescriptions  $descriptions
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\TagList  $tags
     */
    public function __construct(

        /** The builder for resource component schemas. */
        private ResourceSchemaBuilder $schemaBuilder,

        /** The builder for reusable query parameter definitions. */
        private QueryParameterBuilder $parameterBuilder,

        /** The builder of the shared error-envelope schema. */
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

        /** The registry collecting DefinesSchema classes referenced by a $ref. */
        private DefinedSchemaRegistry $definedSchemas,

        /** The builder for referenced DefinesSchema component schemas. */
        private DefinedSchemaBuilder $definedSchemaBuilder,

        /** The builder of the error examples the paths reference. */
        private ErrorExampleBuilder $errorExamples,

        /** The collector of every exported audience's documentable routes. */
        private DocumentedRouteCollector $routes,

        /** The resolver of the operation descriptions. */
        private OperationDescriptions $descriptions,

        /** The builder of the top-level tags list. */
        private TagList $tags,
    ) {
        $this->envelopeBuilder = new EnvelopeBuilder;
        $this->reachability    = new ReachableSchemaResolver;
        $this->audiences       = new AudienceConfiguration;
        $this->schemaObjects   = new SchemaObjectNormaliser;
    }

    /**
     * Assemble the OpenAPI 3.1 document for the given audience.
     *
     * The description and tag files of every exported audience are audited, so
     * a file describing nothing fails every export.
     *
     * @param  string|null  $audience
     * @return array<string, mixed>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\TagConfigurationException
     */
    public function assemble(?string $audience = null): array
    {
        $this->enums->reset();
        $this->definedSchemas->reset();

        $audience = $audience === null || $audience === '' ? $this->audiences->defaultAudience() : $audience;
        $routes   = $this->routes->collect($this->audiences->exportedAudiences($audience));
        $servers  = $this->audiences->serversFor($audience);
        $paths    = $this->pathBuilder->build($routes->in($audience), $this->descriptions->for($audience, $routes), $audience, $servers);

        $document = [
            'openapi' => self::OPENAPI_VERSION,
            'info'    => $this->buildInfo($audience),
        ];

        if ($servers !== []) {
            $document['servers'] = $servers;
        }

        $document['paths']      = $paths === [] ? (object) [] : $paths;
        $document['components'] = $this->buildComponents($paths);

        $tags = $this->tags->for($audience, $routes);

        if ($tags !== null) {
            $document['tags'] = $tags;
        }

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
     * Build the components block, pruning the schemas, examples and security
     * schemes to those the paths reach.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $paths
     * @return array<string, mixed>
     */
    private function buildComponents(array $paths): array
    {
        $components = [
            'schemas'    => $this->buildSchemas($paths),
            'parameters' => $this->parameterBuilder->build(),
        ];

        $examples = $this->errorExamples->build($paths);

        if ($examples !== []) {
            $components['examples'] = $examples;
        }

        $components['headers'] = $this->envelopeBuilder->buildHeaders();

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
     * @param  array<string, array<string, array<string, mixed>>>  $paths
     * @return array<string, array<string, mixed>>
     */
    private function buildSecuritySchemes(array $paths): array
    {
        $schemes = [];

        foreach ($this->referencedSchemeNames($paths) as $name) {
            $schemes[$name] = $this->security->definitionFor($name);
        }

        return $schemes;
    }

    /**
     * Collect the distinct security scheme names referenced across every
     * operation's security requirements.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $paths
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
     * @param  array<string, array<string, array<string, mixed>>>  $paths
     * @return iterable<array<string, mixed>>
     */
    private function operations(array $paths): iterable
    {
        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                yield $operation;
            }
        }
    }

    /**
     * List the security scheme names named by a single operation's security
     * requirements.
     *
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function schemeNamesIn(array $operation): array
    {
        $names = [];

        foreach ((array) ($operation['security'] ?? []) as $requirement) {
            $names = array_merge($names, array_map('strval', array_keys((array) $requirement)));
        }

        return $names;
    }

    /**
     * Build the schemas block, reduced to those reachable from the paths.
     *
     * Resource schemas are built first so their enum references are registered.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $paths
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

        $definedSchemas = $this->definedSchemaBuilder->build($this->definedSchemas->classes(), $this->enums->classes(), $reserved);

        $schemas = array_replace(
            $resourceSchemas,
            $envelopeSchemas,
            $definedSchemas,
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
