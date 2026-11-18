<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\ImportRow;
use Tests\TestCase;

/**
 * Integration tests for deferred writes at the default chunk size and pool
 * limit.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
final class DeferredWriteVolumeTest extends TestCase
{
    use CreatesDeferredWriteTargets, RecordsStatements;

    /** @var int The column count of the wide table, whose full chunk would bind 70000 values */
    private const int WIDE_COLUMNS = 140;

    /**
     * Test that reaching the default pool limit flushes it in one INSERT per
     * default chunk.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testReachingThePoolLimitFlushesOneInsertPerChunk(): void
    {
        $repository = $this->deferrableRepository(ImportRow::class);

        $statements = $this->recordStatements(function () use ($repository): void {
            for ($i = 0; $i < 10000; $i++) {
                $repository->defer(['reference' => "ref-{$i}", 'payload' => 'row']);
            }
        });

        self::assertStatementCount(20, $this->inserts($statements));
        self::assertSame(10000, DB::table('import_rows')->count());
        self::assertTrue($this->pool()->isEmpty());
    }

    /**
     * Test that a full default chunk of a table too wide to bind it in one
     * INSERT is written in statements that stay under the binding ceiling.
     *
     * @return void
     */
    public function testAWideTableIsWrittenWithinTheBindingCeiling(): void
    {
        $pool = $this->pool();
        $row  = array_fill_keys($this->wideColumns(), 1);

        for ($i = 0; $i < 500; $i++) {
            $pool->add(DB::getDefaultConnection(), 'wide_rows', $row);
        }

        $statements = $this->recordStatements(fn (): bool => $pool->flush()->isSuccessful());

        self::assertStatementCount(3, $this->inserts($statements));
        self::assertSame(500, DB::table('wide_rows')->count());
        self::assertTrue($pool->isEmpty());
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('wide_rows');
        Schema::create('wide_rows', function (Blueprint $table): void {
            foreach ($this->wideColumns() as $column) {
                $table->integer($column);
            }
        });
    }

    /**
     * Get the column names of the wide table.
     *
     * @return list<string>
     */
    private function wideColumns(): array
    {
        return array_map(fn (int $column): string => "c{$column}", range(1, self::WIDE_COLUMNS));
    }

    /**
     * Keep the INSERT statements of the given statements.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function inserts(array $statements): array
    {
        return array_values(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'insert')));
    }

    /**
     * Get the container's write pool, built from the default settings.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePool
     */
    private function pool(): WritePool
    {
        $pool = $this->app?->make(WritePool::class);

        assert($pool instanceof WritePool);

        return $pool;
    }
}
