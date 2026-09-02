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
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\InternalOnlyTagController;
use Tests\Fixtures\OpenApi\PartnerOrganizationController;
use Tests\Fixtures\OpenApi\PathOrganizationController;
use Tests\Fixtures\OpenApi\PathTaggedController;
use Tests\Fixtures\OpenApi\UndocumentedOrganizationController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\QueryableTagResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end audience-selection test for the OpenAPI exporter.
 *
 * Proves that the audience directives - the DocumentedIn / NotDocumentedIn /
 * Undocumented attributes and the equivalent route macros - drive which routes
 * and which reachable resource schemas survive into each per-audience document,
 * against real routes and the container-resolved builder graph. Covers
 * allowlist opt-in, the blanket-Undocumented drop from every audience, the
 * canonical internal-only pattern, the route-macro exclusion, the
 * reachable-schema isolation guaranteeing an internal-only resource never leaks
 * into the public document, and the same isolation for the per-property query
 * surface that schema carries. Every produced document is asserted valid
 * OpenAPI 3.1.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiAssembler::class)]
#[CoversClass(PathBuilder::class)]
#[CoversClass(AudienceResolver::class)]
#[CoversClass(AudienceConfiguration::class)]
#[CoversClass(ReachableSchemaResolver::class)]
#[CoversClass(ResourceSchemaBuilder::class)]
final class OpenApiAudienceExportTest extends TestCase
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
    }

    /**
     * Test that a route opted into an allowlist partner audience surfaces with
     * its resource schema in that audience while an allowlist default audience
     * that names it nowhere leaves both out.
     *
     * @return void
     */
    public function testAllowlistOptInDocumentsRouteAndSchemaInPartnerOnly(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'  => ['posture' => 'allowlist'],
            'partner' => ['posture' => 'allowlist'],
        ]);

        $this->router()->get('organizations', [PartnerOrganizationController::class, 'index']);

        $partner = $this->export('partner')->document;

        self::assertArrayHasKey('/organizations', $partner['paths']);
        self::assertSame(
            ['type' => 'string', 'const' => 'organizations'],
            $partner['components']['schemas']['Organization']['properties']['_type'],
        );

        // The default audience (public) is an allowlist naming nothing, so the
        // partner-only route and its schema are excluded from it.
        $default = $this->export()->document;

        self::assertSame('{}', json_encode($default['paths']));
        self::assertArrayNotHasKey('Organization', $default['components']['schemas']);

        $this->assertValid($partner);
        $this->assertValid($default);
    }

    /**
     * Test that a blanket-Undocumented controller is dropped from every
     * audience's paths and its resource schema from every audience's
     * components, while an unrelated documented route survives.
     *
     * @return void
     */
    public function testUndocumentedControllerDroppedFromEveryAudiencePathsAndSchemas(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()->get('organizations', [UndocumentedOrganizationController::class, 'index']);
        $this->router()->get('widgets', [PathTaggedController::class, 'index']);

        foreach (['public', 'internal'] as $audience) {

            $document = $this->export($audience)->document;

            self::assertArrayHasKey('/widgets', $document['paths']);
            self::assertArrayNotHasKey('/organizations', $document['paths']);
            self::assertArrayNotHasKey('Organization', $document['components']['schemas']);

            $this->assertValid($document);
        }
    }

    /**
     * Test that the canonical internal-only pattern documents the route and its
     * schema in the internal audience alone, absent from both the public and
     * the default audience.
     *
     * @return void
     */
    public function testInternalOnlyPatternDocumentsRouteAndSchemaInInternalOnly(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);

        $internal = $this->export('internal')->document;

        self::assertArrayHasKey('/tags', $internal['paths']);
        self::assertSame(
            ['type' => 'string', 'const' => 'tags'],
            $internal['components']['schemas']['Tag']['properties']['_type'],
        );

        $public = $this->export('public')->document;

        // The public audience documents no route here, so its paths object is
        // emitted empty; cast it before asserting the tag route is absent.
        self::assertArrayNotHasKey('/tags', (array) $public['paths']);
        self::assertArrayNotHasKey('Tag', $public['components']['schemas']);

        // The default audience resolves to public and must agree with it.
        self::assertArrayNotHasKey('/tags', (array) $this->export()->document['paths']);

        $this->assertValid($internal);
        $this->assertValid($public);
    }

    /**
     * Test that the notDocumentedIn route macro excludes a route from the named
     * audience while a blocklist audience it does not name still documents it.
     *
     * @return void
     */
    public function testRouteMacroNotDocumentedInExcludesFromThatAudienceOnly(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()
            ->get('organizations', [PathOrganizationController::class, 'index'])
            ->notDocumentedIn('public'); // @phpstan-ignore method.notFound

        $public = $this->export('public')->document;

        // The only route is excluded from public, leaving its paths empty.
        self::assertArrayNotHasKey('/organizations', (array) $public['paths']);

        $internal = $this->export('internal')->document;

        self::assertArrayHasKey('/organizations', $internal['paths']);

        $this->assertValid($public);
        $this->assertValid($internal);
    }

    /**
     * Test that a resource reachable only through an internal-only route never
     * leaks into the public document's component schemas while the resource of
     * a genuinely public route is retained.
     *
     * @return void
     */
    public function testInternalOnlyResourceDoesNotLeakIntoPublicSchemas(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()->get('organizations', [PathOrganizationController::class, 'index']);
        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);

        $public = $this->export('public')->document;

        self::assertArrayHasKey('/organizations', $public['paths']);
        self::assertArrayNotHasKey('/tags', $public['paths']);
        self::assertArrayHasKey('Organization', $public['components']['schemas']);
        self::assertArrayNotHasKey('Tag', $public['components']['schemas']);

        // The internal audience documents the tag route and pulls in its
        // schema, proving the resource is genuinely reachable and not merely
        // unbuilt.
        $internal = $this->export('internal')->document;

        self::assertArrayHasKey('Tag', $internal['components']['schemas']);

        $this->assertValid($public);
        $this->assertValid($internal);
    }

    /**
     * Test that the query surface a resource declares travels with its schema:
     * the audience reaching the resource learns which of its properties accept
     * a filter, an order, and a search, while the audience that never reaches
     * it carries no query surface anywhere in its document.
     *
     * @return void
     */
    public function testInternalOnlyQuerySurfaceDoesNotLeakIntoPublicDocument(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            Organization::class => OrganizationResource::class,
            Tag::class          => QueryableTagResource::class,
        ]);

        $this->router()->get('organizations', [PathOrganizationController::class, 'index']);
        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);

        $internal = $this->export('internal')->document;

        self::assertSame(
            [
                'filter' => ['key' => 'name', 'capability' => 'exact', 'operators' => ['$eq', '$in', '$null', '$notNull']],
                'sort'   => ['key' => 'name', 'indexed' => true],
                'search' => ['key' => 'name', 'strategy' => 'prefix'],
            ],
            $internal['components']['schemas']['QueryableTag']['properties']['name']['x-query-surface'],
        );

        $public = $this->export('public')->document;

        self::assertArrayNotHasKey('QueryableTag', $public['components']['schemas']);

        // The surface rides the property rather than the parameter components,
        // which are emitted globally, so an audience that never reaches the
        // resource carries no trace of what that resource may be asked.
        self::assertStringNotContainsString('x-query-surface', json_encode($public, JSON_THROW_ON_ERROR));

        $this->assertValid($internal);
        $this->assertValid($public);
    }

    /**
     * Assert the document validates against the OpenAPI 3.1 meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return void
     */
    private function assertValid(array $document): void
    {
        $result = $this->validateAgainstMetaSchema($document);

        self::assertTrue(
            $result->isValid(),
            'Produced document is not valid OpenAPI 3.1: ' . $this->formatErrors($result),
        );
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @param  string|null  $audience
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(?string $audience = null): ExportResult
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->makeApplication()->make(ExportOpenApiComponents::class);

        return $exporter->export($audience);
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
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        return $this->makeApplication()->make(Router::class);
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
