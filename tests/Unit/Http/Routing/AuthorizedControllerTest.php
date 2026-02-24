<?php

namespace Tests\Unit\Http\Routing;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Controllers\TestingAuthorizedController;
use Tests\Fixtures\Controllers\TestingMinimalAuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Tests for the AuthorizedController.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AuthorizedController::class)]
class AuthorizedControllerTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that getResourceModel returns the RESOURCE_MODEL constant value.
     *
     * @return void
     */
    public function testGetResourceModelReturnsConstantValue(): void
    {
        $result = TestingAuthorizedController::getResourceModel();

        static::assertSame(User::class, $result);
    }

    /**
     * Test that getResourceModel throws LogicException when constant is not defined.
     *
     * @return void
     */
    public function testGetResourceModelThrowsLogicExceptionWhenNotDefined(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The RESOURCE_MODEL constant must be defined on the authorized controller');

        $controller = new class extends AuthorizedController {
            /**
             * Create a new instance without parent constructor.
             */
            public function __construct() // @phpstan-ignore constructor.missingParentCall
            {
                // Skip parent constructor to avoid authorizeResource call
            }
        };

        $controller::getResourceModel();
    }

    /**
     * Test that getRouteParameter returns lowercase ROUTE_PARAMETER value.
     *
     * @return void
     */
    public function testGetRouteParameterReturnsLowercaseValue(): void
    {
        $result = TestingAuthorizedController::getRouteParameter();

        static::assertSame('user', $result);
    }

    /**
     * Test that getRouteParameter returns null when constant is not defined.
     *
     * @return void
     */
    public function testGetRouteParameterReturnsNullWhenNotDefined(): void
    {
        $result = TestingMinimalAuthorizedController::getRouteParameter();

        static::assertNull($result);
    }

    /**
     * Test that getResourceModel works for minimal controller with RESOURCE_MODEL.
     *
     * @return void
     */
    public function testMinimalControllerReturnsResourceModel(): void
    {
        $result = TestingMinimalAuthorizedController::getResourceModel();

        static::assertSame(User::class, $result);
    }

    /**
     * Test that the controller extends Controller.
     *
     * @return void
     */
    public function testExtendsController(): void
    {
        $parents = class_parents(AuthorizedController::class);

        static::assertIsArray($parents);
        static::assertArrayHasKey(Controller::class, $parents);
    }

    /**
     * Test that the constructor registers authorizeResource middleware.
     *
     * Using newInstanceWithoutConstructor avoids running authorizeResource
     * twice; we then manually invoke the constructor to exercise line 22.
     *
     * @return void
     */
    public function testConstructorRegistersAuthorizeResourceMiddleware(): void
    {
        $reflection = new \ReflectionClass(TestingAuthorizedController::class);
        $controller = $reflection->newInstanceWithoutConstructor(); // qlty-ignore: radarlint-php:php:S3011

        // Call the constructor to exercise line 22 (authorizeResource call)
        $constructor = $reflection->getConstructor();
        static::assertNotNull($constructor);
        $constructor->invoke($controller);

        $middleware = $this->getProperty($controller, 'middleware');

        static::assertNotEmpty($middleware);
    }

    /**
     * Test that getGuardExclusions returns the GUARD_EXCLUSIONS constant value
     * when defined.
     *
     * @return void
     */
    public function testGetGuardExclusionsReturnsConstantValueWhenDefined(): void
    {
        $reflection = new \ReflectionClass(TestingAuthorizedController::class);
        $controller = $reflection->newInstanceWithoutConstructor(); // qlty-ignore: radarlint-php:php:S3011

        $result = $this->invokeMethod($controller, 'getGuardExclusions');

        static::assertSame(['index', 'show'], $result);
    }
}
