<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Validation\Rejection;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Middleware\SetRequestLocale;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for query rejections worded in the request's locale.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Rejection::class)]
final class LocalisedRejectionHttpTest extends TestCase
{
    use LocalisesToolkitMessages, RegistersApiExceptionHandler;

    /** @var string The German rejection of an undeclared key */
    private const string GERMAN_UNDECLARED = 'Der Schlüssel "status" ist kein zulässiger Abfrageparameter.';

    /** @var string The German rejection of a filters value that is not an object */
    private const string GERMAN_NOT_OBJECT = 'Der Parameter filters muss ein JSON-Objekt sein.';

    /**
     * Seed a user and register the toolkit exception handler.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        $this->overrideToolkitMessages([
            'query'   => ['undeclared_key' => 'Der Schlüssel ":key" ist kein zulässiger Abfrageparameter.'],
            'filters' => ['not_object' => self::GERMAN_NOT_OBJECT],
        ]);

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
    }

    /**
     * Test that an undeclared filter key is rejected in the request's locale.
     *
     * @return void
     */
    public function testUndeclaredFilterKeyIsRejectedInTheRequestLocale(): void
    {
        $this->registerUsersRoute([SetRequestLocale::class, ParseApiQuery::class]);

        $response = $this->getJson('/users?filters=' . urlencode('{"status":"active"}'), [SetRequestLocale::HEADER => 'de']);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonPath('error.meta', ['filters.status' => [self::GERMAN_UNDECLARED]]);
    }

    /**
     * Test that the parser words its rejection in a locale set before it runs.
     *
     * @return void
     */
    public function testParserRejectionFollowsALocaleSetBeforeTheParserRuns(): void
    {
        $this->registerUsersRoute([SetRequestLocale::class, ParseApiQuery::class]);

        $response = $this->getJson('/users?filters=' . urlencode('[1]'), [SetRequestLocale::HEADER => 'de']);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonPath('error.meta', ['filters' => [self::GERMAN_NOT_OBJECT]]);
    }

    /**
     * Test that a global parser rejects before a route-level locale is set.
     *
     * @return void
     */
    public function testGlobalParserRejectsBeforeARouteLocaleIsSet(): void
    {
        $kernel = $this->app?->make(HttpKernel::class);

        assert($kernel instanceof Kernel);

        $kernel->pushMiddleware(ParseApiQuery::class);

        $this->registerUsersRoute([SetRequestLocale::class]);

        $response = $this->getJson('/users?filters=' . urlencode('[1]'), [SetRequestLocale::HEADER => 'de']);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonPath('error.meta', ['filters' => ['The filters parameter must be a JSON object.']]);
    }

    /**
     * Test that sequential requests in different locales each get their own.
     *
     * @return void
     */
    public function testSequentialRequestsEachGetTheirOwnLocale(): void
    {
        $this->registerUsersRoute([SetRequestLocale::class, ParseApiQuery::class]);

        $url = '/users?filters=' . urlencode('{"status":"active"}');

        $this->getJson($url, [SetRequestLocale::HEADER => 'de'])
            ->assertJsonPath('error.meta', ['filters.status' => [self::GERMAN_UNDECLARED]]);

        $this->getJson($url, [SetRequestLocale::HEADER => 'en'])
            ->assertJsonPath('error.meta', ['filters.status' => ['The "status" key is not a permitted query parameter for this resource.']]);
    }

    /**
     * Register the users index route behind the given middleware.
     *
     * @param  array<int, string>  $middleware
     * @return void
     */
    private function registerUsersRoute(array $middleware): void
    {
        Route::middleware($middleware)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });
    }
}
