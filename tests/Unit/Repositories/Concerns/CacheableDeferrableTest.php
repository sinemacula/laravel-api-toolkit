<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Repositories\CacheableDeferrableTagRepository;
use Tests\TestCase;

/**
 * Tests that the Cacheable and Deferrable concerns coexist on one repository.
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
     * Test that a repository using Cacheable and Deferrable boots both.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testCacheableAndDeferrableConcernsCoexist(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(CacheableDeferrableTagRepository::class);

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $result);
        self::assertTrue($repository->getCacheStatus()->isPopulated());

        $repository->defer(['name' => 'deferred']);
        $flush = $repository->flushWrites();

        self::assertTrue($flush->isSuccessful());
        $this->assertDatabaseHas('tags', ['name' => 'deferred']);
    }
}
