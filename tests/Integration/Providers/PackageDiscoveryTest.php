<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\ApiServiceProvider;

/**
 * Tests that an application installing the package discovers its service
 * provider from the composer manifest.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class PackageDiscoveryTest extends TestCase
{
    /** @var string The application directory the package is installed into */
    private string $basePath;

    /**
     * Install the package's composer manifest into an empty application.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/api-toolkit-discovery-' . getmypid() . '-' . uniqid('', true);

        mkdir($this->basePath . '/vendor/composer', 0o755, true);

        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        self::assertIsArray($composer);

        file_put_contents($this->basePath . '/vendor/composer/installed.json', json_encode([
            'packages' => [['name' => $composer['name'], 'extra' => $composer['extra'] ?? []]],
        ]));
    }

    /**
     * Remove the application directory.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    /**
     * Test that the service provider is discovered and every discovered
     * provider is a service provider that exists.
     *
     * @return void
     */
    public function testServiceProviderIsDiscovered(): void
    {
        $providers = (new PackageManifest(new Filesystem, $this->basePath, $this->basePath . '/packages.php'))->providers();

        self::assertContains(ApiServiceProvider::class, $providers);

        foreach ($providers as $provider) {
            self::assertTrue(is_subclass_of($provider, ServiceProvider::class), $provider . ' is not a service provider');
        }
    }
}
