<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use Tests\Fixtures\OpenApi\PathErrorController;
use Tests\Fixtures\OpenApi\PathReflectionGapController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathRouteFactsController;
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
     * Test that each named ApiException is described with a code example.
     *
     * @return void
     */
    public function testNamedApiExceptionsAreDescribedByStatus(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve(PathRouteFactsController::class, 'signIn');

        self::assertSame([401, 429, 403, 419], array_keys($errors));
        self::assertSame($this->descriptor('Unauthorized.', 'UnauthenticatedException', 401, 10101), $errors[401]);
        self::assertSame($this->descriptor('Forbidden.', 'ForbiddenException', 403, 10102), $errors[403]);
        self::assertSame($this->descriptor('Token mismatch.', 'TokenMismatchException', 419, 10105), $errors[419]);
        self::assertSame('Too many requests.', $errors[429]['phrase']);
        self::assertSame(['TooManyRequestsException' => ['value' => ['error' => ['status' => 429, 'code' => 10107]]]], $errors[429]['examples']);
    }

    /**
     * Test that exception headers, inherited ones too, are carried.
     *
     * @return void
     */
    public function testDeclaredExceptionHeadersAreCarriedByStatus(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve(PathResponseHeaderController::class, 'signIn');

        self::assertSame(
            ['Retry-After' => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']]],
            $errors[429]['headers'],
        );
        self::assertSame([], $errors[409]['headers']);
    }

    /**
     * Test that a shared status merges headers in @throws order.
     *
     * @return void
     */
    public function testExceptionsSharingStatusMergeHeaders(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve(PathResponseHeaderController::class, 'quota');

        self::assertSame([429], array_keys($errors));
        self::assertSame(['QuotaExceededException', 'TooManyRequestsException'], array_keys($errors[429]['examples']));
        self::assertSame(
            ['retry-after', 'X-Quota-Reset', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys($errors[429]['headers']),
        );
        self::assertSame('The number of seconds until the quota resets.', $errors[429]['headers']['retry-after']['description']);
    }

    /**
     * Test that a shared status merges examples under one descriptor.
     *
     * @return void
     */
    public function testExceptionsSharingStatusMergeExamples(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve(PathErrorController::class, 'show');

        self::assertSame([422, 503], array_keys($errors));
        self::assertSame('Unprocessable content.', $errors[422]['phrase']);
        self::assertSame('Service unavailable.', $errors[503]['phrase']);
        self::assertSame(
            [
                'ServiceUnavailableException' => ['value' => ['error' => ['status' => 503, 'code' => 10112]]],
                'MaintenanceModeException'    => ['value' => ['error' => ['status' => 503, 'code' => 10200]]],
            ],
            $errors[503]['examples'],
        );
        self::assertSame(['Retry-After', 'Refresh'], array_keys($errors[503]['headers']));
    }

    /**
     * Test that an action naming only non-ApiException throwables yields none.
     *
     * @return void
     */
    public function testNonApiThrowablesYieldNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve(PathErrorController::class, 'update'));
    }

    /**
     * Test that an action without a doc comment yields none.
     *
     * @return void
     */
    public function testActionWithoutDocCommentYieldsNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve(PathReflectionGapController::class, 'index'));
    }

    /**
     * Test that an undeclared action yields nothing.
     *
     * @return void
     */
    public function testUndeclaredActionYieldsNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve(PathReflectionGapController::class, 'destroy'));
    }

    /**
     * Test that an exception named by its imported short name is documented.
     *
     * @return void
     */
    public function testImportedShortNameIsDocumented(): void
    {
        self::assertSame([403 => $this->descriptor('Forbidden.', 'ForbiddenException', 403, 10102)], $this->resolve(self::IMPORTED, 'show'));
    }

    /**
     * Test that an exception named by its import alias is documented.
     *
     * @return void
     */
    public function testAliasedImportIsDocumented(): void
    {
        self::assertSame([419 => $this->descriptor('Token mismatch.', 'TokenMismatchException', 419, 10105)], $this->resolve(self::IMPORTED, 'store'));
    }

    /**
     * Test that exceptions imported by a group use are documented.
     *
     * @return void
     */
    public function testGroupUseIsDocumented(): void
    {
        $errors = $this->resolve(self::IMPORTED, 'update');

        self::assertSame([409, 410], array_keys($errors));
        self::assertSame(['ConflictException'], array_keys($errors[409]['examples']));
        self::assertSame(['GoneException'], array_keys($errors[410]['examples']));
    }

    /**
     * Test that each member of a union is documented.
     *
     * @return void
     */
    public function testUnionMembersAreDocumented(): void
    {
        $errors = $this->resolve(self::IMPORTED, 'restore');

        self::assertSame([403, 401], array_keys($errors));
        self::assertSame(['UnauthenticatedException'], array_keys($errors[401]['examples']));
    }

    /**
     * Test that a same-namespace exception is documented without an import.
     *
     * @return void
     */
    public function testSameNamespaceNameIsDocumented(): void
    {
        $errors = $this->resolve(self::IMPORTED, 'throttle');

        self::assertSame([429], array_keys($errors));
        self::assertSame(['QuotaExceededException'], array_keys($errors[429]['examples']));
    }

    /**
     * Test that an exception named fully qualified is still documented.
     *
     * @return void
     */
    public function testFullyQualifiedNameIsDocumented(): void
    {
        self::assertSame([404], array_keys($this->resolve(self::IMPORTED, 'destroy')));
    }

    /**
     * Test that an inherited action resolves against its parent's imports.
     *
     * @return void
     */
    public function testInheritedActionResolvesAgainstParentImports(): void
    {
        $errors = $this->resolve(self::IMPORTED, 'lock');

        self::assertSame([423], array_keys($errors));
        self::assertSame(['LockedException'], array_keys($errors[423]['examples']));
    }

    /**
     * Test that a trait action resolves against the trait's imports.
     *
     * @return void
     */
    public function testTraitActionResolvesAgainstTraitImports(): void
    {
        self::assertSame([413], array_keys($this->resolve(self::IMPORTED, 'upload')));
    }

    /**
     * Test that names resolving to no class are skipped.
     *
     * @return void
     */
    public function testUnresolvableNamesAreSkipped(): void
    {
        self::assertSame([409], array_keys($this->resolve(self::IMPORTED, 'purge')));
    }

    /**
     * Resolve the error descriptors of the given action.
     *
     * @param  string  $controllerClass
     * @param  string  $action
     * @return array<int, array{phrase: string, examples: array<string, array<string, mixed>>, headers: array<string, array<string, mixed>>}>
     */
    private function resolve(string $controllerClass, string $action): array
    {
        require_once dirname(__DIR__, 3) . '/Fixtures/OpenApi/ImportedThrows/ImportedThrowsController.stub';

        /** @var class-string $controllerClass */
        return (new ThrowsErrorResolver(new ResponseHeaderResolver, new ClassNameResolver))->resolve($controllerClass, $action);
    }

    /**
     * Build the descriptor for a status with one headerless exception.
     *
     * @param  string  $phrase
     * @param  string  $name
     * @param  int  $status
     * @param  int  $code
     * @return array<string, mixed>
     */
    private function descriptor(string $phrase, string $name, int $status, int $code): array
    {
        return [
            'phrase'   => $phrase,
            'examples' => [$name => ['value' => ['error' => ['status' => $status, 'code' => $code]]]],
            'headers'  => [],
        ];
    }
}
