<?php

declare(strict_types = 1);

namespace Tests\Integration\Config;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Config\ToolkitConfigValidator;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\Concerns\AssertsRefusedSettings;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests that config:cache and optimize build the config cache from a fresh
 * application that validates every toolkit setting and holds no discovered
 * bindings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(ToolkitConfigValidator::class)]
#[CoversClass(RuntimeContext::class)]
final class ConfigCacheTest extends TestCase
{
    use AssertsRefusedSettings;

    /** @var string The variable setting the dispatcher depth cap */
    private const string DEPTH_VARIABLE = 'API_TOOLKIT_QUERY_COST_MAX_DEPTH';

    /** @var string The message a negative depth cap fails with */
    private const string NEGATIVE_DEPTH = 'The api-toolkit.query_cost.max_depth setting must be an integer of at least 0, or null; [\'-1\'] given.';

    /** @var string The cached config file */
    private string $cachePath;

    /**
     * Point the application at a bootstrap directory whose app.php builds a
     * fresh application with the toolkit and discovery paths, and raise the
     * command events as a console application does.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $bootstrap = $this->useIsolatedBootstrapPath();

        file_put_contents($bootstrap . '/app.php', sprintf(
            <<<'PHP'
                <?php

                return Orchestra\Testbench\Foundation\Application::create(
                    resolvingCallback: static function ($app): void {
                        $app->useBootstrapPath(%s);
                        $app->booting(static function ($app): void {
                            $app['config']->set('api-toolkit.resources.paths', [%s]);
                            $app['config']->set('api-toolkit.resources.resource_map', [%s => %s]);
                        });
                    },
                    options: ['extra' => ['providers' => [SineMacula\ApiToolkit\ApiServiceProvider::class]]],
                );
                PHP,
            var_export($bootstrap, true),
            var_export(dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary', true),
            var_export(User::class, true),
            var_export(UserResource::class, true),
        ));

        $this->cachePath = $this->app->getCachedConfigPath();

        $kernel = $this->app->make(Kernel::class);

        assert($kernel instanceof ConsoleKernel);

        // The test runner leaves the command events unraised
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
    }

    /**
     * Test that config:cache with a negative cap in the environment fails
     * naming the setting, and writes no cache.
     *
     * @return void
     */
    public function testConfigCacheWithANegativeCapFailsNamingItAndWritesNoFile(): void
    {
        $this->assertCommandFails('config:cache', [], self::NEGATIVE_DEPTH);

        self::assertFileDoesNotExist($this->cachePath);
    }

    /**
     * Test that optimize fails the same way.
     *
     * @return void
     */
    public function testOptimizeWithANegativeCapFailsNamingItAndWritesNoFile(): void
    {
        $this->assertCommandFails('optimize', ['--except' => 'events,routes,views,api-toolkit'], self::NEGATIVE_DEPTH);

        self::assertFileDoesNotExist($this->cachePath);
    }

    /**
     * Test that a valid environment rebuilds over a cache holding an invalid
     * value, writing the explicit bindings alone beside the shipped settings.
     *
     * @return void
     */
    public function testConfigCacheRebuildsOverAnInvalidCacheWithoutDiscoveredBindings(): void
    {
        file_put_contents($this->cachePath, '<?php return [\'api-toolkit\' => [\'query_cost\' => [\'max_depth\' => -1]]];');

        self::assertSame(0, $this->runInProcess('config:cache'));

        $cached = require $this->cachePath;

        self::assertIsArray($cached);

        $resources = $cached['api-toolkit']['resources'];

        self::assertIsArray($resources);
        self::assertSame([User::class => UserResource::class], $resources['resource_map']);
        self::assertArrayNotHasKey('discovered_bindings', $resources);
        self::assertSame(3, $cached['api-toolkit']['query_cost']['max_depth']);
        self::assertTrue($resources['narrow_columns']);
    }

    /**
     * Test that an application booting from the cache merges the discovered
     * bindings at runtime, beneath the explicit ones.
     *
     * @return void
     */
    public function testBootingFromTheCacheMergesTheDiscoveredBindings(): void
    {
        assert($this->app !== null);

        self::assertSame(0, $this->runInProcess('config:cache'));

        $cached = require $this->cachePath;

        self::assertIsArray($cached);

        Config::set('api-toolkit', $cached['api-toolkit']);

        $this->app->instance('config_loaded_from_cache', true);

        $provider = new ApiServiceProvider($this->app);

        $provider->register();
        $provider->boot();

        self::assertSame([
            User::class => UserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], Config::get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that a failure under a cached configuration tells how to rebuild the
     * cache.
     *
     * @return void
     */
    public function testFailureUnderACachedConfigurationNamesTheCacheFile(): void
    {
        assert($this->app !== null);

        $this->app->instance('config_loaded_from_cache', true);

        Config::set('api-toolkit.query_cost.max_depth', '-1');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('%s The configuration is cached; delete %s and rebuild it.', self::NEGATIVE_DEPTH, $this->cachePath));

        ToolkitConfigValidator::validate();
    }

    /**
     * Assert that the command, run in process with a negative depth cap in the
     * environment, fails with the given message.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     * @param  string  $message
     * @return void
     */
    private function assertCommandFails(string $command, array $parameters, string $message): void
    {
        $original = getenv(self::DEPTH_VARIABLE);

        putenv(self::DEPTH_VARIABLE . '=-1');
        $_ENV[self::DEPTH_VARIABLE]    = '-1';
        $_SERVER[self::DEPTH_VARIABLE] = '-1';

        try {
            $this->assertRefused(fn (): int => $this->runInProcess($command, $parameters), $message);
        } finally {
            putenv($original === false ? self::DEPTH_VARIABLE : self::DEPTH_VARIABLE . '=' . $original);
            unset($_ENV[self::DEPTH_VARIABLE], $_SERVER[self::DEPTH_VARIABLE]);
        }
    }

    /**
     * Run the command in process, as a deploy script calling Artisan does, then
     * hand the container and facades back to the test application, which the
     * fresh application took over.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     * @return int
     */
    private function runInProcess(string $command, array $parameters = []): int
    {
        assert($this->app !== null);

        $app = $this->app;

        try {
            return $app->make(Kernel::class)->call($command, $parameters);
        } finally {
            Container::setInstance($app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($app);
        }
    }
}
