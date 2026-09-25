<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
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
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);

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
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);

        // Act
        $writer->rememberMetadataForever('my-metadata-key', fn () => 'value');

        // Assert
        self::assertContains($writer->storageKey('my-metadata-key'), $registry->keys());
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
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);
        $key      = 'memo-store-key';

        // Act
        $writer->rememberMetadataForever($key, fn () => 'stored-value');

        // Assert
        self::assertSame('stored-value', Cache::memo()->get($writer->storageKey($key)));
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
        $key      = 'warm-cache-key';
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn () => 'pre-warmed-value');

        // Act — callback would not be called because the key is already
        // memoised
        $writer->rememberMetadataForever($key, fn () => 'should-not-be-called');

        // Assert
        self::assertContains($writer->storageKey($key), $registry->keys());
        self::assertSame('pre-warmed-value', Cache::memo()->get($writer->storageKey($key)));
    }

    /**
     * Test that readMetadata registers the key of a value another process
     * wrote, which is the whole point of reading through the writer.
     *
     * A warm value is served without its callback ever running, so a reader
     * going straight to the store would hold a live key this process never
     * registered and a scoped flush would leave it behind.
     *
     * @return void
     */
    public function testReadMetadataRegistersTheKeyOfAValueWrittenElsewhere(): void
    {
        $key      = 'written-elsewhere-key';
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn () => 'value-from-another-process');

        self::assertSame('value-from-another-process', $writer->readMetadata($key));
        self::assertContains($writer->storageKey($key), $registry->keys());
    }

    /**
     * Test that readMetadata registers the key even where nothing is stored
     * under it, and reports the caller's own answer for absence.
     *
     * A reader that found nothing is about to write, so registering now costs
     * nothing and closes the window where the write is the only thing that
     * would have registered it.
     *
     * @return void
     */
    public function testReadMetadataRegistersTheKeyAndReportsAbsence(): void
    {
        $registry = new MetadataKeyRegistry;
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);

        self::assertNull($writer->readMetadata('never-written-key'));
        self::assertSame([], $writer->readMetadata('never-written-key', []));
        self::assertContains($writer->storageKey('never-written-key'), $registry->keys());
    }

    /**
     * Test that a value stored as an empty array reads back as itself rather
     * than as absence.
     *
     * An empty listing is a real answer about a table, so reading it as a miss
     * would send every reader back to the connection for something already
     * settled.
     *
     * @return void
     */
    public function testReadMetadataReportsAnEmptyValueRatherThanAbsence(): void
    {
        $key    = 'empty-value-key';
        $writer = new MetadataCacheWriter(new MetadataKeyRegistry, new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn (): array => []);

        self::assertSame([], $writer->readMetadata($key));
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
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);

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
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);

        // Act
        $writer->rememberMetadata('ttl-metadata-key', fn () => 'value', 3600);

        // Assert
        self::assertContains($writer->storageKey('ttl-metadata-key'), $registry->keys());
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
        $writer   = new MetadataCacheWriter($registry, new MetadataGeneration);
        $key      = 'ttl-memo-store-key';

        // Act
        $writer->rememberMetadata($key, fn () => 'stored-value', 3600);

        // Assert
        self::assertSame('stored-value', Cache::memo()->get($writer->storageKey($key)));
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
        $registry   = new MetadataKeyRegistry;
        $generation = new MetadataGeneration;
        $writer     = new MetadataCacheWriter($registry, $generation);
        $storageKey = $writer->storageKey('ttl-passthrough-key');

        $repository = \Mockery::mock(Repository::class);
        $repository->shouldReceive('remember')
            ->once()
            ->with($storageKey, 1234, \Mockery::type(\Closure::class))
            ->andReturn('value');

        Cache::shouldReceive('memo')
            ->once()
            ->andReturn($repository);

        // Act
        $value = $writer->rememberMetadata('ttl-passthrough-key', fn () => 'value', 1234);

        // Assert
        self::assertSame('value', $value);
        self::assertContains($storageKey, $registry->keys());
    }

    /**
     * Test that the stored key carries the current generation, so the same
     * metadata key lands somewhere else once the generation moves on.
     *
     * @return void
     */
    public function testStorageKeyIsNamespacedByTheCurrentGeneration(): void
    {
        $generation = new MetadataGeneration;
        $writer     = new MetadataCacheWriter(new MetadataKeyRegistry, $generation);

        self::assertSame('metadata-key:' . $generation->current(), $writer->storageKey('metadata-key'));

        $advanced = $generation->advance();

        self::assertSame('metadata-key:' . $advanced, $writer->storageKey('metadata-key'));
    }

    /**
     * Test that a value remembered under one generation is not served once the
     * generation has moved on, and the callback runs again.
     *
     * @return void
     */
    public function testAdvancingTheGenerationRetiresRememberedValues(): void
    {
        $generation = new MetadataGeneration;
        $writer     = new MetadataCacheWriter(new MetadataKeyRegistry, $generation);

        $writer->rememberMetadataForever('retired-key', fn (): string => 'before');
        $generation->advance();

        self::assertNull($writer->readMetadata('retired-key'));
        self::assertSame('after', $writer->rememberMetadataForever('retired-key', fn (): string => 'after'));
    }
}
