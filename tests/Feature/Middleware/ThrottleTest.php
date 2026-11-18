<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\Concerns\ThrottleRequestsTrait;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for the throttle middleware through the HTTP kernel.
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
    use RecordsStatements, RegistersApiExceptionHandler;

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
     * with headers counting down to the end of the window, and that the caller
     * is served again once the window has passed.
     *
     * @return void
     */
    public function testExceedingTheRateLimitRendersTheThrottledEnvelopeUntilTheWindowPasses(): void
    {
        $this->freezeTime();

        $this->getJson('/ping')->assertOk();

        $response = $this->getJson('/ping');

        $response->assertStatus(429);
        $response->assertJsonPath('error.status', 429);
        $response->assertJsonPath('error.code', 10107);
        $response->assertHeader('Retry-After', '60');
        $response->assertHeader('X-RateLimit-Limit', '1');
        $response->assertHeader('X-RateLimit-Remaining', '0');
        $response->assertHeader('X-RateLimit-Reset', (string) now()->addSeconds(60)->timestamp);

        $this->travel(59)->seconds();

        $this->getJson('/ping')->assertStatus(429)->assertHeader('Retry-After', '1');

        $this->travel(1)->seconds();

        $this->getJson('/ping')->assertOk();
    }

    /**
     * Test that every URL of a parameterised route draws on one budget, so a
     * caller walking ids is throttled like a caller repeating one URL.
     *
     * @return void
     */
    public function testUrlsOfOneParameterisedRouteShareOneBudget(): void
    {
        Route::middleware(ThrottleRequests::class . ':1,1')->get('/users/{id}', static fn (string $id): array => ['id' => $id]);

        $this->getJson('/users/1')->assertOk();
        $this->getJson('/users/2')->assertStatus(429);
        $this->getJson('/ping')->assertOk();
    }

    /**
     * Test that a throttled request is refused without touching the database.
     *
     * @return void
     */
    public function testThrottledRequestRunsNoStatements(): void
    {
        $this->getJson('/ping')->assertOk();

        $statements = $this->recordStatements(fn () => $this->getJson('/ping')->assertStatus(429));

        self::assertStatementCount(0, $statements);
    }

    /**
     * Test that an allowed response carries the rate-limit headers with a
     * decrementing remaining count.
     *
     * @return void
     */
    public function testAllowedResponseCarriesRateLimitHeaders(): void
    {
        Route::middleware(ThrottleRequests::class . ':2,1')->get('/multi', static fn (): array => ['ok' => true]);

        $first = $this->getJson('/multi');

        $first->assertOk();
        $first->assertHeader('X-RateLimit-Limit', '2');
        $first->assertHeader('X-RateLimit-Remaining', '1');

        $second = $this->getJson('/multi');

        $second->assertOk();
        $second->assertHeader('X-RateLimit-Limit', '2');
        $second->assertHeader('X-RateLimit-Remaining', '0');
    }
}
