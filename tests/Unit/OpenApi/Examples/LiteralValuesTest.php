<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\LiteralValues;

/**
 * Tests for the literal values of a schema node.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LiteralValues::class)]
final class LiteralValuesTest extends TestCase
{
    /**
     * Provide nodes and the values they list, in order.
     *
     * @return iterable<string, array{array<string, mixed>, list<mixed>}>
     */
    public static function listedProvider(): iterable
    {
        yield 'every source' => [['examples' => ['a', 'b'], 'example' => 'c', 'const' => 'd', 'enum' => [null, 'e', 'f']], ['a', 'c', 'd', 'e', 'f', null]];
        yield 'single example' => [['examples' => ['a']], ['a']];
        yield 'null const' => [['const' => null], [null]];
        yield 'empty examples' => [['examples' => [], 'enum' => []], []];
        yield 'nothing listed' => [['type' => 'string'], []];
    }

    /**
     * Test that a node lists its first example, deprecated example, const and
     * enum values, null last.
     *
     * @param  array<string, mixed>  $node
     * @param  list<mixed>  $expected
     * @return void
     */
    #[DataProvider('listedProvider')]
    public function testNodeListsItsValuesInOrder(array $node, array $expected): void
    {
        self::assertSame($expected, iterator_to_array((new LiteralValues)->listed($node), false));
    }

    /**
     * Provide scalar types with their nodes and the value each builds.
     *
     * @return iterable<string, array{string, array<string, mixed>, mixed}>
     */
    public static function scalarProvider(): iterable
    {
        yield 'email' => ['string', ['format' => 'email'], 'user@example.com'];
        yield 'uri' => ['string', ['format' => 'uri'], 'https://example.com'];
        yield 'uuid' => ['string', ['format' => 'uuid'], '123e4567-e89b-42d3-a456-426614174000'];
        yield 'ulid' => ['string', ['format' => 'ulid'], '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        yield 'date' => ['string', ['format' => 'date'], '2025-01-01'];
        yield 'date-time' => ['string', ['format' => 'date-time'], '2025-01-01T00:00:00Z'];
        yield 'time' => ['string', ['format' => 'time'], '00:00:00Z'];
        yield 'ipv4' => ['string', ['format' => 'ipv4'], '192.0.2.1'];
        yield 'ipv6' => ['string', ['format' => 'ipv6'], '2001:db8::1'];
        yield 'hostname' => ['string', ['format' => 'hostname'], 'example.com'];
        yield 'byte' => ['string', ['format' => 'byte'], 'c3RyaW5n'];
        yield 'other format' => ['string', ['format' => 'color', 'minLength' => 7], 'stringx'];
        yield 'string' => ['string', [], 'string'];
        yield 'padded string' => ['string', ['minLength' => 9], 'stringxxx'];
        yield 'cut string' => ['string', ['maxLength' => 3], 'str'];
        yield 'integer' => ['integer', [], 1];
        yield 'integer minimum' => ['integer', ['minimum' => 5], 5];
        yield 'fractional minimum' => ['integer', ['minimum' => 1.25], 2];
        yield 'exclusive minimum' => ['integer', ['exclusiveMinimum' => 5], 6];
        yield 'integer maximum' => ['integer', ['maximum' => -3], -3];
        yield 'exclusive maximum' => ['integer', ['exclusiveMaximum' => 1], 0];
        yield 'multiple' => ['integer', ['minimum' => 7, 'multipleOf' => 5], 10];
        yield 'non-positive multiple' => ['integer', ['minimum' => 3, 'multipleOf' => 0], 3];
        yield 'non-numeric bound' => ['integer', ['minimum' => '5'], 1];
        yield 'number' => ['number', [], 1.5];
        yield 'number minimum' => ['number', ['minimum' => 2], 2];
        yield 'number maximum' => ['number', ['maximum' => 1], 1];
        yield 'number exclusive bounds' => ['number', ['exclusiveMinimum' => 2.25, 'exclusiveMaximum' => 4], 3];
        yield 'range narrower than a unit' => ['number', ['minimum' => 1, 'exclusiveMaximum' => 1.5], 1.25];
        yield 'exclusive range of a unit' => ['number', ['exclusiveMinimum' => 0, 'exclusiveMaximum' => 1], 0.5];
        yield 'number multiple' => ['number', ['multipleOf' => 0.5, 'minimum' => 1.75], 2.0];
        yield 'boolean' => ['boolean', [], true];
        yield 'null' => ['null', [], null];
    }

    /**
     * Test that a scalar type builds its value within the node's bounds.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $node
     * @param  mixed  $expected
     * @return void
     */
    #[DataProvider('scalarProvider')]
    public function testScalarTypeBuildsItsValueWithinTheBounds(string $type, array $node, mixed $expected): void
    {
        self::assertSame($expected, (new LiteralValues)->scalar($type, $node));
    }

    /**
     * Provide scalar types with their nodes, an ordinal and the value built.
     *
     * @return iterable<string, array{string, array<string, mixed>, int, mixed}>
     */
    public static function variantProvider(): iterable
    {
        yield 'string' => ['string', [], 2, 'string3'];
        yield 'padded string' => ['string', ['minLength' => 8], 1, 'string2x'];
        yield 'formatted string' => ['string', ['format' => 'email'], 1, 'user@example.com'];
        yield 'integer' => ['integer', [], 2, 3];
        yield 'integer multiple' => ['integer', ['minimum' => 7, 'multipleOf' => 5], 1, 15];
        yield 'number' => ['number', [], 1, 2.5];
        yield 'boolean' => ['boolean', [], 1, false];
    }

    /**
     * Test that a later ordinal varies a plain string, a number by its step
     * and a boolean, leaving a formatted string as it is.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $node
     * @param  int  $ordinal
     * @param  mixed  $expected
     * @return void
     */
    #[DataProvider('variantProvider')]
    public function testLaterOrdinalVariesTheValue(string $type, array $node, int $ordinal, mixed $expected): void
    {
        self::assertSame($expected, (new LiteralValues)->scalar($type, $node, $ordinal));
    }
}
