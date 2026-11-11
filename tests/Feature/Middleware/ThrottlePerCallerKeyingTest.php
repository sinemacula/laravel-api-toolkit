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
     * another caller or a guest.
     *
     * @return void
     */
    public function testAuthenticatedThrottleDoesNotAffectOtherCallers(): void
    {
        $userOne = User::create(['name' => 'One', 'email' => 'one@example.com']);
        $userTwo = User::create(['name' => 'Two', 'email' => 'two@example.com']);

        $this->actingAs($userOne);
        $this->getJson('/ping')->assertOk();
        $this->getJson('/ping')->assertStatus(429);

        $this->actingAs($userTwo);
        $this->getJson('/ping')->assertOk();

        // A guest is keyed by the client IP
        Auth::forgetGuards();

        $this->getJson('/ping')->assertOk();
    }
}
