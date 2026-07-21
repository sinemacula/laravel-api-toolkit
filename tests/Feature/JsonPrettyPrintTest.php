<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use Tests\TestCase;

/**
 * Feature tests for the JSON pretty-print middleware through the HTTP kernel.
 *
 * Dispatches real requests against a route wrapped in the pretty-print
 * middleware and proves the query-string toggle survives the full request flow:
 * a truthy pretty parameter renders indented JSON while its absence or a falsey
 * value leaves the compact envelope untouched.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(JsonPrettyPrint::class)]
final class JsonPrettyPrintTest extends TestCase
{
    /** @var array<string, mixed> The payload rendered by the test route. */
    private const array PAYLOAD = ['key' => 'value', 'nested' => ['a' => 1]];

    /**
     * Set up each test with a route returning a fixed JSON payload behind the
     * pretty-print middleware.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(JsonPrettyPrint::class)->get('/api/pretty', static fn (): JsonResponse => new JsonResponse(self::PAYLOAD));
    }

    /**
     * Test that a truthy pretty parameter renders indented JSON over HTTP.
     *
     * @return void
     */
    public function testPrettyParameterRendersIndentedJson(): void
    {
        $response = $this->getJson('/api/pretty?pretty=1');

        $response->assertOk();

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertStringContainsString("\n", $content);
        self::assertSame(json_encode(self::PAYLOAD, JSON_PRETTY_PRINT), $content);
    }

    /**
     * Test that an omitted pretty parameter leaves the compact envelope
     * untouched over HTTP.
     *
     * @return void
     */
    public function testMissingPrettyParameterRendersCompactJson(): void
    {
        $response = $this->getJson('/api/pretty');

        $response->assertOk();

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString("\n", $content);
        self::assertSame(json_encode(self::PAYLOAD), $content);
    }

    /**
     * Test that a falsey pretty parameter leaves the compact envelope untouched
     * over HTTP.
     *
     * @return void
     */
    public function testFalseyPrettyParameterRendersCompactJson(): void
    {
        $response = $this->getJson('/api/pretty?pretty=0');

        $response->assertOk();

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertSame(json_encode(self::PAYLOAD), $content);
    }
}
