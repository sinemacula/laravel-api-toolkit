<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
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
#[CoversClass(ExportOpenApiComponents::class)]
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

        self::assertFileExists($this->outputPath);

        $contents = file_get_contents($this->outputPath);

        self::assertIsString($contents);
        self::assertNotSame('', $contents);
        self::assertStringContainsString('"openapi"', $contents);
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

        self::assertIsString($contents);
        self::assertStringContainsString("\n", $contents);
        self::assertStringContainsString('#/components/schemas/', $contents);
        self::assertStringNotContainsString('#\/components\/schemas\/', $contents);
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

        self::assertFileDoesNotExist($this->outputPath);
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

        self::assertFileExists($this->outputPath);
    }

    /**
     * Test that an empty configured output path falls back to the default
     * project path rather than being treated as a usable destination.
     *
     * @return void
     */
    public function testCommandFallsBackToDefaultPathWhenConfiguredOutputIsEmpty(): void
    {
        $this->registerResourceMap();

        $this->getConfig()->set('api-toolkit.openapi.output', '');

        $default = base_path('openapi.json');
        @unlink($default);

        $this->runCommand()
            ->assertExitCode(0);

        self::assertFileExists($default);

        @unlink($default);
    }

    /**
     * Test that a document-write failure is surfaced as a non-zero outcome.
     *
     * The command has no try/catch around the write, so a failing writer port
     * propagates its exception uncaught rather than reporting success.
     *
     * @return void
     */
    public function testCommandSurfacesDocumentWriteFailure(): void
    {
        $this->registerResourceMap();

        assert($this->app instanceof Application);

        $this->app->instance(DocumentWriter::class, new class implements DocumentWriter {
            /**
             * Persist the serialized document at the given path.
             *
             * @param  string  $path
             * @param  string  $contents
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            public function write(string $path, string $contents): void
            {
                throw new \RuntimeException('Unable to write the OpenAPI document.');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to write the OpenAPI document.');

        Artisan::call(self::COMMAND, ['--output' => $this->outputPath]);
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
