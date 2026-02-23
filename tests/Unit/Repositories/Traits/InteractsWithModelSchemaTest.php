<?php

namespace Tests\Unit\Repositories\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Traits\InteractsWithModelSchema;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the InteractsWithModelSchema trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InteractsWithModelSchema::class)]
class InteractsWithModelSchemaTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that getColumnsFromModel returns the column listing for the model.
     *
     * @return void
     */
    public function testGetColumnsFromModelReturnsColumnListing(): void
    {
        $consumer = $this->createConsumer();
        $model    = new User;

        $columns = $this->invokeMethod($consumer, 'getColumnsFromModel', $model);

        $expectedColumns = Schema::getColumnListing('users');

        static::assertSame($expectedColumns, $columns);
    }

    /**
     * Test that column results are cached in the instance property.
     *
     * @return void
     */
    public function testColumnResultsAreCachedInInstanceProperty(): void
    {
        $consumer = $this->createConsumer();
        $model    = new User;

        $first  = $this->invokeMethod($consumer, 'getColumnsFromModel', $model);
        $second = $this->invokeMethod($consumer, 'getColumnsFromModel', $model);

        static::assertSame($first, $second);

        $columns = $this->getProperty($consumer, 'columns');

        static::assertArrayHasKey(User::class, $columns);
    }

    /**
     * Test that the cache is used on the second call without hitting Schema.
     *
     * @return void
     */
    public function testCacheIsUsedOnSecondCall(): void
    {
        $consumer = $this->createConsumer();
        $model    = new User;

        $this->invokeMethod($consumer, 'getColumnsFromModel', $model);

        $columns = $this->getProperty($consumer, 'columns');

        static::assertArrayHasKey(User::class, $columns);
        static::assertNotEmpty($columns[User::class]);
    }

    /**
     * Test that a second consumer reads columns from the Laravel cache,
     * exercising the early-return branch in resolveColumnsFromModel.
     *
     * @return void
     */
    public function testSecondConsumerReadsColumnsFromLaravelCache(): void
    {
        $model = new User;

        $consumer1 = $this->createConsumer();
        $this->invokeMethod($consumer1, 'getColumnsFromModel', $model);

        // Fresh instance: no instance cache, but Laravel cache is populated.
        $consumer2 = $this->createConsumer();
        $columns   = $this->invokeMethod($consumer2, 'getColumnsFromModel', $model);

        static::assertNotEmpty($columns);
    }

    /**
     * Create a test consumer class that uses the InteractsWithModelSchema
     * trait.
     *
     * @return object
     */
    private function createConsumer(): object
    {
        return new class {
            use InteractsWithModelSchema;
        };
    }
}
