<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumValuesToken;
use Tests\TestCase;

/**
 * Tests for the exact enum values token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EnumValuesToken::class)]
final class EnumValuesTokenTest extends TestCase
{
    /**
     * Test that a token names the rule and lists its values as JSON.
     *
     * @return void
     */
    public function testTokenListsTheValuesAsJson(): void
    {
        self::assertSame('enum-values:["a,b",3]', (new EnumValuesToken)->token(['a,b', 3]));
        self::assertSame('enum-values:[]', (new EnumValuesToken)->token([]));
    }

    /**
     * Test that a token's argument reads back as exactly the values it was
     * built from, an empty list included.
     *
     * @return void
     */
    public function testValuesReadBackExactly(): void
    {
        $values = ['a,b', 'say "hi"', 'dir\\', 3, -1];
        $token  = new EnumValuesToken;

        self::assertSame($values, $token->values(substr($token->token($values), strlen(EnumValuesToken::NAME) + 1)));
        self::assertSame([], $token->values('[]'));
    }

    /**
     * Provide arguments that do not hold a list of values.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['[a'];
        yield 'scalar' => ['3'];
        yield 'object' => ['{"x":1}'];
        yield 'float value' => ['[1.5]'];
        yield 'null value' => ['["a",null]'];
        yield 'nested value' => ['[["a"]]'];
    }

    /**
     * Test that an argument not holding a list of integer or string values
     * reads as null.
     *
     * @param  string  $argument
     * @return void
     */
    #[DataProvider('invalidArguments')]
    public function testArgumentWithoutAListOfValuesReadsAsNull(string $argument): void
    {
        self::assertNull((new EnumValuesToken)->values($argument));
    }
}
