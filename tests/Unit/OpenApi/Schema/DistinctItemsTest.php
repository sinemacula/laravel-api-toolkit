<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\DistinctItems;
use Tests\TestCase;

/**
 * Tests for the distinct-items reader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DistinctItems::class)]
final class DistinctItemsTest extends TestCase
{
    /**
     * Provide item rules and schemas with whether the items are unique.
     *
     * @return iterable<string, array{0: list<string>, 1: array<string, mixed>|false, 2: bool}>
     */
    public static function itemsProvider(): iterable
    {
        yield from [
            'string items'    => [['string', 'distinct'], ['type' => 'string'], true],
            'integer items'   => [['distinct'], ['type' => 'integer'], true],
            'number items'    => [['distinct'], ['type' => 'number'], true],
            'boolean items'   => [['distinct'], ['type' => 'boolean'], true],
            'enum items'      => [['distinct'], ['$ref' => '#/components/schemas/UserStatus'], true],
            'ignoring case'   => [['distinct:ignore_case'], ['type' => 'string'], true],
            'colon in option' => [['distinct:strict:x'], ['type' => 'integer'], true],
            'strict'          => [['distinct:strict'], ['type' => 'integer'], false],
            'quoted strict'   => [['distinct:"strict"'], ['type' => 'number'], false],
            'strict and case' => [['distinct:ignore_case,strict'], ['type' => 'string'], false],
            'lookalike rule'  => [['distinctly'], ['type' => 'string'], false],
            'no distinct'     => [['string'], ['type' => 'string'], false],
            'nullable items'  => [['distinct'], ['type' => ['string', 'null']], false],
            'array items'     => [['distinct'], ['type' => 'array'], false],
            'untyped items'   => [['distinct'], [], false],
            'never sent'      => [['distinct'], false, false],
        ];
    }

    /**
     * Test that only distinct scalar items are unique by JSON equality.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>|false  $items
     * @param  bool  $unique
     * @return void
     */
    #[DataProvider('itemsProvider')]
    public function testOnlyDistinctScalarItemsAreUnique(array $tokens, array|false $items, bool $unique): void
    {
        self::assertSame($unique, (new DistinctItems)->areUnique($tokens, $items));
    }
}
