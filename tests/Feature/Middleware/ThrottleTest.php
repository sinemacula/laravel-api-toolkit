<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\Concerns\ThrottleRequestsTrait;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for the throttle middleware through the HTTP kernel.
 *
 * Dispatches real requests against a rate-limited route and asserts that an
 * exhausted bucket produces the toolkit too-many-requests envelope with a
 * Retry-After header, rather than the framework default.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequests::class)]
#[CoversClass(ApiExceptionHandler::class)]
#[CoversTrait(ThrottleRequestsTrait::class)]
final class ThrottleTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a route limited to a single request.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ThrottleRequests::class . ':1,1')->get('/ping', static fn (): array => ['ok' => true]);
    }

    /**
     * Test that exceeding the rate limit renders the toolkit throttled envelope
     * with a Retry-After header.
     *
     * @return void
     */
    public function testExceedingTheRateLimitRendersTheThrottledEnvelope(): void
    {
        $this->getJson('/ping')->assertOk();

        $response = $this->getJson('/ping');

        $response->assertStatus(429);
        $response->assertJsonPath('error.status', 429);
        $response->assertJsonPath('error.code', 10107);
        $response->assertHeader('Retry-After');
    }

    /**
     * Test that an allowed response carries the rate-limit headers and the
     * remaining count decrements across successive successful requests.
     *
     * @return void
     */
    public function testAllowedResponseCarriesRateLimitHeaders(): void
    {
        Route::middleware(ThrottleRequests::class . ':2,1')->get('/multi', static fn (): array => ['ok' => true]);

        $first = $this->getJson('/multi');

        $first->assertOk();
        $first->assertHeader('X-RateLimit-Limit', 2);
        $first->assertHeader('X-RateLimit-Remaining', 1);

        $second = $this->getJson('/multi');

        $second->assertOk();
        $second->assertHeader('X-RateLimit-Limit', 2);
        $second->assertHeader('X-RateLimit-Remaining', 0);
    }

    /**
     * Test that the throttled response carries the retry and reset headers.
     *
     * @return void
     */
    public function testThrottledResponseCarriesRetryAndResetHeaders(): void
    {
        $this->getJson('/ping')->assertOk();

        $response = $this->getJson('/ping');

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $response->assertHeader('X-RateLimit-Reset');
    }
}
