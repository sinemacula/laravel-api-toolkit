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
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end security test for the OpenAPI exporter.
 *
 * Exports real routes carrying auth middleware and proves the derived security
 * surfaces correctly in the assembled, meta-schema-valid document: every guard
 * driver maps to its scheme, per-operation `security` names the referenced
 * schemes, `components.securitySchemes` carries exactly those definitions, an
 * OR of distinct schemes stays separate, same-scheme guards dedupe to one, a
 * config override flows to both the operation and the component, and a public
 * operation emits an explicit empty requirement. Each produced document is
 * validated against the official OpenAPI 3.1 meta-schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiAssembler::class)]
final class OpenApiSecurityExportTest extends TestCase
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
        $this->getConfig()->set('auth.defaults.guard', 'web');
    }

    /**
     * Test that a plain bearer-token guard has its operation reference the
     * bearerAuth scheme, which is then defined once in components.
     *
     * @return void
     */
    public function testSanctumGuardReferencesBearerScheme(): void
    {
        $this->setGuard('sanctum', 'sanctum');

        $document = $this->exportRoute('auth:sanctum');

        self::assertSame(
            [['bearerAuth' => []]],
            $document['paths']['/users']['get']['security'],
        );

        self::assertSame(['bearerAuth'], array_keys($document['components']['securitySchemes']));

        self::assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $document['components']['securitySchemes']['bearerAuth'],
        );

        $this->assertValid($document);
    }

    /**
     * Test that a JWT guard references the shared plain bearer scheme, defined
     * once in components without an unverifiable bearer format.
     *
     * @return void
     */
    public function testJwtGuardEmitsPlainBearerScheme(): void
    {
        $this->setGuard('api', 'jwt');

        $document = $this->exportRoute('auth:api');

        self::assertSame(
            [['bearerAuth' => []]],
            $document['paths']['/users']['get']['security'],
        );

        self::assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $document['components']['securitySchemes']['bearerAuth'],
        );

        $this->assertValid($document);
    }

    /**
     * Test that a session guard emits the cookieAuth apiKey scheme keyed to the
     * configured session cookie name, referenced by the operation and defined
     * in components.
     *
     * @return void
     */
    public function testSessionGuardEmitsCookieSchemeWithConfiguredCookieName(): void
    {
        $this->getConfig()->set('session.cookie', 'acme_session');
        $this->setGuard('web', 'session');

        $document = $this->exportRoute('auth:web');

        self::assertSame(
            [['cookieAuth' => []]],
            $document['paths']['/users']['get']['security'],
        );

        self::assertSame(
            ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'acme_session'],
            $document['components']['securitySchemes']['cookieAuth'],
        );

        $this->assertValid($document);
    }

    /**
     * Test that a config-overridden custom driver flows to both the operation's
     * referenced scheme name and the matching components definition.
     *
     * @return void
     */
    public function testConfigOverriddenDriverFlowsToOperationAndComponent(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.security.drivers', [
            'passport' => [
                'name'       => 'oauthBearer',
                'definition' => ['type' => 'http', 'scheme' => 'bearer'],
            ],
        ]);
        $this->setGuard('passport', 'passport');

        $document = $this->exportRoute('auth:passport');

        self::assertSame(
            [['oauthBearer' => []]],
            $document['paths']['/users']['get']['security'],
        );

        self::assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $document['components']['securitySchemes']['oauthBearer'],
        );

        $this->assertValid($document);
    }

    /**
     * Test that two guards mapping to different schemes stay separate as an OR
     * and both schemes are defined in components.
     *
     * @return void
     */
    public function testTwoGuardsWithDifferentSchemesExpressAnOr(): void
    {
        $this->setGuard('api', 'jwt');
        $this->setGuard('web', 'session');

        $document = $this->exportRoute('auth:api,web');

        self::assertSame(
            [['bearerAuth' => []], ['cookieAuth' => []]],
            $document['paths']['/users']['get']['security'],
        );

        $schemes = $document['components']['securitySchemes'];

        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], $schemes['bearerAuth']);
        self::assertSame(['type' => 'apiKey', 'in' => 'cookie', 'name' => $this->defaultCookie()], $schemes['cookieAuth']);

        $this->assertValid($document);
    }

    /**
     * Test that two guards mapping to the same scheme dedupe to one requirement
     * and one components entry, never revealing the second guard.
     *
     * @return void
     */
    public function testTwoGuardsWithSameSchemeDedupe(): void
    {
        $this->setGuard('jwtguard', 'jwt');
        $this->setGuard('sanctumguard', 'sanctum');

        $document = $this->exportRoute('auth:jwtguard,sanctumguard');

        self::assertSame(
            [['bearerAuth' => []]],
            $document['paths']['/users']['get']['security'],
        );

        self::assertSame(['bearerAuth'], array_keys($document['components']['securitySchemes']));

        $this->assertValid($document);
    }

    /**
     * Test that a public operation carrying no auth middleware emits an
     * explicit empty security requirement and no securitySchemes component.
     *
     * @return void
     */
    public function testPublicOperationEmitsExplicitEmptySecurity(): void
    {
        $document = $this->exportRoute();

        self::assertSame([], $document['paths']['/users']['get']['security']);

        self::assertArrayNotHasKey('securitySchemes', $document['components']);

        $this->assertValid($document);
    }

    /**
     * Register a GET /users route with the given middleware and export the
     * default-audience document.
     *
     * @param  string  ...$middleware
     * @return array<string, mixed>
     */
    private function exportRoute(string ...$middleware): array
    {
        $route = $this->router()->get('users', [PathFixtureController::class, 'index']);

        if ($middleware !== []) {
            $route->middleware($middleware);
        }

        return $this->export()->document;
    }

    /**
     * Configure a single auth guard with the given driver.
     *
     * @param  string  $guard
     * @param  string  $driver
     * @return void
     */
    private function setGuard(string $guard, string $driver): void
    {
        $this->getConfig()->set("auth.guards.{$guard}", ['driver' => $driver]);
    }

    /**
     * Resolve the session cookie name backing the default cookie scheme.
     *
     * @return string
     */
    private function defaultCookie(): string
    {
        $cookie = $this->getConfig()->get('session.cookie');

        return is_string($cookie) && $cookie !== '' ? $cookie : 'session';
    }

    /**
     * Assert that a document validates against the OpenAPI 3.1 meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return void
     */
    private function assertValid(array $document): void
    {
        $result = $this->validateAgainstMetaSchema($document);

        self::assertTrue(
            $result->isValid(),
            'Emitted document is not valid OpenAPI 3.1: ' . $this->formatErrors($result),
        );
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
