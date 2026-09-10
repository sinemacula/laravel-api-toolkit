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
 * The rule decides a sortable declaration on two things the connection reports:
 * the kind of an index, and the order of the columns within it. Neither can be
 * proven against a supplied catalogue, and neither can be proven on an engine
 * that names no kind, so the suite runs only against the engines that do, over
 * a table shaped to hold all three cases at once - a column leading an ordered
 * index, a column named second in one, and a column carrying only an index of a
 * kind that holds no order.
 *
 * The catalogue read is asserted first and on its own terms, because a refusal
 * proves nothing about a kind if the index the column was refused over is
 * simply missing. What the engine reports is pinned there; the acceptance and
 * the refusals that follow are then decided by it.
 *
 * The table is created and dropped by the suite rather than migrated with the
 * rest, since an index of a kind that holds no order is written in a dialect
 * only its own engine speaks.
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
     * Build the table whose catalogue every test reads, having skipped the
     * suite on any engine that names no index kind.
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
     * One of the engines under test commits the open transaction as a side
     * effect of creating a table, so the wrapper the suite would otherwise roll
     * back is already gone by the time the first index is read.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that the connection reports the index kinds and the column order the
     * rule decides on, which is what makes the acceptance and the refusals
     * below answers about a catalogue rather than about a missing index.
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
     * Test that the gate a deployment runs accepts sortable columns an ordered
     * index leads with, which is the half an engine reporting the ordered kind
     * under another name would break for every consuming application at once.
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
     * Test that a sortable column the engine names second in a composite index
     * is refused, so the position the catalogue reports decides the answer
     * rather than mere membership of an index.
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
     * Test that a sortable column whose only index is of a kind the engine
     * reports as holding no order is refused, so a column indexed for matching
     * alone cannot offer an ordered read of the whole table.
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
     * Create the table and the three indexes the declarations are proved
     * against.
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
     * Create the index over the body column in whichever kind the engine
     * carries that holds no order.
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
     * Return the defects validation reports against the given field of the
     * unbacked declaration.
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
     * Validate the unbacked declaration and return the errors it is refused
     * with.
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
