<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\InRuleValues;
use Tests\TestCase;

/**
 * Tests for the in-rule value reader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InRuleValues::class)]
final class InRuleValuesTest extends TestCase
{
    /**
     * Provide the argument, field type, and expected field enum.
     *
     * @return iterable<string, array{0: string, 1: string|null, 2: array<int, float|int|string>}>
     */
    public static function fieldProvider(): iterable
    {
        yield from [
            'plain strings'        => ['a,b', 'string', ['a', 'b']],
            'quoted comma'         => ['"a,b",c', 'string', ['a,b', 'c']],
            'doubled quote'        => ['"say ""hi""",x', null, ['say "hi"', 'x']],
            'empty value kept'     => ['a,,b', null, ['a', '', 'b']],
            'string keeps numbers' => ['1,2', 'string', ['1', '2']],
            'untyped keeps number' => ['1,2', null, ['1', '2']],
            'boolean keeps number' => ['0,1', 'boolean', ['0', '1']],
            'integer exact'        => ['1,-3,0', 'integer', [1, -3, 0]],
            'integer inexact'      => ['01,-0,1e3,a', 'integer', ['01', '-0', '1e3', 'a']],
            'number exact'         => ['0.5,2', 'number', [0.5, 2]],
            'number trailing zero' => ['1.0,1.50', 'number', ['1.0', '1.50']],
            'number out of range'  => ['1.0e999,1e-2', 'number', ['1.0e999', '1e-2']],
            'empty argument'       => ['', 'string', []],
        ];
    }

    /**
     * Test that an enum carries exact numbers only on a numeric field.
     *
     * @param  string  $argument
     * @param  string|null  $type
     * @param  array<int, float|int|string>  $expected
     * @return void
     */
    #[DataProvider('fieldProvider')]
    public function testFieldEnumMatchesTheValidatorsComparison(string $argument, ?string $type, array $expected): void
    {
        self::assertSame($expected, (new InRuleValues)->forField($argument, $type));
    }

    /**
     * Provide not_in arguments with the values a field must not match.
     *
     * @return iterable<string, array{0: string, 1: string|null, 2: list<float|int|string>}>
     */
    public static function exclusionProvider(): iterable
    {
        yield from [
            'zero dropped'       => ['0,5', 'integer', [5]],
            'unit values kept'   => ['1,-1', 'integer', [1, -1]],
            'large integers'     => ['99999999999999,100000000000000,-100000000000000,-7', 'number', [99999999999999, -7]],
            'fractions kept'     => ['0.5,a', 'number', [0.5, 'a']],
            'integral floats'    => ['1.0E+14,-1.0E+14,-1.5,1.0E-7', 'integer', [-1.5, 1.0E-7]],
            'strings keep zero'  => ['0,a', 'string', ['0', 'a']],
            'untyped keeps zero' => ['0', null, ['0']],
            'empty argument'     => ['', 'integer', []],
        ];
    }

    /**
     * Test that an exclusion leaves out a number a JSON-equal float renders
     * differently.
     *
     * @param  string  $argument
     * @param  string|null  $type
     * @param  list<float|int|string>  $expected
     * @return void
     */
    #[DataProvider('exclusionProvider')]
    public function testExclusionLeavesOutNumbersAFloatRendersDifferently(string $argument, ?string $type, array $expected): void
    {
        self::assertSame($expected, (new InRuleValues)->forExclusion($argument, $type));
    }

    /**
     * Test that an array item enum lists values as strings and numbers.
     *
     * @return void
     */
    public function testItemEnumListsBothFormsOfAnExactNumber(): void
    {
        self::assertSame(['a', '1', 1, '1.0', '0.5', 0.5, 'b,c'], (new InRuleValues)->forItems('a,1,1.0,0.5,"b,c"'));
    }
}
