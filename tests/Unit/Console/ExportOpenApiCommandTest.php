<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
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

    /** @var string The error printed once nothing was written for an empty audience. */
    private const string NOTHING_WRITTEN_ERROR = 'Nothing was written. Document a route for every exported audience,'
        . ' or pass --allow-empty to write empty documents.';

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
     * operation and resource counts, exit 0.
     *
     * @return void
     */
    public function testCommandExportsResourcesWithExitZero(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();

        $this->runCommand(['--output' => $this->outputPath])
            ->expectsOutputToContain('Exported 1 operation(s), 2 resource schema(s)')
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
        $this->registerDocumentedRoute();

        $this->runCommand(['--output' => $this->outputPath])
            ->assertExitCode(0);

        $contents = file_get_contents($this->outputPath);

        self::assertIsString($contents);
        self::assertStringContainsString("\n", $contents);
        self::assertStringContainsString('#/components/schemas/', $contents);
        self::assertStringNotContainsString('#\/components\/schemas\/', $contents);
    }

    /**
     * Test that the command fails and writes nothing when the audience
     * documents no operations.
     *
     * @return void
     */
    public function testCommandFailsWhenTheAudienceDocumentsNoOperations(): void
    {
        $this->registerResourceMap();

        $this->runCommand(['--output' => $this->outputPath])
            ->expectsOutputToContain(self::emptyAudienceError('public'))
            ->expectsOutputToContain(self::NOTHING_WRITTEN_ERROR)
            ->assertExitCode(1);

        self::assertFileDoesNotExist($this->outputPath);
    }

    /**
     * Test that an empty resource map does not block the export of documented
     * non-resource operations.
     *
     * @return void
     */
    public function testCommandExportsNonResourceOperationsWithoutResources(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $router = $this->router();
        $router->post('articles', [PathRequestBodyController::class, 'store']);
        $router->get('widget', [PathResponseSchemaController::class, 'widget']);

        $this->runCommand(['--output' => $this->outputPath])
            ->expectsOutputToContain('Exported 2 operation(s), 0 resource schema(s)')
            ->assertExitCode(0);

        $paths = $this->decodePaths($this->outputPath);

        self::assertArrayHasKey('requestBody', $paths['/articles']['post']);
        self::assertArrayHasKey('get', $paths['/widget']);
    }

    /**
     * Test that --allow-empty writes a document with an empty paths object and
     * warns, exit 0, when the audience documents no operations.
     *
     * @return void
     */
    public function testCommandAllowEmptyWritesAnEmptyDocument(): void
    {
        $this->registerResourceMap();

        $this->runCommand(['--output' => $this->outputPath, '--allow-empty' => true])
            ->expectsOutputToContain(sprintf(
                'The [public] audience documents no operations; wrote an empty document to %s.',
                $this->outputPath,
            ))
            ->doesntExpectOutputToContain('Nothing was written')
            ->doesntExpectOutputToContain('Exported')
            ->assertExitCode(0);

        self::assertSame([], $this->decodePaths($this->outputPath));
    }

    /**
     * Test that --allow-empty leaves an export that documents operations
     * unchanged.
     *
     * @return void
     */
    public function testCommandAllowEmptyLeavesANonEmptyExportUnchanged(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();

        $this->runCommand(['--output' => $this->outputPath, '--allow-empty' => true])
            ->expectsOutputToContain('Exported 1 operation(s), 2 resource schema(s)')
            ->doesntExpectOutputToContain('documents no operations')
            ->assertExitCode(0);

        self::assertArrayHasKey('/widget', $this->decodePaths($this->outputPath));
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
        $this->registerDocumentedRoute();

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
        $this->registerDocumentedRoute();

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
        $this->registerDocumentedRoute();

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
     * Test that the command prints the no-database notice and still exports
     * successfully when no database connection can be established.
     *
     * @return void
     */
    public function testCommandPrintsNoticeAndExportsWhenNoDatabaseConnection(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();

        // Point the default connection at an unconfigured name so the PDO probe
        // fails, then restore it before teardown so the transactional refresh
        // can roll back the real connection.
        $this->getConfig()->set('database.default', 'nonexistent');

        $notice = 'No database connection - field types and nullability are inferred from declared casts only; run against a database for the most accurate output.';

        try {
            $this->runCommand(['--output' => $this->outputPath])
                ->expectsOutputToContain($notice)
                ->assertExitCode(0);
        } finally {
            $this->getConfig()->set('database.default', 'testing');
        }

        self::assertFileExists($this->outputPath);
    }

    /**
     * Test that the command does not print the no-database notice when a live
     * connection is available.
     *
     * @return void
     */
    public function testCommandDoesNotPrintNoticeWhenDatabaseConnectionIsAvailable(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();

        $this->runCommand(['--output' => $this->outputPath])
            ->doesntExpectOutputToContain('No database connection')
            ->assertExitCode(0);
    }

    /**
     * Test that an unknown audience fails with a clear error and a non-zero
     * exit code, writing nothing.
     *
     * @return void
     */
    public function testCommandFailsOnUnknownAudience(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();

        $this->runCommand(['--output' => $this->outputPath, '--audience' => 'nonexistent'])
            ->expectsOutputToContain('Unknown audience [nonexistent]')
            ->assertExitCode(1);

        self::assertFileDoesNotExist($this->outputPath);
    }

    /**
     * Test that a configured named audience is exported to the resolved output
     * path.
     *
     * @return void
     */
    public function testCommandExportsNamedAudience(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();

        $this->runCommand(['--output' => $this->outputPath, '--audience' => 'public'])
            ->expectsOutputToContain('for the public audience')
            ->assertExitCode(0);

        self::assertFileExists($this->outputPath);
    }

    /**
     * Test that the --all flag writes one file per configured audience, each
     * with the audience name injected before the extension.
     *
     * @return void
     */
    public function testCommandAllFlagWritesOneFilePerAudience(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $base     = sys_get_temp_dir() . '/api-toolkit-all-' . uniqid('', true) . '.json';
        $public   = substr($base, 0, -strlen('.json')) . '.public.json';
        $internal = substr($base, 0, -strlen('.json')) . '.internal.json';

        try {

            $this->runCommand(['--output' => $base, '--all' => true])
                ->assertExitCode(0);

            self::assertFileExists($public);
            self::assertFileExists($internal);
            self::assertFileDoesNotExist($base);

        } finally {
            @unlink($public);
            @unlink($internal);
        }
    }

    /**
     * Test that an output path carrying no extension takes the audience name as
     * a trailing suffix, so --all still writes one distinct file per audience.
     *
     * @return void
     */
    public function testCommandAllFlagSuffixesAnExtensionlessOutputPath(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $base     = sys_get_temp_dir() . '/' . uniqid('api-toolkit-noext-');
        $public   = $base . '.public';
        $internal = $base . '.internal';

        try {

            $this->runCommand(['--output' => $base, '--all' => true])
                ->assertExitCode(0);

            self::assertFileExists($public);
            self::assertFileExists($internal);
            self::assertFileDoesNotExist($base);

        } finally {
            @unlink($public);
            @unlink($internal);
        }
    }

    /**
     * Test that --all fails and writes no document, not even for the audiences
     * that document operations, when any audience documents none.
     *
     * @return void
     */
    public function testCommandAllFlagFailsAndWritesNothingWhenAnAudienceIsEmpty(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();
        $this->registerPublicAndAllowlistAudiences();

        $base     = sys_get_temp_dir() . '/api-toolkit-all-' . uniqid('', true) . '.json';
        $public   = substr($base, 0, -strlen('.json')) . '.public.json';
        $internal = substr($base, 0, -strlen('.json')) . '.internal.json';

        try {

            $this->runCommand(['--output' => $base, '--all' => true])
                ->expectsOutputToContain(self::emptyAudienceError('internal'))
                ->doesntExpectOutputToContain(self::emptyAudienceError('public'))
                ->expectsOutputToContain(self::NOTHING_WRITTEN_ERROR)
                ->assertExitCode(1);

            self::assertFileDoesNotExist($public);
            self::assertFileDoesNotExist($internal);

        } finally {
            @unlink($public);
            @unlink($internal);
        }
    }

    /**
     * Test that --all reports every audience that documents no operations.
     *
     * @return void
     */
    public function testCommandAllFlagReportsEveryEmptyAudience(): void
    {
        $this->registerResourceMap();
        $this->registerPublicAndAllowlistAudiences();

        $base = sys_get_temp_dir() . '/api-toolkit-all-' . uniqid('', true) . '.json';

        $this->runCommand(['--output' => $base, '--all' => true])
            ->expectsOutputToContain(self::emptyAudienceError('public'))
            ->expectsOutputToContain(self::emptyAudienceError('internal'))
            ->assertExitCode(1);

        self::assertFileDoesNotExist(substr($base, 0, -strlen('.json')) . '.public.json');
        self::assertFileDoesNotExist(substr($base, 0, -strlen('.json')) . '.internal.json');
    }

    /**
     * Test that --all with --allow-empty writes every audience, warning for the
     * empty one and reporting the counts for the other.
     *
     * @return void
     */
    public function testCommandAllFlagWithAllowEmptyWritesEveryAudience(): void
    {
        $this->registerResourceMap();
        $this->registerDocumentedRoute();
        $this->registerPublicAndAllowlistAudiences();

        $base     = sys_get_temp_dir() . '/api-toolkit-all-' . uniqid('', true) . '.json';
        $public   = substr($base, 0, -strlen('.json')) . '.public.json';
        $internal = substr($base, 0, -strlen('.json')) . '.internal.json';

        try {

            $this->runCommand(['--output' => $base, '--all' => true, '--allow-empty' => true])
                ->expectsOutputToContain('Exported 1 operation(s), 2 resource schema(s)')
                ->expectsOutputToContain('The [internal] audience documents no operations; wrote an empty document')
                ->doesntExpectOutputToContain('The [public] audience documents no operations')
                ->assertExitCode(0);

            self::assertArrayHasKey('/widget', $this->decodePaths($public));
            self::assertSame([], $this->decodePaths($internal));

        } finally {
            @unlink($public);
            @unlink($internal);
        }
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
     * Register a documented non-resource route on the application router.
     *
     * @return void
     */
    private function registerDocumentedRoute(): void
    {
        $this->router()->get('widget', [PathResponseSchemaController::class, 'widget']);
    }

    /**
     * Declare a blocklist public audience and an allowlist internal audience
     * that no route opts into.
     *
     * @return void
     */
    private function registerPublicAndAllowlistAudiences(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => ['posture' => AudienceResolver::POSTURE_ALLOWLIST],
        ]);
    }

    /**
     * Build the error printed for an audience that documents no operations.
     *
     * @param  string  $audience
     * @return string
     */
    private static function emptyAudienceError(string $audience): string
    {
        return sprintf(
            'The [%s] audience documents no operations; no route reaches it after the namespace exclusions and its posture.',
            $audience,
        );
    }

    /**
     * Decode the paths object of a written document.
     *
     * @param  string  $path
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function decodePaths(string $path): array
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents);

        /** @var array{paths: array<string, array<string, array<string, mixed>>>} $document */
        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $document['paths'];
    }

    /**
     * Resolve the application router.
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
