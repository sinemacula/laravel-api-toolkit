<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Events\PublishingStubs;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMaintenance;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Contracts\OperationTerminated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Console\MakeResourceCommand;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MorphMapRegistrar;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Validation\Rejection;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Discovery\Conflict\FirstUserResource;
use Tests\Fixtures\Discovery\Conflict\SecondUserResource;
use Tests\Fixtures\Discovery\Invalid\AbstractAttributedResource;
use Tests\Fixtures\Discovery\Primary\ChildDiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Discovery\Unbound\UnboundPostResource;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\CatalogueRow;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\BrokenResource;
use Tests\Fixtures\Resources\CatalogueRowResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests for the ApiServiceProvider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(ContainerBindingRegistrar::class)]
#[CoversClass(LifecycleRegistrar::class)]
#[CoversClass(LoggingRegistrar::class)]
#[CoversClass(MiddlewareRegistrar::class)]
#[CoversClass(MorphMapRegistrar::class)]
final class ApiServiceProviderTest extends TestCase
{
    use InteractsWithNonPublicMembers, LocalisesToolkitMessages, RecordsStatements;

    /**
     * Test that the package config is merged.
     *
     * @return void
     */
    public function testPackageConfigIsMerged(): void
    {
        $config = $this->getConfig();

        self::assertNotNull($config->get('api-toolkit'));
        self::assertIsArray($config->get('api-toolkit.resources'));
    }

    /**
     * Test that the package neither adds nor merges into log channels.
     *
     * @return void
     */
    public function testPackageDoesNotAlterLoggingConfig(): void
    {
        $config   = $this->getConfig();
        $channels = $config->get('logging.channels');

        self::assertIsArray($channels);
        self::assertArrayNotHasKey('notifications', $channels);
        self::assertArrayNotHasKey('api-exceptions', $channels);

        $config->set('logging.channels.notifications', [
            'driver' => 'single',
            'path'   => storage_path('logs/app-notifications.log'),
        ]);

        $expected = $config->get('logging');

        (new ApiServiceProvider($this->getApplication()))->register();

        self::assertSame($expected, $config->get('logging'));
    }

    /**
     * Test that translations are loaded.
     *
     * @return void
     */
    public function testTranslationsAreLoaded(): void
    {
        /** @var \Illuminate\Translation\Translator $translator */
        $translator = $this->getApplication()->make('translator');

        self::assertTrue($translator->hasForLocale('api-toolkit::exceptions', 'en'));
        self::assertTrue($translator->hasForLocale('api-toolkit::validation', 'en'));
    }

    /**
     * Test that the API query parser is registered as a singleton.
     *
     * @return void
     */
    public function testApiQueryParserIsRegisteredAsSingleton(): void
    {
        $app   = $this->getApplication();
        $alias = $this->getConfig()->get('api-toolkit.parser.alias');

        self::assertIsString($alias);

        $parser = $app->make($alias);

        self::assertInstanceOf(ApiQueryParser::class, $parser);

        self::assertSame($parser, $app->make($alias));
    }

    /**
     * Test that JsonPrettyPrint middleware is registered globally by default.
     *
     * @return void
     */
    public function testJsonPrettyPrintMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(JsonPrettyPrint::class, $middleware);
    }

    /**
     * Test that the toolkit maintenance middleware replaces the framework's.
     *
     * It must keep the framework's slot after HandleCors.
     *
     * @return void
     */
    public function testMaintenanceModeMiddlewareReplacesFrameworkMiddlewareInPlaceWhenEnabled(): void
    {
        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(PreventRequestsDuringMaintenance::class, $middleware);
        self::assertNotContains(FrameworkMaintenance::class, $middleware);

        $cors = array_search(HandleCors::class, $middleware, true);

        self::assertIsInt($cors);

        self::assertSame(PreventRequestsDuringMaintenance::class, $middleware[$cors + 1] ?? null);
    }

    /**
     * Test that throttle middleware alias is set by default.
     *
     * @return void
     */
    public function testThrottleMiddlewareAliasIsSet(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->getApplication()->make(Router::class);

        $middleware = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $middleware);
    }

    /**
     * Test that the middleware config section exists with correct defaults.
     *
     * @return void
     */
    public function testMiddlewareConfigSectionHasCorrectDefaults(): void
    {
        $config = $this->getConfig();

        self::assertTrue($config->get('api-toolkit.middleware.maintenance_mode_swap.enabled'));
        self::assertTrue($config->get('api-toolkit.middleware.json_pretty_print.enabled'));
        self::assertSame('global', $config->get('api-toolkit.middleware.json_pretty_print.scope'));
        self::assertTrue($config->get('api-toolkit.middleware.throttle.enabled'));
        self::assertNull($config->get('api-toolkit.middleware.throttle.class'));
    }

    /**
     * Test that the correlation id is read from X-Request-Id by default.
     *
     * @return void
     */
    public function testServicesConfigSectionHasCorrectDefaults(): void
    {
        self::assertSame('X-Request-Id', $this->getConfig()->get('api-toolkit.services.correlation_header'));
    }

    /**
     * Test that the default config registers the expected middleware.
     *
     * @return void
     */
    public function testDefaultConfigProducesBackwardCompatibleBehavior(): void
    {
        $app = $this->getApplication();

        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel = $app->make(HttpKernel::class);
        $global = $kernel->getGlobalMiddleware();

        self::assertContains(PreventRequestsDuringMaintenance::class, $global);
        self::assertNotContains(FrameworkMaintenance::class, $global);

        self::assertContains(JsonPrettyPrint::class, $global);

        /** @var \Illuminate\Routing\Router $router */
        $router     = $app->make(Router::class);
        $middleware = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $middleware);
    }

    /**
     * Test that notification listeners are registered when enabled.
     *
     * @return void
     */
    public function testNotificationListenersAreRegisteredWhenEnabled(): void
    {
        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertTrue($events->hasListeners(NotificationSending::class));
        self::assertTrue($events->hasListeners(NotificationSent::class));
    }

    /**
     * Test that boot merges discovered resources beneath the configured map.
     *
     * @return void
     */
    public function testBootMergesDiscoveredResourcesBeneathConfiguredMap(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        $map = $this->getConfig()->get('api-toolkit.resources.resource_map');

        self::assertIsArray($map);
        self::assertSame(UserResource::class, $map[User::class]);
        self::assertSame(DiscoveredPostResource::class, $map[Post::class]);
    }

    /**
     * Provide the arguments of the commands that skip the discovery merge.
     *
     * @return iterable<string, array{0: array<int, string>}>
     */
    public static function cacheBuildArgvProvider(): iterable
    {
        yield 'config:cache' => [['artisan', 'config:cache']];
        yield 'optimize' => [['artisan', 'optimize']];
        yield 'optimize:clear' => [['artisan', 'optimize:clear']];
        yield 'resource cache' => [['artisan', 'api-toolkit:resources:cache']];
        yield 'resource clear' => [['artisan', 'api-toolkit:resources:clear']];
        yield 'leading inline option' => [['artisan', '--env=production', 'config:cache']];
        yield 'leading flags' => [['artisan', '-q', '--no-interaction', 'optimize']];
        yield 'leading separate env value' => [['artisan', '--env', 'production', 'config:cache']];
    }

    /**
     * Test that the discovery merge is skipped while caches build or clear.
     *
     * @param  array<int, string>  $argv
     * @return void
     */
    #[DataProvider('cacheBuildArgvProvider')]
    public function testCacheBuildSkipsTheDiscoveryMerge(array $argv): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $this->bootWithArgv($argv);

        self::assertSame([
            User::class => UserResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Provide the arguments of invocations that keep the discovery merge.
     *
     * @return iterable<string, array{0: array<int, string>|null}>
     */
    public static function mergingArgvProvider(): iterable
    {
        yield 'no arguments' => [null];
        yield 'no command' => [['artisan']];
        yield 'options only' => [['artisan', '-q', '--env', 'production']];
        yield 'another command' => [['artisan', 'route:cache']];
        yield 'argument value' => [['artisan', 'queue:work', '--queue', 'optimize']];
        yield 'env value' => [['artisan', '--env', 'optimize', 'route:cache']];
    }

    /**
     * Test that every other invocation keeps the discovery merge.
     *
     * @param  array<int, string>|null  $argv
     * @return void
     */
    #[DataProvider('mergingArgvProvider')]
    public function testOtherInvocationsKeepTheDiscoveryMerge(?array $argv): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->bootWithArgv($argv);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that a web request keeps the discovery merge whatever its arguments.
     *
     * @return void
     */
    public function testRequestOutsideTheConsoleKeepsTheDiscoveryMerge(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $console  = new \ReflectionProperty(Application::class, 'isRunningInConsole');
        $original = $console->getValue($app);

        $console->setValue($app, false);

        try {
            $this->bootWithArgv(['search=foo', 'optimize']);
        } finally {
            $console->setValue($app, $original);
        }

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that a rebuild over a stale compiled map still reports conflicts.
     *
     * @return void
     */
    public function testResourceCacheRebuildReportsConflictsDespiteAStaleCompiledMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Conflict']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledMap([User::class => FirstUserResource::class]);

        $this->bootWithArgv(['artisan', 'deploy']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'ignoring [' . SecondUserResource::class . ']'));

        $this->pendingArtisan('api-toolkit:resources:cache')->assertExitCode(0)->run();
    }

    /**
     * Test that a stale compiled map does not block its own rebuild.
     *
     * @return void
     */
    public function testResourceCacheRebuildsAStaleCompiledMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->useIsolatedBootstrapPath();

        $path = $this->writeCompiledMap([User::class => AbstractAttributedResource::class]);

        $this->bootWithArgv(['artisan', 'api-toolkit:resources:cache']);

        $this->pendingArtisan('api-toolkit:resources:cache')->assertExitCode(0)->run();

        self::assertSame([
            'bindings' => [
                User::class => DiscoveredUserResource::class,
                Post::class => DiscoveredPostResource::class,
            ],
            'resources' => [
                ChildDiscoveredUserResource::class,
                DiscoveredUserResource::class,
                DiscoveredPostResource::class,
            ],
        ], require $path);
    }

    /**
     * Test that boot registers derived morph aliases when enabled.
     *
     * @return void
     */
    public function testBootRegistersDerivedMorphAliasesWhenEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame(['users' => User::class], Relation::morphMap());
        self::assertFalse(Relation::requiresMorphMap());
    }

    /**
     * Test that boot skips resources that lack getResourceType.
     *
     * @return void
     */
    public function testBootSkipsMorphAliasesForResourcesWithoutGetResourceType(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => \stdClass::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame([], Relation::morphMap());
        self::assertFalse(Relation::requiresMorphMap());
    }

    /**
     * Test that boot registers morph aliases for discovered resources too.
     *
     * @return void
     */
    public function testBootRegistersMorphAliasesForDiscoveredResources(): void
    {
        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->useIsolatedBootstrapPath();

        $this->bootWithArgv(['artisan', 'deploy']);

        self::assertSame([
            'discovered_users' => User::class,
            'discovered_posts' => Post::class,
        ], Relation::morphMap());
    }

    /**
     * Test that the OperatorRegistry is a singleton with built-in operators.
     *
     * @return void
     */
    public function testOperatorRegistryIsRegisteredAsSingleton(): void
    {
        $app      = $this->getApplication();
        $registry = $app->make(OperatorRegistry::class);

        self::assertInstanceOf(OperatorRegistry::class, $registry);

        self::assertSame($registry, $app->make(OperatorRegistry::class));

        self::assertTrue($registry->has('$eq'));
        self::assertTrue($registry->has('$neq'));
        self::assertTrue($registry->has('$gt'));
        self::assertTrue($registry->has('$lt'));
        self::assertTrue($registry->has('$ge'));
        self::assertTrue($registry->has('$le'));
        self::assertTrue($registry->has('$in'));
        self::assertTrue($registry->has('$between'));
        self::assertTrue($registry->has('$contains'));
        self::assertTrue($registry->has('$null'));
        self::assertTrue($registry->has('$notNull'));

        // The unindexable partial-match operator ships unregistered
        self::assertFalse($registry->has('$like'));
    }

    /**
     * Test that every shipped operator token is permitted by some capability.
     *
     * @return void
     */
    public function testEveryRegisteredOperatorTokenIsDecidedByACapability(): void
    {
        $registry = $this->getApplication()->make(OperatorRegistry::class);

        foreach ($registry->tokens() as $token) {

            $permitted = array_filter(
                Capability::cases(),
                static fn (Capability $capability): bool => $capability->permits($token),
            );

            self::assertNotSame([], $permitted, sprintf('The "%s" operator is permitted by no capability.', $token));
        }
    }

    /**
     * Test that boot leaves an invalid resource alone, and reads nothing, when
     * schema validation is disabled.
     *
     * @return void
     */
    public function testValidateSchemasSkippedWhenDisabled(): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', false);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $statements = $this->recordStatements(fn () => $this->bootWithArgv(null));

        self::assertStatementCount(0, $statements);
        self::assertSame([User::class => BrokenResource::class], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Provide the environment spellings of true.
     *
     * @return iterable<string, array{string}>
     */
    public static function environmentTrueProvider(): iterable
    {
        yield 'one' => ['1'];
        yield 'on' => ['on'];
        yield 'yes' => ['yes'];
        yield 'upper-case true' => ['TRUE'];
    }

    /**
     * Test that an environment spelling of true enables boot validation, which
     * a strict comparison with true would leave off.
     *
     * @param  string  $value
     * @return void
     */
    #[DataProvider('environmentTrueProvider')]
    public function testValidateSchemasAcceptsEnvironmentSpellingsOfTrue(string $value): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', $value);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $this->expectException(InvalidSchemaException::class);

        $this->bootWithArgv(null);
    }

    /**
     * Provide resource maps with the number of them that declare a sort.
     *
     * @return iterable<string, array{0: array<class-string, class-string>, 1: int}>
     */
    public static function sortableResourceMapProvider(): iterable
    {
        yield 'one sortable beside three unsortable' => [[
            User::class         => UserResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
            Organization::class => OrganizationResource::class,
        ], 1];
        yield 'two sortable beside three unsortable' => [[
            User::class         => UserResource::class,
            Article::class      => ArticleResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
            Organization::class => OrganizationResource::class,
        ], 2];
    }

    /**
     * Test that a warm boot validates against cached metadata, reading only the
     * index eligibility of each resource that declares a sort.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @param  int  $sortable
     * @return void
     */
    #[DataProvider('sortableResourceMapProvider')]
    public function testWarmBootValidationReadsOnlyTheEligibilityOfEachSortableResource(array $resourceMap, int $sortable): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', $resourceMap);

        $this->bootWithArgv(null);
        $this->getApplication()->make(CacheManager::class)->flush();

        $statements = $this->recordStatements(fn () => $this->bootWithArgv(null));

        self::assertStatementCount($sortable, $statements);

        foreach ($statements as $statement) {
            self::assertMatchesRegularExpression('/pragma_index_list|information_schema\.statistics|pg_index/', $statement);
        }
    }

    /**
     * Test that a boot without validation runs no statements, even cold and
     * with a compiled discovery map to merge.
     *
     * @return void
     */
    public function testBootWithoutValidationRunsNoStatements(): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', false);
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledMap([Tag::class => TagResource::class]);

        $statements = $this->recordStatements(fn () => $this->bootWithArgv(null));

        self::assertStatementCount(0, $statements);
        self::assertSame([User::class => UserResource::class, Tag::class => TagResource::class], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that validate schemas throws on invalid schema at boot.
     *
     * @return void
     */
    public function testValidateSchemasThrowsOnInvalidSchemaAtBoot(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $this->expectException(InvalidSchemaException::class);

        $provider->boot();
    }

    /**
     * Test that boot validates a discovered resource no model binds, even with
     * an empty map.
     *
     * @return void
     */
    public function testValidateSchemasCoversAnUnboundResourceAtBoot(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Unbound']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage(sprintf('[%s] Field "tags": Relation "tags" renders %s', UnboundPostResource::class, TagResource::class));

        $provider->boot();
    }

    /**
     * Test that a table that is not migrated does not fail boot, since boot
     * passes over a catalogue it cannot read.
     *
     * @return void
     */
    public function testValidateSchemasBootsAgainstATableThatIsNotMigrated(): void
    {
        $this->getConfig()->set('database.connections.catalogue', $this->getConfig()->get('database.connections.testing'));
        $this->getConfig()->set('api-toolkit.search.unverified_connections', ['catalogue']);
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            CatalogueRow::class => CatalogueRowResource::class,
        ]);

        $this->bootWithArgv(null);

        self::assertSame([CatalogueRow::class => CatalogueRowResource::class], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Provide the arguments of the validation command.
     *
     * @return iterable<string, array{0: array<int, string>}>
     */
    public static function validationCommandArgvProvider(): iterable
    {
        yield 'bare' => [['artisan', 'api-toolkit:validate-schemas']];
        yield 'with an environment' => [['artisan', '--env', 'ci', 'api-toolkit:validate-schemas']];
        yield 'with an inline environment' => [['artisan', '--env=ci', 'api-toolkit:validate-schemas']];
        yield 'with options around it' => [['artisan', '-v', 'api-toolkit:validate-schemas', '--allow-unverified']];
    }

    /**
     * Test that boot leaves schema validation to the validation command.
     *
     * @param  array<int, string>  $argv
     * @return void
     */
    #[DataProvider('validationCommandArgvProvider')]
    public function testValidateSchemasIsLeftToTheValidationCommand(array $argv): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $this->bootWithArgv($argv);

        self::assertSame([User::class => BrokenResource::class], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that the commands booting without the discovered resources leave
     * schema validation to a full boot.
     *
     * @param  array<int, string>  $argv
     * @return void
     */
    #[DataProvider('cacheBuildArgvProvider')]
    public function testCacheBuildSkipsSchemaValidation(array $argv): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $this->bootWithArgv($argv);

        self::assertSame([User::class => BrokenResource::class], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that boot still validates schemas for every other command.
     *
     * @return void
     */
    public function testValidateSchemasStillRunsForOtherCommands(): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $this->expectException(InvalidSchemaException::class);

        $this->bootWithArgv(['artisan', 'migrate']);
    }

    /**
     * Test that a discovery conflict still fails boot for the validation
     * command.
     *
     * @return void
     */
    public function testDiscoveryConflictStillFailsBootForTheValidationCommand(): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Conflict']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Resource discovery found multiple resources for model [' . User::class . ']');

        $this->bootWithArgv(['artisan', 'api-toolkit:validate-schemas']);
    }

    /**
     * Test that an invalid exception map fails the booted application.
     *
     * @return void
     */
    public function testInvalidExceptionMapFailsOnceBooted(): void
    {
        $this->getConfig()->set('api-toolkit.exceptions.map', [\stdClass::class => GoneException::class]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.exceptions.map key [stdClass] must be an interface or a Throwable class, and not an ApiException.');

        $this->outsideTheConsole(fn () => (new ApiServiceProvider($this->getApplication()))->boot());
    }

    /**
     * Test that the exception map is validated after every provider has booted,
     * so a map a later provider writes is validated too.
     *
     * @return void
     */
    public function testExceptionMapWrittenByALaterProviderFailsOnceBooted(): void
    {
        $validate = $this->outsideTheConsole(fn (): callable => $this->registeredBootedCallback());

        $this->getConfig()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => \RuntimeException::class]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            'The api-toolkit.exceptions.map target [RuntimeException] for [%s] must be an instantiable ApiException with a CODE, a 4xx or 5xx status, '
            . 'and ApiException\'s own constructor and getStatusCode().',
            NotFoundHttpException::class,
        ));

        $validate($this->getApplication());
    }

    /**
     * Test that an empty exception map passes the boot validation.
     *
     * @return void
     */
    public function testEmptyExceptionMapPassesTheBootValidation(): void
    {
        $validate = $this->outsideTheConsole(fn (): callable => $this->registeredBootedCallback());

        $this->getConfig()->set('api-toolkit.exceptions.map', []);
        $validate($this->getApplication());

        $this->getConfig()->set('api-toolkit.exceptions.map', [\stdClass::class => GoneException::class]);

        $this->expectException(InvalidConfigurationException::class);

        $validate($this->getApplication());
    }

    /**
     * Test that the console skips the configuration validation, so a broken
     * setting cannot block the commands that repair it.
     *
     * @return void
     */
    public function testConsoleSkipsTheConfigurationValidation(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.exceptions.map', [\stdClass::class => GoneException::class]);
        $this->setProperty($app, 'booted', false);

        try {
            /** @var list<callable(\Illuminate\Foundation\Application): void> $before */
            $before = $this->getProperty($app, 'bootedCallbacks');

            (new ApiServiceProvider($app))->boot();

            self::assertSame($before, $this->getProperty($app, 'bootedCallbacks'));
        } finally {
            $this->setProperty($app, 'booted', true);
        }
    }

    /**
     * Test that register fills the settings a published configuration lacks,
     * but leaves a cached configuration as it was built.
     *
     * @return void
     */
    public function testRegisterMergesTheShippedSettingsUnlessTheConfigurationIsCached(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources', ['fixed_fields' => ['id']]);

        $app->instance('config_loaded_from_cache', true);

        (new ApiServiceProvider($app))->register();

        self::assertSame(['fixed_fields' => ['id']], $this->getConfig()->get('api-toolkit.resources'));

        $app->instance('config_loaded_from_cache', false);

        (new ApiServiceProvider($app))->register();

        self::assertSame(['id'], $this->getConfig()->get('api-toolkit.resources.fixed_fields'));
        self::assertTrue($this->getConfig()->get('api-toolkit.resources.narrow_columns'));
    }

    /**
     * Provide the commands that serve queue jobs or build the config cache.
     *
     * @return iterable<string, array{array<int, string>}>
     */
    public static function validatingCommandProvider(): iterable
    {
        yield 'queue worker' => [['artisan', 'queue:work']];
        yield 'queue listener' => [['artisan', 'queue:listen', 'redis']];
        yield 'horizon worker' => [['artisan', 'horizon:work', 'redis', '--supervisor=default']];
        yield 'config cache' => [['artisan', 'config:cache']];
        yield 'optimize' => [['artisan', '--env', 'production', 'optimize']];
    }

    /**
     * Test that a command serving queue jobs, or building the config cache from
     * a fresh configuration, validates every setting once booted.
     *
     * @param  array<int, string>  $argv
     * @return void
     */
    #[DataProvider('validatingCommandProvider')]
    public function testCommandsServingJobsOrCachingConfigValidateTheConfiguration(array $argv): void
    {
        $this->getConfig()->set('api-toolkit.query_cost.max_depth', '-1');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.query_cost.max_depth setting must be an integer of at least 0, or null; [\'-1\'] given.');

        $this->bootWithArgv($argv);
    }

    /**
     * Provide the invocations that leave settings to their read sites.
     *
     * @return iterable<string, array{array<int, string>|null}>
     */
    public static function nonValidatingCommandProvider(): iterable
    {
        yield 'no arguments' => [null];
        yield 'config clear' => [['artisan', 'config:clear']];
        yield 'route cache' => [['artisan', 'route:cache']];
        yield 'a queue name argument' => [['artisan', 'migrate', '--path', 'queue:work']];
    }

    /**
     * Test that other commands leave each setting to the site that reads it.
     *
     * @param  array<int, string>|null  $argv
     * @return void
     */
    #[DataProvider('nonValidatingCommandProvider')]
    public function testOtherCommandsLeaveTheConfigurationToItsReadSites(?array $argv): void
    {
        self::assertFalse($this->bootRegistersValidation($argv));
    }

    /**
     * Test that building the config cache over a cached configuration leaves
     * the validation to the fresh application the build boots, so a stale cache
     * cannot block its own rebuild.
     *
     * @return void
     */
    public function testConfigCacheOverACachedConfigurationSkipsTheValidation(): void
    {
        self::assertTrue($this->bootRegistersValidation(['artisan', 'config:cache']));

        $this->getApplication()->instance('config_loaded_from_cache', true);

        self::assertFalse($this->bootRegistersValidation(['artisan', 'config:cache']));
        self::assertFalse($this->bootRegistersValidation(['artisan', 'optimize']));
        self::assertTrue($this->bootRegistersValidation(['artisan', 'queue:work']));
    }

    /**
     * Test that an application booted while config:cache runs in process, as
     * the one the command builds the cache from, sees that command rather than
     * the one on the command line.
     *
     * @return void
     */
    public function testAnApplicationBootedDuringAnInProcessConfigCacheSeesThatCommand(): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $events = $this->getEventDispatcher();

        $events->dispatch(new CommandStarting('config:cache', new ArrayInput([]), new NullOutput));

        self::assertTrue($this->bootRegistersValidation(['artisan', 'app:deploy']));
        self::assertSame([], $this->getConfig()->get('api-toolkit.resources.resource_map'));

        $events->dispatch(new CommandFinished('config:cache', new ArrayInput([]), new NullOutput, 0));

        self::assertFalse($this->bootRegistersValidation(['artisan', 'app:deploy']));
        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that SchemaValidator is registered as a singleton.
     *
     * @return void
     */
    public function testSchemaValidatorIsRegisteredAsSingleton(): void
    {
        $app       = $this->getApplication();
        $validator = $app->make(SchemaValidator::class);

        self::assertInstanceOf(SchemaValidator::class, $validator);

        self::assertSame($validator, $app->make(SchemaValidator::class));
    }

    /**
     * Test that the validate schemas command is registered.
     *
     * @return void
     */
    public function testValidateSchemasCommandIsRegistered(): void
    {
        $commands = Artisan::all();

        self::assertArrayHasKey('api-toolkit:validate-schemas', $commands);
    }

    /**
     * Test that the invalidate metadata command is registered.
     *
     * @return void
     */
    public function testInvalidateMetadataCommandIsRegistered(): void
    {
        self::assertArrayHasKey('api-toolkit:invalidate-metadata', Artisan::all());
    }

    /**
     * Test that the cache resources command is registered.
     *
     * @return void
     */
    public function testCacheResourcesCommandIsRegistered(): void
    {
        self::assertArrayHasKey('api-toolkit:resources:cache', Artisan::all());
    }

    /**
     * Test that the clear resources command is registered.
     *
     * @return void
     */
    public function testClearResourcesCommandIsRegistered(): void
    {
        self::assertArrayHasKey('api-toolkit:resources:clear', Artisan::all());
    }

    /**
     * Test that the make resource command is registered.
     *
     * @return void
     */
    public function testMakeResourceCommandIsRegistered(): void
    {
        self::assertArrayHasKey('api-toolkit:make:resource', Artisan::all());
    }

    /**
     * Test that publishing the stubs offers the resource stub alongside the
     * framework stubs.
     *
     * @return void
     */
    public function testPublishingStubsOffersTheResourceStub(): void
    {
        $event = new PublishingStubs(['framework.stub' => 'framework.stub']);

        Event::dispatch($event);

        self::assertSame([
            'framework.stub'          => 'framework.stub',
            MakeResourceCommand::STUB => 'api-toolkit.resource.stub',
        ], $event->stubs);
    }

    /**
     * Test that registering the provider hooks the resource optimize commands.
     *
     * @return void
     */
    public function testOptimizeRunsTheResourceCommands(): void
    {
        $optimize = ServiceProvider::$optimizeCommands;
        $clear    = ServiceProvider::$optimizeClearCommands;

        // The registry is static and outlives each test application
        unset(ServiceProvider::$optimizeCommands['api-toolkit'], ServiceProvider::$optimizeClearCommands['api-toolkit']);

        try {
            (new ApiServiceProvider($this->getApplication()))->register();

            self::assertSame('api-toolkit:resources:cache', ServiceProvider::$optimizeCommands['api-toolkit'] ?? null);
            self::assertSame('api-toolkit:resources:clear', ServiceProvider::$optimizeClearCommands['api-toolkit'] ?? null);
        } finally {
            ServiceProvider::$optimizeCommands      = $optimize;
            ServiceProvider::$optimizeClearCommands = $clear;
        }
    }

    /**
     * Test that optimize compiles the resource map and optimize:clear drops it.
     *
     * @return void
     */
    public function testOptimizeAndOptimizeClearCompileAndRemoveTheResourceMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);

        $this->useIsolatedBootstrapPath();

        $path = $this->getApplication()->bootstrapPath('cache/api-toolkit-resources.php');

        $this->pendingArtisan('optimize', ['--except' => 'config,events,routes,views'])->assertExitCode(0)->run();

        self::assertSame([
            'bindings' => [
                User::class => DiscoveredUserResource::class,
                Post::class => DiscoveredPostResource::class,
            ],
            'resources' => [
                ChildDiscoveredUserResource::class,
                DiscoveredUserResource::class,
                DiscoveredPostResource::class,
            ],
        ], require $path);

        $this->pendingArtisan('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views'])->assertExitCode(0)->run();

        self::assertFileDoesNotExist($path);
    }

    /**
     * Test that the shipped config invalidates metadata after migrations.
     *
     * @return void
     */
    public function testConfigurationInvalidatesMetadataAfterMigrationsByDefault(): void
    {
        self::assertTrue($this->getConfig()->get('api-toolkit.lifecycle.migrations'));
    }

    /**
     * Test that CacheManager is bound as a singleton.
     *
     * @return void
     */
    public function testCacheManagerIsBoundAsSingleton(): void
    {
        $app   = $this->getApplication();
        $first = $app->make(CacheManager::class);

        self::assertInstanceOf(CacheManager::class, $first);
        self::assertSame($first, $app->make(CacheManager::class));
    }

    /**
     * Test that the Octane flush listener is registered.
     *
     * @return void
     */
    #[Group('octane')]
    public function testOctaneFlushListenerRegistered(): void
    {
        $app = $this->getApplication();

        // Reset the dispatcher so Octane's own listeners cannot mask the result
        Event::swap(new Dispatcher($app));

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertTrue($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the queue flush subscriber is registered.
     *
     * @return void
     */
    public function testQueueFlushSubscriberRegistered(): void
    {
        $app = $this->getApplication();

        // Reset the dispatcher so boot-time wiring does not skew the baseline
        Event::swap(new Dispatcher($app));

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertTrue($events->hasListeners(JobAttempted::class));
    }

    /**
     * Test that the on_failure config key is available with the default value.
     *
     * @return void
     */
    public function testOnFailureConfigKeyIsAvailable(): void
    {
        $value = $this->getConfig()->get('api-toolkit.deferred_writes.on_failure');

        self::assertNotNull($value);
        self::assertSame('collect', $value);
    }

    /**
     * Test that the WritePool receives the COLLECT strategy by default.
     *
     * @return void
     */
    public function testWritePoolReceivesCollectStrategyByDefault(): void
    {
        $pool = $this->getApplication()->make(WritePool::class);

        $reflection = new \ReflectionProperty(WritePool::class, 'strategy');
        $strategy   = $reflection->getValue($pool);

        self::assertSame(FlushStrategy::COLLECT, $strategy);
    }

    /**
     * Test that the WritePool is non-transactional by default.
     *
     * @return void
     */
    public function testWritePoolIsNonTransactionalByDefault(): void
    {
        $pool = $this->getApplication()->make(WritePool::class);

        $reflection    = new \ReflectionProperty(WritePool::class, 'transactional');
        $transactional = $reflection->getValue($pool);

        self::assertFalse($transactional);
    }

    /**
     * Test that the WritePool receives a non-default on_failure strategy.
     *
     * @return void
     */
    public function testWritePoolReceivesConfiguredStrategy(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.on_failure', 'throw');

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $pool = $app->make(WritePool::class);

        $reflection = new \ReflectionProperty(WritePool::class, 'strategy');
        $strategy   = $reflection->getValue($pool);

        self::assertSame(FlushStrategy::THROW, $strategy);
    }

    /**
     * Test that a misspelt on_failure value fails naming the cases.
     *
     * @return void
     */
    public function testMisspeltOnFailureConfigFails(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.on_failure', 'Collect');

        (new ApiServiceProvider($app))->register();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.deferred_writes.on_failure setting must be one of log, throw, collect; [\'Collect\'] given.');

        $app->make(WritePool::class);
    }

    /**
     * Test that ParseApiQuery is pushed to the global stack when enabled.
     *
     * @return void
     */
    public function testParseApiQueryMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(ParseApiQuery::class, $middleware);
    }

    /**
     * Test that middleware registration defaults on when keys are missing.
     *
     * A fresh kernel and router are resolved so only this boot's registrations
     * show.
     *
     * @return void
     */
    public function testMiddlewareRegistrationDefaultsApplyWhenConfigKeysAreMissing(): void
    {
        $app    = $this->getApplication();
        $config = $this->getConfig();

        $config->set('api-toolkit.parser', []);
        $config->set('api-toolkit.middleware', []);

        $app->forgetInstance(HttpKernel::class);
        $app->forgetInstance(Router::class);
        $app->forgetInstance('router');

        $provider = new ApiServiceProvider($app);
        $provider->register();
        $provider->boot();

        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $app->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(ParseApiQuery::class, $middleware);
        self::assertContains(PreventRequestsDuringMaintenance::class, $middleware);
        self::assertContains(JsonPrettyPrint::class, $middleware);

        /** @var \Illuminate\Routing\Router $router */
        $router  = $app->make(Router::class);
        $aliases = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $aliases);
        self::assertSame(ThrottleRequests::class, $aliases['throttle']);
    }

    /**
     * Test that the config file is publishable under the toolkit's own tag.
     *
     * @return void
     */
    public function testConfigIsPublishableUnderItsOwnTag(): void
    {
        $paths = ServiceProvider::pathsToPublish(ApiServiceProvider::class, 'api-toolkit-config');

        self::assertSame(
            [$this->getProviderPath('/../config/api-toolkit.php') => config_path('api-toolkit.php')],
            $paths,
        );
    }

    /**
     * Test that the translations publish beneath the application's lang path.
     *
     * @return void
     */
    public function testTranslationsArePublishableToTheApplicationLangPath(): void
    {
        $paths = ServiceProvider::pathsToPublish(ApiServiceProvider::class, 'api-toolkit-lang');

        self::assertSame(
            [$this->getProviderPath('/../resources/lang') => $this->getApplication()->langPath('vendor/api-toolkit')],
            $paths,
        );
    }

    /**
     * Test that nothing is published under the generic tags.
     *
     * @return void
     */
    public function testNothingIsPublishedUnderTheGenericTags(): void
    {
        self::assertSame([], ServiceProvider::pathsToPublish(ApiServiceProvider::class, 'translations'));
        self::assertSame([], ServiceProvider::pathsToPublish(ApiServiceProvider::class, 'config'));
    }

    /**
     * Test that a vendor override replaces only the lines it holds.
     *
     * @return void
     */
    public function testVendorOverrideReplacesOnlyTheLinesItHolds(): void
    {
        $this->overrideToolkitMessages(['search' => ['not_permitted' => 'Die Suche ist für diese Ressource nicht zulässig.']]);

        $this->getApplication()->setLocale('de');

        self::assertSame('Die Suche ist für diese Ressource nicht zulässig.', Rejection::message('search.not_permitted'));
        self::assertSame('The search term may not be longer than 64 characters.', Rejection::message('search.too_long', ['max' => '64']));
    }

    /**
     * Test that a vendor override of a shipped locale merges over its lines.
     *
     * @return void
     */
    public function testVendorOverrideOfTheShippedLocaleKeepsItsOtherLines(): void
    {
        $this->overrideToolkitMessages(['search' => ['not_permitted' => 'Search is switched off here.']], 'en');

        self::assertSame('Search is switched off here.', Rejection::message('search.not_permitted'));
        self::assertSame('The search term may not be longer than 64 characters.', Rejection::message('search.too_long', ['max' => '64']));
    }

    /**
     * Test that no morph alias is registered when dynamic mapping is disabled.
     *
     * @return void
     */
    public function testNoMorphAliasIsRegisteredWhenDynamicMorphMappingIsDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', false);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame([], Relation::morphMap());
        self::assertFalse(Relation::requiresMorphMap());
    }

    /**
     * Test that schema validation defaults off when its config key is missing.
     *
     * The resource map holds an invalid schema, so a passing boot proves the
     * gate is off.
     *
     * @return void
     */
    public function testValidateSchemasDefaultsToDisabledWhenConfigKeyIsMissing(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources', [
            'resource_map' => [
                User::class => BrokenResource::class,
            ],
            'fixed_fields' => ['id', '_type'],
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->register();
        $provider->boot();

        self::assertFalse($this->getConfig()->get('api-toolkit.resources.validate_schemas'));
    }

    /**
     * Test that schema validation fails for a non-array resource map rather
     * than skipping it.
     *
     * @return void
     */
    public function testValidateSchemasFailsWhenResourceMapIsNotAnArray(): void
    {
        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', 'not-an-array');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.resources.resource_map setting must be an array; [\'not-an-array\'] given.');

        $this->bootWithArgv(null);
    }

    /**
     * Test that notification logging defaults on when its key is missing.
     *
     * @return void
     */
    public function testNotificationLoggingDefaultsToEnabledWhenConfigKeyIsMissing(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.notifications', []);

        $before = $this->countRawListeners(NotificationSending::class);

        $provider = new ApiServiceProvider($app);
        $provider->register();
        $provider->boot();

        self::assertSame($before + 1, $this->countRawListeners(NotificationSending::class));
    }

    /**
     * Test that no notification listeners are added when logging is disabled.
     *
     * @return void
     */
    public function testNotificationListenersAreNotAddedWhenLoggingDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.notifications.enable_logging', false);

        $sending = $this->countRawListeners(NotificationSending::class);
        $sent    = $this->countRawListeners(NotificationSent::class);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame($sending, $this->countRawListeners(NotificationSending::class));
        self::assertSame($sent, $this->countRawListeners(NotificationSent::class));
    }

    /**
     * Test that notification events are wired to the NotificationListener.
     *
     * @return void
     */
    public function testNotificationListenersDelegateToNotificationListener(): void
    {
        $raw = $this->getEventDispatcher()->getRawListeners();

        self::assertContains([NotificationListener::class, 'sending'], self::listenersFor($raw, NotificationSending::class));
        self::assertContains([NotificationListener::class, 'sent'], self::listenersFor($raw, NotificationSent::class));
    }

    /**
     * Test that ResourceMetadataProvider is bound to a singleton.
     *
     * @return void
     */
    public function testResourceMetadataProviderIsBoundAsSingleton(): void
    {
        $app   = $this->getApplication();
        $first = $app->make(ResourceMetadataProvider::class);

        self::assertInstanceOf(ResourceMetadataService::class, $first);
        self::assertSame($first, $app->make(ResourceMetadataProvider::class));
    }

    /**
     * Test that SchemaIntrospectionProvider is bound to a singleton.
     *
     * @return void
     */
    public function testSchemaIntrospectorIsBoundAsSingleton(): void
    {
        $app   = $this->getApplication();
        $first = $app->make(SchemaIntrospectionProvider::class);

        self::assertInstanceOf(SchemaIntrospector::class, $first);
        self::assertSame($first, $app->make(SchemaIntrospectionProvider::class));
    }

    /**
     * Test that the write pool flush subscriber hears the lifecycle events.
     *
     * @return void
     */
    public function testWritePoolFlushSubscriberIsSubscribedToLifecycleEvents(): void
    {
        self::assertTrue($this->hasSubscriberListener(RequestHandled::class, WritePoolFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(CommandFinished::class, WritePoolFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(JobAttempted::class, WritePoolFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(JobTimedOut::class, WritePoolFlushSubscriber::class));
    }

    /**
     * Test that the queue flush subscriber is subscribed to job attempts only.
     *
     * @return void
     */
    public function testQueueFlushSubscriberIsSubscribedToJobAttemptsOnly(): void
    {
        $app = $this->getApplication();

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertTrue($this->hasSubscriberListener(JobAttempted::class, QueueFlushSubscriber::class));
        self::assertFalse($this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class));
        self::assertFalse($this->hasSubscriberListener(JobFailed::class, QueueFlushSubscriber::class));
    }

    /**
     * Test that the WritePool uses default sizes when config keys are missing.
     *
     * @return void
     */
    public function testWritePoolUsesDefaultSizesWhenConfigKeysAreMissing(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes', ['on_failure' => 'log']);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $pool = $app->make(WritePool::class);

        self::assertSame(500, (new \ReflectionProperty(WritePool::class, 'chunkSize'))->getValue($pool));
        self::assertSame(10000, (new \ReflectionProperty(WritePool::class, 'poolLimit'))->getValue($pool));
    }

    /**
     * Test that the WritePool fails for a non-numeric size rather than using a
     * default.
     *
     * @return void
     */
    public function testWritePoolFailsForANonNumericSize(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.chunk_size', 'not-numeric');

        (new ApiServiceProvider($app))->register();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.deferred_writes.chunk_size setting must be an integer of at least 1; [\'not-numeric\'] given.');

        $app->make(WritePool::class);
    }

    /**
     * Test that the WritePool receives the configured numeric sizes.
     *
     * @return void
     */
    public function testWritePoolUsesConfiguredNumericSizes(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.chunk_size', 250);
        $this->getConfig()->set('api-toolkit.deferred_writes.pool_limit', 5000);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $pool = $app->make(WritePool::class);

        self::assertSame(250, (new \ReflectionProperty(WritePool::class, 'chunkSize'))->getValue($pool));
        self::assertSame(5000, (new \ReflectionProperty(WritePool::class, 'poolLimit'))->getValue($pool));
    }

    /**
     * Define the test environment configuration.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.parser.register_middleware', true);
        $config->set('api-toolkit.notifications.enable_logging', true);
    }

    /**
     * Boot a fresh provider with the given console arguments, then restore.
     *
     * @param  array<int, string>|null  $argv
     * @return void
     */
    private function bootWithArgv(?array $argv): void
    {
        $original = $_SERVER['argv'] ?? null;

        $_SERVER['argv'] = $argv;

        if ($argv === null) {
            unset($_SERVER['argv']);
        }

        try {
            (new ApiServiceProvider($this->getApplication()))->boot();
        } finally {
            $_SERVER['argv'] = $original;
        }
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Config\Repository */
        return $this->getApplication()->make('config');
    }

    /**
     * Get the event dispatcher instance.
     *
     * @return \Illuminate\Events\Dispatcher
     */
    private function getEventDispatcher(): Dispatcher
    {
        /** @var \Illuminate\Events\Dispatcher */
        return $this->getApplication()->make('events');
    }

    /**
     * Resolve a path relative to the service provider source directory.
     *
     * Mirrors the __DIR__-relative paths the provider publishes from.
     *
     * @param  string  $path
     * @return string
     */
    private function getProviderPath(string $path): string
    {
        $file = (new \ReflectionClass(ApiServiceProvider::class))->getFileName();

        self::assertIsString($file);

        return dirname($file) . $path;
    }

    /**
     * Count the raw listeners registered for the given event.
     *
     * @param  class-string  $event
     * @return int
     */
    private function countRawListeners(string $event): int
    {
        $listeners = $this->getEventDispatcher()->getRawListeners()[$event] ?? [];

        return is_countable($listeners) ? count($listeners) : 0;
    }

    /**
     * Determine whether the event has a listener from the given subscriber.
     *
     * @param  class-string  $event
     * @param  class-string  $subscriber
     * @return bool
     */
    private function hasSubscriberListener(string $event, string $subscriber): bool
    {
        $listeners = $this->getEventDispatcher()->getRawListeners()[$event] ?? [];

        if (!is_iterable($listeners)) {
            return false;
        }

        foreach ($listeners as $listener) {
            if (is_array($listener) && ($listener[0] ?? null) instanceof $subscriber) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the raw listeners registered for the given event.
     *
     * @param  array<array-key, mixed>  $raw
     * @param  string  $event
     * @return array<array-key, mixed>
     */
    private static function listenersFor(array $raw, string $event): array
    {
        $listeners = $raw[$event] ?? [];

        self::assertIsArray($listeners);

        return $listeners;
    }

    /**
     * Boot the provider on an unbooted application and return the one booted
     * callback it registers.
     *
     * @return callable(\Illuminate\Foundation\Application): void
     */
    private function registeredBootedCallback(): callable
    {
        $app = $this->getApplication();

        $this->setProperty($app, 'booted', false);

        try {
            /** @var list<callable(\Illuminate\Foundation\Application): void> $before */
            $before = $this->getProperty($app, 'bootedCallbacks');

            (new ApiServiceProvider($app))->boot();

            /** @var list<callable(\Illuminate\Foundation\Application): void> $after */
            $after = $this->getProperty($app, 'bootedCallbacks');
        } finally {
            $this->setProperty($app, 'booted', true);
        }

        self::assertCount(count($before) + 1, $after);

        return end($after);
    }

    /**
     * Boot a fresh provider with the given console arguments, reporting whether
     * it deferred the configuration validation.
     *
     * @param  array<int, string>|null  $argv
     * @return bool
     */
    private function bootRegistersValidation(?array $argv): bool
    {
        $app = $this->getApplication();

        $this->setProperty($app, 'booted', false);

        try {
            /** @var list<callable> $before */
            $before = $this->getProperty($app, 'bootedCallbacks');

            $this->bootWithArgv($argv);

            /** @var list<callable> $after */
            $after = $this->getProperty($app, 'bootedCallbacks');
        } finally {
            $this->setProperty($app, 'booted', true);
        }

        return count($after) > count($before);
    }

    /**
     * Run the callback as if the application were serving a request.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function outsideTheConsole(callable $callback): mixed
    {
        $app      = $this->getApplication();
        $original = $this->getProperty($app, 'isRunningInConsole');

        $this->setProperty($app, 'isRunningInConsole', false);

        try {
            return $callback();
        } finally {
            $this->setProperty($app, 'isRunningInConsole', $original);
        }
    }
}
