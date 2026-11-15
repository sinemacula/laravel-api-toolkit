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
                . ' Ids must be unique across every audience: name the routes apart, or give their actions distinct #[Operation] ids.',
            $id,
            $first,
            PathPlainController::class,
            $second,
            PathPlainController::class,
        ), '/') . '$/');
    }

    /**
     * Build a GET route documented as handled by the controller action, or by a
     * closure when the controller is null.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  array<string, string>  $operationIds
     * @param  string  $path
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute
     */
    private static function handledBy(?string $controllerClass, string $action, array $operationIds, string $path = '/users'): DocumentedRoute
    {
        $route = new Route(['GET'], ltrim($path, '/'), $controllerClass === null ? static fn (): null => null : [$controllerClass, $action]);

        return new DocumentedRoute($route, $controllerClass, $action, $path, ['get'], null, 'Widget', $operationIds, null);
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
