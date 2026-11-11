<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\OpenApi\PathResponseStatusController;
use Tests\Fixtures\OpenApi\PathStatusConflictController;

/**
 * Tests for the response status resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseStatusResolver::class)]
final class ResponseStatusResolverTest extends TestCase
{
    /**
     * Test that the declared status is resolved, with or without a body.
     *
     * @return void
     */
    public function testResolvesTheDeclaredStatus(): void
    {
        $resolver = new ResponseStatusResolver;

        self::assertSame(HttpStatus::NO_CONTENT, $resolver->resolve(PathResponseStatusController::class, 'destroyAll')?->status);
        self::assertSame(HttpStatus::ACCEPTED, $resolver->resolve(PathResponseStatusController::class, 'queue')?->status);
    }

    /**
     * Test that undeclared, missing and closure actions resolve no status.
     *
     * @return void
     */
    public function testResolvesNothingWithoutADeclaration(): void
    {
        $resolver = new ResponseStatusResolver;

        self::assertNull($resolver->resolve(PathResponseStatusController::class, 'bare'));
        self::assertNull($resolver->resolve(PathResponseStatusController::class, 'missing'));
        self::assertNull($resolver->resolve(null, \Closure::class));
    }

    /**
     * Provide the actions declaring a body beside a no-content status.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function conflictingActions(): iterable
    {
        yield 'no content' => ['update', 204];
        yield 'reset content' => ['reset', 205];
    }

    /**
     * Test that a body beside a no-content status fails, naming both.
     *
     * @param  string  $action
     * @param  int  $status
     * @return void
     */
    #[DataProvider('conflictingActions')]
    public function testABodyBesideANoContentStatusFails(string $action, int $status): void
    {
        $this->expectException(ResponseStatusConflictException::class);
        $this->expectExceptionMessage(sprintf(
            'The %d status declared for %s::%s answers with no content and cannot carry a #[ResponseSchema] body',
            $status,
            PathStatusConflictController::class,
            $action,
        ));

        (new ResponseStatusResolver)->resolve(PathStatusConflictController::class, $action);
    }
}
