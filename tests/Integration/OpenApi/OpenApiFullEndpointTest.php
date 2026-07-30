<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\BillingController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Full-facet interaction test for a single assembled endpoint.
 *
 * Registers one realistic write endpoint - an authorized controller carrying a
 * class-level #[Tag], a store action naming a rules source through
 * #[RequestSchema] and documenting a ConflictException throw, mapped to a
 * registered resource, and routed under an auth guard - then exports the
 * document and proves the assembled POST operation carries every facet at once:
 * a required requestBody translated from the rules source, the 201 created
 * response, the per-operation security requirement, the 409 error alongside the
 * baseline 401/403/422/500 set, the custom tag, and whole-document validity
 * against the OpenAPI 3.1 meta-schema. A facet dropped or mis-merged during
 * assembly must fail one of these assertions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(OpenApiAssembler::class)]
#[CoversClass(PathBuilder::class)]
final class OpenApiFullEndpointTest extends TestCase
{
    /** @var string The identifier under which the OpenAPI 3.1 meta-schema is registered. */
    private const string META_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    /** @var string The identifier of the JSON Schema 2020-12 dialect document. */
    private const string DIALECT_ID = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->registerResourceMap();
        $this->configureGuards();
        $this->registerBillingRoute();
    }

    /**
     * Test that the assembled POST operation carries every facet at once - a
     * required translated requestBody, the 201 created response, per-operation
     * security, the 409 plus baseline error set, and the custom tag - while the
     * whole document validates against the OpenAPI 3.1 meta-schema.
     *
     * @return void
     */
    public function testAssembledOperationCarriesEveryFacetSimultaneously(): void
    {
        $document  = $this->export()->document;
        $operation = $document['paths']['/billing']['post'];

        // Facet 1: a required requestBody translated from the rules source,
        // with the concrete title fragment and required list the translator
        // emits.
        $body   = $operation['requestBody'];
        $schema = $body['content']['application/json']['schema'];

        self::assertTrue($body['required']);
        self::assertSame(['type' => 'string', 'maxLength' => 255], $schema['properties']['title']);
        self::assertSame(['title', 'email', 'password'], $schema['required']);

        // Facet 2: the 201 created response wrapping the mapped resource.
        self::assertSame(
            ['$ref' => '#/components/schemas/User'],
            $operation['responses']['201']['content']['application/json']['schema']['properties']['data'],
        );

        // Facet 3: the per-operation security requirement for the auth guard.
        self::assertSame([['bearerAuth' => []]], $operation['security']);

        // Facet 4: the documented 409 error alongside the baseline error set.
        self::assertSame(
            ['$ref' => '#/components/schemas/ErrorEnvelope'],
            $operation['responses'][409]['content']['application/json']['schema'],
        );
        self::assertSame(
            [201, 401, 403, 409, 422, 500],
            array_keys($operation['responses']),
        );

        // Facet 5: the custom class-level tag survives to the operation.
        self::assertSame(['Billing'], $operation['tags']);

        // Facet 6: the whole assembled document is valid OpenAPI 3.1.
        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'The full-facet document is not valid OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that the same write operation is simultaneously authenticated and
     * body-bearing, proving the security and requestBody facets coexist on one
     * operation rather than one displacing the other.
     *
     * @return void
     */
    public function testWriteOperationIsBothAuthenticatedAndBodyBearing(): void
    {
        $operation = $this->export()->document['paths']['/billing']['post'];

        self::assertSame([['bearerAuth' => []]], $operation['security']);
        self::assertTrue($operation['requestBody']['required']);
        self::assertArrayHasKey('title', $operation['requestBody']['content']['application/json']['schema']['properties']);
    }

    /**
     * Test that the referenced security scheme is emitted as a component
     * definition, so the operation's requirement is not a dangling reference.
     *
     * @return void
     */
    public function testSecuritySchemeComponentBacksTheOperationRequirement(): void
    {
        $document = $this->export()->document;

        self::assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $document['components']['securitySchemes']['bearerAuth'],
        );
    }

    /**
     * Test that the custom class-level #[Tag] overrides the resource-derived
     * tag and survives all the way to the exported operation.
     *
     * @return void
     */
    public function testCustomTagSurvivesToExportedDocument(): void
    {
        $operation = $this->export()->document['paths']['/billing']['post'];

        self::assertSame(['Billing'], $operation['tags']);
    }

    /**
     * Configure the auth guards the security derivation reads drivers from.
     *
     * @return void
     */
    private function configureGuards(): void
    {
        $config = $this->getConfig();

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
    }

    /**
     * Register the authenticated, body-bearing billing write route.
     *
     * @return void
     */
    private function registerBillingRoute(): void
    {
        $this->router()->post('billing', [BillingController::class, 'store'])->middleware('auth:api');
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(): ExportResult
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->makeApplication()->make(ExportOpenApiComponents::class);

        return $exporter->export();
    }

    /**
     * Validate a document array against the bundled (opis-adapted) OpenAPI 3.1
     * meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateAgainstMetaSchema(array $document): ValidationResult
    {
        return $this->validator()->validate(Helper::toJSON($document), self::META_SCHEMA_ID);
    }

    /**
     * Build a validator with the dialect and meta-schema registered.
     *
     * @return \Opis\JsonSchema\Validator
     */
    private function validator(): Validator
    {
        $validator = new Validator;
        $resolver  = $validator->resolver();

        assert($resolver instanceof SchemaResolver);

        $resolver->registerRaw($this->dialectSchema(), self::DIALECT_ID);
        $resolver->registerRaw($this->metaSchema(), self::META_SCHEMA_ID);

        return $validator;
    }

    /**
     * Load the OpenAPI 3.1 meta-schema, applying the documented opis
     * compatibility transform.
     *
     * @return string
     */
    private function metaSchema(): string
    {
        /** @var array<string, mixed> $schema */
        $schema = json_decode($this->fixture('openapi-3.1-schema.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->adaptForOpis($schema);

        return json_encode($schema, JSON_THROW_ON_ERROR);
    }

    /**
     * Load the JSON Schema 2020-12 dialect document.
     *
     * @return string
     */
    private function dialectSchema(): string
    {
        return $this->fixture('json-schema-2020-12.json');
    }

    /**
     * Apply the two opis/json-schema compatibility transforms in place: rewrite
     * the Schema Object's `$dynamicRef: "#meta"` to the equivalent static
     * `$ref: "#/$defs/schema"`, and relax every `unevaluatedProperties: false`
     * to `true`. Both work around opis annotation/reference gaps without
     * weakening any validity-bearing constraint; see the fixtures README.
     *
     * @param  array<string, mixed>  $node
     * @return void
     */
    private function adaptForOpis(array &$node): void
    {
        if (($node['$dynamicRef'] ?? null) === '#meta') {
            unset($node['$dynamicRef']);
            $node['$ref'] = '#/$defs/schema';
        }

        if (($node['unevaluatedProperties'] ?? null) === false) {
            $node['unevaluatedProperties'] = true;
        }

        foreach ($node as &$value) {
            if (!is_array($value)) {
                continue;
            }

            $this->adaptForOpis($value);
        }
    }

    /**
     * Read a bundled fixture file by name.
     *
     * @param  string  $name
     * @return string
     */
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/OpenApi/' . $name);

        assert(is_string($contents));

        return $contents;
    }

    /**
     * Format a validation result's errors for a failure message.
     *
     * @param  \Opis\JsonSchema\ValidationResult  $result
     * @return string
     */
    private function formatErrors(ValidationResult $result): string
    {
        $error = $result->error();

        if ($error === null) {
            return '(no error)';
        }

        return json_encode((new ErrorFormatter)->format($error), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Register the fixture resource map on the config repository.
     *
     * @return void
     */
    private function registerResourceMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
        ]);
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        return $this->makeApplication()->make(Router::class);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->makeApplication()->make('config');
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function makeApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
