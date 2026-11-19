<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\ValidatesOpenApiDocuments;
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
 * Each produced document is also validated against the OpenAPI 3.1 meta-schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiAssembler::class)]
final class OpenApiSecurityExportTest extends TestCase
{
    use ValidatesOpenApiDocuments;

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
     * Test that a bearer-token guard references the bearerAuth scheme.
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

        self::assertIsArray($document['components']['securitySchemes']);
        self::assertSame(['bearerAuth'], array_keys($document['components']['securitySchemes']));

        self::assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $document['components']['securitySchemes']['bearerAuth'],
        );

        $this->assertValidDocument($document);
    }

    /**
     * Test that a JWT guard references the shared plain bearer scheme.
     *
     * No bearer format is emitted, since it cannot be verified.
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

        $this->assertValidDocument($document);
    }

    /**
     * Test that a session guard emits a cookieAuth scheme for its cookie.
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

        $this->assertValidDocument($document);
    }

    /**
     * Test that a config-overridden driver reaches the operation and component.
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

        $this->assertValidDocument($document);
    }

    /**
     * Test that a configured driver keeping the built-in scheme name replaces
     * that scheme's definition.
     *
     * @return void
     */
    public function testConfiguredDriverKeepingTheSchemeNameReplacesItsDefinition(): void
    {
        $definition = ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Sanctum'];

        $this->getConfig()->set('api-toolkit.openapi.security.drivers', [
            'sanctum' => ['name' => 'bearerAuth', 'definition' => $definition],
        ]);
        $this->setGuard('sanctum', 'sanctum');

        $document = $this->exportRoute('auth:sanctum');

        self::assertSame([['bearerAuth' => []]], $document['paths']['/users']['get']['security']);
        self::assertSame(['bearerAuth' => $definition], $document['components']['securitySchemes']);

        $this->assertValidDocument($document);
    }

    /**
     * Test that guards mapping to different schemes stay separate as an OR.
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

        $this->assertValidDocument($document);
    }

    /**
     * Test that guards mapping to the same scheme dedupe to one requirement.
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

        self::assertIsArray($document['components']['securitySchemes']);
        self::assertSame(['bearerAuth'], array_keys($document['components']['securitySchemes']));

        $this->assertValidDocument($document);
    }

    /**
     * Test that a public operation emits an explicit empty security list.
     *
     * @return void
     */
    public function testPublicOperationEmitsExplicitEmptySecurity(): void
    {
        $document = $this->exportRoute();

        self::assertSame([], $document['paths']['/users']['get']['security']);

        self::assertIsArray($document['components']);
        self::assertArrayNotHasKey('securitySchemes', $document['components']);

        $this->assertValidDocument($document);
    }

    /**
     * Register a GET /users route with the middleware and export the document.
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
