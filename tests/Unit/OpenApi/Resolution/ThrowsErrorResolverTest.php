<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
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
    /**
     * Test that each named ApiException is described under its status with a
     * code example, ignoring other throwables and a status-less ApiException.
     *
     * @return void
     */
    public function testNamedApiExceptionsAreDescribedByStatus(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver))->resolve(PathRouteFactsController::class, 'signIn');

        self::assertSame([401, 429, 403, 419], array_keys($errors));
        self::assertSame($this->descriptor('Unauthorized.', 'UnauthenticatedException', 401, 10101), $errors[401]);
        self::assertSame($this->descriptor('Forbidden.', 'ForbiddenException', 403, 10102), $errors[403]);
        self::assertSame($this->descriptor('Token mismatch.', 'TokenMismatchException', 419, 10105), $errors[419]);
        self::assertSame('Too many requests.', $errors[429]['phrase']);
        self::assertSame(['TooManyRequestsException' => ['value' => ['error' => ['status' => 429, 'code' => 10107]]]], $errors[429]['examples']);
    }

    /**
     * Test that an exception's declared headers, including those inherited from
     * its parent, are carried under its status, while an exception declaring
     * none carries no header.
     *
     * @return void
     */
    public function testDeclaredExceptionHeadersAreCarriedByStatus(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver))->resolve(PathResponseHeaderController::class, 'signIn');

        self::assertSame(
            ['Retry-After' => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']]],
            $errors[429]['headers'],
        );
        self::assertSame([], $errors[409]['headers']);
    }

    /**
     * Test that exceptions sharing a status merge their headers in @throws
     * order, the first declaration of a name winning.
     *
     * @return void
     */
    public function testExceptionsSharingStatusMergeHeaders(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver))->resolve(PathResponseHeaderController::class, 'quota');

        self::assertSame([429], array_keys($errors));
        self::assertSame(['QuotaExceededException', 'TooManyRequestsException'], array_keys($errors[429]['examples']));
        self::assertSame(
            ['retry-after', 'X-Quota-Reset', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys($errors[429]['headers']),
        );
        self::assertSame('The number of seconds until the quota resets.', $errors[429]['headers']['retry-after']['description']);
    }

    /**
     * Test that exceptions sharing a status merge their examples under one
     * descriptor carrying the multi-word phrase.
     *
     * @return void
     */
    public function testExceptionsSharingStatusMergeExamples(): void
    {
        $errors = (new ThrowsErrorResolver(new ResponseHeaderResolver))->resolve(PathErrorController::class, 'show');

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
        self::assertSame([], (new ThrowsErrorResolver(new ResponseHeaderResolver))->resolve(PathErrorController::class, 'update'));
    }

    /**
     * Test that an action without a doc comment yields none.
     *
     * @return void
     */
    public function testActionWithoutDocCommentYieldsNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver(new ResponseHeaderResolver))->resolve(PathReflectionGapController::class, 'index'));
    }

    /**
     * Test that an action the controller does not declare yields none rather
     * than throwing.
     *
     * @return void
     */
    public function testUndeclaredActionYieldsNone(): void
    {
        self::assertSame([], (new ThrowsErrorResolver(new ResponseHeaderResolver))->resolve(PathReflectionGapController::class, 'destroy'));
    }

    /**
     * Build the descriptor expected for a status whose single exception
     * declares no header.
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
