<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature test for an imperative field override on a single resource.
 *
 * Drives a route that returns a single UserResource with withoutFields()
 * applied, proving the exclusion reaches the rendered response body: a default
 * field is removed while the remaining default and fixed fields survive.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(FieldResolver::class)]
final class FieldOverrideHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a single-resource route and a seeded user.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/user', function (): UserResource {

            $user = User::query()->firstOrFail();

            return (new UserResource($user))->withoutFields(['email']);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
    }

    /**
     * Test that withoutFields() removes a default field from the response body
     * while the remaining default and fixed fields survive.
     *
     * @return void
     */
    public function testWithoutFieldsRemovesFieldFromBody(): void
    {
        $response = $this->getJson('/user');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Alice');
        $response->assertJsonPath('data._type', 'users');

        /** @var array<string, mixed> $record */
        $record = $response->json('data');

        self::assertArrayHasKey('id', $record);
        self::assertArrayNotHasKey('email', $record);
    }
}
