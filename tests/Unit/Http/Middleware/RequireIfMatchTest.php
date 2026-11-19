<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\PreconditionRequiredException;
use SineMacula\ApiToolkit\Http\Middleware\RequireIfMatch;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

/**
 * Tests for the RequireIfMatch middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequireIfMatch::class)]
final class RequireIfMatchTest extends TestCase
{
    /**
     * Provide each write method with a field that does not set a precondition.
     *
     * @return iterable<string, array{string, list<string>|null}>
     */
    public static function missingFieldProvider(): iterable
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            yield $method . ' without the field' => [$method, null];
            yield $method . ' with a blank field' => [$method, [' ']];
            yield $method . ' with empty list members only' => [$method, ['', " ,\t"]];
        }
    }

    /**
     * Test that a write without a usable If-Match field is refused before it
     * reaches the action.
     *
     * @param  string  $method
     * @param  list<string>|null  $lines
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PreconditionRequiredException
     */
    #[DataProvider('missingFieldProvider')]
    public function testWriteWithoutTheFieldIsRefused(string $method, ?array $lines): void
    {
        $this->expectException(PreconditionRequiredException::class);

        $this->handle($this->request($method, $lines));
    }

    /**
     * Provide requests the middleware passes through.
     *
     * @return iterable<string, array{string, list<string>|null}>
     */
    public static function passingProvider(): iterable
    {
        yield 'GET' => ['GET', null];
        yield 'HEAD' => ['HEAD', null];
        yield 'POST' => ['POST', null];
        yield 'PATCH with a tag' => ['PATCH', ['W/"a"']];
        yield 'DELETE with a malformed field' => ['DELETE', ['a']];
    }

    /**
     * Test that a read, a create, or a write sending the field reaches the
     * action.
     *
     * @param  string  $method
     * @param  list<string>|null  $lines
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PreconditionRequiredException
     */
    #[DataProvider('passingProvider')]
    public function testOtherRequestsReachTheAction(string $method, ?array $lines): void
    {
        self::assertSame('reached', $this->handle($this->request($method, $lines))->getContent());
    }

    /**
     * Run the middleware in front of an action answering "reached".
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PreconditionRequiredException
     */
    private function handle(Request $request): SymfonyResponse
    {
        return (new RequireIfMatch)->handle($request, static fn (): Response => new Response('reached'));
    }

    /**
     * Build a request with the given method and If-Match lines.
     *
     * @param  string  $method
     * @param  list<string>|null  $lines
     * @return \Illuminate\Http\Request
     */
    private function request(string $method, ?array $lines): Request
    {
        $request = Request::create('/documents/1', $method);

        if ($lines !== null) {
            $request->headers->set('If-Match', $lines);
        }

        return $request;
    }
}
