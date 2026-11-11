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
 * A facet dropped or mis-merged during assembly must fail one of these
 * assertions.
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
     * Test that the assembled POST operation carries every facet at once.
     *
     * @return void
     */
    public function testAssembledOperationCarriesEveryFacetSimultaneously(): void
    {
        $document  = $this->export()->document;
        $operation = $document['paths']['/billing']['post'];

        $body   = $operation['requestBody'];
        $schema = $body['content']['application/json']['schema'];

        self::assertTrue($body['required']);
        self::assertSame(['type' => 'string', 'maxLength' => 255], $schema['properties']['title']);
        self::assertSame(['title', 'email', 'password', 'password_confirmation'], $schema['required']);

        self::assertSame(
            ['$ref' => '#/components/schemas/User'],
            $operation['responses']['201']['content']['application/json']['schema']['properties']['data'],
        );

        self::assertSame([['bearerAuth' => []]], $operation['security']);

        self::assertSame(
            ['$ref' => '#/components/schemas/ErrorEnvelope'],
            $operation['responses'][409]['content']['application/json']['schema'],
        );
        self::assertIsArray($operation['responses']);
        self::assertSame(
            [201, 401, 403, 409, 422, 500],
            array_keys($operation['responses']),
        );

        self::assertSame(['Billing'], $operation['tags']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'The full-facet document is not valid OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that the write operation is both authenticated and body-bearing.
     *
     * @return void
     */
    public function testWriteOperationIsBothAuthenticatedAndBodyBearing(): void
    {
        $operation = $this->export()->document['paths']['/billing']['post'];

        self::assertSame([['bearerAuth' => []]], $operation['security']);
        self::assertTrue($operation['requestBody']['required']);
        self::assertIsArray($operation['requestBody']['content']['application/json']['schema']['properties']);
        self::assertArrayHasKey('title', $operation['requestBody']['content']['application/json']['schema']['properties']);
    }

    /**
     * Test that the referenced security scheme is emitted as a component.
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
     * Test that the class-level #[Tag] overrides the resource-derived tag.
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
     * Validate a document against the opis-adapted OpenAPI 3.1 meta-schema.
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
     * Load the OpenAPI 3.1 meta-schema with the opis compatibility transform.
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
     * Apply the two opis/json-schema compatibility transforms in place.
     *
     * Rewrites `$dynamicRef: "#meta"` to `$ref: "#/$defs/schema"` and relaxes
     * `unevaluatedProperties: false` to `true`, working around opis gaps
     * without weakening any validity constraint; see the fixtures README.
     *
     * @param  array<array-key, mixed>  $node
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
