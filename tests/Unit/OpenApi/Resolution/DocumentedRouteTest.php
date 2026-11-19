<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm;
use Tests\Concerns\BuildsDocumentedRoutes;
use Tests\Fixtures\OpenApi\PathPlainController;

/**
 * Tests for the documented route value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocumentedRoute::class)]
final class DocumentedRouteTest extends TestCase
{
    use BuildsDocumentedRoutes;

    /**
     * Test that a controller route is labelled by verb, path and action.
     *
     * @return void
     */
    public function testLabelsAControllerRouteByItsAction(): void
    {
        self::assertSame(
            sprintf('PATCH /users/{user} (%s::report)', PathPlainController::class),
            self::documentedRoute('/users/{user}', ['put', 'patch'])->label('patch'),
        );
    }

    /**
     * Test that a closure route is labelled by verb, path and closure.
     *
     * @return void
     */
    public function testLabelsAClosureRoute(): void
    {
        $closure = new Route(['GET'], 'health', static fn (): null => null);
        $route   = new DocumentedRoute($closure, null, \Closure::class, new RoutePathForm('/health', [], [], true), ['get'], null, 'health', [], null, false, '/health', null);

        self::assertSame('GET /health (Closure)', $route->label('get'));
    }

    /**
     * Test that a route served on a domain is labelled with its domain.
     *
     * @return void
     */
    public function testLabelsADomainRouteWithItsDomain(): void
    {
        $route = self::documentedRoute('/users');

        $route->route->domain('https://api.example.com');

        self::assertSame(sprintf('GET api.example.com/users (%s::report)', PathPlainController::class), $route->label('get'));
    }
}
