<?php

namespace Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use Tests\TestCase;

/**
 * Tests for the MetadataKeyRegistry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MetadataKeyRegistry::class)]
final class MetadataKeyRegistryTest extends TestCase
{
    /**
     * Test that register adds a key to the set.
     *
     * @return void
     */
    public function testRegisterAddsKeyToTheSet(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;

        // Act
        $registry->register('sm-api-toolkit:model-schema:User');

        // Assert
        static::assertSame(['sm-api-toolkit:model-schema:User'], $registry->keys());
    }

    /**
     * Test that register is idempotent for repeated keys.
     *
     * @return void
     */
    public function testRegisterIsIdempotentForRepeatedKeys(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;

        // Act
        $registry->register('sm-api-toolkit:model-schema:User');
        $registry->register('sm-api-toolkit:model-schema:User');
        $registry->register('sm-api-toolkit:model-schema:User');

        // Assert
        static::assertSame(['sm-api-toolkit:model-schema:User'], $registry->keys());
    }

    /**
     * Test that keys returns all distinct registered keys as an integer-indexed list.
     *
     * @return void
     */
    public function testKeysReturnsDistinctRegisteredKeysAsList(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;

        // Act
        $registry->register('sm-api-toolkit:model-schema:User');
        $registry->register('sm-api-toolkit:model-resources:Post');
        $registry->register('sm-api-toolkit:model-casts:Comment');

        $keys = $registry->keys();

        // Assert
        static::assertCount(3, $keys);
        static::assertContains('sm-api-toolkit:model-schema:User', $keys);
        static::assertContains('sm-api-toolkit:model-resources:Post', $keys);
        static::assertContains('sm-api-toolkit:model-casts:Comment', $keys);
        static::assertSame(array_values($keys), $keys);
    }

    /**
     * Test that keys returns an empty array before any registration.
     *
     * @return void
     */
    public function testKeysReturnsEmptyArrayBeforeAnyRegistration(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;

        // Assert
        static::assertSame([], $registry->keys());
    }

    /**
     * Test that clear empties the registry.
     *
     * @return void
     */
    public function testClearEmptiesTheRegistry(): void
    {
        // Arrange
        $registry = new MetadataKeyRegistry;
        $registry->register('sm-api-toolkit:model-schema:User');
        $registry->register('sm-api-toolkit:model-resources:Post');

        // Act
        $registry->clear();

        // Assert
        static::assertSame([], $registry->keys());
    }
}
