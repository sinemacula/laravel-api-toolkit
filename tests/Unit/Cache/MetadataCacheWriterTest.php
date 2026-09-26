<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
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
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        // Act
        $value = $writer->rememberMetadataForever('test-key', fn () => 'expected-value');

        // Assert
        self::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadataForever persists the value to the memo store.
     *
     * @return void
     */
    public function testRememberMetadataForeverWritesToTheMemoStore(): void
    {
        // Arrange
        $writer = new MetadataCacheWriter(new MetadataGeneration);
        $key    = 'memo-store-key';

        // Act
        $writer->rememberMetadataForever($key, fn () => 'stored-value');

        // Assert
        self::assertSame('stored-value', Cache::memo()->get($writer->storageKey($key)));
    }

    /**
     * Test that rememberMetadataForever serves a value another process already
     * stored under the current generation without running the callback.
     *
     * @return void
     */
    public function testRememberMetadataForeverServesAWarmValueWithoutTheCallback(): void
    {
        $key    = self::warmKey();
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn () => 'pre-warmed-value');

        $calls = 0;

        $value = $writer->rememberMetadataForever($key, static function () use (&$calls, $key): string {
            $calls++;

            return $key;
        });

        self::assertSame('pre-warmed-value', $value);
        self::assertSame(0, $calls);
    }

    /**
     * Test that readMetadata serves a value another process wrote under the
     * current generation, which is what lets every worker share the store.
     *
     * @return void
     */
    public function testReadMetadataServesAValueWrittenElsewhere(): void
    {
        $key    = 'written-elsewhere-key';
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        Cache::memo()->rememberForever($writer->storageKey($key), fn () => 'value-from-another-process');

        self::assertSame('value-from-another-process', (new MetadataCacheWriter(new MetadataGeneration))->readMetadata($key));
    }

    /**
     * Test that readMetadata reports the caller's own answer for absence.
     *
     * @return void
     */
    public function testReadMetadataReportsAbsence(): void
    {
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        self::assertNull($writer->readMetadata('never-written-key'));
        self::assertSame([], $writer->readMetadata('never-written-key', []));
    }

    /**
     * Test that readMetadata does not serve a value stored under the bare
     * metadata key, since only the generation-namespaced key is current.
     *
     * @return void
     */
    public function testReadMetadataIgnoresAValueStoredWithoutTheGeneration(): void
    {
        Cache::memo()->rememberForever('bare-key', fn (): string => 'unversioned');

        self::assertNull((new MetadataCacheWriter(new MetadataGeneration))->readMetadata('bare-key'));
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
        $writer = new MetadataCacheWriter(new MetadataGeneration);

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
        $writer = new MetadataCacheWriter(new MetadataGeneration);

        // Act
        $value = $writer->rememberMetadata('ttl-key', fn () => 'expected-value', 3600);

        // Assert
        self::assertSame('expected-value', $value);
    }

    /**
     * Test that rememberMetadata persists the value to the memo store.
     *
     * @return void
     */
    public function testRememberMetadataWritesToTheMemoStore(): void
    {
        // Arrange
        $writer = new MetadataCacheWriter(new MetadataGeneration);
        $key    = 'ttl-memo-store-key';

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
        $writer     = new MetadataCacheWriter(new MetadataGeneration);
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
        $writer     = new MetadataCacheWriter($generation);

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
        $writer     = new MetadataCacheWriter($generation);

        $writer->rememberMetadataForever('retired-key', fn (): string => 'before');
        $generation->advance();

        self::assertNull($writer->readMetadata('retired-key'));
        self::assertSame('after', $writer->rememberMetadataForever('retired-key', fn (): string => 'after'));
    }

    /**
     * Return the key the warm-value test stores under.
     *
     * @return string
     */
    private static function warmKey(): string
    {
        return 'warm-cache-key';
    }
}
