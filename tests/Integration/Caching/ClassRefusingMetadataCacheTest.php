<?php

declare(strict_types = 1);

namespace Tests\Integration\Caching;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end test of the metadata cache under a store that unserializes no
 * class, as Laravel's serializable_classes setting refuses them by default.
 *
 * One process warms the cache and the next reads it back, so every entry the
 * first writes must survive being read without its classes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaIntrospector::class)]
final class ClassRefusingMetadataCacheTest extends TestCase
{
    /** @var string The temporary docs directory. */
    private string $docsDir;

    /** @var string The temporary OpenAPI output path. */
    private string $outputPath;

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->docsDir    = sys_get_temp_dir() . '/api-toolkit-docs-' . uniqid('', true);
        $this->outputPath = sys_get_temp_dir() . '/api-toolkit-openapi-' . uniqid('', true) . '.json';

        $this->getConfig()->set('api-toolkit.openapi.docs_path', $this->docsDir);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);

        $this->router()->get('widget', [PathResponseSchemaController::class, 'widget']);

        $this->useClassRefusingCacheStore();
    }

    /**
     * Tear down each test, removing the generated files.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->outputPath);

        foreach (glob($this->docsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->docsDir);

        parent::tearDown();
    }

    /**
     * Test that the OpenAPI export succeeds against a cache the docs command
     * warmed in an earlier process, and exports what a cold cache would.
     *
     * @return void
     */
    public function testTheExportSucceedsAgainstACacheWarmedByTheDocsCommand(): void
    {
        $this->pendingArtisan('api-toolkit:docs:generate')->assertExitCode(0);

        $this->startAnotherProcess();

        $this->pendingArtisan('api-toolkit:export-openapi', ['--output' => $this->outputPath])->assertExitCode(0);

        $warm = file_get_contents($this->outputPath);

        $this->getConfig()->set('cache.stores.cold', ['driver' => 'array']);
        $this->getConfig()->set('cache.default', 'cold');
        $this->startAnotherProcess();

        $this->pendingArtisan('api-toolkit:export-openapi', ['--output' => $this->outputPath])->assertExitCode(0);

        self::assertIsString($warm);
        self::assertSame(file_get_contents($this->outputPath), $warm);
    }

    /**
     * Drop every piece of state this process holds, so the next command reads
     * the metadata from the shared store as another process would.
     *
     * @return void
     */
    private function startAnotherProcess(): void
    {
        assert($this->app instanceof Application);

        $this->app->make(CacheManager::class)->flush();
        $this->app->forgetScopedInstances();
    }

    /**
     * Get the application router.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app instanceof Application);

        return $this->app->make(Router::class);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        assert($this->app instanceof Application);

        /** @var \Illuminate\Config\Repository */
        return $this->app->make('config');
    }
}
