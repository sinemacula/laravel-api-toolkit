<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Metadata\Psr4RootMap;
use Tests\TestCase;

/**
 * Tests for the Psr4RootMap.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Psr4RootMap::class)]
final class Psr4RootMapTest extends TestCase
{
    /**
     * Test that the raw prefix map is returned unchanged.
     *
     * @return void
     */
    public function testAllReturnsRawPrefixes(): void
    {
        $prefixes = ['App\\' => ['/app/src']];

        self::assertSame($prefixes, (new Psr4RootMap($prefixes))->all());
    }

    /**
     * Test that a Composer-built map exposes the package's own root.
     *
     * @return void
     */
    public function testFromComposerExposesNonVendorPrefixes(): void
    {
        self::assertContains('SineMacula\ApiToolkit', Psr4RootMap::fromComposer()->prefixes());
    }

    /**
     * Test that roots keeps only existing, non-vendor real paths.
     *
     * @return void
     */
    public function testRootsKeepsExistingNonVendorDirectories(): void
    {
        $base   = sys_get_temp_dir() . '/api-toolkit-rootmap-' . uniqid('', true);
        $plain  = $base . '/plain';
        $vendor = $base . '/vendor/pkg';

        mkdir($plain, 0777, true);
        mkdir($vendor, 0777, true);

        try {

            $map = new Psr4RootMap([
                'App\\'     => [$plain, $vendor],
                'Missing\\' => [$base . '/nope'],
            ]);

            $roots = $map->roots();

            self::assertSame(['App\\' => [realpath($plain)]], $roots);

        } finally {
            rmdir($vendor);
            rmdir($base . '/vendor');
            rmdir($plain);
            rmdir($base);
        }
    }

    /**
     * Test that prefixes are trimmed, longest first, without vendor-only ones.
     *
     * @return void
     */
    public function testPrefixesAreLongestFirstAndExcludeVendorOnly(): void
    {
        $map = new Psr4RootMap([
            'App\\'        => ['/app/src'],
            'App\Module\\' => ['/app/module/src'],
            'Vendored\\'   => ['/base/vendor/pkg/src'],
        ]);

        self::assertSame(['App\Module', 'App'], $map->prefixes());
    }

    /**
     * Test that no Composer ClassLoader yields an empty prefix map.
     *
     * @return void
     */
    public function testFromComposerYieldsAnEmptyMapWithoutAClassLoader(): void
    {
        // Warm every class the call touches before the loader is taken away
        Psr4RootMap::fromComposer()->roots();

        $loaders = array_filter(
            spl_autoload_functions(),
            static fn (mixed $autoloader): bool => is_array($autoloader) && $autoloader[0] instanceof ClassLoader,
        );

        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }

        try {
            $map = Psr4RootMap::fromComposer();
        } finally {
            foreach (array_reverse($loaders) as $loader) {
                spl_autoload_register($loader, true, true);
            }
        }

        self::assertSame([], $map->all());
        self::assertSame([], $map->roots());
        self::assertSame([], $map->prefixes());
    }
}
