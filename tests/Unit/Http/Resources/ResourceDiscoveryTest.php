<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Fixtures\Discovery\Conflict\FirstUserResource;
use Tests\Fixtures\Discovery\Conflict\SecondUserResource;
use Tests\Fixtures\Discovery\Multi\MultiModelResource;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\FunctionOverrides;
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
     * Test that files are visited in sorted order across the configured paths,
     * so the first-discovered-wins conflict rule is deterministic regardless of
     * path order.
     *
     * The Conflict directory sorts before Primary, so its resource must win the
     * User binding even though Primary is configured first.
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
     * Test that a configured path that does not exist is skipped without error.
     *
     * @return void
     */
    public function testMissingPathIsSkipped(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('DoesNotExist')]);

        self::assertSame([], $this->discovery()->discover());
    }

    /**
     * Test that a scan root the iterator cannot open yields no bindings rather
     * than propagating its failure, so a directory whose permissions change
     * under a running scan cannot break resolution. The root holds a resource
     * that would otherwise be discovered, so the empty result can only come
     * from the failure being absorbed.
     *
     * @return void
     */
    public function testUnreadableScanRootYieldsNoBindings(): void
    {
        $directory = sys_get_temp_dir() . '/resource-discovery-' . uniqid((string) getmypid(), true);

        mkdir($directory, 0o755, true);

        $contents = file_get_contents($this->fixturePath('Primary') . '/DiscoveredUserResource.php');

        assert($contents !== false);

        $file = $directory . '/DiscoveredUserResource.php';

        file_put_contents($file, $contents);
        chmod($directory, 0o000);
        clearstatcache();

        try {
            if (is_readable($directory)) {
                self::markTestSkipped('The scan root stayed readable, so the iterator failure cannot be provoked.');
            }

            Config::set('api-toolkit.resources.paths', [$directory]);

            self::assertSame([], $this->discovery()->discover());
        } finally {
            chmod($directory, 0o755);
            unlink($file);
            rmdir($directory);
        }
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
     * Test that a compiled map is served as-is without scanning: the configured
     * paths hold only invalid bindings, each of which a scan would report with
     * a warning.
     *
     * @return void
     */
    public function testCompiledMapIsServedWithoutScanning(): void
    {
        Log::shouldReceive('warning')->never();

        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Invalid')]);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledMap([User::class => FirstUserResource::class]);

        self::assertSame([User::class => FirstUserResource::class], $this->discovery()->discover());
    }

    /**
     * Test that an empty compiled map is honoured rather than treated as
     * absent, so a build that discovered nothing never scans on boot.
     *
     * @return void
     */
    public function testEmptyCompiledMapIsHonoured(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledMap([]);

        self::assertSame([], $this->discovery()->discover());
    }

    /**
     * Test that discovery scans the configured paths when no compiled map
     * exists, detecting the absence without raising a warning.
     *
     * @return void
     */
    public function testMissingCompiledMapFallsBackToScanning(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        $this->useIsolatedBootstrapPath();

        set_error_handler(static function (int $level, string $message): never {
            throw new \LogicException($message);
        });

        try {
            self::assertSame([
                User::class => DiscoveredUserResource::class,
                Post::class => DiscoveredPostResource::class,
            ], $this->discovery()->discover());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Test that a compiled file that does not return an array is ignored in
     * favour of a scan.
     *
     * @return void
     */
    public function testCompiledFileWithoutAnArrayFallsBackToScanning(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledResources("<?php return 'stale';\n");

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that a compiled map removed after its existence check, as an
     * optimize:clear racing a boot does, falls back to a scan rather than
     * failing the boot.
     *
     * @return void
     */
    public function testCompiledMapClearedMidBootFallsBackToScanning(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        $this->useIsolatedBootstrapPath();

        FunctionOverrides::set('is_file', static fn (): bool => true);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that the compiled map lives in the application's bootstrap cache
     * directory.
     *
     * @return void
     */
    public function testCachePathIsUnderTheBootstrapCacheDirectory(): void
    {
        assert($this->app !== null);

        $this->useIsolatedBootstrapPath();

        self::assertSame($this->app->bootstrapPath('cache/api-toolkit-resources.php'), $this->discovery()->cachePath());
    }

    /**
     * Test that an explicit scan ignores the compiled map, so rebuilding it
     * never re-writes a stale map.
     *
     * @return void
     */
    public function testScanIgnoresTheCompiledMap(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledMap([User::class => FirstUserResource::class]);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->scan());
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
     * Test that enabling schema validation ignores the compiled map, so every
     * diagnostic runs on every boot.
     *
     * @return void
     */
    public function testSchemaValidationIgnoresTheCompiledMap(): void
    {
        Config::set('api-toolkit.resources.validate_schemas', true);
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledMap([User::class => FirstUserResource::class]);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->discovery()->discover());
    }

    /**
     * Test that a published config predating the paths key falls back to the
     * default application resource directory rather than silently disabling
     * discovery. The app path is redirected to an isolated temporary tree so
     * the assertion is deterministic under parallel runs.
     *
     * @return void
     */
    public function testMissingPathsKeyFallsBackToTheDefaultDirectory(): void
    {
        assert($this->app !== null);

        $resources = Config::get('api-toolkit.resources');

        assert(is_array($resources));

        unset($resources['paths']);

        Config::set('api-toolkit.resources', $resources);

        $base = sys_get_temp_dir() . '/resource-discovery-fallback-' . uniqid((string) getmypid(), true);

        mkdir($base . '/Http/Resources', 0o755, true);

        $this->app->useAppPath($base);

        $contents = file_get_contents($this->fixturePath('Primary') . '/DiscoveredUserResource.php');

        assert($contents !== false);

        $file = $base . '/Http/Resources/DiscoveredUserResource.php';

        file_put_contents($file, $contents);

        try {
            self::assertSame([
                User::class => DiscoveredUserResource::class,
            ], $this->discovery()->discover());
        } finally {
            unlink($file);
            rmdir($base . '/Http/Resources');
            rmdir($base . '/Http');
            rmdir($base);
        }
    }

    /**
     * Test that the null default resolves to the application resource directory
     * plus each module's, so a modular application - which repoints app_path()
     * at its module root - has its module resources discovered with no
     * configuration.
     *
     * A module's resources sit at app_path('{Module}/Http/Resources'), one
     * level below the flat app_path('Http/Resources'); the default scan must
     * reach that nested directory. The app path is redirected to an isolated
     * temporary tree so the assertion is deterministic under parallel runs.
     *
     * @return void
     */
    public function testNullDefaultDiscoversApplicationAndModuleResources(): void
    {
        assert($this->app !== null);

        $base = sys_get_temp_dir() . '/resource-discovery-app-' . uniqid((string) getmypid(), true);

        mkdir($base . '/Http/Resources', 0o755, true);
        mkdir($base . '/Blog/Http/Resources', 0o755, true);

        $contents = file_get_contents($this->fixturePath('Primary') . '/DiscoveredUserResource.php');

        assert($contents !== false);

        file_put_contents($base . '/Blog/Http/Resources/DiscoveredUserResource.php', $contents);

        $this->app->useAppPath($base);

        $resources = Config::get('api-toolkit.resources');

        assert(is_array($resources));

        $resources['paths'] = null;

        Config::set('api-toolkit.resources', $resources);

        try {
            self::assertSame([
                User::class => DiscoveredUserResource::class,
            ], $this->discovery()->discover());
        } finally {
            unlink($base . '/Blog/Http/Resources/DiscoveredUserResource.php');
            rmdir($base . '/Blog/Http/Resources');
            rmdir($base . '/Blog/Http');
            rmdir($base . '/Blog');
            rmdir($base . '/Http/Resources');
            rmdir($base . '/Http');
            rmdir($base);
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
     * Test that every declared class in a multi-class, multi-namespace file is
     * considered: both attributed resources bind, and a trailing unloadable
     * class is skipped without disturbing the bindings already made.
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
     * Test that a non-PHP file encountered while walking a resource directory
     * does not halt enumeration: a resource sitting beside a stray file is
     * still discovered.
     *
     * @return void
     */
    public function testNonPhpFilesDoNotHaltDiscovery(): void
    {
        $directory = sys_get_temp_dir() . '/resource-discovery-' . uniqid((string) getmypid(), true);

        mkdir($directory, 0o755, true);

        $contents = file_get_contents($this->fixturePath('Primary') . '/DiscoveredUserResource.php');

        assert($contents !== false);

        file_put_contents($directory . '/notes.txt', 'not a php file');
        file_put_contents($directory . '/DiscoveredUserResource.php', $contents);

        try {
            Config::set('api-toolkit.resources.paths', [$directory]);

            self::assertSame([
                User::class => DiscoveredUserResource::class,
            ], $this->discovery()->discover());
        } finally {
            unlink($directory . '/notes.txt');
            unlink($directory . '/DiscoveredUserResource.php');
            rmdir($directory);
        }
    }

    /**
     * Test that a class declared in the global namespace is resolved without a
     * leading namespace separator, so the compiled name matches the value the
     * autoloader and reflection expect.
     *
     * @return void
     */
    public function testGlobalNamespaceClassNameHasNoLeadingSeparator(): void
    {
        $directory = sys_get_temp_dir() . '/resource-discovery-' . uniqid((string) getmypid(), true);

        mkdir($directory, 0o755, true);

        $file = $directory . '/global-class.php';

        file_put_contents($file, "<?php\n\nclass ResourceDiscoveryGlobalProbe {}\n");

        $method = new \ReflectionMethod(ResourceDiscovery::class, 'classesFromFile');

        try {
            self::assertSame(['ResourceDiscoveryGlobalProbe'], $method->invoke($this->discovery(), $file));
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    /**
     * Test that the namespace name is read from the token that abuts the
     * keyword: a non-name token sitting immediately after namespace resolves to
     * no name rather than skipping ahead to a later identifier.
     *
     * @return void
     */
    public function testNamespaceNameIsReadFromTheTokenAbuttingTheKeyword(): void
    {
        $tokens = \PhpToken::tokenize('<?php namespace(Foo)');
        $method = new \ReflectionMethod(ResourceDiscovery::class, 'namespaceFromTokens');

        // The keyword sits at index 1; the token at index 2 abuts it and is not
        // a name token, so no namespace resolves. Beginning the scan a token
        // later would wrongly read the identifier at index 3.
        self::assertNull($method->invoke($this->discovery(), $tokens, 1));
    }

    /**
     * Test that the declared class name is read from the token that abuts the
     * keyword: a non-name token sitting immediately after class resolves to no
     * declaration rather than skipping ahead to a later identifier.
     *
     * @return void
     */
    public function testDeclaredNameIsReadFromTheTokenAbuttingTheKeyword(): void
    {
        $tokens = \PhpToken::tokenize('<?php class(Foo)');
        $method = new \ReflectionMethod(ResourceDiscovery::class, 'declaredNameFromTokens');

        // The keyword sits at index 1; the token at index 2 abuts it and is not
        // a name token, so no declaration resolves. Beginning the scan a token
        // later would wrongly read the identifier at index 3.
        self::assertNull($method->invoke($this->discovery(), $tokens, 1, null));
    }

    /**
     * Test that a class keyword preceded by a double colon is treated as a
     * class-constant reference and never as a declaration, even when an
     * identifier token follows it.
     *
     * @return void
     */
    public function testClassConstantReferenceIsNeverADeclaration(): void
    {
        $tokens = \PhpToken::tokenize('<?php Foo::class Bar');
        $method = new \ReflectionMethod(ResourceDiscovery::class, 'declaredNameFromTokens');

        // The class keyword sits at index 3, preceded by the double colon at
        // index 2; the guard must short-circuit before the loop reads the
        // trailing identifier at index 5.
        self::assertNull($method->invoke($this->discovery(), $tokens, 3, $tokens[2]));
    }

    /**
     * Test that the merge places the discovered bindings beneath the explicit
     * map and records the bindings it contributed.
     *
     * @return void
     */
    public function testMergePlacesDiscoveredBindingsBeneathTheExplicitMap(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        $this->discovery()->merge();

        self::assertSame([
            User::class => UserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], Config::get('api-toolkit.resources.resource_map'));

        self::assertSame([
            Post::class => DiscoveredPostResource::class,
        ], Config::get('api-toolkit.resources.discovered_bindings'));
    }

    /**
     * Test that the merge replaces the bindings an earlier merge contributed,
     * as a config cached after a merge holds, while explicit entries stay.
     *
     * @return void
     */
    public function testMergeReplacesBindingsAnEarlierMergeContributed(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);
        Config::set('api-toolkit.resources.resource_map', [
            User::class => FirstUserResource::class,
            Post::class => PostResource::class,
        ]);
        Config::set('api-toolkit.resources.discovered_bindings', [User::class => FirstUserResource::class]);

        $this->discovery()->merge();

        self::assertSame([
            Post::class => PostResource::class,
            User::class => DiscoveredUserResource::class,
        ], Config::get('api-toolkit.resources.resource_map'));

        self::assertSame([
            User::class => DiscoveredUserResource::class,
        ], Config::get('api-toolkit.resources.discovered_bindings'));
    }

    /**
     * Test that the merge drops the bindings an earlier merge contributed when
     * nothing is discovered any more.
     *
     * @return void
     */
    public function testMergeDropsEarlierContributionsWhenNothingIsDiscovered(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', [
            User::class => FirstUserResource::class,
            Post::class => PostResource::class,
        ]);
        Config::set('api-toolkit.resources.discovered_bindings', [User::class => FirstUserResource::class]);

        $this->discovery()->merge();

        self::assertSame([Post::class => PostResource::class], Config::get('api-toolkit.resources.resource_map'));
        self::assertSame([], Config::get('api-toolkit.resources.discovered_bindings'));
    }

    /**
     * Test that the merge leaves the configuration untouched when nothing is
     * discovered and no earlier merge contributed, even for a malformed map.
     *
     * @return void
     */
    public function testMergeWithNothingToMergeLeavesTheConfigurationUntouched(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', 'not-an-array');

        $this->discovery()->merge();

        self::assertSame('not-an-array', Config::get('api-toolkit.resources.resource_map'));
        self::assertNull(Config::get('api-toolkit.resources.discovered_bindings'));
    }

    /**
     * Test that malformed recorded bindings are treated as no contribution.
     *
     * @return void
     */
    public function testMalformedRecordedBindingsAreIgnored(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);
        Config::set('api-toolkit.resources.discovered_bindings', 'not-an-array');

        $this->discovery()->merge();

        self::assertSame([User::class => UserResource::class], Config::get('api-toolkit.resources.resource_map'));
        self::assertSame('not-an-array', Config::get('api-toolkit.resources.discovered_bindings'));
    }

    /**
     * Test that a model bound only by an earlier merge is not treated as
     * explicitly declared, so a rebuild still reports a conflict on it.
     *
     * @return void
     */
    public function testConflictIsReportedForAModelBoundOnlyByAnEarlierMerge(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'ignoring [' . SecondUserResource::class . ']'));

        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Conflict')]);
        Config::set('api-toolkit.resources.resource_map', [User::class => FirstUserResource::class]);
        Config::set('api-toolkit.resources.discovered_bindings', [User::class => FirstUserResource::class]);

        self::assertSame([
            User::class => FirstUserResource::class,
        ], $this->discovery()->scan());
    }

    /**
     * Test that the merge keeps an explicit entry naming a different resource
     * from the one an earlier merge recorded for its model.
     *
     * @return void
     */
    public function testMergeKeepsAnExplicitEntryThatDiffersFromTheRecordedBinding(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Primary')]);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);
        Config::set('api-toolkit.resources.discovered_bindings', [User::class => FirstUserResource::class]);

        $this->discovery()->merge();

        self::assertSame([
            User::class => UserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], Config::get('api-toolkit.resources.resource_map'));

        self::assertSame([
            Post::class => DiscoveredPostResource::class,
        ], Config::get('api-toolkit.resources.discovered_bindings'));
    }

    /**
     * Test that an explicit entry naming a different resource from the one an
     * earlier merge recorded still resolves a conflict on its model.
     *
     * @return void
     */
    public function testConflictIsSuppressedByAnExplicitEntryThatDiffersFromTheRecordedBinding(): void
    {
        Log::shouldReceive('warning')->never();

        Config::set('api-toolkit.resources.paths', [$this->fixturePath('Conflict')]);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);
        Config::set('api-toolkit.resources.discovered_bindings', [User::class => FirstUserResource::class]);

        self::assertSame([
            User::class => FirstUserResource::class,
        ], $this->discovery()->scan());
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
}
