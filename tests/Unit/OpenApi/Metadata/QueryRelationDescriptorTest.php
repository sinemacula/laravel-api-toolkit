<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use Tests\Fixtures\Resources\PostResource;

/**
 * Tests for the QueryRelationDescriptor value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryRelationDescriptor::class)]
final class QueryRelationDescriptorTest extends TestCase
{
    /**
     * Test that all constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testStoresAllProperties(): void
    {
        $descriptor = new QueryRelationDescriptor('posts', PostResource::class, true);

        self::assertSame('posts', $descriptor->relation);
        self::assertSame(PostResource::class, $descriptor->resource);
        self::assertTrue($descriptor->polymorphic);
    }

    /**
     * Test that a relation names no governing resource and no polymorphism by
     * default.
     *
     * @return void
     */
    public function testARelationIsGovernedByNothingByDefault(): void
    {
        $descriptor = new QueryRelationDescriptor('tags');

        self::assertNull($descriptor->resource);
        self::assertFalse($descriptor->polymorphic);
    }
}
