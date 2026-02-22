<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelMaintenanceMiddleware;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Log\LogManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ApiServiceProviderTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    public function testRegisterQueryParserBindsConfiguredAlias(): void
    {
        $provider = new ApiServiceProvider($this->app);

        Config::set('api-toolkit.parser.alias', 'custom.parser');

        $this->invokeNonPublic($provider, 'registerQueryParser');

        static::assertTrue($this->app->bound('custom.parser'));
        static::assertInstanceOf(ApiQueryParser::class, $this->app->make('custom.parser'));
    }

    public function testRequestMacrosAreRegisteredAndRespectConfiguration(): void
    {
        $provider = new ApiServiceProvider($this->app);

        Config::set('api-toolkit.exports.enabled', true);
        Config::set('api-toolkit.exports.supported_formats', ['csv', 'xml']);

        $this->invokeNonPublic($provider, 'registerTrashedMacros');
        $this->invokeNonPublic($provider, 'registerExportMacros');
        $this->invokeNonPublic($provider, 'registerStreamMacros');

        $request = HttpRequest::create('/api/users', 'GET', [
            'include_trashed' => 'true',
            'only_trashed'    => 'true',
        ], [], [], ['HTTP_ACCEPT' => 'text/csv']);

        static::assertTrue($request->includeTrashed());
        static::assertTrue($request->onlyTrashed());
        static::assertTrue($request->expectsCsv());
        static::assertTrue($request->expectsExport());
        static::assertFalse($request->expectsXml());
        static::assertFalse($request->expectsPdf());

        $streamRequest = HttpRequest::create('/api/stream', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/event-stream']);
        static::assertTrue($streamRequest->expectsStream());

        Config::set('api-toolkit.exports.enabled', false);
        static::assertFalse($request->expectsExport());
    }

    public function testRegisterMorphMapHonorsConfiguration(): void
    {
        $provider = new ApiServiceProvider($this->app);

        Relation::morphMap([], false);

        Config::set('api-toolkit.resources.enable_dynamic_morph_mapping', false);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        $this->invokeNonPublic($provider, 'registerMorphMap');

        static::assertNull(Relation::getMorphedModel('user'));

        Config::set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->invokeNonPublic($provider, 'registerMorphMap');

        static::assertSame(User::class, Relation::getMorphedModel('user'));

        Config::set('api-toolkit.resources.resource_map', [User::class => ResourceWithoutTypeMethod::class]);
        $this->invokeNonPublic($provider, 'registerMorphMap');

        Config::set('api-toolkit.resources.resource_map', 'invalid');
        $this->invokeNonPublic($provider, 'registerMorphMap');

        static::assertSame(User::class, Relation::getMorphedModel('user'));
    }

    public function testRegisterNotificationLoggingCanBeEnabledOrDisabled(): void
    {
        $provider = new ApiServiceProvider($this->app);

        Event::forget(NotificationSending::class);
        Event::forget(NotificationSent::class);

        Config::set('api-toolkit.notifications.enable_logging', false);

        $this->invokeNonPublic($provider, 'registerNotificationLogging');

        static::assertFalse(Event::hasListeners(NotificationSending::class));

        Config::set('api-toolkit.notifications.enable_logging', true);

        $this->invokeNonPublic($provider, 'registerNotificationLogging');

        static::assertTrue(Event::hasListeners(NotificationSending::class));
        static::assertTrue(Event::hasListeners(NotificationSent::class));
    }

    public function testOfferPublishingHandlesConsoleAndMissingConfigPathChecks(): void
    {
        $provider = new ApiServiceProvider($this->app);

        $this->setNonPublicProperty($this->app, 'isRunningInConsole', false);

        $this->invokeNonPublic($provider, 'offerPublishing');

        $this->setNonPublicProperty($this->app, 'isRunningInConsole', true);
        FunctionOverrides::forceMissingConfigPath(true);

        $this->invokeNonPublic($provider, 'offerPublishing');

        FunctionOverrides::forceMissingConfigPath(false);

        $this->invokeNonPublic($provider, 'offerPublishing');

        static::assertIsArray(ServiceProvider::pathsToPublish(ApiServiceProvider::class, 'config'));
    }

    public function testRegisterCloudwatchLoggerAddsCustomDriver(): void
    {
        $provider = new ApiServiceProvider($this->app);

        $this->invokeNonPublic($provider, 'registerCloudwatchLogger');

        $manager = $this->app->make(LogManager::class);

        $logger = $manager->driver('cloudwatch');

        static::assertTrue(method_exists($logger, 'debug'));
    }

    public function testRegisterMiddlewareSetsAliasesAndPushesGlobalMiddleware(): void
    {
        $provider = new ApiServiceProvider($this->app);

        Config::set('cache.default', 'redis');
        Config::set('api-toolkit.parser.register_middleware', true);

        $kernel = $this->app->make(Kernel::class);

        $global   = $kernel->getGlobalMiddleware();
        $global[] = LaravelMaintenanceMiddleware::class;
        $kernel->setGlobalMiddleware($global);

        $this->invokeNonPublic($provider, 'registerMiddleware');

        $global = $kernel->getGlobalMiddleware();

        static::assertContains(ParseApiQuery::class, $global);
        static::assertContains(JsonPrettyPrint::class, $global);
        static::assertContains(PreventRequestsDuringMaintenance::class, $global);

        $router = $this->app->make(Router::class);

        static::assertSame(ThrottleRequestsWithRedis::class, $router->getMiddleware()['throttle']);

        Config::set('cache.default', 'array');
        static::assertSame(ThrottleRequests::class, $this->invokeNonPublic($provider, 'getThrottleMiddleware'));
        Config::set('cache.default', 'redis');
        static::assertSame(ThrottleRequestsWithRedis::class, $this->invokeNonPublic($provider, 'getThrottleMiddleware'));
    }
}

class ResourceWithoutTypeMethod {}
