<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\Concerns\ThrottleRequestsTrait;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Feature tests for the per-caller throttle keying through the HTTP kernel.
 *
 * Drives a rate-limited route with several distinct callers to prove the
 * signature the throttle trait builds gives every caller its own bucket: an
 * authenticated caller exhausting its limit and receiving a 429 must not
 * consume the allowance of a second authenticated caller (keyed by a different
 * user identifier) or of a guest (keyed by the client IP).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequests::class)]
#[CoversClass(ApiExceptionHandler::class)]
#[CoversTrait(ThrottleRequestsTrait::class)]
final class ThrottlePerCallerKeyingTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a route limited to a single request per caller.
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
     * Test that an authenticated caller exhausting its bucket does not throttle
     * a second authenticated caller or a guest.
     *
     * @return void
     */
    public function testAuthenticatedThrottleDoesNotAffectOtherCallers(): void
    {
        $userOne = User::create(['name' => 'One', 'email' => 'one@example.com']);
        $userTwo = User::create(['name' => 'Two', 'email' => 'two@example.com']);

        // The first caller exhausts its own single-request bucket.
        $this->actingAs($userOne);
        $this->getJson('/ping')->assertOk();
        $this->getJson('/ping')->assertStatus(429);

        // A second authenticated caller is keyed by a different identifier and
        // still has its full allowance.
        $this->actingAs($userTwo);
        $this->getJson('/ping')->assertOk();

        // A guest is keyed by the client IP rather than a user identifier, so
        // it is unaffected by the first caller's exhausted bucket.
        Auth::forgetGuards();

        $this->getJson('/ping')->assertOk();
    }
}
