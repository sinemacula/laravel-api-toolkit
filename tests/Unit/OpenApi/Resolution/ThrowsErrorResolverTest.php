<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use Tests\Fixtures\OpenApi\PathErrorController;
use Tests\Fixtures\OpenApi\PathReflectionGapController;
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
    /**
     * Test that each named ApiException is described under its status with a
     * code example, ignoring other throwables and a status-less ApiException.
     *
     * @return void
     */
    public function testNamedApiExceptionsAreDescribedByStatus(): void
    {
        self::assertSame([
            401 => ['phrase' => 'Unauthorized.', 'examples' => ['UnauthenticatedException' => ['value' => ['error' => ['status' => 401, 'code' => 10101]]]]],
            429 => ['phrase' => 'Too many requests.', 'examples' => ['TooManyRequestsException' => ['value' => ['error' => ['status' => 429, 'code' => 10107]]]]],
            403 => ['phrase' => 'Forbidden.', 'examples' => ['ForbiddenException' => ['value' => ['error' => ['status' => 403, 'code' => 10102]]]]],
            419 => ['phrase' => 'Token mismatch.', 'examples' => ['TokenMismatchException' => ['value' => ['error' => ['status' => 419, 'code' => 10105]]]]],
        ], (new ThrowsErrorResolver)->resolve(PathRouteFactsController::class, 'signIn'));
    }

    /**
     * Test that exceptions sharing a status merge their examples under one
     * descriptor carrying the multi-word phrase.
     *
     * @return void
     */
    public function testExceptionsSharingStatusMergeExamples(): void
    {
        $errors = (new ThrowsErrorResolver)->resolve(PathErrorController::class, 'show');

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
    }

    /**
     * Test that an action naming only non-ApiException throwables yields none.
     *
     * @return void
     */
    public function testNonApiThrowablesYieldNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver)->resolve(PathErrorController::class, 'update'));
    }

    /**
     * Test that an action without a doc comment yields none.
     *
     * @return void
     */
    public function testActionWithoutDocCommentYieldsNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver)->resolve(PathReflectionGapController::class, 'index'));
    }

    /**
     * Test that an action the controller does not declare yields none rather
     * than throwing.
     *
     * @return void
     */
    public function testUndeclaredActionYieldsNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver)->resolve(PathReflectionGapController::class, 'destroy'));
    }
}
