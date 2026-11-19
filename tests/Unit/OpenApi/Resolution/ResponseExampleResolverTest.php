<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseExample;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseExampleResolver;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\OpenApi\PathResponseExampleController;

/**
 * Tests for the response example resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseExampleResolver::class)]
final class ResponseExampleResolverTest extends TestCase
{
    /**
     * Test that the examples are resolved in declaration order.
     *
     * @return void
     */
    public function testResolvesTheExamplesInDeclarationOrder(): void
    {
        self::assertEquals(
            [
                new ResponseExample(HttpStatus::OK, 'pending', 'auth/pending.json'),
                new ResponseExample(HttpStatus::OK, 'signed-in', 'auth/signed-in.json'),
            ],
            (new ResponseExampleResolver)->forAction(PathResponseExampleController::class, 'challenge'),
        );
    }

    /**
     * Test that an action, closure or missing method declaring nothing resolves
     * no example.
     *
     * @return void
     */
    public function testUndeclaredActionResolvesNone(): void
    {
        $resolver = new ResponseExampleResolver;

        self::assertSame([], $resolver->forAction(PathResponseExampleController::class, 'bare'));
        self::assertSame([], $resolver->forAction(null, 'signIn'));
        self::assertSame([], $resolver->forAction(PathResponseExampleController::class, 'missing'));
    }

    /**
     * Test that a name declared twice fails.
     *
     * @return void
     */
    public function testNameDeclaredTwiceFails(): void
    {
        $this->expectException(ExampleDeclarationException::class);
        $this->expectExceptionMessage(sprintf('The response example [signed-in] is declared more than once on %s::duplicated.', PathResponseExampleController::class));

        (new ResponseExampleResolver)->forAction(PathResponseExampleController::class, 'duplicated');
    }
}
