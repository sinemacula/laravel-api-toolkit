<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRouteCollector;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathAttributelessController;
use Tests\Fixtures\OpenApi\PathExcludedController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathInvalidOperationController;
use Tests\Fixtures\OpenApi\PathOperationController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathReflectionGapController;
use Tests\Fixtures\OpenApi\PathTaggedController;
use Tests\Fixtures\OpenApi\PathUnmappedController;
use Tests\Fixtures\OpenApi\Vendor\PathVendorController;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the documented route collector.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocumentedRouteCollector::class)]
final class DocumentedRouteCollectorTest extends TestCase
{
    /**
     * Test that a resource action carries its resource, tag and handler.
     *
     * @return void
     */
    public function testResourceActionCarriesItsResourceTagAndHandler(): void
    {
        $this->router()->get('users', [PathFixtureController::class, 'index']);

        $route = $this->route('/users');

        self::assertSame(['model' => User::class, 'resource' => UserResource::class, 'schema' => 'User'], $route->resource);
        self::assertSame('User', $route->tag);
        self::assertSame(PathFixtureController::class, $route->controllerClass);
        self::assertSame('index', $route->action);
        self::assertSame(['get'], $route->verbs);
        self::assertSame([], $route->operationIds);
        self::assertNull($route->summary);
    }

    /**
     * Test that an action outside the resource contract carries no resource.
     *
     * @return void
     */
    public function testNonResourceActionsCarryNoResource(): void
    {
        $this->router()->get('users/export', [PathFixtureController::class, 'export']);
        $this->router()->get('plain', [PathPlainController::class, 'index']);
        $this->router()->get('posts', [PathUnmappedController::class, 'index']);
        $this->router()->get('drafts', [PathAttributelessController::class, 'index']);

        foreach (['/users/export', '/plain', '/posts', '/drafts'] as $path) {
            self::assertNull($this->route($path)->resource, $path);
        }

        self::assertSame('PathPlain', $this->route('/plain')->tag);
    }

    /**
     * Test that a routed action absent from its controller is still collected.
     *
     * @return void
     */
    public function testRoutedActionAbsentFromControllerIsCollected(): void
    {
        $this->router()->delete('gaps/{gap}', [PathReflectionGapController::class, 'destroy']);

        $route = $this->route('/gaps/{gap}', 'delete');

        self::assertSame('destroy', $route->action);
        self::assertSame(['delete'], $route->verbs);
        self::assertSame('User', $route->tag);
    }

    /**
     * Test that a closure or a missing controller carries no controller class.
     *
     * @return void
     */
    public function testClosureAndMissingControllerCarryNoControllerClass(): void
    {
        $this->router()->get('health', static fn (): null => null);
        $this->router()->get('ghosts', 'App\Http\Controllers\GhostController@index');

        self::assertNull($this->route('/health')->controllerClass);
        self::assertSame('health', $this->route('/health')->tag);
        self::assertNull($this->route('/ghosts')->controllerClass);
    }

    /**
     * Test that an optional route parameter renders without its marker.
     *
     * @return void
     */
    public function testOptionalParameterRendersWithoutMarker(): void
    {
        $this->router()->get('users/{user?}', [PathFixtureController::class, 'show']);

        self::assertSame('/users/{user}', $this->route('/users/{user}')->path);
    }

    /**
     * Test that the root route keeps a single slash.
     *
     * @return void
     */
    public function testRootRouteKeepsASingleSlash(): void
    {
        $this->router()->get('/', [PathPlainController::class, 'index']);

        self::assertSame('/', $this->route('/')->path);
    }

    /**
     * Test that a numeric audience name is collected like any other.
     *
     * @return void
     */
    public function testNumericAudienceNameIsCollected(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['2024' => []]);

        $this->router()->get('plain', [PathPlainController::class, 'index']);

        $routes = $this->collect(['2024']);

        self::assertSame(['2024'], $routes->audiences());
        self::assertSame(['/plain'], $this->paths($routes->in('2024')));
    }

    /**
     * Test that a method-level #[Tag] beats the class-level one.
     *
     * @return void
     */
    public function testExplicitTagOverridesDerivedTag(): void
    {
        $this->router()->get('widgets', [PathTaggedController::class, 'index']);
        $this->router()->get('reports', [PathTaggedController::class, 'report']);

        self::assertSame('Widgets', $this->route('/widgets')->tag);
        self::assertSame('Reports', $this->route('/reports')->tag);
    }

    /**
     * Test that filtered routes, a leading separator included, are dropped.
     *
     * @return void
     */
    public function testFilteredRoutesAreDropped(): void
    {
        $this->config()->set('api-toolkit.openapi.exclude.namespaces', ['Tests\Fixtures\OpenApi\Vendor']);

        $this->router()->get('vendor', [PathVendorController::class, 'index']);
        $this->router()->get('separated', ['\\' . PathVendorController::class, 'index']);
        $this->router()->get('secret', static fn (): null => null)->undocumented(); // @phpstan-ignore method.notFound
        $this->router()->get('plain', [PathPlainController::class, 'index']);

        self::assertSame(['/plain'], $this->paths($this->collect(['public'])->in('public')));
    }

    /**
     * Test that a route joins only the audiences it belongs to, as one shared
     * instance.
     *
     * @return void
     */
    public function testRouteJoinsOnlyItsAudiencesAsOneInstance(): void
    {
        $this->router()->get('excluded', [PathExcludedController::class, 'index']);
        $this->router()->get('plain', [PathPlainController::class, 'index']);

        $routes = $this->collect(['public', 'internal']);

        self::assertSame(['/plain'], $this->paths($routes->in('public')));
        self::assertSame(['/excluded', '/plain'], $this->paths($routes->in('internal')));
        self::assertSame($routes->in('public')[0], $routes->in('internal')[1]);
        self::assertSame(['public', 'internal'], $routes->audiences());
    }

    /**
     * Test that an allowlist audience excludes routes that opt into nothing.
     *
     * @return void
     */
    public function testAllowlistPostureExcludesUndeclaredRoutes(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['partner' => ['posture' => AudienceResolver::POSTURE_ALLOWLIST]]);

        $this->router()->get('plain', [PathPlainController::class, 'index']);

        self::assertSame(['partner' => []], $this->collect(['partner'])->byAudience);
    }

    /**
     * Test that a route without a documentable verb is dropped before it is
     * resolved, and excluded verbs are dropped from the rest.
     *
     * @return void
     */
    public function testRouteWithoutADocumentableVerbIsDropped(): void
    {
        $this->router()->match(['HEAD'], 'ping', [PathPlainController::class, 'report']);
        $this->router()->options('preflight', [PathPlainController::class, 'index']);
        $this->router()->match(['HEAD', 'GET'], 'pong', [PathPlainController::class, 'report']);

        $routes = $this->collect(['public'])->in('public');

        self::assertSame(['/pong'], $this->paths($routes));
        self::assertSame(['get'], $routes[0]->verbs);
    }

    /**
     * Test that a route excluded from every audience never reads its
     * #[Operation], while one it joins does.
     *
     * @return void
     */
    public function testRouteExcludedEverywhereNeverReadsItsOperation(): void
    {
        $this->router()->get('invalid', [PathInvalidOperationController::class, 'index']);

        self::assertSame([], $this->collect(['public'])->in('public'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^An operation directive must declare an id or a summary$/');

        $this->collect(['internal']);
    }

    /**
     * Test that routes are collected in router collection order.
     *
     * @return void
     */
    public function testRoutesAreCollectedInRouterOrder(): void
    {
        $this->router()->get('c', [PathPlainController::class, 'index']);
        $this->router()->get('a', [PathPlainController::class, 'report']);
        $this->router()->get('b', [PathPlainController::class, 'index']);

        self::assertSame(['/c', '/a', '/b'], $this->paths($this->collect(['public'])->in('public')));
    }

    /**
     * Test that ids are empty while off and derived while on.
     *
     * @return void
     */
    public function testIdsAreEmptyWhileOffAndDerivedWhileOn(): void
    {
        $this->router()->match(['PUT', 'PATCH'], 'users/{user}', [PathFixtureController::class, 'update'])->name('users.update');

        $off = $this->collect(['public']);

        self::assertFalse($off->operationIdsEnabled);
        self::assertSame([], $this->route('/users/{user}', 'put')->operationIds);

        $this->config()->set('api-toolkit.openapi.operation_ids', true);

        $on = $this->collect(['public']);

        self::assertTrue($on->operationIdsEnabled);
        self::assertSame(['put' => 'users.update', 'patch' => 'users.update.patch'], $this->route('/users/{user}', 'put')->operationIds);
    }

    /**
     * Test that ids stay off when the key is absent, and a truthy value turns
     * them on.
     *
     * @return void
     */
    public function testIdsAreOffWhenTheKeyIsAbsentAndOnForATruthyValue(): void
    {
        $this->router()->get('users', [PathFixtureController::class, 'index'])->name('users.index');

        $openapi = $this->config()->get('api-toolkit.openapi');

        self::assertIsArray($openapi);

        unset($openapi['operation_ids']);

        $this->config()->set('api-toolkit.openapi', $openapi);

        self::assertFalse($this->collect(['public'])->operationIdsEnabled);

        $this->config()->set('api-toolkit.openapi.operation_ids', '1');

        self::assertTrue($this->collect(['public'])->operationIdsEnabled);
        self::assertSame(['get' => 'users.index'], $this->route('/users')->operationIds);
    }

    /**
     * Test that the declared id and summary, and whether an id is declared, are
     * read from the method.
     *
     * @return void
     */
    public function testDeclaredIdAndSummaryAreReadFromTheMethod(): void
    {
        $this->config()->set('api-toolkit.openapi.operation_ids', true);

        $this->router()->get('widgets/{widget}', [PathOperationController::class, 'show'])->name('widgets.show');
        $this->router()->match(['PUT', 'PATCH'], 'widgets/{widget}', [PathOperationController::class, 'update']);

        $show   = $this->route('/widgets/{widget}', 'get');
        $update = $this->route('/widgets/{widget}', 'put');

        self::assertSame(['get' => 'widgets.showcase'], $show->operationIds);
        self::assertSame('Show a widget', $show->summary);
        self::assertTrue($show->operationIdDeclared);
        self::assertSame(['put' => 'path-operation.update', 'patch' => 'path-operation.update.patch'], $update->operationIds);
        self::assertSame('Update a widget', $update->summary);
        self::assertFalse($update->operationIdDeclared);
    }

    /**
     * Test that an unnamed controller served on two domains does not collide
     * with itself.
     *
     * @return void
     */
    public function testUnnamedControllerOnTwoDomainsDoesNotCollide(): void
    {
        $this->config()->set('api-toolkit.openapi.operation_ids', true);

        foreach (['a.example.com', 'b.example.com'] as $domain) {
            $this->router()->group(['domain' => $domain], static function (Router $router): void {
                $router->get('users', [PathPlainController::class, 'index']);
            });
        }

        $routes = $this->collect(['public', 'internal']);

        self::assertCount(2, $routes->in('public'));
        self::assertCount(1, $routes->operations('public')['/users']);
    }

    /**
     * Test that an unnamed controller served on two domains, each in its own
     * audience, does not collide with itself.
     *
     * @return void
     */
    public function testUnnamedControllerOnTwoDomainsInSeparateAudiencesDoesNotCollide(): void
    {
        $this->config()->set('api-toolkit.openapi.operation_ids', true);

        foreach (['a.example.com' => ['public', 'internal'], 'b.example.com' => ['internal', 'public']] as $domain => [$in, $out]) {
            $this->router()->group(['domain' => $domain], static function (Router $router) use ($in, $out): void {
                $router->get('users', [PathPlainController::class, 'index'])->documentedIn($in)->notDocumentedIn($out); // @phpstan-ignore method.notFound
            });
        }

        $routes = $this->collect(['public', 'internal']);

        self::assertSame('a.example.com', $routes->operations('public')['/users']['get']->route->getDomain());
        self::assertSame('b.example.com', $routes->operations('internal')['/users']['get']->route->getDomain());
    }

    /**
     * Test that routes in different audiences claiming one id fail the
     * collection.
     *
     * @return void
     */
    public function testCrossAudienceCollisionThrows(): void
    {
        $this->config()->set('api-toolkit.openapi.operation_ids', true);

        $this->router()->get('a', static fn (): null => null)->name('duplicate')->notDocumentedIn('internal'); // @phpstan-ignore method.notFound
        $this->router()->get('b', static fn (): null => null)->name('duplicate')->notDocumentedIn('public'); // @phpstan-ignore method.notFound

        $this->expectException(OperationIdCollisionException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(
            'The operationId "duplicate" is claimed by both [GET /a (Closure)] and [GET /b (Closure)].'
                . ' Ids must be unique across every audience: name the routes apart, or declare distinct #[Operation] ids on separate controller actions.',
            '/',
        ) . '$/');

        $this->collect(['public', 'internal']);
    }

    /**
     * Collect the routes of the given audiences.
     *
     * @param  list<string>  $audiences
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes
     */
    private function collect(array $audiences): DocumentedRoutes
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([User::class => UserResource::class]);

        return (new DocumentedRouteCollector(
            $this->router(),
            $catalogue,
            new AudienceResolver,
            new AudienceConfiguration,
            new DocumentableRouteFilter,
            new TagResolver,
        ))->collect($audiences);
    }

    /**
     * Collect the public audience and return the route owning the path and
     * verb.
     *
     * @param  string  $path
     * @param  string  $verb
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute
     */
    private function route(string $path, string $verb = 'get'): DocumentedRoute
    {
        $route = $this->collect(['public'])->operations('public')[$path][$verb] ?? null;

        self::assertInstanceOf(DocumentedRoute::class, $route, $path);

        return $route;
    }

    /**
     * List the routes' path templates in order.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>  $routes
     * @return list<string>
     */
    private function paths(array $routes): array
    {
        return array_map(static fn (DocumentedRoute $route): string => $route->path, $routes);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(): ConfigRepository
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->app->make('config');
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }
}
