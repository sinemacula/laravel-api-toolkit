<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ExportOpenApiCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiCommand::class)]
final class ExportOpenApiCommandTest extends TestCase
{
    /** @var string The console command signature. */
    private const string COMMAND = 'api-toolkit:export-openapi';

    /** @var string The temporary output path used for export assertions. */
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

        SchemaCompiler::clearCache();

        $this->outputPath = sys_get_temp_dir() . '/api-toolkit-command-' . uniqid() . '.json';
    }

    /**
     * Tear down each test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->outputPath);

        parent::tearDown();
    }

    /**
     * Test that the command exports a non-empty document and reports the
     * resource count, exit 0.
     *
     * @return void
     */
    public function testCommandExportsResourcesWithExitZero(): void
    {
        $this->registerResourceMap();

        $this->runCommand(['--output' => $this->outputPath])
            ->expectsOutputToContain('Exported 2 resource schema(s)')
            ->assertExitCode(0);

        static::assertFileExists($this->outputPath);

        $contents = file_get_contents($this->outputPath);

        static::assertIsString($contents);
        static::assertNotSame('', $contents);
        static::assertStringContainsString('"openapi"', $contents);
    }

    /**
     * Test that the exported document is pretty-printed with unescaped slashes.
     *
     * @return void
     */
    public function testCommandWritesPrettyPrintedJson(): void
    {
        $this->registerResourceMap();

        $this->runCommand(['--output' => $this->outputPath])
            ->assertExitCode(0);

        $contents = file_get_contents($this->outputPath);

        static::assertIsString($contents);
        static::assertStringContainsString("\n", $contents);
        static::assertStringContainsString('#/components/schemas/', $contents);
        static::assertStringNotContainsString('#\/components\/schemas\/', $contents);
    }

    /**
     * Test that the command warns and writes nothing when no resources are
     * registered, exit 0.
     *
     * @return void
     */
    public function testCommandWarnsWhenNoResourcesRegistered(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->runCommand(['--output' => $this->outputPath])
            ->expectsOutputToContain('No resources registered in the resource map')
            ->assertExitCode(0);

        static::assertFileDoesNotExist($this->outputPath);
    }

    /**
     * Test that the command falls back to the configured default output path
     * when no option is supplied.
     *
     * @return void
     */
    public function testCommandUsesConfiguredDefaultOutputPath(): void
    {
        $this->registerResourceMap();

        $this->getConfig()->set('api-toolkit.openapi.output', $this->outputPath);

        $this->runCommand()
            ->assertExitCode(0);

        static::assertFileExists($this->outputPath);
    }

    /**
     * Run the export command, returning the pending command for assertions.
     *
     * @param  array<string, mixed>  $arguments
     * @return \Illuminate\Testing\PendingCommand
     */
    private function runCommand(array $arguments = []): PendingCommand
    {
        $command = $this->artisan(self::COMMAND, $arguments);

        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * Register the fixture resource map on the config repository.
     *
     * @return void
     */
    private function registerResourceMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        assert($this->app instanceof Application);

        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->app->make('config');
    }
}
