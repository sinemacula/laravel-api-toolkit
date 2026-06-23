<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Repositories\CacheableDeferrableTagRepository;
use Tests\TestCase;

/**
 * Tests that the Cacheable and Deferrable concerns coexist on a single
 * repository without a boot() collision.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
final class CacheableDeferrableTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);
    }

    /**
     * Test that a repository using both Cacheable and Deferrable boots without a
     * trait collision and that both concerns are functional: the cache is
     * populated on read and a deferred write is persisted on flush.
     *
     * @return void
     */
    public function testCacheableAndDeferrableConcernsCoexist(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(CacheableDeferrableTagRepository::class);

        // Cacheable booted: a read populates the per-query cache.
        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertInstanceOf(Collection::class, $result);
        static::assertTrue($repository->getCacheStatus()->isPopulated());

        // Deferrable booted: a deferred write is buffered then persisted.
        $repository->defer(['name' => 'deferred']);
        $flush = $repository->flushWrites();

        static::assertTrue($flush->isSuccessful());
        $this->assertDatabaseHas('tags', ['name' => 'deferred']);
    }
}
