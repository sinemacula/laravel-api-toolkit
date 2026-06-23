<?php

namespace Tests\Unit\Cache;

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
     * Test that rememberMetadataForever returns the value produced by the callback.
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
        static::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadataForever registers the key in the injected registry.
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
        static::assertContains('my-metadata-key', $registry->keys());
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
        static::assertSame('stored-value', Cache::memo()->get($key));
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

        // Act — callback would not be called because the key is already memoised
        $writer->rememberMetadataForever($key, fn () => 'should-not-be-called');

        // Assert
        static::assertContains($key, $registry->keys());
        static::assertSame('pre-warmed-value', Cache::memo()->get($key));
    }
}
