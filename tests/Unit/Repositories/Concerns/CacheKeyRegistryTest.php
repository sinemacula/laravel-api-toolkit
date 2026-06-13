<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheKeyRegistry;
use Tests\TestCase;

/**
 * Tests for the CacheKeyRegistry collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheKeyRegistry::class)]
class CacheKeyRegistryTest extends TestCase
{
    /** @var string The registry key for the test table. */
    private const string REGISTRY_KEY = 'api-toolkit:repository-cache-registry:test-table';

    /** @var \Illuminate\Contracts\Cache\Repository The cache store backing the registry. */
    private CacheContract $store;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\CacheKeyRegistry The registry under test. */
    private CacheKeyRegistry $registry;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->store    = Cache::store('array');
        $this->registry = new CacheKeyRegistry($this->store, 'test-table', 3600);
    }

    /**
     * Test that a tracked key is recorded in the registry.
     *
     * @return void
     */
    public function testTrackRecordsKeyInRegistry(): void
    {
        $this->registry->track('api-toolkit:repository-query:test-table:hash-a');

        $tracked = $this->store->get(self::REGISTRY_KEY);

        static::assertIsArray($tracked);
        static::assertArrayHasKey('api-toolkit:repository-query:test-table:hash-a', $tracked);
    }

    /**
     * Test that multiple tracked keys accumulate in the registry.
     *
     * @return void
     */
    public function testTrackAccumulatesMultipleKeys(): void
    {
        $this->registry->track('key-a');
        $this->registry->track('key-b');

        $tracked = $this->store->get(self::REGISTRY_KEY);

        static::assertIsArray($tracked);
        static::assertCount(2, $tracked);
    }

    /**
     * Test that flush forgets every tracked key and the registry itself.
     *
     * @return void
     */
    public function testFlushForgetsEveryTrackedKeyAndRegistry(): void
    {
        $this->store->put('key-a', 'value-a', 3600);
        $this->store->put('key-b', 'value-b', 3600);

        $this->registry->track('key-a');
        $this->registry->track('key-b');

        $this->registry->flush();

        static::assertNull($this->store->get('key-a'));
        static::assertNull($this->store->get('key-b'));
        static::assertNull($this->store->get(self::REGISTRY_KEY));
    }
}
