<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\CacheResourcesCommand;
use Tests\Fixtures\Discovery\Conflict\FirstUserResource;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the CacheResourcesCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheResourcesCommand::class)]
final class CacheResourcesCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:resources:cache';

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);

        $this->useIsolatedBootstrapPath();
    }

    /**
     * Test that the command compiles the scanned map to a PHP file that returns
     * it.
     *
     * @return void
     */
    public function testCommandCompilesTheScannedMap(): void
    {
        $this->pendingArtisan(self::COMMAND)
            ->expectsOutputToContain('API resources cached successfully.')
            ->assertExitCode(0)
            ->run();

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], require $this->compiledPath());
    }

    /**
     * Test that the command replaces a stale compiled map with a fresh scan
     * rather than re-writing the stale one.
     *
     * @return void
     */
    public function testCommandReplacesAStaleCompiledMap(): void
    {
        $this->writeCompiledMap([User::class => FirstUserResource::class]);

        $this->pendingArtisan(self::COMMAND)->assertExitCode(0)->run();

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], require $this->compiledPath());
    }

    /**
     * Test that the command creates a missing bootstrap cache directory.
     *
     * @return void
     */
    public function testCommandCreatesAMissingCacheDirectory(): void
    {
        rmdir(dirname($this->compiledPath()));

        $this->pendingArtisan(self::COMMAND)->assertExitCode(0)->run();

        self::assertFileExists($this->compiledPath());
    }

    /**
     * Test that the command never touches the cache store, so a build whose
     * store is unreachable still compiles the map.
     *
     * @return void
     */
    public function testCommandSucceedsWithAnUnreachableCacheStore(): void
    {
        $this->useUnreachableCacheStore();

        $this->pendingArtisan(self::COMMAND)->assertExitCode(0)->run();

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], require $this->compiledPath());
    }

    /**
     * Resolve the path of the compiled map.
     *
     * @return string
     */
    private function compiledPath(): string
    {
        assert($this->app !== null);

        return $this->app->bootstrapPath('cache/api-toolkit-resources.php');
    }
}
