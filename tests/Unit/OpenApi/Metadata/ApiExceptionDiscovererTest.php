<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Exceptions\WidgetFailureException;
use Tests\TestCase;

/**
 * Tests for the ApiExceptionDiscoverer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionDiscoverer::class)]
final class ApiExceptionDiscovererTest extends TestCase
{
    /**
     * Test that an application ApiException is discovered from a non-vendor
     * root while a sibling that is not an ApiException is ignored.
     *
     * @return void
     */
    public function testDiscoversApiExceptionsAndIgnoresOthers(): void
    {
        $directory = $this->fixtureExceptionsDirectory();

        $discoverer = new ApiExceptionDiscoverer(['Tests\Fixtures\Exceptions\\' => [$directory]]);

        $discovered = $discoverer->discover();

        self::assertContains(WidgetFailureException::class, $discovered);
        self::assertNotContains(ServiceExecutionException::class, $discovered);
    }

    /**
     * Test that a root whose real path sits inside a vendor directory is
     * excluded, so an installed package is never scanned.
     *
     * @return void
     */
    public function testExcludesVendorRoots(): void
    {
        $base   = sys_get_temp_dir() . '/api-toolkit-discoverer-' . uniqid('', true);
        $plain  = $base . '/plain';
        $vendor = $base . '/vendor/pkg';

        mkdir($plain, 0777, true);
        mkdir($vendor, 0777, true);

        // Both stub files derive the same already-autoloadable FQCN, so only
        // the vendor exclusion distinguishes them.
        file_put_contents($plain . '/WidgetFailureException.php', "<?php\n");
        file_put_contents($vendor . '/WidgetFailureException.php', "<?php\n");

        try {

            $discoverer = new ApiExceptionDiscoverer([
                'Tests\Fixtures\Exceptions\\' => [$plain, $vendor],
            ]);

            $discovered = $discoverer->discover();

            self::assertSame([WidgetFailureException::class], $discovered);

        } finally {
            unlink($plain . '/WidgetFailureException.php');
            unlink($vendor . '/WidgetFailureException.php');
            rmdir($vendor);
            rmdir($base . '/vendor');
            rmdir($plain);
            rmdir($base);
        }
    }

    /**
     * Test that a discoverer with no roots discovers nothing.
     *
     * @return void
     */
    public function testDiscoversNothingWithoutRoots(): void
    {
        self::assertSame([], (new ApiExceptionDiscoverer([]))->discover());
    }

    /**
     * Test that a root that does not exist is skipped rather than raising.
     *
     * @return void
     */
    public function testIgnoresNonExistentRoots(): void
    {
        $discoverer = new ApiExceptionDiscoverer([
            'App\\' => [sys_get_temp_dir() . '/api-toolkit-missing-' . uniqid('', true)],
        ]);

        self::assertSame([], $discoverer->discover());
    }

    /**
     * Test that an error raised while autoloading a candidate leaves it
     * unqualified rather than aborting the scan.
     *
     * @return void
     */
    public function testToleratesAutoloadFailures(): void
    {
        $directory = sys_get_temp_dir() . '/api-toolkit-throwing-' . uniqid('', true);

        mkdir($directory, 0777, true);
        file_put_contents($directory . '/Boom.php', "<?php\n");

        $autoloader = static function (string $class): void {
            if ($class === 'Tests\Fixtures\Throwing\Boom') {
                throw new \RuntimeException('autoload exploded');
            }
        };

        spl_autoload_register($autoloader);

        try {

            $discoverer = new ApiExceptionDiscoverer(['Tests\Fixtures\Throwing\\' => [$directory]]);

            self::assertSame([], $discoverer->discover());

        } finally {
            spl_autoload_unregister($autoloader);
            unlink($directory . '/Boom.php');
            rmdir($directory);
        }
    }

    /**
     * Test that a discoverer built from the registered Composer autoloader
     * scans the package's own non-vendor roots, finding both the toolkit's
     * exceptions and the application fixture.
     *
     * @return void
     */
    public function testFromComposerScansNonVendorRoots(): void
    {
        $discovered = ApiExceptionDiscoverer::fromComposer()->discover();

        self::assertContains(NotFoundException::class, $discovered);
        self::assertContains(WidgetFailureException::class, $discovered);
    }

    /**
     * Resolve the directory holding the fixture exceptions.
     *
     * @return string
     */
    private function fixtureExceptionsDirectory(): string
    {
        $file = (new \ReflectionClass(WidgetFailureException::class))->getFileName();

        self::assertIsString($file);

        return dirname($file);
    }
}
