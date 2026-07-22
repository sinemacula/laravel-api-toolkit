<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\DetectsCapabilities;
use SineMacula\ApiToolkit\Http\RequestCapabilities;
use Tests\TestCase;

/**
 * Feature tests for the capability-detection middleware over the HTTP kernel.
 *
 * Dispatches real requests carrying the trashed-record query parameters and
 * proves the flags the middleware resolves are visible to a downstream
 * handler via RequestCapabilities::fromRequest, so the query string
 * round-trips through the live request into the typed capabilities object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DetectsCapabilities::class)]
#[CoversClass(RequestCapabilities::class)]
final class DetectsCapabilitiesTest extends TestCase
{
    /**
     * Set up each test with a route that echoes the resolved capabilities.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(DetectsCapabilities::class)->get('/capabilities', static function (Request $request): array {

            $capabilities = RequestCapabilities::fromRequest($request);

            return [
                'include_trashed' => $capabilities->includeTrashed(),
                'only_trashed'    => $capabilities->onlyTrashed(),
            ];
        });
    }

    /**
     * Test that the include-trashed parameter surfaces downstream.
     *
     * @return void
     */
    public function testIncludeTrashedParameterSurfacesDownstream(): void
    {
        $response = $this->getJson('/capabilities?include_trashed=true');

        $response->assertOk();
        $response->assertJsonPath('include_trashed', true);
        $response->assertJsonPath('only_trashed', false);
    }

    /**
     * Test that the only-trashed parameter surfaces downstream.
     *
     * @return void
     */
    public function testOnlyTrashedParameterSurfacesDownstream(): void
    {
        $response = $this->getJson('/capabilities?only_trashed=true');

        $response->assertOk();
        $response->assertJsonPath('include_trashed', false);
        $response->assertJsonPath('only_trashed', true);
    }

    /**
     * Test that both capability flags default to false when unrequested.
     *
     * @return void
     */
    public function testCapabilitiesDefaultToFalseWhenUnrequested(): void
    {
        $response = $this->getJson('/capabilities');

        $response->assertOk();
        $response->assertJsonPath('include_trashed', false);
        $response->assertJsonPath('only_trashed', false);
    }
}
