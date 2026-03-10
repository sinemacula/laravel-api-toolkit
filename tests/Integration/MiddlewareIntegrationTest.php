<?php

namespace Tests\Integration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
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
class MiddlewareIntegrationTest extends TestCase
{
    /** @var string The shared test URI. */
    private const TEST_URI = '/test';

    /**
     * Test that ParseApiQuery middleware populates API query parser.
     *
     * @return void
     */
    public function testParseApiQueryMiddlewarePopulatesParser(): void
    {
        $request = Request::create(self::TEST_URI, 'GET', [
            'page'  => '3',
            'limit' => '25',
            'order' => 'name:desc',
        ]);

        $middleware = new ParseApiQuery;

        $middleware->handle($request, fn () => new Response('ok'));

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');

        static::assertInstanceOf(ApiQueryParser::class, $parser);
        static::assertSame(3, $parser->getPage());
        static::assertSame(25, $parser->getLimit());
        static::assertSame(['name' => 'desc'], $parser->getOrder());
    }

    /**
     * Test that JsonPrettyPrint middleware pretty-prints when requested.
     *
     * @return void
     */
    public function testJsonPrettyPrintMiddlewarePrettyPrintsWhenRequested(): void
    {
        $request = Request::create(self::TEST_URI, 'GET', ['pretty' => '1']);

        $middleware   = new JsonPrettyPrint;
        $jsonResponse = new JsonResponse(['key' => 'value']);

        $response = $middleware->handle($request, fn () => $jsonResponse);

        $content = $response->getContent();

        static::assertStringContainsString("\n", $content);
        static::assertSame(json_encode(['key' => 'value'], JSON_PRETTY_PRINT), $content);
    }

    /**
     * Test the full middleware chain works end-to-end.
     *
     * @return void
     */
    public function testFullMiddlewareChainWorksEndToEnd(): void
    {
        $request = Request::create(self::TEST_URI, 'GET', [
            'page'   => '2',
            'limit'  => '10',
            'pretty' => '1',
        ]);

        $parseMiddleware  = new ParseApiQuery;
        $prettyMiddleware = new JsonPrettyPrint;

        $response = $parseMiddleware->handle($request, function ($request) use ($prettyMiddleware) {
            return $prettyMiddleware->handle($request, function () {
                /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
                $parser = app('api.query');

                return new JsonResponse([
                    'page'  => $parser->getPage(),
                    'limit' => $parser->getLimit(),
                ]);
            });
        });

        $content = json_decode($response->getContent(), true);

        static::assertSame(2, $content['page']);
        static::assertSame(10, $content['limit']);

        static::assertStringContainsString("\n", $response->getContent());
    }
}
