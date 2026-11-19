<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Naming;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Naming\OperationIds;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm;
use Tests\Fixtures\OpenApi\Naming\APIKeyController;
use Tests\Fixtures\OpenApi\Naming\Controller;
use Tests\Fixtures\OpenApi\Naming\OAuth2CallbackController;
use Tests\Fixtures\OpenApi\PathInvokableController;
use Tests\Fixtures\OpenApi\PathPlainController;

/**
 * Tests for the operationId derivation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OperationIds::class)]
final class OperationIdsTest extends TestCase
{
    /**
     * Test that a declared id wins over the route name.
     *
     * @return void
     */
    public function testDeclaredIdWinsOverTheRouteName(): void
    {
        self::assertSame(
            ['get' => 'reports.latest'],
            OperationIds::derive($this->route('reports.index'), PathPlainController::class, 'report', ['get'], self::form('/reports'), 'reports.latest'),
        );
    }

    /**
     * Test that the route name wins over the controller and action.
     *
     * @return void
     */
    public function testRouteNameWinsOverTheControllerAndAction(): void
    {
        self::assertSame(
            ['get' => 'reports.index'],
            OperationIds::derive($this->route('reports.index'), PathPlainController::class, 'report', ['get'], self::form('/reports'), null),
        );
    }

    /**
     * Test that an unnamed controller route derives its id from the controller
     * and action.
     *
     * @return void
     */
    public function testUnnamedControllerRouteUsesTheControllerAndAction(): void
    {
        self::assertSame(
            ['get' => 'path-plain.report'],
            OperationIds::derive($this->route(), PathPlainController::class, 'report', ['get'], self::form('/reports'), null),
        );
    }

    /**
     * Test that an unnamed closure derives its id from the method and path.
     *
     * @return void
     */
    public function testUnnamedClosureUsesTheMethodAndPath(): void
    {
        self::assertSame(
            ['get' => 'get.users.user.avatar'],
            OperationIds::derive($this->route(), null, \Closure::class, ['get'], self::form('/users/{user}/avatar'), null),
        );
    }

    /**
     * Provide the route names that count as unnamed.
     *
     * @return iterable<string, array{string}>
     */
    public static function syntheticNames(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'group prefix' => ['admin.'];
        yield 'cached' => ['generated::aBcD1234'];
        yield 'cached under a group prefix' => ['admin.generated::aBcD1234'];
        yield 'cached after zero' => ['0generated::aBcD1234'];
    }

    /**
     * Test that a synthetic route name falls back to the controller and action.
     *
     * @param  string  $name
     * @return void
     */
    #[DataProvider('syntheticNames')]
    public function testSyntheticRouteNameCountsAsUnnamed(string $name): void
    {
        self::assertSame(
            ['get' => 'path-plain.report'],
            OperationIds::derive($this->route($name), PathPlainController::class, 'report', ['get'], self::form('/reports'), null),
        );
    }

    /**
     * Test that the controller and action are kebab-cased with acronyms kept
     * whole.
     *
     * @return void
     */
    public function testControllerAndActionKeepAcronymsWhole(): void
    {
        self::assertSame(
            ['get' => 'api-key.show-all'],
            OperationIds::derive($this->route(), APIKeyController::class, 'showAll', ['get'], self::form('/keys'), null),
        );
        self::assertSame(
            ['get' => 'o-auth2-callback.handle-http-response'],
            OperationIds::derive($this->route(), OAuth2CallbackController::class, 'handleHTTPResponse', ['get'], self::form('/callback'), null),
        );
    }

    /**
     * Test that an invokable controller drops the action.
     *
     * @return void
     */
    public function testInvokableControllerDropsTheAction(): void
    {
        self::assertSame(
            ['get' => 'path-invokable'],
            OperationIds::derive($this->route(), PathInvokableController::class, '__invoke', ['get'], self::form('/widgets'), null),
        );
    }

    /**
     * Test that a basename of just Controller is kept whole.
     *
     * @return void
     */
    public function testBasenameOfJustControllerIsKept(): void
    {
        self::assertSame(
            ['get' => 'controller.index'],
            OperationIds::derive($this->route(), Controller::class, 'index', ['get'], self::form('/items'), null),
        );
    }

    /**
     * Test that the closure segments drop braces and empty segments, and the
     * root path keeps the verb alone.
     *
     * @return void
     */
    public function testClosureSegmentsDropBracesAndTheRootKeepsTheVerb(): void
    {
        self::assertSame(
            ['get' => 'get.users.user.avatar'],
            OperationIds::derive($this->route(), null, \Closure::class, ['get'], self::form('/users/{user}//avatar/'), null),
        );
        self::assertSame(['get' => 'get'], OperationIds::derive($this->route(), null, \Closure::class, ['get'], self::form('/'), null));
    }

    /**
     * Test that each verb after the first appends itself, in the given order.
     *
     * @return void
     */
    public function testLaterVerbsAppendThemselvesInOrder(): void
    {
        self::assertSame(
            ['put' => 'users.update', 'patch' => 'users.update.patch'],
            OperationIds::derive($this->route('users.update'), PathPlainController::class, 'update', ['put', 'patch'], self::form('/users/{user}'), null),
        );
        self::assertSame(
            ['patch' => 'users.update', 'put' => 'users.update.put'],
            OperationIds::derive($this->route('users.update'), PathPlainController::class, 'update', ['patch', 'put'], self::form('/users/{user}'), null),
        );
    }

    /**
     * Test that a named closure with several verbs is suffixed like any route.
     *
     * @return void
     */
    public function testNamedMultiVerbClosureIsSuffixed(): void
    {
        self::assertSame(
            ['get' => 'ping', 'post' => 'ping.post'],
            OperationIds::derive($this->route('ping'), null, \Closure::class, ['get', 'post'], self::form('/ping'), null),
        );
    }

    /**
     * Test that an unnamed closure with several verbs leads each id with its
     * verb.
     *
     * @return void
     */
    public function testUnnamedMultiVerbClosureLeadsEachIdWithItsVerb(): void
    {
        self::assertSame(
            ['get' => 'get.ping', 'post' => 'post.ping'],
            OperationIds::derive($this->route(), null, \Closure::class, ['get', 'post'], self::form('/ping'), null),
        );
    }

    /**
     * Test that a path form appends the optional parameters it includes to the
     * base id, ahead of any verb suffix.
     *
     * @return void
     */
    public function testFormAppendsItsOptionalParametersAheadOfTheVerbSuffix(): void
    {
        self::assertSame(
            ['get' => 'reports.show', 'post' => 'reports.show.post'],
            OperationIds::derive($this->route('reports.show'), PathPlainController::class, 'report', ['get', 'post'], self::form('/reports'), null),
        );
        self::assertSame(
            ['get' => 'reports.show.period', 'post' => 'reports.show.period.post'],
            OperationIds::derive($this->route('reports.show'), PathPlainController::class, 'report', ['get', 'post'], self::form('/reports/{period}', 'period'), null),
        );
        self::assertSame(
            ['get' => 'reports.show.period.post'],
            OperationIds::derive($this->route('reports.show'), PathPlainController::class, 'report', ['get'], self::form('/reports/{period}/{post}', 'period', 'post'), null),
        );
    }

    /**
     * Test that a declared id and a controller id are suffixed by the form, and
     * an id taken from the method and path is not.
     *
     * @return void
     */
    public function testDeclaredAndControllerIdsAreSuffixedButPathIdsAreNot(): void
    {
        $form  = self::form('/reports/{period}', 'period');
        $named = $this->route('reports.show');

        self::assertSame(['get' => 'reports.latest.period'], OperationIds::derive($named, PathPlainController::class, 'report', ['get'], $form, 'reports.latest'));
        self::assertSame(['get' => 'path-plain.report.period'], OperationIds::derive($this->route(), PathPlainController::class, 'report', ['get'], $form, null));
        self::assertSame(['get' => 'get.reports.period'], OperationIds::derive($this->route(), null, \Closure::class, ['get'], $form, null));
    }

    /**
     * Test that the base id shared by every path form is the declared id, else
     * the route name, else the controller id, and null for an unnamed closure.
     *
     * @return void
     */
    public function testBaseIsNullOnlyForIdsTakenFromTheMethodAndPath(): void
    {
        self::assertSame('reports.latest', OperationIds::base($this->route('reports.show'), PathPlainController::class, 'report', 'reports.latest'));
        self::assertSame('reports.show', OperationIds::base($this->route('reports.show'), PathPlainController::class, 'report', null));
        self::assertSame('path-plain.report', OperationIds::base($this->route(), PathPlainController::class, 'report', null));
        self::assertNull(OperationIds::base($this->route(), null, \Closure::class, null));
    }

    /**
     * Build a route, optionally named.
     *
     * @param  string|null  $name
     * @return \Illuminate\Routing\Route
     */
    private function route(?string $name = null): Route
    {
        $route = new Route(['GET'], 'stub', static fn (): null => null);

        return $name === null ? $route : $route->name($name);
    }

    /**
     * Build a path form of the template, including the optional parameters.
     *
     * @param  string  $path
     * @param  string  ...$optional
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm
     */
    private static function form(string $path, string ...$optional): RoutePathForm
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);

        return new RoutePathForm($path, $matches[1], array_values($optional), true);
    }
}
