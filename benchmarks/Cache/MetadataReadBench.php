<?php

declare(strict_types = 1);

namespace Benchmarks\Cache;

use Benchmarks\Support\BenchmarkApplication;
use PhpBench\Attributes as Bench;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;

/**
 * Benchmarks a new operation reading three tables' column and index metadata
 * from the shared cache.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\OutputTimeUnit('microseconds')]
#[Bench\Revs(100)]
final class MetadataReadBench
{
    /** @var \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider The introspector under benchmark */
    private SchemaIntrospectionProvider $introspector;

    /** @var list<\Illuminate\Database\Eloquent\Model> The models whose tables are read */
    private array $models;

    /**
     * Fill the shared cache from the catalogue.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->introspector = BenchmarkApplication::create()->make(SchemaIntrospectionProvider::class);
        $this->models       = [new User, new Post, new Tag];

        $this->benchRead();
    }

    /**
     * Drop the operation's memo and read the metadata again.
     *
     * @return void
     */
    public function benchRead(): void
    {
        $this->introspector->flush();

        foreach ($this->models as $model) {
            $this->introspector->getColumns($model);
            $this->introspector->getColumnDefinitions($model);
            $this->introspector->getIndexes($model);
        }
    }
}
