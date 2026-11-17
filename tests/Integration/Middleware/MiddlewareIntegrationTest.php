<?php

declare(strict_types = 1);

namespace Tests\Integration\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\Http\Enums\HttpMethod;
use Tests\TestCase;

/**
 * Integration tests for the middleware pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ParseApiQuery::class)]
#[CoversClass(JsonPrettyPrint::class)]
final class MiddlewareIntegrationTest extends TestCase
{
    /** @var string The shared test URI. */
    private const string TEST_URI = '/test';

    /**
     * Test that ParseApiQuery middleware populates API query parser.
     *
     * @return void
     */
    public function testParseApiQueryMiddlewarePopulatesParser(): void
    {
        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), [
            'page'  => '3',
            'limit' => '25',
            'order' => 'name:desc',
        ]);

        $middleware = new ParseApiQuery;

        $middleware->handle($request, fn () => new Response('ok'));

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');

        self::assertInstanceOf(ApiQueryParser::class, $parser);
        self::assertSame(3, $parser->getPage());
        self::assertSame(25, $parser->getLimit());
        self::assertSame(['name' => 'desc'], $parser->getOrder());
    }

    /**
     * Test that JsonPrettyPrint middleware pretty-prints when requested.
     *
     * @return void
     */
    public function testJsonPrettyPrintMiddlewarePrettyPrintsWhenRequested(): void
    {
        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => '1']);

        $middleware   = new JsonPrettyPrint;
        $jsonResponse = new JsonResponse(['key' => 'value']);

        $response = $middleware->handle($request, fn () => $jsonResponse);

        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString("\n", $content);
        self::assertSame(json_encode(['key' => 'value'], JSON_PRETTY_PRINT), $content);
    }

    /**
     * Test the full middleware chain works end-to-end through the kernel.
     *
     * @return void
     */
    public function testFullMiddlewareChainWorksEndToEnd(): void
    {
        Route::middleware(ParseApiQuery::class)->get(self::TEST_URI, static fn (): JsonResponse => new JsonResponse([
            'page'  => ApiQuery::getPage(),
            'limit' => ApiQuery::getLimit(),
        ]));

        $response = $this->getJson(self::TEST_URI . '?page=2&limit=10&pretty=1');

        $response->assertOk();

        self::assertSame(json_encode(['page' => 2, 'limit' => 10], JSON_PRETTY_PRINT), $response->baseResponse->getContent());
    }
}
