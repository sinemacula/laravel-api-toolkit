<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for the exception render-strategy matrix through the kernel.
 *
 * These prove the strategy switch and the debug controls that govern the
 * rendered error body: always_json forces the JSON envelope even for an
 * HTML-accepting client, include_debug_info surfaces the underlying throwable
 * internals, auto defers to the framework HTML page for a non-JSON request in
 * debug, and the pretty flag indents the rendered error body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class ExceptionRenderStrategyTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the toolkit handler wired and failing routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::get('/abort-conflict', static function (): never {
            abort(409);
        });

        Route::get('/unhandled', static function (): never {
            throw new \RuntimeException('Something broke internally');
        });
    }

    /**
     * Test that the always_json strategy forces the JSON envelope for a request
     * that accepts HTML rather than JSON.
     *
     * @return void
     */
    public function testAlwaysJsonForcesJsonEnvelopeForHtmlRequest(): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', 'always_json');

        $response = $this->get('/abort-conflict', ['Accept' => 'text/html']);

        $response->assertStatus(409);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('error.status', 409);
        $response->assertJsonPath('error.code', 10113);
    }

    /**
     * Test that include_debug_info surfaces the underlying throwable internals
     * into the rendered error meta even when the framework debug flag is off.
     *
     * @return void
     */
    public function testIncludeDebugInfoSurfacesThrowableInternals(): void
    {
        Config::set('app.debug', false);
        Config::set('api-toolkit.exceptions.include_debug_info', true);

        $response = $this->getJson('/unhandled');

        $response->assertStatus(500);
        $response->assertJsonPath('error.meta.message', 'Something broke internally');
        $response->assertJsonPath('error.meta.exception', \RuntimeException::class);

        $meta = $response->json('error.meta');

        self::assertIsArray($meta);
        self::assertArrayHasKey('file', $meta);
        self::assertArrayHasKey('line', $meta);
        self::assertArrayHasKey('trace', $meta);
        self::assertIsArray($meta['trace']);
    }

    /**
     * Test that the auto strategy defers to the framework HTML error page for a
     * non-JSON request when debug mode is enabled.
     *
     * @return void
     */
    public function testAutoDefersToHtmlForNonJsonRequestInDebug(): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', 'auto');
        Config::set('app.debug', true);

        $response = $this->get('/abort-conflict', ['Accept' => 'text/html']);

        $response->assertStatus(409);

        $contentType = $response->headers->get('Content-Type');

        self::assertIsString($contentType);
        self::assertStringContainsString('text/html', $contentType);

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString('"error":', $content);
    }

    /**
     * Test that the pretty flag indents the rendered error body while the
     * absence of the flag keeps it compact.
     *
     * @return void
     */
    public function testPrettyFlagIndentsErrorBody(): void
    {
        $pretty = $this->getJson('/abort-conflict?pretty=1');

        $pretty->assertStatus(409);

        $prettyContent = $pretty->baseResponse->getContent();

        self::assertIsString($prettyContent);
        self::assertStringContainsString("\n", $prettyContent);
        self::assertStringContainsString('    ', $prettyContent);

        $compact = $this->getJson('/abort-conflict');

        $compact->assertStatus(409);

        $compactContent = $compact->baseResponse->getContent();

        self::assertIsString($compactContent);
        self::assertStringNotContainsString("\n", $compactContent);
    }
}
