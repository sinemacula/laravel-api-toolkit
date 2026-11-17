<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

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
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for API parameters carried in a request body, which the parser
 * never reads.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(QueryParameterExtractor::class)]
#[CoversClass(QueryParameterValidator::class)]
final class RequestBodyParameterTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a read served by POST, as a client avoiding long URLs sends it,
     * over three users.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [FilterableUserResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->post('/users-search', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        User::create(['name' => 'Ben', 'email' => 'ben@example.com']);
        User::create(['name' => 'Cara', 'email' => 'cara@example.com']);
    }

    /**
     * Provide every parser key crossed with every JSON type, and the body
     * parameters that would change the read if they were parsed.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function bodyParameterProvider(): iterable
    {
        $values = [
            'an integer'      => 2,
            'a float'         => 2.5,
            'a boolean'       => true,
            'null'            => null,
            'a list'          => ['name'],
            'an object'       => ['name' => 'Anna'],
            'an empty string' => '',
        ];

        foreach (['fields', 'aggregates', 'filters', 'search', 'order', 'limit', 'page', 'cursor', 'trashed', 'pagination'] as $key) {
            foreach ($values as $label => $value) {
                yield $key . ' as ' . $label => [[$key => $value]];
            }
        }

        yield 'a page size' => [['limit' => '1']];
        yield 'a filter document' => [['filters' => '{"name":"Anna"}']];
        yield 'an order' => [['order' => 'name:desc']];
        yield 'a cursor pagination mode' => [['pagination' => 'cursor']];
        yield 'a cursor token' => [['cursor' => 'eyJpZCI6MX0']];
        yield 'a selection' => [['fields' => ['filterable_users' => 'name']]];
    }

    /**
     * Test that a body parameter of any type is ignored, so the read is the
     * unparameterised offset page.
     *
     * @param  array<string, mixed>  $body
     * @return void
     */
    #[DataProvider('bodyParameterProvider')]
    public function testBodyParameterIsIgnored(array $body): void
    {
        $response = $this->postJson('/users-search', $body);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);

        self::assertSame(['Anna', 'Ben', 'Cara'], array_column((array) $response->json('data'), 'name'));
        self::assertSame(['_type', 'id', 'email', 'name'], array_keys((array) $response->json('data.0')));
    }

    /**
     * Test that the query string drives the read when the body disagrees with
     * it.
     *
     * @return void
     */
    public function testQueryStringIsReadOverAConflictingBody(): void
    {
        $response = $this->postJson('/users-search?' . http_build_query([
            'limit'   => '1',
            'filters' => '{"name":{"$in":["Ben","Cara"]}}',
        ]), [
            'limit'   => '3',
            'filters' => '{"name":"Anna"}',
        ]);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);

        self::assertSame(['Ben'], array_column((array) $response->json('data'), 'name'));
    }

    /**
     * Test that the next link of a filtered read keeps its filters, since they
     * travel in the query string.
     *
     * @return void
     */
    public function testNextLinkKeepsTheQueryStringFilters(): void
    {
        $query = ['limit' => '1', 'filters' => '{"name":{"$in":["Ben","Cara"]}}'];

        $next = $this->postJson('/users-search?' . http_build_query($query))->assertOk()->json('links.next');

        self::assertIsString($next);

        parse_str((string) parse_url($next, PHP_URL_QUERY), $nextQuery);

        self::assertSame([...$query, 'page' => '2'], $nextQuery);
    }

    /**
     * Test that an empty aggregates parameter, which the empty-string
     * middleware turns into null, is a 422 on the parameter.
     *
     * @return void
     */
    public function testEmptyAggregatesParameterIsRejected(): void
    {
        $response = $this->postJson('/users-search?aggregates=');

        $response->assertUnprocessable();

        self::assertSame(['aggregates' => ['The aggregates parameter must be keyed by resource type, e.g. aggregates[users]=posts_count.']], $response->json('error.meta'));
    }

    /**
     * Test that aggregates listed without a type are a 422 naming the integer
     * key as an unknown type.
     *
     * @return void
     */
    public function testListedAggregatesAreRejectedAsAnUnknownType(): void
    {
        $response = $this->postJson('/users-search?aggregates[]=x');

        $response->assertUnprocessable();

        self::assertSame(['aggregates.0' => ['The "0" resource type is not known.']], $response->json('error.meta'));
    }
}
