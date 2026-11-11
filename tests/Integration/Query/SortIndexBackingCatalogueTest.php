<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateIndexBacking;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use Tests\Fixtures\Models\SortCatalogueRow;
use Tests\Fixtures\Resources\SortCatalogueBackedResource;
use Tests\Fixtures\Resources\SortCatalogueUnbackedResource;
use Tests\TestCase;

/**
 * Integration tests for sortable index backing against a live catalogue.
 *
 * Index kind and column order cannot be proven against a supplied catalogue, so
 * this runs only on engines that name index kinds.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateIndexBacking::class)]
final class SortIndexBackingCatalogueTest extends TestCase
{
    /** @var string The command a deployment runs before it serves its first sort */
    private const string COMMAND = 'api-toolkit:validate-schemas';

    /** @var string The table the declarations are proved against */
    private const string TABLE = 'sort_catalogue_rows';

    /** @var array<int, string> The engines that name an index kind */
    private const array ENGINES = ['mysql', 'pgsql'];

    /**
     * Build the table every test reads, skipping engines naming no index kind.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->hasNamedIndexKinds()) {
            self::markTestSkipped('The index catalogue suite runs against an engine that names index kinds.');
        }

        $this->createCatalogueTable();
    }

    /**
     * Drop the table before the next test rebuilds it.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->hasNamedIndexKinds()) {
            Schema::dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    /**
     * Leave the table the suite builds outside a transaction.
     *
     * One engine commits the open transaction when a table is created.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that the connection reports the index kinds and column order.
     *
     * @return void
     */
    public function testTheConnectionReportsTheIndexKindsAndColumnOrderTheRuleReadsOn(): void
    {
        $ordered   = $this->index('sort_catalogue_rows_label_index');
        $composite = $this->index('sort_catalogue_rows_status_ranking_index');
        $unordered = $this->index('sort_catalogue_rows_body_index');

        self::assertSame(['label'], $ordered->columns);
        self::assertSame('btree', $ordered->type);
        self::assertSame(['status', 'ranking'], $composite->columns);
        self::assertSame('btree', $composite->type);
        self::assertSame(['body'], $unordered->columns);
        self::assertSame($this->unorderedKind(), $unordered->type);
    }

    /**
     * Test that validation accepts columns an ordered index leads with.
     *
     * @return void
     */
    public function testValidationAcceptsSortableColumnsAnOrderedIndexLeadsWith(): void
    {
        Config::set('api-toolkit.resources.resource_map', [
            SortCatalogueRow::class => SortCatalogueBackedResource::class,
        ]);

        $this->runValidation()
            ->expectsOutputToContain('All 1 resource schema(s) validated successfully.')
            ->assertExitCode(0);
    }

    /**
     * Test that a column named second in a composite index is refused.
     *
     * @return void
     */
    public function testValidationRefusesASortableColumnTheEngineNamesSecondInACompositeIndex(): void
    {
        self::assertSame(
            ['Field is declared sortable against "ranking", and no ordered index on table "sort_catalogue_rows" leads with that column'],
            $this->defectsFor('ranking'),
        );
    }

    /**
     * Test that a column whose only index holds no order is refused.
     *
     * @return void
     */
    public function testValidationRefusesASortableColumnWhoseOnlyIndexHoldsNoOrder(): void
    {
        self::assertSame(
            ['Field is declared sortable against "body", and no ordered index on table "sort_catalogue_rows" leads with that column'],
            $this->defectsFor('body'),
        );
    }

    /**
     * Test that a column led only by an unplannable index is refused.
     *
     * The catalogue reports such an index exactly as it reports a usable one.
     *
     * @return void
     */
    public function testValidationRefusesASortableColumnLedOnlyByAnIndexThePlannerWillNotUse(): void
    {
        Config::set('api-toolkit.resources.resource_map', [
            SortCatalogueRow::class => SortCatalogueBackedResource::class,
        ]);

        $this->makeLabelIndexUnusable();

        assert($this->app !== null);

        try {
            $this->app->make(SchemaValidator::class)->validate([
                SortCatalogueRow::class => SortCatalogueBackedResource::class,
            ]);
        } catch (InvalidSchemaException $exception) {

            self::assertContains(
                'Field is declared sortable against "label", and no ordered index on table "sort_catalogue_rows" leads with that column',
                array_map(static fn (SchemaValidationError $error): string => $error->defect, $exception->getErrors()),
            );

            return;
        }

        self::fail('Validation accepted a sortable column led only by an index the planner will not use.');
    }

    /**
     * Replace the label index with one the engine carries but will not plan.
     *
     * @return void
     */
    private function makeLabelIndexUnusable(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {

            DB::statement('alter table `sort_catalogue_rows` alter index `sort_catalogue_rows_label_index` invisible');

            return;
        }

        DB::statement('drop index sort_catalogue_rows_label_index');
        DB::statement('create index sort_catalogue_rows_label_index on sort_catalogue_rows (label) where ranking > 0');
    }

    /**
     * Determine whether the connection under test names an index kind.
     *
     * @return bool
     */
    private function hasNamedIndexKinds(): bool
    {
        return in_array(DB::connection()->getDriverName(), self::ENGINES, true);
    }

    /**
     * Return the kind the engine reports for an index holding no order.
     *
     * @return string
     */
    private function unorderedKind(): string
    {
        return DB::connection()->getDriverName() === 'mysql' ? 'fulltext' : 'gin';
    }

    /**
     * Create the table and the three indexes the declarations are proved on.
     *
     * @return void
     */
    private function createCatalogueTable(): void
    {
        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('status');
            $table->unsignedInteger('ranking');
            $table->text('body');

            $table->index('label');
            $table->index(['status', 'ranking']);
        });

        $this->createUnorderedIndex();
    }

    /**
     * Create the body column index in a kind that holds no order.
     *
     * @return void
     */
    private function createUnorderedIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {

            DB::statement('alter table `sort_catalogue_rows` add fulltext index `sort_catalogue_rows_body_index` (`body`)');

            return;
        }

        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create index sort_catalogue_rows_body_index on sort_catalogue_rows using gin (body gin_trgm_ops)');
    }

    /**
     * Return the defects validation reports against the given field.
     *
     * @param  string  $field
     * @return array<int, string>
     */
    private function defectsFor(string $field): array
    {
        return array_values(array_map(
            static fn (SchemaValidationError $error): string => $error->defect,
            array_filter(
                $this->unbackedErrors(),
                static fn (SchemaValidationError $error): bool => $error->fieldKey === $field,
            ),
        ));
    }

    /**
     * Validate the unbacked declaration and return its errors.
     *
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    private function unbackedErrors(): array
    {
        assert($this->app !== null);

        try {
            $this->app->make(SchemaValidator::class)->validate([
                SortCatalogueRow::class => SortCatalogueUnbackedResource::class,
            ]);
        } catch (InvalidSchemaException $exception) {
            return $exception->getErrors();
        }

        self::fail('Validation accepted sortable columns no ordered index leads with.');
    }

    /**
     * Return the index of the given name from the live catalogue.
     *
     * @param  string  $name
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition
     */
    private function index(string $name): IndexDefinition
    {
        return $this->catalogue()[$name]
            ?? self::fail(sprintf('The connection reports no "%s" index on table "%s".', $name, self::TABLE));
    }

    /**
     * Return the live index catalogue behind the table, keyed by index name.
     *
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>
     */
    private function catalogue(): array
    {
        assert($this->app !== null);

        $indexes = $this->app->make(SchemaIntrospectionProvider::class)->getIndexes(new SortCatalogueRow);

        if ($indexes === null) {
            self::fail(sprintf('The connection could not be inspected for table "%s".', self::TABLE));
        }

        $keyed = [];

        foreach ($indexes as $index) {
            $keyed[$index->name] = $index;
        }

        return $keyed;
    }

    /**
     * Run the schema validation command.
     *
     * @return \Illuminate\Testing\PendingCommand
     */
    private function runValidation(): PendingCommand
    {
        $command = $this->artisan(self::COMMAND);

        assert($command instanceof PendingCommand);

        return $command;
    }
}
