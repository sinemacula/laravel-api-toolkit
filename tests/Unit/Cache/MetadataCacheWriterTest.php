<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use Tests\TestCase;

/**
 * Tests for the MetadataCacheWriter chokepoint.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MetadataCacheWriter::class)]
final class MetadataCacheWriterTest extends TestCase
{
    /**
     * Test that rememberMetadataForever returns the value produced by the
     * callback.
     *
     * @return void
     */
    public function testRememberMetadataForeverReturnsTheCallbackValue(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);

        // Act
        $value = $writer->rememberMetadataForever('test-key', fn () => 'expected-value');

        // Assert
        self::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadataForever registers the key in the injected
     * registry.
     *
     * @return void
     */
    public function testRememberMetadataForeverRegistersTheKey(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);

        // Act
        $writer->rememberMetadataForever('my-metadata-key', fn () => 'value');

        // Assert
        self::assertContains('my-metadata-key', $registry->keys());
    }

    /**
     * Test that rememberMetadataForever persists the value to the memo store.
     *
     * @return void
     */
    public function testRememberMetadataForeverWritesToTheMemoStore(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);
        $key      = 'memo-store-key';

        // Act
        $writer->rememberMetadataForever($key, fn () => 'stored-value');

        // Assert
        self::assertSame('stored-value', Cache::memo()->get($key));
    }

    /**
     * Test that rememberMetadataForever registers the key even when the memo
     * store already holds the value and the callback is never invoked.
     *
     * @return void
     */
    public function testRememberMetadataForeverRegistersKeyEvenOnWarmCache(): void
    {
        // Arrange
        $key = 'warm-cache-key';

        Cache::memo()->rememberForever($key, fn () => 'pre-warmed-value');

        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);

        // Act — callback would not be called because the key is already
        // memoised
        $writer->rememberMetadataForever($key, fn () => 'should-not-be-called');

        // Assert
        self::assertContains($key, $registry->keys());
        self::assertSame('pre-warmed-value', Cache::memo()->get($key));
    }

    /**
     * Test that rememberMetadata returns the value produced by the callback.
     *
     * @return void
     */
    public function testRememberMetadataReturnsTheCallbackValue(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);

        // Act
        $value = $writer->rememberMetadata('ttl-key', fn () => 'expected-value', 3600);

        // Assert
        self::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadata registers the key in the injected registry so
     * a scoped flush still forgets it.
     *
     * @return void
     */
    public function testRememberMetadataRegistersTheKey(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);

        // Act
        $writer->rememberMetadata('ttl-metadata-key', fn () => 'value', 3600);

        // Assert
        self::assertContains('ttl-metadata-key', $registry->keys());
    }

    /**
     * Test that rememberMetadata persists the value to the memo store.
     *
     * @return void
     */
    public function testRememberMetadataWritesToTheMemoStore(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);
        $key      = 'ttl-memo-store-key';

        // Act
        $writer->rememberMetadata($key, fn () => 'stored-value', 3600);

        // Assert
        self::assertSame('stored-value', Cache::memo()->get($key));
    }

    /**
     * Test that rememberMetadata passes the given time-to-live through to the
     * underlying store rather than storing the value forever.
     *
     * @return void
     */
    public function testRememberMetadataPassesTheTtlToTheStore(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry);

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('remember')
            ->once()
            ->with('ttl-passthrough-key', 1234, \Mockery::type(\Closure::class))
            ->andReturn('value');

        Cache::shouldReceive('memo')
            ->once()
            ->andReturn($repository);

        // Act
        $value = $writer->rememberMetadata('ttl-passthrough-key', fn () => 'value', 1234);

        // Assert
        self::assertSame('value', $value);
        self::assertContains('ttl-passthrough-key', $registry->keys());
    }
}
