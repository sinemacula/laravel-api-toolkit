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
 * End-to-end tests that schema metadata is shared across workers.
 *
 * Each simulated worker shares nothing with the others but the cache store.
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
     * Test that a second worker serves the first worker's schema unqueried.
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
     * Test that a table created after an empty read is served once it exists.
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
     * Cross a lifecycle boundary, dropping in-process state and scoped
     * bindings.
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
