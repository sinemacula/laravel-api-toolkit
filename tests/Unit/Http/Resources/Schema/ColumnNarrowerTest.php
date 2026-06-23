<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\ColumnNarrower;
use SineMacula\ApiToolkit\Schema\FieldColumnMap;

/**
 * Tests for the ColumnNarrower domain rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnNarrower::class)]
final class ColumnNarrowerTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Schema\ColumnNarrower */
    private ColumnNarrower $narrower;

    /**
     * Set up the narrower instance for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->narrower = new ColumnNarrower;
    }

    /**
     * Test that all mapped fields produce a narrow decision with the union of
     * mapped columns and the safety set.
     *
     * @return void
     */
    public function testNarrowsWhenAllFieldsMapped(): void
    {
        $map = FieldColumnMap::make(
            ['name' => ['first_name', 'last_name'], 'email' => ['email']],
            ['name', 'email'],
        );

        $decision = $this->narrower->decide($map, ['name', 'email'], ['id']);

        static::assertTrue($decision->shouldNarrow());
        static::assertSame(['first_name', 'last_name', 'email', 'id'], $decision->columns());
    }

    /**
     * Test that a single unmapped field produces a fall-back decision whose
     * reason equals that field key.
     *
     * @return void
     */
    public function testFallsBackOnFirstUnmappedField(): void
    {
        $map = FieldColumnMap::make([], []);

        $decision = $this->narrower->decide($map, ['name'], ['id']);

        static::assertFalse($decision->shouldNarrow());
        static::assertSame('name', $decision->reason());
    }

    /**
     * Test that when multiple fields are unmapped the reason is the first
     * unmapped field in resolution order.
     *
     * @return void
     */
    public function testFallsBackReasonIsFirstUnmappedInOrder(): void
    {
        $map = FieldColumnMap::make([], []);

        $decision = $this->narrower->decide($map, ['alpha', 'beta'], ['id']);

        static::assertFalse($decision->shouldNarrow());
        static::assertSame('alpha', $decision->reason());
    }

    /**
     * Test that overlapping mapped columns and safety-set columns are
     * deduplicated with first-seen order preserved.
     *
     * @return void
     */
    public function testNarrowedColumnsUnionDedupesNeededAndSafetySet(): void
    {
        $map = FieldColumnMap::make(
            ['name' => ['first_name', 'id']],
            ['name'],
        );

        $decision = $this->narrower->decide($map, ['name'], ['id', 'deleted_at']);

        static::assertTrue($decision->shouldNarrow());
        static::assertSame(['first_name', 'id', 'deleted_at'], $decision->columns());
    }

    /**
     * Test that an empty resolved field set narrows to the safety set only.
     *
     * @return void
     */
    public function testEmptyResolvedFieldsNarrowsToSafetySetOnly(): void
    {
        $map = FieldColumnMap::make([], []);

        $decision = $this->narrower->decide($map, [], ['id', 'deleted_at']);

        static::assertTrue($decision->shouldNarrow());
        static::assertSame(['id', 'deleted_at'], $decision->columns());
    }

    /**
     * Test that a mapped field with an empty declared column list does not
     * force a fall-back.
     *
     * @return void
     */
    public function testMappedFieldWithEmptyColumnsDoesNotForceFallback(): void
    {
        $map = FieldColumnMap::make(
            ['virtual' => []],
            ['virtual'],
        );

        $decision = $this->narrower->decide($map, ['virtual'], ['id']);

        static::assertTrue($decision->shouldNarrow());
        static::assertSame(['id'], $decision->columns());
    }

    /**
     * Test that columns shared across fields and the safety set are
     * de-duplicated and the result is re-indexed into a contiguous list.
     *
     * @return void
     */
    public function testNarrowDeduplicatesAndReindexesColumns(): void
    {
        $map = FieldColumnMap::make(
            ['a' => ['x', 'y'], 'b' => ['y', 'z']],
            ['a', 'b'],
        );

        $decision = $this->narrower->decide($map, ['a', 'b'], ['x']);

        static::assertTrue($decision->shouldNarrow());
        static::assertSame(['x', 'y', 'z'], $decision->columns());
    }
}
