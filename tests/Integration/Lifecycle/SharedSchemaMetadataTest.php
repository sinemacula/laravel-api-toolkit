<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * End-to-end proof that schema metadata is shared across workers and lifecycle
 * boundaries, and is retired only by a generation advance.
 *
 * Each worker is simulated with its own introspector, writer, and generation,
 * sharing nothing with the others but the cache store, after the framework has
 * discarded the memoised store repository the way it does at every boundary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
#[CoversClass(MetadataCacheWriter::class)]
#[CoversClass(SchemaIntrospector::class)]
final class SharedSchemaMetadataTest extends TestCase
{
    /**
     * Test that a second worker serves the schema the first worker read after a
     * boundary, without asking the connection a single question.
     *
     * @return void
     */
    public function testASecondWorkerServesTheFirstWorkersSchemaWithoutQuerying(): void
    {
        $first = $this->worker();

        $columns     = $first->getColumns(new User);
        $definitions = $first->getColumnDefinitions(new User);
        $indexes     = $first->getIndexes(new User);

        $this->boundary();

        $queries = $this->countQueries(function () use ($columns, $definitions, $indexes): void {
            $second = $this->worker();

            self::assertSame($columns, $second->getColumns(new User));
            self::assertEquals($definitions, $second->getColumnDefinitions(new User));
            self::assertEquals($indexes, $second->getIndexes(new User));
        });

        self::assertSame(0, $queries);
    }

    /**
     * Test that a table created after an empty read is served with its real
     * columns at the next boundary, with no migration event or invalidation to
     * retire the empty answer.
     *
     * @return void
     */
    public function testATableCreatedAfterAnEmptyReadIsServedWithItsColumns(): void
    {
        $model = new #[Table('late_widgets')] class extends Model {};

        self::assertSame([], $this->worker()->getColumns($model));
        self::assertSame([], $this->worker()->getColumnDefinitions($model));

        Schema::create('late_widgets', static function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        $this->boundary();

        self::assertSame(['id', 'label'], $this->worker()->getColumns($model));
        self::assertSame(['id', 'label'], array_keys($this->worker()->getColumnDefinitions($model)));
    }

    /**
     * Build an introspector with the in-process state of a fresh worker.
     *
     * @return \SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector
     */
    private function worker(): SchemaIntrospector
    {
        return new SchemaIntrospector(new MetadataCacheWriter(new MetadataGeneration));
    }

    /**
     * Cross a lifecycle boundary: the toolkit resets its in-process state and
     * the framework discards its scoped instances, the memoised store among
     * them.
     *
     * @return void
     */
    private function boundary(): void
    {
        assert($this->app !== null);

        $this->app->make(CacheManager::class)->flush();
        $this->app->forgetScopedInstances();
    }

    /**
     * Count the queries any connection runs while the callback runs.
     *
     * @param  \Closure(): void  $callback
     * @return int
     */
    private function countQueries(\Closure $callback): int
    {
        $count = 0;

        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }
}
