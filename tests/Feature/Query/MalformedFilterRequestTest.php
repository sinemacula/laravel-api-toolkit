<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for malformed filters payloads being rejected up front.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class MalformedFilterRequestTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a repository-backed users route and seed a row.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    }

    /**
     * Test that a non-JSON filters value renders the 422 envelope keyed on the
     * filters parameter.
     *
     * @return void
     */
    public function testNonJsonFiltersValueIsRejectedWithValidationEnvelope(): void
    {
        $response = $this->getJson('/users?filters=' . urlencode('{not-valid-json'));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.code', 10106);

        self::assertArrayHasKey('filters', (array) $response->json('error.meta'));
        self::assertArrayNotHasKey('filters.status', (array) $response->json('error.meta'));
    }

    /**
     * Test that a filters document nested beyond the decoder depth limit is
     * rejected.
     *
     * @return void
     */
    public function testFiltersNestedBeyondTheDecoderDepthLimitAreRejected(): void
    {
        $filters = str_repeat('{"a":', 512) . '1' . str_repeat('}', 512);

        $response = $this->getJson('/users?filters=' . urlencode($filters));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);

        self::assertArrayHasKey('filters', (array) $response->json('error.meta'));
    }

    /**
     * Test that a JSON list is rejected rather than read as no filters.
     *
     * @return void
     */
    public function testJsonListFiltersValueIsRejected(): void
    {
        $response = $this->getJson('/users?filters=' . urlencode('[{"name":"Alice"}]'));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);

        self::assertArrayHasKey('filters', (array) $response->json('error.meta'));
    }

    /**
     * Test that a field given a bare list of values is rejected rather than
     * raising a type error.
     *
     * @return void
     */
    public function testFieldGivenABareListOfValuesIsRejected(): void
    {
        $response = $this->getJson('/users?filters=' . urlencode('{"name":["Alice","Bob"]}'));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);

        self::assertArrayHasKey('name', (array) $response->json('error.meta'));
    }

    /**
     * Test that a logical group given a bare list is rejected rather than
     * raising a type error.
     *
     * @return void
     */
    public function testLogicalGroupGivenABareListIsRejected(): void
    {
        $filters = '{"$or":[{"name":"Alice"},{"name":"Bob"}]}';

        $response = $this->getJson('/users?filters=' . urlencode($filters));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
    }
}
