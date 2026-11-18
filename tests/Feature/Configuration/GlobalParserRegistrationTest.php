<?php

declare(strict_types = 1);

namespace Tests\Feature\Configuration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\ShippedDefaultsTestCase;

/**
 * Feature tests for the parser the shipped configuration registers on every
 * request, against routes that are not toolkit API routes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MiddlewareRegistrar::class)]
#[CoversClass(ApiQueryParser::class)]
final class GlobalParserRegistrationTest extends ShippedDefaultsTestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a JSON webhook, a form post and a plain web page.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::post('/webhook', static fn (Request $request): JsonResponse => new JsonResponse(['received' => $request->input('event')]));
        Route::post('/contact', static fn (Request $request): Response => new Response('Sent by ' . $request->string('name')->toString()));
        Route::get('/about', static fn (): Response => new Response('<p>About</p>'));
    }

    /**
     * Provide webhook bodies carrying the parser's parameter names.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function webhookBodyProvider(): iterable
    {
        yield 'an integer page' => [['page' => 2]];
        yield 'an oversize limit' => [['limit' => 1000]];
        yield 'an object under filters' => [['filters' => ['status' => 'paid']]];
        yield 'a short search' => [['search' => 'ab']];
        yield 'an order' => [['order' => 'priority']];
        yield 'a malformed order' => [['order' => 'a:b']];
        yield 'flat fields' => [['fields' => 'name']];
        yield 'an integer aggregates' => [['aggregates' => 5]];
        yield 'an unknown trashed value' => [['trashed' => 'deleted']];
    }

    /**
     * Test that a webhook body carrying the parser's parameter names reaches
     * its route untouched.
     *
     * @param  array<string, mixed>  $body
     * @return void
     */
    #[DataProvider('webhookBodyProvider')]
    public function testWebhookBodyIsNeverParsed(array $body): void
    {
        $this->postJson('/webhook', ['event' => 'invoice.paid', ...$body])
            ->assertOk()
            ->assertExactJson(['received' => 'invoice.paid']);
    }

    /**
     * Test that a form post carrying the parser's parameter names reaches its
     * route untouched.
     *
     * @return void
     */
    public function testFormBodyIsNeverParsed(): void
    {
        $this->post('/contact', ['name' => 'Ada', 'page' => 'abc', 'search' => 'a', 'limit' => '1000'])
            ->assertOk()
            ->assertSeeText('Sent by Ada');
    }

    /**
     * Provide query strings a web page might carry that collide with the
     * parser's parameters, with the parameter each is refused on.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function collidingQueryProvider(): iterable
    {
        yield 'a page name' => ['page=abc', 'page'];
        yield 'a short search' => ['search=a', 'search'];
        yield 'a filters word' => ['filters=books', 'filters'];
        yield 'flat fields' => ['fields=name', 'fields'];
    }

    /**
     * Test that the query string of a plain web page is parsed, so a colliding
     * parameter is refused with the API envelope.
     *
     * Pins the global parser's reach; no route is exempt from it.
     *
     * @param  string  $query
     * @param  string  $parameter
     * @return void
     */
    #[DataProvider('collidingQueryProvider')]
    public function testWebPageQueryStringIsParsed(string $query, string $parameter): void
    {
        $response = $this->getJson('/about?' . $query);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.status', 422);

        self::assertSame([$parameter], array_keys((array) $response->json('error.meta')));
    }

    /**
     * Test that an oversize limit is refused on a web page and on a path with
     * no route, before routing answers it.
     *
     * Pins the global parser's reach; a missing route is not found only once
     * the query string parses.
     *
     * @return void
     */
    public function testOversizeLimitIsRefusedBeforeRouting(): void
    {
        foreach (['/about', '/does-not-exist'] as $path) {
            $this->getJson($path . '?limit=1000')
                ->assertUnprocessable()
                ->assertJsonPath('error.meta.parameter', 'limit');
        }

        $this->getJson('/does-not-exist?limit=10')->assertNotFound();
        $this->get('/about')->assertOk()->assertSee('<p>About</p>', false);
    }
}
