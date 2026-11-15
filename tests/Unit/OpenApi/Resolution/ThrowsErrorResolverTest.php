<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\LockedException;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\PayloadTooLargeException;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Exceptions\UnauthenticatedException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use Tests\Fixtures\OpenApi\BurstLimitedException;
use Tests\Fixtures\OpenApi\PathErrorController;
use Tests\Fixtures\OpenApi\PathReflectionGapController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathRouteFactsController;
use Tests\Fixtures\OpenApi\QuotaExceededException;
use Tests\TestCase;

/**
 * Tests for the throws error resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrowsErrorResolver::class)]
final class ThrowsErrorResolverTest extends TestCase
{
    /** @var string Fixture controller naming its exceptions in every class-name form */
    private const string IMPORTED = 'Tests\Fixtures\OpenApi\ImportedThrowsController';

    /**
     * Test that the ApiExceptions reporting a status are listed in tag order.
     *
     * @return void
     */
    public function testApiExceptionsReportingAStatusAreListedInTagOrder(): void
    {
        self::assertSame(
            [UnauthenticatedException::class, TooManyRequestsException::class, ForbiddenException::class, TokenMismatchException::class],
            $this->resolve(PathRouteFactsController::class, 'signIn'),
        );
    }

    /**
     * Test that an exception tagged twice is listed once.
     *
     * @return void
     */
    public function testExceptionTaggedTwiceIsListedOnce(): void
    {
        self::assertSame([TooManyRequestsException::class, ConflictException::class], $this->resolve(PathRouteFactsController::class, 'retry'));
    }

    /**
     * Test that exceptions sharing a status are each listed.
     *
     * @return void
     */
    public function testExceptionsSharingAStatusAreEachListed(): void
    {
        self::assertSame([QuotaExceededException::class, TooManyRequestsException::class], $this->resolve(PathResponseHeaderController::class, 'quota'));
        self::assertSame([BurstLimitedException::class, ConflictException::class], $this->resolve(PathResponseHeaderController::class, 'signIn'));
        self::assertSame(
            [InvalidInputException::class, ServiceUnavailableException::class, MaintenanceModeException::class],
            $this->resolve(PathErrorController::class, 'show'),
        );
    }

    /**
     * Test that an action naming only non-ApiException throwables lists none.
     *
     * @return void
     */
    public function testNonApiThrowablesAreNotListed(): void
    {
        self::assertSame([], $this->resolve(PathErrorController::class, 'update'));
    }

    /**
     * Test that an action without a doc comment lists none.
     *
     * @return void
     */
    public function testActionWithoutDocCommentListsNone(): void
    {
        self::assertSame([], $this->resolve(PathReflectionGapController::class, 'index'));
    }

    /**
     * Test that an undeclared action lists none.
     *
     * @return void
     */
    public function testUndeclaredActionListsNone(): void
    {
        self::assertSame([], $this->resolve(PathReflectionGapController::class, 'destroy'));
    }

    /**
     * Test that an exception named by its imported short name is listed.
     *
     * @return void
     */
    public function testImportedShortNameIsListed(): void
    {
        self::assertSame([ForbiddenException::class], $this->resolve(self::IMPORTED, 'show'));
    }

    /**
     * Test that an exception named by its import alias is listed.
     *
     * @return void
     */
    public function testAliasedImportIsListed(): void
    {
        self::assertSame([TokenMismatchException::class], $this->resolve(self::IMPORTED, 'store'));
    }

    /**
     * Test that exceptions imported by a group use are listed.
     *
     * @return void
     */
    public function testGroupUseIsListed(): void
    {
        self::assertSame([ConflictException::class, GoneException::class], $this->resolve(self::IMPORTED, 'update'));
    }

    /**
     * Test that each member of a union is listed.
     *
     * @return void
     */
    public function testUnionMembersAreListed(): void
    {
        self::assertSame([ForbiddenException::class, UnauthenticatedException::class], $this->resolve(self::IMPORTED, 'restore'));
    }

    /**
     * Test that a same-namespace exception is listed without an import.
     *
     * @return void
     */
    public function testSameNamespaceNameIsListed(): void
    {
        self::assertSame([QuotaExceededException::class], $this->resolve(self::IMPORTED, 'throttle'));
    }

    /**
     * Test that an exception named fully qualified is listed.
     *
     * @return void
     */
    public function testFullyQualifiedNameIsListed(): void
    {
        self::assertSame([NotFoundException::class], $this->resolve(self::IMPORTED, 'destroy'));
    }

    /**
     * Test that an inherited action resolves against its parent's imports.
     *
     * @return void
     */
    public function testInheritedActionResolvesAgainstParentImports(): void
    {
        self::assertSame([LockedException::class], $this->resolve(self::IMPORTED, 'lock'));
    }

    /**
     * Test that a trait action resolves against the trait's imports.
     *
     * @return void
     */
    public function testTraitActionResolvesAgainstTraitImports(): void
    {
        self::assertSame([PayloadTooLargeException::class], $this->resolve(self::IMPORTED, 'upload'));
    }

    /**
     * Test that names resolving to no class are skipped.
     *
     * @return void
     */
    public function testUnresolvableNamesAreSkipped(): void
    {
        self::assertSame([ConflictException::class], $this->resolve(self::IMPORTED, 'purge'));
    }

    /**
     * Resolve the exception classes the given action documents.
     *
     * @param  string  $controllerClass
     * @param  string  $action
     * @return list<class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    private function resolve(string $controllerClass, string $action): array
    {
        require_once dirname(__DIR__, 3) . '/Fixtures/OpenApi/ImportedThrows/ImportedThrowsController.stub';

        /** @var class-string $controllerClass */
        return (new ThrowsErrorResolver(new ClassNameResolver))->resolve($controllerClass, $action);
    }
}
