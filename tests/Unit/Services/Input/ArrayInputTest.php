<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;

/**
 * Tests for the ArrayInput class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ArrayInput::class)]
final class ArrayInputTest extends TestCase
{
    /**
     * Test that typed accessors coerce present keys to the expected type.
     *
     * @return void
     */
    public function testTypedAccessorsCoerceValues(): void
    {
        $input = new ArrayInput([
            'name'   => 42,
            'count'  => '7',
            'active' => 1,
            'tags'   => 'php',
        ]);

        self::assertSame('42', $input->string('name'));
        self::assertSame(7, $input->integer('count'));
        self::assertTrue($input->boolean('active'));
        self::assertSame(['php'], $input->array('tags'));
    }

    /**
     * Test that typed accessors return zero values for absent keys.
     *
     * @return void
     */
    public function testAccessorsHandleMissingKeys(): void
    {
        $input = new ArrayInput([]);

        self::assertSame('', $input->string('missing'));
        self::assertSame(0, $input->integer('missing'));
        self::assertFalse($input->boolean('missing'));
        self::assertSame([], $input->array('missing'));
    }

    /**
     * Test that non-scalar values coerce to the zero value of the target type.
     *
     * @return void
     */
    public function testNonScalarValuesCoerceToZeroValues(): void
    {
        $input = new ArrayInput([
            'list' => ['a', 'b'],
        ]);

        self::assertSame('', $input->string('list'));
        self::assertSame(0, $input->integer('list'));
    }

    /**
     * Test that get() returns the supplied default for a missing key.
     *
     * @return void
     */
    public function testGetReturnsDefaultForMissingKey(): void
    {
        $input = new ArrayInput([]);

        self::assertSame('fallback', $input->get('missing', 'fallback'));
        self::assertNull($input->get('missing'));
    }

    /**
     * Test that toArray() returns the original attribute snapshot.
     *
     * @return void
     */
    public function testToArrayReturnsSnapshot(): void
    {
        $attributes = ['foo' => 'bar', 'baz' => 42];
        $input      = new ArrayInput($attributes);

        self::assertSame($attributes, $input->toArray());
    }

    /**
     * Test that the snapshot is not affected by mutation of the source array.
     *
     * @return void
     */
    public function testSnapshotIsImmutableFromSourceMutation(): void
    {
        $source = ['key' => 'original'];
        $input  = new ArrayInput($source);

        $source['key'] = 'mutated';

        self::assertSame('original', $input->get('key'));
    }
}
