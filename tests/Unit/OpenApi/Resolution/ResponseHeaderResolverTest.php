<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use Tests\Fixtures\OpenApi\BurstLimitedException;
use Tests\Fixtures\OpenApi\PathClassHeaderController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\QuotaExceededException;

/**
 * Tests for the response header resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseHeaderResolver::class)]
final class ResponseHeaderResolverTest extends TestCase
{
    /**
     * Test that an action's headers become header objects in declared order,
     * carrying the description only where one is given.
     *
     * @return void
     */
    public function testActionHeadersBecomeHeaderObjects(): void
    {
        self::assertSame([
            'Set-Cookie' => [
                'description' => 'The refresh token, HttpOnly, Secure, SameSite=Strict.',
                'schema'      => ['type' => 'string', 'pattern' => '^refresh_token='],
            ],
            'X-Session-Id' => ['schema' => ['type' => 'string']],
        ], (new ResponseHeaderResolver)->forAction(PathResponseHeaderController::class, 'signIn'));
    }

    /**
     * Test that an action declaring nothing on a controller declaring nothing
     * resolves no header.
     *
     * @return void
     */
    public function testUndeclaredActionResolvesNone(): void
    {
        self::assertSame([], (new ResponseHeaderResolver)->forAction(PathResponseHeaderController::class, 'bare'));
    }

    /**
     * Test that a route served by a closure resolves no header.
     *
     * @return void
     */
    public function testClosureRouteResolvesNone(): void
    {
        self::assertSame([], (new ResponseHeaderResolver)->forAction(null, \Closure::class));
    }

    /**
     * Test that an action inherits the headers its controller and the
     * controller's parent declare, nearest first, the controller's own
     * declaration winning over its parent's.
     *
     * @return void
     */
    public function testActionInheritsControllerAndParentHeaders(): void
    {
        self::assertSame([
            'X-Request-Id'  => ['description' => 'The identifier the controller assigns.', 'schema' => ['type' => 'string']],
            'X-Api-Version' => ['description' => 'The version of the API that answered.', 'schema' => ['type' => 'string']],
        ], (new ResponseHeaderResolver)->forAction(PathClassHeaderController::class, 'index'));
    }

    /**
     * Test that the action's own declaration wins over its controller's under a
     * different case, keeping the action's spelling of the name.
     *
     * @return void
     */
    public function testActionDeclarationWinsOverTheController(): void
    {
        self::assertSame([
            'ETag'          => ['description' => 'The entity tag of the record.', 'schema' => ['type' => 'string']],
            'x-request-id'  => ['description' => 'The identifier the action assigns.', 'schema' => ['type' => 'string']],
            'X-Api-Version' => ['description' => 'The version of the API that answered.', 'schema' => ['type' => 'string']],
        ], (new ResponseHeaderResolver)->forAction(PathClassHeaderController::class, 'show'));
    }

    /**
     * Test that an action the controller does not declare still carries the
     * headers the controller declares for every action.
     *
     * @return void
     */
    public function testUndeclaredActionKeepsTheControllerHeaders(): void
    {
        self::assertSame(
            ['X-Request-Id', 'X-Api-Version'],
            array_keys((new ResponseHeaderResolver)->forAction(PathClassHeaderController::class, 'missing')),
        );
    }

    /**
     * Test that an exception declaring nothing inherits its parent's header.
     *
     * @return void
     */
    public function testExceptionInheritsItsParentHeader(): void
    {
        self::assertSame(
            ['Retry-After' => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']]],
            (new ResponseHeaderResolver)->forException(BurstLimitedException::class),
        );
    }

    /**
     * Test that an exception's own declaration wins over its parent's under a
     * different case.
     *
     * @return void
     */
    public function testExceptionDeclarationWinsOverItsParent(): void
    {
        self::assertSame([
            'retry-after'   => ['description' => 'The number of seconds until the quota resets.', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            'X-Quota-Reset' => ['schema' => ['type' => 'integer']],
        ], (new ResponseHeaderResolver)->forException(QuotaExceededException::class));
    }

    /**
     * Test that an exception declaring nothing, with no parent declaring
     * anything, resolves no header.
     *
     * @return void
     */
    public function testUndeclaredExceptionResolvesNone(): void
    {
        self::assertSame([], (new ResponseHeaderResolver)->forException(ConflictException::class));
    }

    /**
     * Test that the toolkit's rate-limit exception documents the headers the
     * rate limiter sets.
     *
     * @return void
     */
    public function testTooManyRequestsDocumentsTheRateLimitHeaders(): void
    {
        self::assertSame(
            ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys((new ResponseHeaderResolver)->forException(TooManyRequestsException::class)),
        );
    }

    /**
     * Test that a repeated directive on one declaration keeps the first.
     *
     * @return void
     */
    public function testRepeatedDirectiveKeepsTheFirst(): void
    {
        $subject = new class {
            /**
             * Carry a header declared twice under different cases.
             *
             * @return void
             */
            #[ResponseHeader('ETag', 'First.')]
            #[ResponseHeader('etag', 'Second.')]
            public function show(): void {}
        };

        self::assertSame(
            ['ETag' => ['description' => 'First.', 'schema' => ['type' => 'string']]],
            (new ResponseHeaderResolver)->forAction($subject::class, 'show'),
        );
    }

    /**
     * Test that merging keeps every header of the first map and only the
     * headers of the second whose name, compared case-insensitively, is new.
     *
     * @return void
     */
    public function testMergeKeepsTheFirstOfEachName(): void
    {
        $merged = (new ResponseHeaderResolver)->merge(
            ['Retry-After' => ['description' => 'First.']],
            [
                'RETRY-AFTER' => ['description' => 'Second.'],
                'X-Limit'     => ['description' => 'Limit.'],
                'x-limit'     => ['description' => 'Repeated limit.'],
            ],
        );

        self::assertSame([
            'Retry-After' => ['description' => 'First.'],
            'X-Limit'     => ['description' => 'Limit.'],
        ], $merged);
    }
}
