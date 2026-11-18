<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for malformed UTF-8 reaching a query parameter or an error body
 * through the kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryParameterValidator::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class MalformedEncodingRequestTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a repository-backed users route and a route validating a body.
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

        Route::post('/items', static fn (Request $request): array => $request->validate(['items.*.name' => 'required']));

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        Exceptions::fake();
    }

    /**
     * Provide query strings carrying malformed UTF-8 and the parameter each is
     * refused on.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function malformedQueryProvider(): iterable
    {
        yield 'order key' => ['order=%FF', 'order'];
        yield 'order key with valid neighbours' => ['order=n%FFme', 'order'];
        yield 'order direction' => ['order=name:%FF', 'order'];
        yield 'fields type' => ['fields[%FF]=name', 'fields'];
        yield 'fields name' => ['fields[filterable_users]=%FF', 'fields'];
        yield 'aggregates type' => ['aggregates[%FF]=posts_count', 'aggregates'];
        yield 'aggregates name' => ['aggregates[filterable_users]=%FF', 'aggregates'];
        yield 'search term' => ['search=abc%FFdef', 'search'];
        yield 'filters document' => ['filters=' . urlencode('{"name":"') . '%FF' . urlencode('"}'), 'filters'];
        yield 'cursor' => ['cursor=%FF', 'cursor'];
        yield 'trashed' => ['trashed=%FF', 'trashed'];
    }

    /**
     * Test that malformed UTF-8 in a parser parameter is a 422 on that
     * parameter that echoes none of its bytes and reports nothing.
     *
     * @param  string  $query
     * @param  string  $parameter
     * @return void
     */
    #[DataProvider('malformedQueryProvider')]
    public function testMalformedQueryParameterIsRejectedOnTheParameter(string $query, string $parameter): void
    {
        $response = $this->getJson('/users?' . $query);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 10106);
        $response->assertJsonPath('error.meta', [$parameter => ['The ' . $parameter . ' parameter must be valid UTF-8.']]);

        Exceptions::assertNothingReported();
    }

    /**
     * Test that a validation failure echoing a malformed body key renders as a
     * 422 with the bytes substituted and reports nothing.
     *
     * @return void
     */
    public function testMalformedKeyInAnApplicationValidationFailureIsSubstituted(): void
    {
        $response = $this->post('/items', ['items' => ["\xFF" => ['x' => '1']]], ['Accept' => 'application/json']);

        $response->assertStatus(422);

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertSame(
            ["items.\u{FFFD}.name" => ["The items.\u{FFFD}.name field is required."]],
            json_decode($content, true, flags: JSON_THROW_ON_ERROR)['error']['meta'],
        );

        Exceptions::assertNothingReported();
    }
}
