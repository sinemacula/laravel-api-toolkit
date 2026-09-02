<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\GenerateDocsCommand;
use SineMacula\ApiToolkit\OpenApi\Docs\ReferencedEnumCollector;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the GenerateDocsCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(GenerateDocsCommand::class)]
#[CoversClass(ReferencedEnumCollector::class)]
final class GenerateDocsCommandTest extends TestCase
{
    /** @var string The console command signature. */
    private const string COMMAND = 'api-toolkit:docs:generate';

    /** @var string The generated error catalogue path. */
    private string $errorFile;

    /** @var string The generated enum reference path. */
    private string $enumFile;

    /** @var string The generated query surface reference path. */
    private string $surfaceFile;

    /** @var string The temporary docs directory. */
    private string $docsDir;

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

        $this->docsDir     = sys_get_temp_dir() . '/api-toolkit-docs-' . uniqid('', true);
        $this->errorFile   = $this->docsDir . '/40-error-catalogue.md';
        $this->enumFile    = $this->docsDir . '/50-enum-reference.md';
        $this->surfaceFile = $this->docsDir . '/60-query-surface-reference.md';

        $this->getConfig()->set('api-toolkit.openapi.docs_path', $this->docsDir);
        $this->registerResourceMap();
    }

    /**
     * Tear down each test, removing the temporary docs directory.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->docsDir . '/*') ?: []);
        @rmdir($this->docsDir);

        parent::tearDown();
    }

    /**
     * Test that the command writes every section file into the configured docs
     * directory and exits successfully.
     *
     * @return void
     */
    public function testCommandWritesEverySectionFileWithExitZero(): void
    {
        $this->runCommand()->assertExitCode(0);

        self::assertFileExists($this->errorFile);
        self::assertFileExists($this->enumFile);
        self::assertFileExists($this->surfaceFile);
    }

    /**
     * Test that the generated error catalogue file is well-formed Markdown.
     *
     * @return void
     */
    public function testGeneratedErrorCatalogueIsWellFormed(): void
    {
        $this->runCommand()->assertExitCode(0);

        $contents = (string) file_get_contents($this->errorFile);

        self::assertStringStartsWith('<!-- This file is auto-generated.', $contents);
        self::assertStringContainsString("\n# Error Catalogue\n", $contents);
        self::assertStringContainsString("| Name | Code | HTTP | Description |\n| --- | --- | --- | --- |\n", $contents);
    }

    /**
     * Test that the generated enum reference file is well-formed Markdown.
     *
     * @return void
     */
    public function testGeneratedEnumReferenceIsWellFormed(): void
    {
        $this->runCommand()->assertExitCode(0);

        $contents = (string) file_get_contents($this->enumFile);

        self::assertStringStartsWith('<!-- This file is auto-generated.', $contents);
        self::assertStringContainsString("\n# Enum Reference\n", $contents);
    }

    /**
     * Test that the generated query surface reference names the resources of
     * the registered map, the bounds a request is held to, and the shape an
     * over-budget request is answered with.
     *
     * @return void
     */
    public function testGeneratedQuerySurfaceReferenceIsWellFormed(): void
    {
        $this->runCommand()->assertExitCode(0);

        $contents = (string) file_get_contents($this->surfaceFile);

        self::assertStringStartsWith('<!-- This file is auto-generated.', $contents);
        self::assertStringContainsString("\n# Query Surface Reference\n", $contents);
        self::assertStringContainsString('| `max_nodes` | 100 |', $contents);
        self::assertStringContainsString('| Shortest word, in characters | 3 |', $contents);
        self::assertStringContainsString('"reason": "max_in_items",', $contents);
        self::assertStringContainsString('| `email` | `email` | `exact` |', $contents);
        self::assertStringContainsString('Traversable relations: `organization`, `posts`.', $contents);
    }

    /**
     * Test that a second run produces byte-identical files, proving the output
     * is idempotent and carries no timestamp.
     *
     * @return void
     */
    public function testSecondRunProducesByteIdenticalFiles(): void
    {
        $this->runCommand()->assertExitCode(0);

        $errorFirst   = (string) file_get_contents($this->errorFile);
        $enumFirst    = (string) file_get_contents($this->enumFile);
        $surfaceFirst = (string) file_get_contents($this->surfaceFile);

        SchemaCompiler::clearCache();

        $this->runCommand()->assertExitCode(0);

        self::assertSame($errorFirst, (string) file_get_contents($this->errorFile));
        self::assertSame($enumFirst, (string) file_get_contents($this->enumFile));
        self::assertSame($surfaceFirst, (string) file_get_contents($this->surfaceFile));
    }

    /**
     * Test that the command reports each written path.
     *
     * @return void
     */
    public function testCommandReportsWrittenPaths(): void
    {
        $this->runCommand()
            ->expectsOutputToContain('40-error-catalogue.md')
            ->expectsOutputToContain('50-enum-reference.md')
            ->expectsOutputToContain('60-query-surface-reference.md')
            ->assertExitCode(0);
    }

    /**
     * Run the docs generate command, returning the pending command.
     *
     * @return \Illuminate\Testing\PendingCommand
     */
    private function runCommand(): PendingCommand
    {
        $command = $this->artisan(self::COMMAND);

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
