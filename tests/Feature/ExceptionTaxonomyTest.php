<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Feature tests rounding out the exception taxonomy through the real kernel.
 *
 * With the toolkit handler wired as a consuming application wires it, a missing
 * model surfaces as a not-found envelope and an authorization failure surfaces
 * as a forbidden envelope, each carrying the matching HTTP status and internal
 * error code.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class ExceptionTaxonomyTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the toolkit handler wired and throwing routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        // findOrFail on an empty table raises a ModelNotFoundException, which
        // Laravel prepares into a NotFoundHttpException before the toolkit
        // render callback maps it to the not-found envelope.
        Route::get('/api/model-missing', static function (): void {
            User::findOrFail(99999);
        });

        // A statusless AuthorizationException is prepared into an
        // AccessDeniedHttpException, which the toolkit maps to the forbidden
        // envelope. abort(403) would instead yield a generic HTTP error code.
        Route::get('/api/forbidden', static function (): never {
            throw new AuthorizationException('This action is unauthorized.');
        });
    }

    /**
     * Test that a missing model renders as a 404 not-found envelope.
     *
     * @return void
     */
    public function testModelNotFoundIsRenderedAsNotFound(): void
    {
        $response = $this->getJson('/api/model-missing');

        $response->assertStatus(404);
        $response->assertJsonPath('error.status', 404);
        $response->assertJsonPath('error.code', 10103);
    }

    /**
     * Test that an authorization failure renders as a 403 forbidden envelope.
     *
     * @return void
     */
    public function testAuthorizationFailureIsRenderedAsForbidden(): void
    {
        $response = $this->getJson('/api/forbidden');

        $response->assertStatus(403);
        $response->assertJsonPath('error.status', 403);
        $response->assertJsonPath('error.code', 10102);
    }
}
