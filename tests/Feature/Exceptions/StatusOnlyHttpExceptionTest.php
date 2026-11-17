<?php

declare(strict_types = 1);

namespace Tests\Feature\Exceptions;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Feature tests for HTTP exceptions carrying only a status, such as abort() and
 * a policy denial with a status, through the real kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
#[CoversClass(ExceptionMap::class)]
final class StatusOnlyHttpExceptionTest extends TestCase
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

        Gate::define('peek', static fn (?Authenticatable $user): Response => Response::denyAsNotFound());
        Gate::define('edit', static fn (?Authenticatable $user): Response => Response::denyWithStatus(403));

        Route::get('/hidden', static function (): void {
            Gate::authorize('peek');
        });

        Route::get('/missing', static function (): void {
            User::findOrFail(99999);
        });

        Route::get('/denied', static function (): void {
            Gate::authorize('edit');
        });

        Route::get('/abort/{status}', static function (int $status): never {
            abort($status);
        });
    }

    /**
     * Test that a record hidden by denyAsNotFound() answers exactly as a
     * missing one.
     *
     * @return void
     */
    public function testHiddenRecordAnswersAsAMissingOne(): void
    {
        $missing = $this->getJson('/missing');

        $missing->assertStatus(404);
        $missing->assertJsonPath('error.code', 10103);

        $this->getJson('/hidden')->assertStatus(404)->assertExactJson((array) $missing->json());
    }

    /**
     * Provide status-only failures with the status, code and detail each
     * renders.
     *
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function statusOnlyFailureProvider(): iterable
    {
        yield 'abort(401)' => ['/abort/401', 401, 10101, 'This request requires authentication'];
        yield 'abort(403)' => ['/abort/403', 403, 10102, 'You do not have the necessary privileges to perform this action'];
        yield 'denyWithStatus(403)' => ['/denied', 403, 10102, 'You do not have the necessary privileges to perform this action'];
        yield 'abort(404)' => ['/abort/404', 404, 10103, 'The requested resource could not be found'];
        yield 'abort(429)' => ['/abort/429', 429, 10107, 'The resource was requested too frequently'];
        yield 'abort(503)' => ['/abort/503', 503, 10112, 'The service is temporarily unavailable, please try again a little later'];
    }

    /**
     * Test that a status-only failure renders the dedicated code and detail of
     * its status.
     *
     * @param  string  $uri
     * @param  int  $status
     * @param  int  $code
     * @param  string  $detail
     * @return void
     */
    #[DataProvider('statusOnlyFailureProvider')]
    public function testStatusOnlyFailureRendersItsStatusDedicatedError(string $uri, int $status, int $code, string $detail): void
    {
        $response = $this->getJson($uri);

        $response->assertStatus($status);
        $response->assertJsonPath('error.status', $status);
        $response->assertJsonPath('error.code', $code);
        $response->assertJsonPath('error.detail', $detail);
    }
}
