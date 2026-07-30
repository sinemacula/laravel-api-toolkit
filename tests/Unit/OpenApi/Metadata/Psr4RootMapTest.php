<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

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
     * Test that a discoverer built from the registered Composer autoloader
     * exposes the package's own non-vendor root among its prefixes.
     *
     * @return void
     */
    public function testFromComposerExposesNonVendorPrefixes(): void
    {
        self::assertContains('SineMacula\ApiToolkit', Psr4RootMap::fromComposer()->prefixes());
    }

    /**
     * Test that roots keeps only existing, non-vendor directories, resolved to
     * their real paths.
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
     * Test that prefixes are trimmed, ordered longest first, and exclude a
     * prefix whose only directory is vendored.
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
}
