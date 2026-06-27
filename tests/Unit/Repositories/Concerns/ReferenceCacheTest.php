<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard;
use SineMacula\ApiToolkit\Repositories\Concerns\ReferenceCache;
use Tests\Concerns\InteractsWithNonPublicMembers;
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
final class ReferenceCacheTest extends TestCase
{
    use InteractsWithNonPublicMembers;

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

        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:00'));

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);

        $this->referenceCache = new ReferenceCache('array', 'tags', 3600, new CacheSizeGuard(null, null));
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

        self::assertCount(1, DB::getQueryLog());

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

        self::assertInstanceOf(Collection::class, $result);
        self::assertCount(2, $result);
    }

    /**
     * Test that repeated reads are served from the instance memo even after
     * the backing store is evicted, proving the snapshot is deserialized once.
     *
     * @return void
     */
    public function testReusesInstanceMemoAfterBackingStoreEviction(): void
    {
        $first = $this->referenceCache->all(new Tag);

        // Evict the backing store directly, bypassing flushTable (which would
        // also clear the instance memo), so only the memo can serve the read.
        $this->referenceCache->getStore()->forget('api-toolkit:repository-cache:tags');

        DB::enableQueryLog();

        $second = $this->referenceCache->all(new Tag);

        self::assertSame($first, $second);
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that loading the snapshot builds a key index for O(1) lookups,
     * keyed by the model's primary key rather than collection position.
     *
     * @return void
     */
    public function testBuildsAKeyIndexForTheMemoisedSnapshot(): void
    {
        $this->referenceCache->all(new Tag);

        $index = $this->getProperty($this->referenceCache, 'index');

        self::assertInstanceOf(Collection::class, $index);

        $tag = $index->get(1);

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('php', $tag->getAttribute('name'));
    }

    /**
     * Test that find resolves a record from an over-large (un-memoised) table
     * by scanning the freshly-queried collection rather than a key index.
     *
     * @return void
     */
    public function testFindResolvesFromAnOverLargeTableWithoutAKeyIndex(): void
    {
        $reference = new ReferenceCache('array', 'tags', 3600, new CacheSizeGuard(1, null));

        $tag = $reference->find(new Tag, 1);

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('php', $tag->getAttribute('name'));
    }

    /**
     * Test that a table exceeding the size guard is returned in full but never
     * cached, so reference mode on an over-large table falls back to querying
     * rather than holding a huge serialized snapshot.
     *
     * @return void
     */
    public function testDoesNotCacheTableExceedingSizeGuard(): void
    {
        $reference = new ReferenceCache('array', 'tags', 3600, new CacheSizeGuard(1, null));

        DB::enableQueryLog();

        $first  = $reference->all(new Tag);
        $second = $reference->all(new Tag);

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertCount(2, DB::getQueryLog());
        self::assertFalse($reference->getStatus()->isPopulated());

        DB::disableQueryLog();
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

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('php', $tag->name); // @phpstan-ignore property.notFound
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that find returns null for an unknown key.
     *
     * @return void
     */
    public function testFindReturnsNullForUnknownKey(): void
    {
        self::assertNull($this->referenceCache->find(new Tag, 999));
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

        $this->referenceCache->flushTable();

        $result = $this->referenceCache->all(new Tag);

        self::assertCount(3, $result);
    }

    /**
     * Test that getStatus reports a populated state after the table loads.
     *
     * @return void
     */
    public function testGetStatusReportsPopulatedStateAfterLoad(): void
    {
        self::assertFalse($this->referenceCache->getStatus()->isPopulated());

        $this->referenceCache->all(new Tag);

        self::assertTrue($this->referenceCache->getStatus()->isPopulated());
    }

    /**
     * Test that the snapshot and metadata cache keys are scoped to the table,
     * so two reference caches for different tables never collide.
     *
     * @return void
     */
    public function testCacheKeysAreScopedToTable(): void
    {
        $this->referenceCache->all(new Tag);

        self::assertTrue($this->referenceCache->getStore()->has('api-toolkit:repository-cache:tags'));
        self::assertTrue($this->referenceCache->getStore()->has('api-toolkit:repository-cache-meta:tags'));
    }

    /**
     * Test that loading the table records the populated_at timestamp in the
     * reference metadata.
     *
     * @return void
     */
    public function testLoadRecordsPopulatedAtMetadata(): void
    {
        $this->referenceCache->all(new Tag);

        $meta = $this->referenceCache->getStore()->get('api-toolkit:repository-cache-meta:tags');

        self::assertIsArray($meta);
        self::assertArrayHasKey('populated_at', $meta);
        self::assertSame(now()->timestamp, $meta['populated_at']);
    }

    /**
     * Test that a flush records the invalidated_at timestamp in the reference
     * metadata.
     *
     * @return void
     */
    public function testFlushRecordsInvalidatedAtMetadata(): void
    {
        $this->referenceCache->all(new Tag);
        $this->referenceCache->flushTable();

        $meta = $this->referenceCache->getStore()->get('api-toolkit:repository-cache-meta:tags');

        self::assertIsArray($meta);
        self::assertArrayHasKey('invalidated_at', $meta);
        self::assertSame(now()->timestamp, $meta['invalidated_at']);
    }
}
