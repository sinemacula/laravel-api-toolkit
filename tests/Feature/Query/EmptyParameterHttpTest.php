<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for parameters sent with an empty value, with and without the
 * middleware that turns an empty string into null.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(QueryParameterExtractor::class)]
#[CoversClass(QueryParameterValidator::class)]
final class EmptyParameterHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a users index over two users, one with posts.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        Post::create(['user_id' => $alice->id, 'title' => 'First', 'body' => 'Content', 'published' => true]);
    }

    /**
     * Provide each parameter sent empty, which arrives as null, with the errors
     * it is refused with.
     *
     * @return iterable<string, array{string, array<string, array<int, string>>}>
     */
    public static function nullParameterProvider(): iterable
    {
        yield 'fields' => ['fields', ['fields' => ['The fields parameter must be keyed by resource type, e.g. fields[users]=name.']]];
        yield 'aggregates' => ['aggregates', ['aggregates' => ['The aggregates parameter must be keyed by resource type, e.g. aggregates[users]=posts_count.']]];
        yield 'filters' => ['filters', ['filters' => ['The filters field must be a valid JSON string.']]];
        yield 'search' => ['search', ['search' => ['The search field must be a string.']]];
        yield 'order' => ['order', ['order' => ['The order field must be a string.']]];
        yield 'limit' => ['limit', ['limit' => ['The limit field must be an integer.', 'The limit field must be at least 1.']]];
        yield 'page' => ['page', ['page' => ['The page field must be an integer.', 'The page field must be at least 1.']]];
        yield 'cursor' => ['cursor', ['cursor' => ['The cursor field must be a string.']]];
        yield 'trashed' => ['trashed', ['trashed' => ['The trashed field must have a value.']]];
    }

    /**
     * Test that a parameter sent empty through the default stack is refused
     * with the 422 envelope keyed on that parameter.
     *
     * @param  string  $parameter
     * @param  array<string, array<int, string>>  $errors
     * @return void
     */
    #[DataProvider('nullParameterProvider')]
    public function testEmptyParameterIsRefusedOnTheParameter(string $parameter, array $errors): void
    {
        $response = $this->getJson('/users?' . $parameter . '=');

        $response->assertUnprocessable();
        $response->assertJsonPath('error.status', 422);

        self::assertSame($errors, $response->json('error.meta'));
    }

    /**
     * Test that an empty pagination mode reads by offset.
     *
     * @return void
     */
    public function testEmptyPaginationReadsByOffset(): void
    {
        $this->getJson('/users?pagination=')->assertOk()->assertJsonPath('meta.total', 2);
    }

    /**
     * Test that an empty fieldset returns the fixed fields only, and an empty
     * aggregate selection none of the defaults, with or without the empty
     * string conversion.
     *
     * @return void
     */
    public function testEmptySelectionsMeanTheSameWithOrWithoutTheConversion(): void
    {
        foreach ([false, true] as $converted) {

            if (!$converted) {
                $this->withoutMiddleware([ConvertEmptyStringsToNull::class, TrimStrings::class]);
            }

            $fixed = $this->getJson('/users?fields[users]=')->assertOk();

            self::assertSame(['_type', 'id'], array_keys((array) $fixed->json('data.0')));

            $aggregates = $this->getJson('/users?fields[users]=name,aggregates&aggregates[users]=')->assertOk();

            self::assertSame(['_type', 'id', 'name'], array_keys((array) $aggregates->json('data.0')));

            $this->withMiddleware();
        }
    }

    /**
     * Test that, without the empty-string conversion, an empty order means no
     * order, an empty cursor reads the first page by cursor and an empty page
     * size or page falls back to the defaults.
     *
     * @return void
     */
    public function testEmptyStringsKeepTheirMeaningWithoutTheConversion(): void
    {
        $this->withoutMiddleware([ConvertEmptyStringsToNull::class, TrimStrings::class]);

        $ordered = $this->getJson('/users?order=&limit=&page=')->assertOk();

        self::assertSame(['Alice', 'Bob'], array_column((array) $ordered->json('data'), 'name'));

        $ordered->assertJsonPath('meta.total', 2);

        $cursor = $this->getJson('/users?cursor=')->assertOk();

        self::assertSame(['Alice', 'Bob'], array_column((array) $cursor->json('data'), 'name'));
        self::assertNull($cursor->json('meta.total'));
    }

    /**
     * Provide parameters still refused when sent as an empty string, with the
     * errors each is refused with.
     *
     * @return iterable<string, array{string, array<string, array<int, string>>}>
     */
    public static function emptyStringParameterProvider(): iterable
    {
        yield 'fields' => ['fields', ['fields' => ['The fields parameter must be keyed by resource type, e.g. fields[users]=name.']]];
        yield 'aggregates' => ['aggregates', ['aggregates' => ['The aggregates parameter must be keyed by resource type, e.g. aggregates[users]=posts_count.']]];
        yield 'filters' => ['filters', ['filters' => ['The filters parameter must be a JSON object.']]];
        yield 'trashed' => ['trashed', ['trashed' => ['The trashed field must have a value.']]];
    }

    /**
     * Test that, without the empty-string conversion, an empty selection,
     * filter document or trashed value is still refused on the parameter.
     *
     * @param  string  $parameter
     * @param  array<string, array<int, string>>  $errors
     * @return void
     */
    #[DataProvider('emptyStringParameterProvider')]
    public function testEmptyStringIsRefusedWithoutTheConversion(string $parameter, array $errors): void
    {
        $this->withoutMiddleware([ConvertEmptyStringsToNull::class, TrimStrings::class]);

        $response = $this->getJson('/users?' . $parameter . '=');

        $response->assertUnprocessable();

        self::assertSame($errors, $response->json('error.meta'));
    }
}
