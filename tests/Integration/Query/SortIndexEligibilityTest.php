<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use Tests\Fixtures\Models\SortEligibilityRow;
use Tests\Fixtures\Resources\SortCatalogueBackedResource;
use Tests\TestCase;

/**
 * Integration tests for index eligibility against a live catalogue.
 *
 * A connection drops a key part naming no column, so an index over an
 * expression and a column reads as an ordinary index over the column alone.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
final class SortIndexEligibilityTest extends TestCase
{
    /** @var string The table the declarations are proved against */
    private const string TABLE = 'sort_eligibility_rows';

    /**
     * Build the table whose catalogue every test reads.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The eligibility suite runs against the engine answering through pragmas.');
        }

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->string('status');
            $table->string('body')->nullable();
        });
    }

    /**
     * Drop the table before the next test rebuilds it.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    /**
     * Leave the table outside a transaction so its indexes survive to be read.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that an expression-led index is reported as one over the column.
     *
     * @return void
     */
    public function testTheConnectionReportsAnExpressionLedIndexAsOneOverTheColumnBehindIt(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (lower(body), label)');

        assert($this->app !== null);

        $indexes = $this->app->make(SchemaIntrospectionProvider::class)->getIndexes(new SortEligibilityRow);

        self::assertIsArray($indexes);

        $index = array_values(array_filter(
            $indexes,
            static fn ($entry): bool => $entry->name === 'sort_eligibility_rows_label_index',
        ));

        self::assertCount(1, $index);
        self::assertSame(['label'], $index[0]->columns);
        self::assertTrue($index[0]->leadsWith('label'));
    }

    /**
     * Test that the engine reports its two facts against the right indexes.
     *
     * @return void
     */
    public function testTheEngineReportsTheFactsItHolds(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (lower(body), label)');
        DB::statement('create index sort_eligibility_rows_status_index on sort_eligibility_rows (status) where body is not null');
        DB::statement('create index sort_eligibility_rows_body_index on sort_eligibility_rows (body)');

        $eligibility = (new IndexEligibilityInspector)->inspect(self::TABLE, DB::connection());

        self::assertTrue($eligibility->keysAnExpression('sort_eligibility_rows_label_index'));
        self::assertFalse($eligibility->restricts('sort_eligibility_rows_label_index'));

        self::assertTrue($eligibility->restricts('sort_eligibility_rows_status_index'));
        self::assertFalse($eligibility->keysAnExpression('sort_eligibility_rows_status_index'));

        self::assertTrue($eligibility->describes('sort_eligibility_rows_body_index'));
        self::assertFalse($eligibility->disregards('sort_eligibility_rows_body_index'));
        self::assertFalse($eligibility->lacksColumnOrder('sort_eligibility_rows_body_index'));
    }

    /**
     * Test that a key collated apart from its column is not reported.
     *
     * The engine reports a key's collation but never the column's, so they
     * cannot be compared.
     *
     * @return void
     */
    public function testTheEngineLeavesAKeyCollatedApartFromItsColumnUnreported(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label collate nocase)');

        $eligibility = (new IndexEligibilityInspector)->inspect(self::TABLE, DB::connection());

        self::assertFalse($eligibility->lacksColumnOrder('sort_eligibility_rows_label_index'));
        self::assertTrue($eligibility->describes('sort_eligibility_rows_label_index'));
    }

    /**
     * Test that a column whose only index is led by an expression is refused.
     *
     * @return void
     */
    public function testValidationRefusesASortableColumnAnExpressionLedIndexAppearsToLead(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (lower(body), label)');
        DB::statement('create index sort_eligibility_rows_status_index on sort_eligibility_rows (status)');

        self::assertSame(
            ['Field is declared sortable against "label", and no ordered index on table "sort_eligibility_rows" leads with that column'],
            $this->defects(),
        );
    }

    /**
     * Test that a column led only by a partial index is refused.
     *
     * @return void
     */
    public function testValidationRefusesASortableColumnLedOnlyByARestrictedIndex(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label) where body is not null');
        DB::statement('create index sort_eligibility_rows_status_index on sort_eligibility_rows (status)');

        self::assertSame(
            ['Field is declared sortable against "label", and no ordered index on table "sort_eligibility_rows" leads with that column'],
            $this->defects(),
        );
    }

    /**
     * Test that the same declaration is accepted over ordinary indexes.
     *
     * @return void
     */
    public function testValidationAcceptsTheSameDeclarationOverOrdinaryIndexes(): void
    {
        DB::statement('create index sort_eligibility_rows_label_index on sort_eligibility_rows (label)');
        DB::statement('create index sort_eligibility_rows_status_index on sort_eligibility_rows (status)');

        assert($this->app !== null);

        $this->app->make(SchemaValidator::class)->validate([
            SortEligibilityRow::class => SortCatalogueBackedResource::class,
        ]);

        self::assertTrue(true);
    }

    /**
     * Validate the declaration and return the defects it is refused with.
     *
     * @return array<int, string>
     */
    private function defects(): array
    {
        assert($this->app !== null);

        try {
            $this->app->make(SchemaValidator::class)->validate([
                SortEligibilityRow::class => SortCatalogueBackedResource::class,
            ]);
        } catch (InvalidSchemaException $exception) {

            return array_values(array_map(
                static fn (SchemaValidationError $error): string => $error->defect,
                $exception->getErrors(),
            ));
        }

        self::fail('Validation accepted a sortable column no ordered index leads with.');
    }
}
