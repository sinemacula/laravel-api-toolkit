<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Naming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException;
use SineMacula\ApiToolkit\OpenApi\Naming\OperationIdCollisionGuard;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use Tests\Concerns\BuildsDocumentedRoutes;
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
