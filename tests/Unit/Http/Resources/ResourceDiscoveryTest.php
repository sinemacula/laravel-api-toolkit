<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Fixtures\Discovery\Conflict\FirstUserResource;
use Tests\Fixtures\Discovery\Multi\MultiModelResource;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the attribute-based resource discovery.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceDiscovery::class)]
#[CoversClass(ForModel::class)]
final class ResourceDiscoveryTest extends TestCase
{
    /**
     * Test that attributed resources are discovered recursively from the
     * configured paths, in deterministic sorted-file order, while classes
     * without the attribute are ignored.
     *
     * @return void
     */
    public function testDiscoversAttributedResourcesRecursively(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that an invalid binding - a non-resource class, an abstract
     * resource, or a resource whose model does not exist - is skipped with a
     * warning and never joins the map.
     *
     * @return void
     */
    public function testInvalidBindingsAreSkippedWithAWarning(): void
    {
        Log::shouldReceive('warning')->times(3);

        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Invalid')]);

        self::assertSame([], $this->discovery()->discover());
    }

    /**
     * Test that when two resources claim the same model the first discovered
     * binding wins and the conflict is logged.
     *
     * @return void
     */
    public function testConflictKeepsFirstDiscoveredBindingAndWarns(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'multiple resources')
                && str_contains($message, 'ignoring [Tests\Fixtures\Discovery\Conflict\SecondUserResource]. Declare the canonical resource'));

        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Conflict')]);

        self::assertSame([
            User::class => FirstUserResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that a model conflict fails hard when schema validation is enabled.
     *
     * @return void
     */
    public function testConflictFailsHardUnderSchemaValidation(): void
    {
        Config::set('api-toolkit.resources.validate_schemas', true);
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Conflict')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ignoring \[Tests\\\Fixtures\\\Discovery\\\Conflict\\\SecondUserResource\]\. Declare the canonical resource/');

        $this->discovery()->discover();
    }

    /**
     * Test that overlapping configured paths do not duplicate a binding or
     * produce a spurious conflict.
     *
     * @return void
     */
    public function testOverlappingPathsAreDeduplicated(): void
    {
        Log::shouldReceive('warning')->never();

        Config::set('api-toolkit.resources.paths', [
            $this->fixturePath('Primary'),
            $this->fixturePath('Primary/Nested'),
        ]);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that files are visited in sorted order across the configured
     * paths, so the first-discovered-wins conflict rule is deterministic
     * regardless of path order.
     *
     * The Conflict directory sorts before Primary, so its resource must win
     * the User binding even though Primary is configured first.
     *
     * @return void
     */
    public function testFilesAreVisitedInSortedOrderAcrossPaths(): void
    {
        Log::shouldReceive('warning')->times(2);

        Config::set('api-toolkit.resources.paths', [
            $this->fixturePath('Primary'),
            $this->fixturePath('Conflict'),
        ]);

        self::assertSame([
            User::class => FirstUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that a configured path that does not exist is skipped without
     * error.
     *
     * @return void
     */
    public function testMissingPathIsSkipped(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('DoesNotExist')]);

        self::assertSame([], $this->discovery()->discover());

        // An empty path set must not touch the metadata cache at all.
        self::assertFalse(Cache::has(CacheKeys::DISCOVERED_RESOURCES->resolveKey([md5('')])));
    }

    /**
     * Test that a malformed paths value - not an array, or containing
     * non-string entries - yields no discovery.
     *
     * @return void
     */
    public function testMalformedPathsConfigurationYieldsNoDiscovery(): void
    {
        Config::set('api-toolkit.resources.paths', 'not-an-array');

        self::assertSame([], $this->discovery()->discover());

        Config::set('api-toolkit.resources.paths', [123, null, ['nested']]);

        self::assertSame([], $this->discovery()->discover());
    }

    /**
     * Test that the compiled map is memoised through the metadata cache: a
     * warm cache entry for the scanned file fingerprint is returned without
     * rescanning the filesystem.
     *
     * @return void
     */
    public function testCompiledMapIsMemoisedThroughTheMetadataCache(): void
    {
        $path = $this->fixturePath('Primary');

        Config::set('api-toolkit.resources.paths', [$path]);

        $sentinel = [User::class => FirstUserResource::class];

        Cache::forever($this->discoveryCacheKey([$path]), $sentinel);

        self::assertSame($sentinel, $this->discovery()->discover());
    }

    /**
     * Test that the discovery cache key is registered with the metadata key
     * registry, so the scoped lifecycle flush can forget it.
     *
     * @return void
     */
    public function testDiscoveryKeyIsRegisteredForTheScopedFlush(): void
    {
        assert($this->app !== null);

        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        $this->discovery()->discover();

        /** @var \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry $registry */
        $registry = $this->app->make(MetadataKeyRegistry::class);

        $registered = array_filter(
            $registry->keys(),
            static fn (string $key): bool => str_contains($key, 'discovered-resources:'),
        );

        self::assertNotEmpty($registered);
    }

    /**
     * Test that a repeated ForModel attribute binds one resource to several
     * models.
     *
     * @return void
     */
    public function testRepeatedAttributeBindsMultipleModels(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Multi')]);

        self::assertSame([
            User::class => MultiModelResource::class,
            Post::class => MultiModelResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that a model conflict is not diagnosed when the model is declared
     * explicitly in the resource map: the explicit entry resolves the
     * ambiguity, so no warning is logged and schema validation does not fail
     * the scan.
     *
     * @return void
     */
    public function testConflictIsSuppressedWhenModelDeclaredExplicitly(): void
    {
        Log::shouldReceive('warning')->never();

        Config::set('api-toolkit.resources.validate_schemas', true);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Conflict')]);

        self::assertSame([
            User::class => FirstUserResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that two scanned files declaring the same class produce a single
     * binding silently - a duplicate file is not an ambiguity.
     *
     * @return void
     */
    public function testDuplicateDeclarationsOfTheSameClassAreSkippedSilently(): void
    {
        Log::shouldReceive('warning')->never();

        $directory = sys_get_temp_dir() . '/resource-discovery-' . uniqid((string) getmypid(), true);

        mkdir($directory, 0o755, true);

        $contents = file_get_contents($this->fixturePath('Conflict') . '/FirstUserResource.php');

        assert($contents !== false);

        file_put_contents($directory . '/CopyOne.php', $contents);
        file_put_contents($directory . '/CopyTwo.php', $contents);

        try {
            Config::set('api-toolkit.resources.paths', [$directory]);

            self::assertSame([
                User::class => FirstUserResource::class,
            ], $this->discovery()->discover());
        } finally {
            unlink($directory . '/CopyOne.php');
            unlink($directory . '/CopyTwo.php');
            rmdir($directory);
        }
    }

    /**
     * Test that enabling schema validation bypasses the metadata cache, so
     * every diagnostic runs on every boot.
     *
     * @return void
     */
    public function testSchemaValidationBypassesTheCache(): void
    {
        $path = $this->fixturePath('Primary');

        Config::set('api-toolkit.resources.validate_schemas', true);
        Config::set('api-toolkit.resources.paths', [$path]);

        Cache::forever($this->discoveryCacheKey([$path]), [User::class => FirstUserResource::class]);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that changing a scanned file rotates the cache key, so the next
     * boot rescans instead of serving the stale map.
     *
     * @return void
     */
    public function testFileChangeRotatesTheCacheKey(): void
    {
        $directory = sys_get_temp_dir() . '/resource-discovery-' . uniqid((string) getmypid(), true);

        mkdir($directory, 0o755, true);

        $contents = file_get_contents($this->fixturePath('Primary') . '/DiscoveredUserResource.php');

        assert($contents !== false);

        $file = $directory . '/DiscoveredUserResource.php';

        file_put_contents($file, $contents);

        try {
            Config::set('api-toolkit.resources.paths', [$directory]);

            $sentinel = [User::class => FirstUserResource::class];

            Cache::forever($this->discoveryCacheKey([$directory]), $sentinel);

            // The warm entry for the current fingerprint is served as-is.
            self::assertSame($sentinel, $this->discovery()->discover());

            touch($file, time() + 10);
            clearstatcache();

            // The touched file rotates the key, forcing a fresh scan.
            self::assertSame([
                User::class => DiscoveredUserResource::class,
            ], $this->discovery()->discover());
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    /**
     * Test that a published config predating the paths key falls back to the
     * default application resource directory rather than silently disabling
     * discovery.
     *
     * The scan is observed through its cache write rather than a binding, so
     * the transient file cannot leak a discovery into concurrently booting
     * tests: the class it declares is not autoloadable.
     *
     * @return void
     */
    public function testMissingPathsKeyFallsBackToTheDefaultDirectory(): void
    {
        $resources = Config::get('api-toolkit.resources');

        assert(is_array($resources));

        unset($resources['paths']);

        Config::set('api-toolkit.resources', $resources);

        $directory = app_path('Http/Resources');
        $created   = !is_dir($directory);

        if ($created) {
            mkdir($directory, 0o755, true);
        }

        $file = $directory . '/FallbackProbe.php';

        file_put_contents($file, "<?php\n\nnamespace Ghost\\Discovery;\n\nclass FallbackProbe {}\n");

        try {
            self::assertSame([], $this->discovery()->discover());

            // The default path was scanned: the fingerprint key was written.
            self::assertTrue(Cache::has($this->discoveryCacheKey([$directory])));
        } finally {
            unlink($file);

            if ($created) {
                rmdir($directory);
            }
        }
    }

    /**
     * Test that a file removed between enumeration and read yields no classes
     * rather than aborting discovery, so a concurrent scan or a hot deploy
     * swapping resources mid-scan cannot break resolution.
     *
     * @return void
     */
    public function testVanishedFileYieldsNoClasses(): void
    {
        $method = new \ReflectionMethod(ResourceDiscovery::class, 'classesFromFile');

        $result = $method->invoke($this->discovery(), $this->fixturePath('Primary') . '/DoesNotExist.php');

        self::assertSame([], $result);
    }

    /**
     * Test that every declared class in a multi-class, multi-namespace file
     * is considered: both attributed resources bind, and a trailing
     * unloadable class is skipped without disturbing the bindings already
     * made.
     *
     * @return void
     */
    public function testEveryClassInAMultiClassFileIsDiscovered(): void
    {
        $directory = sys_get_temp_dir() . '/resource-discovery-' . uniqid((string) getmypid(), true);

        mkdir($directory, 0o755, true);

        $file = $directory . '/multi-class.php';

        file_put_contents($file, implode("\n", [
            '<?php',
            'namespace Tests\Fixtures\Discovery\Primary;',
            'class ZzUnloadableHelper {}',
            'class DiscoveredUserResource {}',
            'namespace Tests\Fixtures\Discovery\Primary\Nested;',
            'class DiscoveredPostResource {}',
            'namespace Ghost\Discovery;',
            'class ZzTrailingGhost {}',
        ]));

        try {
            Config::set('api-toolkit.resources.paths', [$directory]);

            self::assertSame([
                User::class => DiscoveredUserResource::class,
                Post::class => DiscoveredPostResource::class,
            ], $this->discovery()->discover());
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    /**
     * Test that a scanned file declaring no class, or declaring a class the
     * autoloader cannot locate, is skipped without side effects.
     *
     * @return void
     */
    public function testFilesWithoutALoadableClassAreSkipped(): void
    {
        $directory = sys_get_temp_dir() . '/resource-discovery-' . uniqid((string) getmypid(), true);

        mkdir($directory, 0o755, true);

        $files = [
            'functions.php'          => "<?php\n\nfunction resourceDiscoveryFixtureNoop(): void {}\n",
            'ghost.php'              => "<?php\n\nnamespace Ghost\\Discovery;\n\nclass Ghost {}\n",
            'global-class.php'       => "<?php\n\nclass ResourceDiscoveryGlobalFixture {}\n",
            'dangling-class.php'     => '<?php class',
            'trailing-namespace.php' => '<?php namespace ',
            'braced-namespace.php'   => "<?php\n\nnamespace Ghost\\Braced {\n    class BracedGhost {}\n}\n",
            'enum.php'               => "<?php\n\nnamespace Ghost\\Discovery;\n\nenum GhostShape {}\n",
            'trait.php'              => "<?php\n\nnamespace Ghost\\Discovery;\n\ntrait GhostConcern {}\n",
            'notes.txt'              => 'not a php file',
        ];

        foreach ($files as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }

        try {
            Config::set('api-toolkit.resources.paths', [$directory]);

            self::assertSame([], $this->discovery()->discover());
        } finally {
            foreach (array_keys($files) as $name) {
                unlink($directory . '/' . $name);
            }

            rmdir($directory);
        }
    }

    /**
     * Resolve the discovery service from the container.
     *
     * @return \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery
     */
    private function discovery(): ResourceDiscovery
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery */
        return $this->app->make(ResourceDiscovery::class);
    }

    /**
     * Resolve the absolute path to a discovery fixture directory.
     *
     * @param  string  $directory
     * @return string
     */
    private function fixturePath(string $directory): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Discovery/' . $directory;
    }

    /**
     * Compute the discovery cache key for the given paths, mirroring the
     * file-and-mtime fingerprint the service derives.
     *
     * @param  array<int, string>  $paths
     * @return string
     */
    private function discoveryCacheKey(array $paths): string
    {
        $files = [];

        foreach ($paths as $path) {

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {

                if ($file->getExtension() !== 'php' || $file->getRealPath() === false) {
                    continue;
                }

                $files[] = $file->getRealPath();
            }
        }

        sort($files);

        $fingerprint = array_map(static fn (string $file): string => $file . ':' . (int) filemtime($file), $files);

        return CacheKeys::DISCOVERED_RESOURCES->resolveKey([md5(implode('|', $fingerprint))]);
    }
}
