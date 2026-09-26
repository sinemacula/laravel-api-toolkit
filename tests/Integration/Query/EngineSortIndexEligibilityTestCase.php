<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use Tests\Fixtures\Models\SortEligibilityRow;
use Tests\Fixtures\Resources\SortCatalogueBackedResource;
use Tests\TestCase;

/**
 * Shared sort eligibility suite for an engine whose leading key can be too weak
 * to deliver its column's own order.
 *
 * Runs only against the engine the concrete case names. Every declaration is
 * proved against a live catalogue, since whether the engine reports a key as
 * truncated, or ordered apart from its column, is exactly what a supplied
 * catalogue cannot settle. The ordered column the resource also declares is
 * indexed plainly up front, so every refusal answers to the column under test.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class EngineSortIndexEligibilityTestCase extends TestCase
{
    /** @var string The table the declarations are proved against */
    protected const string TABLE = 'sort_eligibility_rows';

    /** @var string The index every test creates over the column under test */
    protected const string INDEX = 'sort_eligibility_rows_label_index';

    /** @var string The defect a sortable column no ordered index leads with draws */
    protected const string DEFECT = 'Field is declared sortable against "label", and no ordered index on table "sort_eligibility_rows" leads with that column';

    /**
     * Build the table whose catalogue every test reads, having skipped the
     * suite on any engine but the one under test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== $this->engine()) {
            static::markTestSkipped(sprintf('The %s sort eligibility suite runs against its own engine only.', $this->engine()));
        }

        $this->createTable();
    }

    /**
     * Drop the table before the next test rebuilds it.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === $this->engine()) {
            Schema::dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    /**
     * Leave the table the suite builds outside a transaction, so the indexes it
     * creates survive to be read.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    abstract protected function engine(): string;

    /**
     * Rebuild the table, with the column under test carrying the given
     * collation where one is named, and index the other ordered column.
     *
     * @param  string|null  $collation
     * @return void
     */
    protected function createTable(?string $collation = null): void
    {
        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, static function (Blueprint $table) use ($collation): void {

            $table->id();

            $label = $table->string('label');

            if ($collation !== null) {
                $label->collation($collation);
            }

            $table->string('status');
            $table->string('body')->nullable();
            $table->index('status', 'sort_eligibility_rows_status_index');
        });
    }

    /**
     * Report what the connection says about the table's indexes.
     *
     * @return \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility
     */
    protected function eligibility(): IndexEligibility
    {
        return (new IndexEligibilityInspector)->inspect(self::TABLE, DB::connection());
    }

    /**
     * Validate the declaration and return the defects it is refused with, or
     * nothing where it is accepted.
     *
     * @return array<int, string>
     */
    protected function defects(): array
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

        return [];
    }

    /**
     * Assert that the index over the column under test lacks the column's own
     * order, and that the sort proof refuses the column for it.
     *
     * @return void
     */
    protected function assertRefusedForLackingTheColumnsOrder(): void
    {
        static::assertTrue($this->eligibility()->lacksColumnOrder(self::INDEX));
        static::assertSame([self::DEFECT], $this->defects());
    }

    /**
     * Assert that the index over the column under test holds the column's own
     * order, and that the sort proof accepts the column over it.
     *
     * @return void
     */
    protected function assertAcceptedAsHoldingTheColumnsOrder(): void
    {
        static::assertFalse($this->eligibility()->lacksColumnOrder(self::INDEX));
        static::assertSame([], $this->defects());
    }
}
