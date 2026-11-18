<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ValidateSchemasCommand;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Introspection\CatalogueProbe;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateIndexBacking;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateNeedsColumns;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateQueryableFields;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationClasses;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSearchIndexes;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;
use Tests\Fixtures\Models\CatalogueRow;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\BrokenResource;
use Tests\Fixtures\Resources\CatalogueRowResource;
use Tests\Fixtures\Resources\MissingNeedsColumnResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UnbackedQueryableResource;
use Tests\Fixtures\Resources\UncompilableResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateSchemasCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateSchemasCommand::class)]
#[CoversClass(SchemaValidator::class)]
#[CoversClass(ValidateRelationClasses::class)]
#[CoversClass(ValidateQueryableFields::class)]
#[CoversClass(ValidateIndexBacking::class)]
#[CoversClass(ValidateSearchIndexes::class)]
#[CoversClass(CatalogueProbe::class)]
#[CoversClass(SchemaValidationReport::class)]
#[CoversClass(UnverifiedDeclaration::class)]
#[CoversClass(ValidateNeedsColumns::class)]
final class ValidateSchemasCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:validate-schemas';

    /**
     * Test that the command reports success for valid schemas.
     *
     * @return void
     */
    public function testCommandReportsSuccessForValidSchemas(): void
    {
        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([]);

        $this->getApplication()->instance(SchemaValidator::class, new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule));

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            Organization::class => OrganizationResource::class,
        ]);

        $this->runCommand()
            ->expectsOutputToContain('All 1 resource schema(s) validated successfully.')
            ->assertExitCode(0);
    }

    /**
     * Test that the command reports failure for invalid schemas.
     *
     * @return void
     */
    public function testCommandReportsFailureForInvalidSchemas(): void
    {
        $error = new SchemaValidationError(UserResource::class, 'id', 'Test defect');

        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([$error]);

        $this->getApplication()->instance(SchemaValidator::class, new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule));

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $this->runCommand()
            ->expectsOutputToContain('Schema validation failed:')
            ->assertExitCode(1);
    }

    /**
     * Test that the container-wired validator reports a genuinely broken
     * resource and exits one.
     *
     * @return void
     */
    public function testCommandFailsWithRealValidatorAgainstBrokenResource(): void
    {
        SchemaCompiler::clearCache();

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $this->runCommand()
            ->expectsOutputToContain('Schema validation failed:')
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    /**
     * Test that a raw schema the compiler refuses is reported as a defect, not
     * thrown.
     *
     * @return void
     */
    public function testCommandReportsARawSchemaDefectAsAFailure(): void
    {
        SchemaCompiler::clearCache();

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UncompilableResource::class,
        ]);

        $output = $this->outputOf(1);

        self::assertStringContainsString('Schema validation failed:', $output);
        self::assertStringContainsString('[' . UncompilableResource::class . '] Field "name": Guard at index 0 is not callable', $output);
    }

    /**
     * Test that the container-wired rule set catches a query declaration on a
     * field with no column.
     *
     * @return void
     */
    public function testCommandFailsForAQueryDeclarationWithNoBackingColumn(): void
    {
        SchemaCompiler::clearCache();

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UnbackedQueryableResource::class,
        ]);

        $this->runCommand()
            ->expectsOutputToContain('Schema validation failed:')
            ->expectsOutputToContain('Field is declared filterable but is computed, so there is no "full_name" column to query')
            ->assertExitCode(1);
    }

    /**
     * Test that the container-wired rule set catches a needs() column the table
     * lacks.
     *
     * @return void
     */
    public function testCommandFailsForANeedsColumnTheTableLacks(): void
    {
        SchemaCompiler::clearCache();

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => MissingNeedsColumnResource::class,
        ]);

        $this->runCommand()
            ->expectsOutputToContain('Schema validation failed:')
            ->expectsOutputToContain('Field declares needs() on "missing", and table "users" carries no such column')
            ->assertExitCode(1);
    }

    /**
     * Test that an empty resource map fails, since nothing was validated.
     *
     * @return void
     */
    public function testCommandFailsWhenNoResourcesRegistered(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->runCommand()
            ->expectsOutputToContain('No resources are registered in the resource map, so nothing was validated. Register or discover a resource, or pass --allow-empty.')
            ->doesntExpectOutputToContain('validated successfully')
            ->assertExitCode(1);
    }

    /**
     * Test that a discovered resource no model binds is validated when the map
     * is empty.
     *
     * @return void
     */
    public function testCommandValidatesAnUnboundResourceBesideAnEmptyMap(): void
    {
        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([]);

        $this->getApplication()->instance(SchemaValidator::class, new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule));

        $this->getConfig()->set('api-toolkit.resources.resource_map', []);
        $this->getConfig()->set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class]);

        $this->runCommand()
            ->expectsOutputToContain('All 1 resource schema(s) validated successfully.')
            ->assertExitCode(0);
    }

    /**
     * Test that an empty resource map passes with a warning when allowed.
     *
     * @return void
     */
    public function testCommandWarnsWhenNoResourcesRegisteredAndEmptyIsAllowed(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->runCommand(['--allow-empty' => true])
            ->expectsOutputToContain('No resources registered in the resource map.')
            ->doesntExpectOutputToContain('validated successfully')
            ->assertExitCode(0);
    }

    /**
     * Test that an unverified declaration fails the run and is listed under why
     * its model's catalogue could not be read.
     *
     * @return void
     */
    public function testCommandFailsForAnUnverifiedDeclaration(): void
    {
        $declaration = new UnverifiedDeclaration(UserResource::class, 'name', 'an ordered index on table "users" leads with "name"', User::class);

        $this->useFindings([$declaration]);

        $this->runCommand()
            ->expectsOutputToContain('1 declaration(s) could not be verified against the database:')
            ->expectsOutputToContain(User::class . ': the catalogue of table "users" on connection "testing" could not be read during validation')
            ->expectsOutputToContain((string) $declaration)
            ->expectsOutputToContain('Run against a migrated database the application can reach, or pass --allow-unverified.')
            ->doesntExpectOutputToContain('Schema validation failed:')
            ->doesntExpectOutputToContain('validated successfully')
            ->assertExitCode(1);
    }

    /**
     * Test that an unverified declaration passes, counted, when allowed.
     *
     * @return void
     */
    public function testCommandPassesAnUnverifiedDeclarationWhenAllowed(): void
    {
        $this->useFindings([
            new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class),
            new UnverifiedDeclaration(UserResource::class, 'email', 'another claim', User::class),
        ]);

        $this->runCommand(['--allow-unverified' => true])
            ->expectsOutputToContain('2 declaration(s) could not be verified against the database:')
            ->expectsOutputToContain('1 resource schema(s) validated; 2 declaration(s) were not verified.')
            ->doesntExpectOutputToContain('Run against a migrated database')
            ->doesntExpectOutputToContain('validated successfully')
            ->assertExitCode(0);
    }

    /**
     * Test that a defect fails the run whatever the flags, listed beside the
     * unverified declarations.
     *
     * @return void
     */
    public function testCommandFailsForADefectBesideAnUnverifiedDeclarationWhateverTheFlags(): void
    {
        $error       = new SchemaValidationError(UserResource::class, 'id', 'Test defect');
        $declaration = new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class);

        $this->useFindings([$error, $declaration]);

        $this->runCommand(['--allow-empty' => true, '--allow-unverified' => true])
            ->expectsOutputToContain('Schema validation failed:')
            ->expectsOutputToContain((string) $error)
            ->expectsOutputToContain('1 declaration(s) could not be verified against the database:')
            ->expectsOutputToContain((string) $declaration)
            ->doesntExpectOutputToContain('were not verified')
            ->doesntExpectOutputToContain('Run against a migrated database')
            ->assertExitCode(1);
    }

    /**
     * Test that a defect beside an unverified declaration still names how to
     * clear the unverified one.
     *
     * @return void
     */
    public function testCommandNamesHowToClearAnUnverifiedDeclarationBesideADefect(): void
    {
        $this->useFindings([
            new SchemaValidationError(UserResource::class, 'id', 'Test defect'),
            new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class),
        ]);

        $output = $this->outputOf(1);

        self::assertStringContainsString('Schema validation failed:', $output);
        self::assertStringContainsString('Run against a migrated database the application can reach, or pass --allow-unverified.', $output);
    }

    /**
     * Test that a declaration carrying its own cause is listed with it.
     *
     * @return void
     */
    public function testCommandListsTheCauseADeclarationCarries(): void
    {
        $declaration = new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class, 'Connection refused');

        $this->useFindings([$declaration]);

        $this->runCommand()
            ->expectsOutputToContain((string) $declaration . ': Connection refused')
            ->assertExitCode(1);
    }

    /**
     * Test that the declarations are grouped under each model, once each.
     *
     * @return void
     */
    public function testCommandGroupsTheDeclarationsByModel(): void
    {
        $this->useFindings([
            new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class),
            new UnverifiedDeclaration(UserResource::class, 'title', 'a post claim', Post::class),
            new UnverifiedDeclaration(UserResource::class, 'email', 'another claim', User::class),
        ]);

        $output = $this->outputOf(1);

        self::assertSame(1, substr_count($output, User::class . ': '));
        self::assertSame(1, substr_count($output, Post::class . ': '));
        self::assertLessThan(strpos($output, Post::class . ': '), strpos($output, 'another claim'));
    }

    /**
     * Test that a table that is not migrated leaves the filter and the sort
     * unverified, named as not found, while the waived search passes.
     *
     * @return void
     */
    public function testCommandNamesATableThatIsNotMigrated(): void
    {
        $this->useCatalogueConnection();

        $output = $this->outputOf(1);

        self::assertStringContainsString('3 declaration(s) could not be verified against the database:', $output);
        self::assertStringContainsString(
            CatalogueRow::class . ': table "catalogue_rows" was not found on connection "catalogue"; it is not migrated, or not visible to this user',
            $output,
        );
        self::assertStringContainsString('could not verify that table "catalogue_rows" carries the "emial" column the filterable declaration names', $output);
        self::assertStringContainsString('could not verify that table "catalogue_rows" carries the "label" column the sortable declaration names', $output);
        self::assertStringContainsString('could not verify that an ordered index on table "catalogue_rows" leads with "label"', $output);
        self::assertStringNotContainsString('Schema validation failed:', $output);
    }

    /**
     * Test that a migrated table reports the misspelt column and the missing
     * index as defects.
     *
     * @return void
     */
    public function testCommandReportsTheDefectsOfAMigratedTable(): void
    {
        $this->useCatalogueConnection();

        Schema::connection('catalogue')->create('catalogue_rows', static function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('label');
            $table->string('title');
        });

        $output = $this->outputOf(1);

        self::assertStringContainsString('Schema validation failed:', $output);
        self::assertStringContainsString('Field is declared filterable against "emial", and table "catalogue_rows" carries no such column', $output);
        self::assertStringContainsString('Field is declared sortable against "label", and no ordered index on table "catalogue_rows" leads with that column', $output);
        self::assertStringNotContainsString('could not be verified', $output);
    }

    /**
     * Test that a database file that does not exist is named as unreadable.
     *
     * @return void
     */
    public function testCommandNamesACatalogueThatCannotBeRead(): void
    {
        $this->useCatalogueConnection(sys_get_temp_dir() . '/api-toolkit-missing-' . uniqid('', true) . '.sqlite');

        self::assertStringContainsString(
            CatalogueRow::class . ': the catalogue of table "catalogue_rows" on connection "catalogue" cannot be read: ',
            $this->outputOf(1),
        );
    }

    /**
     * Test that a connection that is not configured is named as unresolvable
     * rather than thrown, the search included.
     *
     * @return void
     */
    public function testCommandNamesAConnectionThatCannotBeResolved(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', [CatalogueRow::class => CatalogueRowResource::class]);

        $output = $this->outputOf(1);

        self::assertStringContainsString('4 declaration(s) could not be verified against the database:', $output);
        self::assertStringContainsString(CatalogueRow::class . ': connection "catalogue" cannot be resolved: Database connection [catalogue] not configured.', $output);
        self::assertStringContainsString(
            'could not verify that an index on table "catalogue_rows" serves the "substring" search on "title": Database connection [catalogue] not configured',
            $output,
        );
    }

    /**
     * Test that the command output contains error details.
     *
     * @return void
     */
    public function testCommandOutputContainsErrorDetails(): void
    {
        $errors = [
            new SchemaValidationError(UserResource::class, 'id', 'First defect'),
            new SchemaValidationError(UserResource::class, 'name', 'Second defect'),
        ];

        $this->useFindings($errors);

        $output = $this->outputOf(1);

        self::assertStringContainsString((string) $errors[0], $output);
        self::assertStringContainsString((string) $errors[1], $output);
    }

    /**
     * Run the validate schemas command.
     *
     * @param  array<string, mixed>  $parameters
     * @return \Illuminate\Testing\PendingCommand
     */
    private function runCommand(array $parameters = []): PendingCommand
    {
        $command = $this->artisan(self::COMMAND, $parameters);

        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * Run the command through the console kernel, returning all it printed.
     *
     * @param  int  $exitCode
     * @return string
     */
    private function outputOf(int $exitCode): string
    {
        $kernel = $this->getApplication()->make(Kernel::class);

        self::assertSame($exitCode, $kernel->call(self::COMMAND));

        return $kernel->output();
    }

    /**
     * Validate one resource with a rule returning the given findings.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>  $findings
     * @return void
     */
    private function useFindings(array $findings): void
    {
        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn($findings);

        $this->getApplication()->instance(SchemaValidator::class, new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule));

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            Organization::class => OrganizationResource::class,
        ]);
    }

    /**
     * Map the catalogue fixture through a connection to the given database,
     * waiving the search proof the engine cannot take.
     *
     * @param  string  $database
     * @return void
     */
    private function useCatalogueConnection(string $database = ':memory:'): void
    {
        SchemaCompiler::clearCache();

        $this->getConfig()->set('database.connections.catalogue', ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
        $this->getConfig()->set('api-toolkit.search.unverified_connections', ['catalogue']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [CatalogueRow::class => CatalogueRowResource::class]);

        DB::purge('catalogue');

        $this->beforeApplicationDestroyed(static fn () => DB::purge('catalogue'));
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Config\Repository */
        return $this->getApplication()->make('config');
    }
}
