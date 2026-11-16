<?php

declare(strict_types = 1);

namespace Tests\Feature\Configuration;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as Notifier;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\PolymorphicResource;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MorphMapRegistrar;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\ShippedDefaultsTestCase;

/**
 * Feature tests serving requests on the shipped configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(MiddlewareRegistrar::class)]
#[CoversClass(MorphMapRegistrar::class)]
#[CoversClass(LoggingRegistrar::class)]
final class ShippedDefaultsHttpTest extends ShippedDefaultsTestCase
{
    use RegistersApiExceptionHandler;

    /** @var array<string, mixed> The index query every listing test sends */
    private const array INDEX_QUERY = [
        'filters' => '{"status":"active"}',
        'order'   => 'name:desc',
        'limit'   => '2',
        'fields'  => ['users' => 'name'],
    ];

    /**
     * Set up an index served by the global parser alone, the same index with
     * the parser attached again and a show route, and seed users.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        $index = static fn (UserRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $repository->usingResource(UserResource::class)->withApiCriteria()->paginate(),
            UserResource::class,
        );

        Route::get('/users', $index);
        Route::middleware(ParseApiQuery::class)->get('/parsed-users', $index);
        Route::get('/users/{id}', static fn (string $id): UserResource => new UserResource(User::query()->findOrFail($id)));

        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active', 'organization_id' => $organization->id]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'active']);
        User::create(['name' => 'Dave', 'email' => 'dave@example.com', 'status' => 'inactive']);
    }

    /**
     * Test that schema validation is on outside production, so every test here
     * boots by validating the resource map.
     *
     * @return void
     */
    public function testApplicationBootsWithSchemaValidationOn(): void
    {
        self::assertTrue(Config::get('api-toolkit.resources.validate_schemas'));
    }

    /**
     * Test that an index filters, orders, limits and narrows its fields on a
     * route that does not attach the parser.
     *
     * @return void
     */
    public function testIndexAppliesTheQueryOnARouteWithoutTheParser(): void
    {
        $response = $this->getJson('/users?' . http_build_query(self::INDEX_QUERY));

        $response->assertOk();

        self::assertSame(['Carol', 'Bob'], array_column((array) $response->json('data'), 'name'));
        self::assertSame(['_type', 'id', 'name'], array_keys((array) $response->json('data.0')));

        $response->assertJsonPath('meta.total', 3);

        $next = $response->json('links.next');

        self::assertIsString($next);

        parse_str((string) parse_url($next, PHP_URL_QUERY), $nextQuery);

        self::assertSame([...self::INDEX_QUERY, 'page' => '2'], $nextQuery);
    }

    /**
     * Test that attaching the parser to a route again leaves the response
     * unchanged but for the route's own path.
     *
     * @return void
     */
    public function testRouteAttachingTheParserAgainRespondsIdentically(): void
    {
        $query = '?' . http_build_query(self::INDEX_QUERY);

        $global = $this->getJson('/users' . $query)->assertOk()->baseResponse->getContent();
        $routed = $this->getJson('/parsed-users' . $query)->assertOk()->baseResponse->getContent();

        self::assertIsString($routed);
        self::assertSame($global, str_replace('parsed-users', 'users', $routed));
    }

    /**
     * Test that a filter on a column the resource does not declare filterable
     * is refused with the validation envelope.
     *
     * @return void
     */
    public function testUndeclaredFilterIsRefusedWithTheValidationEnvelope(): void
    {
        $response = $this->getJson('/users?filters=' . urlencode('{"created_at":"2026-01-01"}'));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.code', 10106);

        self::assertArrayHasKey('filters.created_at', (array) $response->json('error.meta'));
    }

    /**
     * Test that a show route renders the resource, and a missing model the
     * not-found envelope.
     *
     * @return void
     */
    public function testShowRendersTheResourceAndAMissingModelIsNotFound(): void
    {
        $alice = User::query()->where('name', 'Alice')->firstOrFail();

        $this->getJson('/users/' . $alice->id . '?fields[users]=name')
            ->assertOk()
            ->assertExactJson(['data' => ['_type' => 'users', 'id' => $alice->id, 'name' => 'Alice']]);

        $this->getJson('/users/' . ($alice->id + 100))
            ->assertNotFound()
            ->assertJsonPath('error.status', 404)
            ->assertJsonPath('error.code', 10103);
    }

    /**
     * Test that the pretty parameter pretty-prints the index.
     *
     * @return void
     */
    public function testPrettyParameterPrettyPrintsTheIndex(): void
    {
        $response = $this->getJson('/users?pretty=1')->assertOk();

        self::assertSame(json_encode($response->json(), JSON_PRETTY_PRINT), $response->baseResponse->getContent());
    }

    /**
     * Test that the morph map holds each mapped model under its resource type,
     * which Eloquent stores and resolves, while the polymorphic envelope still
     * renders.
     *
     * @return void
     */
    public function testMappedModelsAreStoredUnderTheirResourceType(): void
    {
        $user         = User::query()->where('name', 'Alice')->firstOrFail();
        $organization = Organization::query()->firstOrFail();
        $post         = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'body' => 'Content']);

        Route::get('/feed', static fn () => PolymorphicResource::collection(collect([$user, $organization, $post])));

        self::assertSame([
            'users'         => User::class,
            'organizations' => Organization::class,
            'posts'         => Post::class,
            'tags'          => Tag::class,
        ], Relation::morphMap());

        self::assertSame('organizations', (new Note)->noteable()->associate($organization)->noteable_type);
        self::assertTrue((new Note)->forceFill(['noteable_type' => 'organizations', 'noteable_id' => $organization->id])->noteable()->first()?->is($organization));

        $this->getJson('/feed')
            ->assertOk()
            ->assertJsonPath('data.0._type', 'users')
            ->assertJsonPath('data.1._type', 'organizations')
            ->assertJsonPath('data.2._type', 'posts');
    }

    /**
     * Test that sending a notification writes its audit log entries.
     *
     * @return void
     */
    public function testSentNotificationIsLogged(): void
    {
        $logged = [];

        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->level, $event->message, $event->context];
        });

        $notification = new class extends Notification {
            /**
             * Get the notification's delivery channels.
             *
             * @return array<int, string>
             */
            public function via(): array
            {
                return ['mail'];
            }

            /**
             * Get the mail representation of the notification.
             *
             * @return \Illuminate\Notifications\Messages\MailMessage
             */
            public function toMail(): MailMessage
            {
                return (new MailMessage)->line('Deployed');
            }
        };

        Notifier::route('mail', 'ops@example.com')->notify($notification);

        $context = ['notification' => $notification::class, 'notifiable_type' => AnonymousNotifiable::class, 'channel' => 'mail'];

        self::assertSame([
            ['debug', 'Notification Sending', $context],
            ['info', 'Notification Sent', $context],
        ], $logged);
    }

    /**
     * Define the environment setup.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('mail.default', 'array');
        $config->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
        ]);
    }
}
