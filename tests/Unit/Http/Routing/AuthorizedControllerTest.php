<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Routing;

use Illuminate\Routing\Controllers\Middleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\Attributes\SkipAuthorization;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use Tests\Fixtures\Controllers\SkipAuthorizationController;
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
final class AuthorizedControllerTest extends TestCase
{
    /**
     * Test that getResourceModel returns the attribute model.
     *
     * @return void
     */
    public function testGetResourceModelReturnsAttributeModel(): void
    {
        self::assertSame(User::class, TestingAuthorizedController::getResourceModel());
        self::assertSame(User::class, TestingMinimalAuthorizedController::getResourceModel());
    }

    /**
     * Test that getResourceModel throws when the attribute is absent.
     *
     * @return void
     */
    public function testGetResourceModelThrowsWhenAttributeAbsent(): void
    {
        $controller = new class extends AuthorizedController {};

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[AuthorizesResource] attribute must be declared on the authorized controller');

        $controller::getResourceModel();
    }

    /**
     * Test that getResourceModel throws when the model is not an Eloquent
     * model.
     *
     * @return void
     */
    public function testGetResourceModelThrowsWhenModelIsNotEloquent(): void
    {
        $controller = new #[AuthorizesResource(\stdClass::class)] class extends AuthorizedController {};

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[AuthorizesResource] model must be an Eloquent model');

        $controller::getResourceModel();
    }

    /**
     * Test that middleware emits the exact gate check set for the full fixture.
     *
     * The parameter is declared mixed-case and must be lowercased; the index
     * and show actions are excluded; the model-less create and store actions
     * target the model class while the model-bound update and destroy target
     * the route parameter, and edit and update collapse into a single update
     * check.
     *
     * @return void
     */
    public function testMiddlewareEmitsExpectedGateChecks(): void
    {
        $indexed = $this->indexByDefinition(TestingAuthorizedController::middleware());

        self::assertSame([
            'can:create,' . User::class => ['create', 'store'],
            'can:update,user'           => ['edit', 'update'],
            'can:delete,user'           => ['destroy'],
        ], $indexed);
    }

    /**
     * Test that the excluded actions are gated by no middleware entry.
     *
     * @return void
     */
    public function testMiddlewareExcludesConfiguredActions(): void
    {
        foreach (TestingAuthorizedController::middleware() as $entry) {
            self::assertNotContains('index', $entry->only ?? []);
            self::assertNotContains('show', $entry->only ?? []);
        }
    }

    /**
     * Test that middleware honours the #[SkipAuthorization] marker, leaving the
     * marked action ungated while every other action stays gated.
     *
     * @return void
     */
    public function testMiddlewareHonoursSkipAuthorization(): void
    {
        $indexed = $this->indexByDefinition(SkipAuthorizationController::middleware());
        $gated   = array_merge(...array_values($indexed));

        self::assertNotContains('show', $gated);
        self::assertContains('update', $gated);
        self::assertContains('index', $gated);
    }

    /**
     * Test that middleware derives the parameter from the model when none is
     * declared, snake-casing the model basename.
     *
     * @return void
     */
    public function testMiddlewareDerivesParameterFromModel(): void
    {
        $indexed = $this->indexByDefinition(TestingMinimalAuthorizedController::middleware());

        self::assertSame(['edit', 'update'], $indexed['can:update,user'] ?? null);
        self::assertSame(['destroy'], $indexed['can:delete,user'] ?? null);
        self::assertSame(['index'], $indexed['can:viewAny,' . User::class] ?? null);
    }

    /**
     * Test that an explicit parameter distinct from the model basename is
     * lowercased and used verbatim rather than derived from the model.
     *
     * @return void
     */
    public function testMiddlewareLowercasesExplicitParameterDistinctFromModel(): void
    {
        $controller = new #[AuthorizesResource(User::class, parameter: 'Admin')] class extends AuthorizedController {};

        $indexed = $this->indexByDefinition($controller::middleware());

        self::assertArrayHasKey('can:update,admin', $indexed);
        self::assertArrayNotHasKey('can:update,user', $indexed);
    }

    /**
     * Test that an empty explicit parameter falls back to the model-derived
     * parameter rather than being used as an empty target.
     *
     * @return void
     */
    public function testMiddlewareDerivesParameterWhenExplicitParameterIsEmpty(): void
    {
        $controller = new #[AuthorizesResource(User::class, parameter: '')] class extends AuthorizedController {};

        $indexed = $this->indexByDefinition($controller::middleware());

        self::assertArrayHasKey('can:update,user', $indexed);
        self::assertArrayNotHasKey('can:update,', $indexed);
    }

    /**
     * Test that every action carrying the marker is excluded, not just the
     * first one discovered.
     *
     * @return void
     */
    public function testMiddlewareExcludesEveryMarkedAction(): void
    {
        $controller = new #[AuthorizesResource(User::class)] class extends AuthorizedController {
            /**
             * Update, marked as skipped.
             *
             * @return void
             */
            #[SkipAuthorization]
            public function update(): void {}

            /**
             * Destroy, marked as skipped.
             *
             * @return void
             */
            #[SkipAuthorization]
            public function destroy(): void {}
        };

        $indexed = $this->indexByDefinition($controller::middleware());
        $gated   = $indexed === [] ? [] : array_merge(...array_values($indexed));

        self::assertNotContains('update', $gated);
        self::assertNotContains('destroy', $gated);
    }

    /**
     * Test that middleware fails closed when no attribute is declared, rather
     * than silently registering an ungated resource.
     *
     * @return void
     */
    public function testMiddlewareThrowsWhenAttributeAbsent(): void
    {
        $controller = new class extends AuthorizedController {};

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[AuthorizesResource] attribute must be declared on the authorized controller');

        $controller::middleware();
    }

    /**
     * Test that the controller extends the base controller.
     *
     * @return void
     */
    public function testExtendsController(): void
    {
        $parents = class_parents(AuthorizedController::class);

        self::assertIsArray($parents);
        self::assertArrayHasKey(Controller::class, $parents);
    }

    /**
     * Index the controller middleware by its definition string, mapping each to
     * its only-list.
     *
     * @param  array<int, \Illuminate\Routing\Controllers\Middleware>  $middleware
     * @return array<string, array<int, string>>
     */
    private function indexByDefinition(array $middleware): array
    {
        $indexed = [];

        foreach ($middleware as $entry) {
            self::assertInstanceOf(Middleware::class, $entry);
            self::assertIsString($entry->middleware);

            $indexed[$entry->middleware] = $entry->only ?? [];
        }

        return $indexed;
    }
}
