<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Naming;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException;
use SineMacula\ApiToolkit\OpenApi\Naming\OperationIdCollisionGuard;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathFormResolver;
use Tests\Concerns\BuildsDocumentedRoutes;
use Tests\Fixtures\OpenApi\PathOperationController;
use Tests\Fixtures\OpenApi\PathPlainController;

/**
 * Tests for the operationId collision guard.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OperationIdCollisionGuard::class)]
final class OperationIdCollisionGuardTest extends TestCase
{
    use BuildsDocumentedRoutes;

    /**
     * Test that two routes claiming one id fail, naming both, even after a
     * route carrying no id.
     *
     * @return void
     */
    public function testTwoRoutesSharingAnIdThrow(): void
    {
        $this->expectCollision('users.index', 'GET /users', 'GET /members');

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::documentedRoute('/health'),
                self::documentedRoute('/users', operationIds: ['get' => 'users.index']),
                self::documentedRoute('/members', operationIds: ['get' => 'users.index']),
            ),
        ]);
    }

    /**
     * Test that routes in different audiences claiming one id fail.
     *
     * @return void
     */
    public function testRoutesInDifferentAudiencesSharingAnIdThrow(): void
    {
        $this->expectCollision('users.index', 'GET /users', 'GET /members');

        (new OperationIdCollisionGuard)->assertUnique([
            'public'   => $this->operations(self::documentedRoute('/users', operationIds: ['get' => 'users.index'])),
            'internal' => $this->operations(self::documentedRoute('/members', operationIds: ['get' => 'users.index'])),
        ]);
    }

    /**
     * Test that a later verb's suffixed id clashing with another route's name
     * fails.
     *
     * @return void
     */
    public function testSuffixedIdClashingWithAnotherRouteNameThrows(): void
    {
        $this->expectCollision('users.update.patch', 'PATCH /users/{user}', 'GET /users/{user}/patch');

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::documentedRoute('/users/{user}', ['put', 'patch'], ['put' => 'users.update', 'patch' => 'users.update.patch']),
                self::documentedRoute('/users/{user}/patch', operationIds: ['get' => 'users.update.patch']),
            ),
        ]);
    }

    /**
     * Test that one route exported to several audiences, and distinct ids,
     * pass.
     *
     * @return void
     */
    public function testOneRouteInSeveralAudiencesAndDistinctIdsPass(): void
    {
        $shared = self::documentedRoute('/users/{user}', ['put', 'patch'], ['put' => 'users.update', 'patch' => 'users.update.patch']);
        $other  = self::documentedRoute('/users', operationIds: ['get' => 'users.index']);

        $this->expectNotToPerformAssertions();

        (new OperationIdCollisionGuard)->assertUnique([
            'public'   => $this->operations($shared, $other),
            'internal' => $this->operations($shared),
        ]);
    }

    /**
     * Test that one controller action exported through a route per domain, each
     * in its own audience, claims its id once and keeps the first label.
     *
     * @return void
     */
    public function testOneActionOnSeveralDomainsClaimsItsIdOnce(): void
    {
        $this->expectCollision('users.index', 'GET a.example.com/users', 'GET /members');

        (new OperationIdCollisionGuard)->assertUnique([
            'public'   => $this->operations(self::onDomain(self::documentedRoute('/users', operationIds: ['get' => 'users.index']), 'a.example.com')),
            'internal' => $this->operations(self::onDomain(self::documentedRoute('/users', operationIds: ['get' => 'users.index']), 'b.example.com')),
            'partner'  => $this->operations(self::documentedRoute('/members', operationIds: ['get' => 'users.index'])),
        ]);
    }

    /**
     * Test that one action under one path claiming one id for two verbs fails.
     *
     * @return void
     */
    public function testOneActionClaimingAnIdUnderTwoVerbsThrows(): void
    {
        $this->expectCollision('users', 'GET /users', 'POST /users');

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::documentedRoute('/users', operationIds: ['get' => 'users']),
                self::documentedRoute('/users', ['post'], ['post' => 'users']),
            ),
        ]);
    }

    /**
     * Test that two actions of one controller under one path and verb claiming
     * one id fail.
     *
     * @return void
     */
    public function testTwoActionsOfOneControllerSharingAnIdThrow(): void
    {
        $this->expectException(OperationIdCollisionException::class);
        $this->expectExceptionMessage(sprintf('[GET /users (%1$s::report)] and [GET /users (%1$s::index)].', PathPlainController::class));

        (new OperationIdCollisionGuard)->assertUnique([
            'public'   => $this->operations(self::documentedRoute('/users', operationIds: ['get' => 'users.index'])),
            'internal' => $this->operations(self::handledBy(PathPlainController::class, 'index', ['get' => 'users.index'])),
        ]);
    }

    /**
     * Test that the same action of two controllers under one path and verb
     * claiming one id fail.
     *
     * @return void
     */
    public function testOneActionOfTwoControllersSharingAnIdThrow(): void
    {
        $this->expectException(OperationIdCollisionException::class);
        $this->expectExceptionMessage(sprintf('[GET /users (%s::report)] and [GET /users (%s::report)].', PathPlainController::class, PathOperationController::class));

        (new OperationIdCollisionGuard)->assertUnique([
            'public'   => $this->operations(self::documentedRoute('/users', operationIds: ['get' => 'users.index'])),
            'internal' => $this->operations(self::handledBy(PathOperationController::class, 'report', ['get' => 'users.index'])),
        ]);
    }

    /**
     * Test that two closure routes under one path and verb, each in its own
     * audience, claiming one id fail, naming their domains.
     *
     * @return void
     */
    public function testClosureRoutesOnTwoDomainsSharingAnIdThrow(): void
    {
        $this->expectException(OperationIdCollisionException::class);
        $this->expectExceptionMessage('[GET a.example.com/ping (Closure)] and [GET b.example.com/ping (Closure)].');

        (new OperationIdCollisionGuard)->assertUnique([
            'public'   => $this->operations(self::onDomain(self::handledBy(null, \Closure::class, ['get' => 'get.ping'], '/ping'), 'a.example.com')),
            'internal' => $this->operations(self::onDomain(self::handledBy(null, \Closure::class, ['get' => 'get.ping'], '/ping'), 'b.example.com')),
        ]);
    }

    /**
     * Test that a shadowed primary verb's id, which an exported later verb
     * falls back to, clashing with another operation's id fails, naming the
     * later verb.
     *
     * @return void
     */
    public function testShadowedPrimaryIdClashingWithAnotherOperationThrows(): void
    {
        $this->expectCollision('users.index', 'POST /users', 'GET /members');

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::documentedRoute('/users', ['get', 'post'], ['get' => 'users.index', 'post' => 'users.index.post']),
                self::handledBy(PathPlainController::class, 'index', ['get' => 'users.list']),
                self::documentedRoute('/members', operationIds: ['get' => 'users.index']),
            ),
        ]);
    }

    /**
     * Test that a shadowed primary verb's id claimed by the same action on
     * another domain passes.
     *
     * @return void
     */
    public function testShadowedPrimaryIdOfTheSameActionOnAnotherDomainPasses(): void
    {
        $this->expectNotToPerformAssertions();

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::onDomain(self::documentedRoute('/users', ['get', 'post'], ['get' => 'users.index', 'post' => 'users.index.post']), 'a.example.com'),
                self::onDomain(self::documentedRoute('/users', operationIds: ['get' => 'users.index']), 'b.example.com'),
            ),
        ]);
    }

    /**
     * Test that a closure's later verb falling back to its shadowed primary id
     * claimed by another closure fails, naming the later verb.
     *
     * @return void
     */
    public function testShadowedPrimaryIdOfAClosureNamesTheVerbFallingBackToIt(): void
    {
        $this->expectException(OperationIdCollisionException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(
            'The operationId "get.a" is claimed by both [GET /a (Closure)] and [POST /a (Closure)].'
                . ' Ids must be unique across every audience: name the routes apart, or declare distinct #[Operation] ids on separate controller actions.',
            '/',
        ) . '$/');

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::handledBy(null, \Closure::class, ['get' => 'get.a', 'post' => 'post.a'], '/a'),
                self::handledBy(null, \Closure::class, ['get' => 'get.a'], '/a'),
            ),
        ]);
    }

    /**
     * Test that one action declaring an id, reached by two routes, fails with
     * the remedy for a declared id.
     *
     * @return void
     */
    public function testOneActionDeclaringAnIdOnTwoRoutesThrows(): void
    {
        $this->expectDeclaredCollision(
            sprintf('[GET /widgets (%1$s::index)] and [GET /listings (%1$s::index)]', PathOperationController::class),
        );

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::handledBy(PathOperationController::class, 'index', ['get' => 'widgets.listing'], '/widgets', true),
                self::handledBy(PathOperationController::class, 'index', ['get' => 'widgets.listing'], '/listings', true),
            ),
        ]);
    }

    /**
     * Test that a declared id clashing with an earlier route's name fails with
     * the remedy for a declared id.
     *
     * @return void
     */
    public function testDeclaredIdClashingWithARouteNameThrows(): void
    {
        $this->expectDeclaredCollision(
            sprintf('[GET /listings (%s::report)] and [GET /widgets (%s::index)]', PathPlainController::class, PathOperationController::class),
        );

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(
                self::documentedRoute('/listings', operationIds: ['get' => 'widgets.listing']),
                self::handledBy(PathOperationController::class, 'index', ['get' => 'widgets.listing'], '/widgets', true),
            ),
        ]);
    }

    /**
     * Test that the path forms of one route sharing its primary id, which their
     * descriptions fall back to, pass.
     *
     * @return void
     */
    public function testFormsOfOneRouteSharingTheirPrimaryIdPass(): void
    {
        $this->expectNotToPerformAssertions();

        (new OperationIdCollisionGuard)->assertUnique([
            'public' => $this->operations(...self::documentedForms('reports/{period?}', ['get', 'post'], [
                ['get' => 'reports.show', 'post' => 'reports.show.post'],
                ['get' => 'reports.show.period', 'post' => 'reports.show.period.post'],
            ])),
        ]);
    }

    /**
     * Test that another route claiming the primary id of a route whose shortest
     * form is shadowed fails, naming the form falling back to it.
     *
     * @return void
     */
    public function testShadowedShortestFormsIdClaimedByAnotherRouteThrows(): void
    {
        $routes = [
            ...self::documentedForms('reports/{period?}', operationIds: [['get' => 'reports.show'], ['get' => 'reports.show.period']]),
            self::documentedRoute('/reports', operationIds: ['get' => 'reports.index']),
            self::documentedRoute('/summaries', operationIds: ['get' => 'reports.show']),
        ];

        $this->expectCollision('reports.show', 'GET /reports/{period}', 'GET /summaries');

        (new OperationIdCollisionGuard)->assertUnique(['public' => $this->operations(...$routes)]);
    }

    /**
     * Expect a collision on the declared widgets.listing id between the
     * labelled operations.
     *
     * @param  string  $labels
     * @return void
     */
    private function expectDeclaredCollision(string $labels): void
    {
        $this->expectException(OperationIdCollisionException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(
            sprintf('The operationId "widgets.listing" is claimed by both %s.', $labels)
                . ' Ids must be unique across every audience: a declared #[Operation] id overrides the route name,'
                . ' so drop it and name the routes apart, or declare distinct ids on separate controller actions.',
            '/',
        ) . '$/');
    }

    /**
     * Expect a collision on the id between the two labelled operations.
     *
     * @param  string  $id
     * @param  string  $first
     * @param  string  $second
     * @return void
     */
    private function expectCollision(string $id, string $first, string $second): void
    {
        $this->expectException(OperationIdCollisionException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(sprintf(
            'The operationId "%s" is claimed by both [%s (%s::report)] and [%s (%s::report)].'
                . ' Ids must be unique across every audience: name the routes apart, or declare distinct #[Operation] ids on separate controller actions.',
            $id,
            $first,
            PathPlainController::class,
            $second,
            PathPlainController::class,
        ), '/') . '$/');
    }

    /**
     * Build a route documented as handled by the controller action, or by a
     * closure when the controller is null, under the verbs its ids are keyed
     * by.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  non-empty-array<string, string>  $operationIds
     * @param  string  $path
     * @param  bool  $declared
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute
     */
    private static function handledBy(?string $controllerClass, string $action, array $operationIds, string $path = '/users', bool $declared = false): DocumentedRoute
    {
        $verbs = array_keys($operationIds);
        $route = new Route(array_map(strtoupper(...), $verbs), ltrim($path, '/'), $controllerClass === null ? static fn (): null => null : [$controllerClass, $action]);
        $form  = (new RoutePathFormResolver)->resolve($route)[0];

        return new DocumentedRoute($route, $controllerClass, $action, $form, $verbs, null, 'Widget', $operationIds, null, $declared, $path, $operationIds[$verbs[0]]);
    }

    /**
     * Serve the documented route on the domain.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  string  $domain
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute
     */
    private static function onDomain(DocumentedRoute $route, string $domain): DocumentedRoute
    {
        $route->route->domain($domain);

        return $route;
    }

    /**
     * Map the routes' operations by path and verb.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  ...$routes
     * @return array<string, array<string, \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>>
     */
    private function operations(DocumentedRoute ...$routes): array
    {
        return (new DocumentedRoutes(['audience' => array_values($routes)], true))->operations('audience');
    }
}
