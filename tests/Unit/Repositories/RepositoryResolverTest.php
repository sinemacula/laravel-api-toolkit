<?php

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\RepositoryResolver;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Repositories\DummyRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\TestCase;

/**
 * Tests for the RepositoryResolver class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RepositoryResolver::class)]
class RepositoryResolverTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        RepositoryResolver::flush();

        $this->forceReloadMap();

        parent::tearDown();
    }

    /**
     * Test that map returns the config repository_map.
     *
     * @return void
     */
    public function testMapReturnsConfigRepositoryMap(): void
    {
        $expected = ['users' => UserRepository::class];

        config()->set('api-toolkit.repositories.repository_map', $expected);
        $this->forceReloadMap();

        $result = RepositoryResolver::map();

        static::assertSame($expected, $result);
    }

    /**
     * Test that has returns true for a registered repository.
     *
     * @return void
     */
    public function testHasReturnsTrueForRegisteredRepository(): void
    {
        config()->set('api-toolkit.repositories.repository_map', [
            'users' => UserRepository::class,
        ]);

        $this->forceReloadMap();

        static::assertTrue(RepositoryResolver::has('users'));
    }

    /**
     * Test that has returns false for an unregistered repository.
     *
     * @return void
     */
    public function testHasReturnsFalseForUnregisteredRepository(): void
    {
        config()->set('api-toolkit.repositories.repository_map', []);
        $this->forceReloadMap();

        static::assertFalse(RepositoryResolver::has('nonexistent'));
    }

    /**
     * Test that get resolves and caches a repository instance.
     *
     * @return void
     */
    public function testGetResolvesAndCachesRepositoryInstance(): void
    {
        config()->set('api-toolkit.repositories.repository_map', [
            'users' => UserRepository::class,
        ]);

        $this->forceReloadMap();

        $repository = RepositoryResolver::get('users');

        static::assertInstanceOf(RepositoryInterface::class, $repository);
        static::assertInstanceOf(UserRepository::class, $repository);

        $second = RepositoryResolver::get('users');

        static::assertSame($repository, $second);
    }

    /**
     * Test that get throws RuntimeException for an unregistered repository.
     *
     * @return void
     */
    public function testGetThrowsRuntimeExceptionForUnregisteredRepository(): void
    {
        config()->set('api-toolkit.repositories.repository_map', []);
        $this->forceReloadMap();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Repository \'nonexistent\' not found in registry.');

        RepositoryResolver::get('nonexistent');
    }

    /**
     * Test that register adds to the map and clears the cached instance.
     *
     * @return void
     */
    public function testRegisterAddsToMapAndClearsCachedInstance(): void
    {
        config()->set('api-toolkit.repositories.repository_map', [
            'users' => UserRepository::class,
        ]);

        $this->forceReloadMap();

        RepositoryResolver::get('users');

        RepositoryResolver::register('users', DummyRepository::class);

        static::assertTrue(RepositoryResolver::has('users'));
        static::assertSame(DummyRepository::class, RepositoryResolver::map()['users']);

        $resolved = RepositoryResolver::get('users');

        static::assertInstanceOf(DummyRepository::class, $resolved);
    }

    /**
     * Test that flush clears all cached repository instances.
     *
     * @return void
     */
    public function testFlushClearsCachedInstances(): void
    {
        config()->set('api-toolkit.repositories.repository_map', [
            'users' => UserRepository::class,
        ]);

        $this->forceReloadMap();

        $first = RepositoryResolver::get('users');

        RepositoryResolver::flush();

        $second = RepositoryResolver::get('users');

        static::assertNotSame($first, $second);
    }

    /**
     * Force the RepositoryResolver to reload its map from config.
     *
     * The static $map property uses ??= to lazily initialize from config.
     * Once set, it cannot be re-read. This method forces re-initialization
     * by setting the map to the current config value.
     *
     * @return void
     */
    private function forceReloadMap(): void
    {
        $this->setStaticProperty(RepositoryResolver::class, 'repositories', []);

        $reflection = new \ReflectionClass(RepositoryResolver::class);
        $mapProp    = $reflection->getProperty('map');

        // Force the map to reload from config by setting it to the current config value
        $mapProp->setValue(null, config('api-toolkit.repositories.repository_map', []));
    }
}
