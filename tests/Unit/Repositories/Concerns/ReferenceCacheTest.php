<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\ReferenceCache;
use Tests\Fixtures\Models\Tag;
use Tests\TestCase;

/**
 * Tests for the ReferenceCache collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ReferenceCache::class)]
class ReferenceCacheTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\ReferenceCache The reference cache under test. */
    private ReferenceCache $referenceCache;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);

        $this->referenceCache = new ReferenceCache('array', 'tags', 3600);
    }

    /**
     * Test that the whole table is loaded with a single query for repeated
     * reads.
     *
     * @return void
     */
    public function testLoadsTableOnceForRepeatedReads(): void
    {
        DB::enableQueryLog();

        $this->referenceCache->all(new Tag);
        $this->referenceCache->all(new Tag);
        $this->referenceCache->all(new Tag);

        static::assertCount(1, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that all returns the full collection from memory.
     *
     * @return void
     */
    public function testAllReturnsFullCollectionFromMemory(): void
    {
        $result = $this->referenceCache->all(new Tag);

        static::assertInstanceOf(Collection::class, $result);
        static::assertCount(2, $result);
    }

    /**
     * Test that find resolves a single record from the in-memory snapshot
     * without an additional query.
     *
     * @return void
     */
    public function testFindResolvesRecordFromMemoryWithoutQuery(): void
    {
        $this->referenceCache->all(new Tag);

        DB::enableQueryLog();

        $tag = $this->referenceCache->find(new Tag, 1);

        static::assertInstanceOf(Tag::class, $tag);
        static::assertSame('php', $tag->name); // @phpstan-ignore property.notFound
        static::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that find returns null for an unknown key.
     *
     * @return void
     */
    public function testFindReturnsNullForUnknownKey(): void
    {
        static::assertNull($this->referenceCache->find(new Tag, 999));
    }

    /**
     * Test that a flush forces the table to reload on the next read.
     *
     * @return void
     */
    public function testFlushForcesReloadOnNextRead(): void
    {
        $this->referenceCache->all(new Tag);

        Tag::create(['name' => 'vue']);

        $this->referenceCache->flush();

        $result = $this->referenceCache->all(new Tag);

        static::assertCount(3, $result);
    }

    /**
     * Test that getStatus reports a populated state after the table loads.
     *
     * @return void
     */
    public function testGetStatusReportsPopulatedStateAfterLoad(): void
    {
        static::assertFalse($this->referenceCache->getStatus()->isPopulated());

        $this->referenceCache->all(new Tag);

        static::assertTrue($this->referenceCache->getStatus()->isPopulated());
    }
}
