<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\RangeConstraintBuilder;
use Tests\TestCase;

/**
 * Tests for the range-constraint builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RangeConstraintBuilder::class)]
final class RangeConstraintBuilderTest extends TestCase
{
    /**
     * Provide size rules with the keywords each type exports.
     *
     * @return iterable<string, array{0: list<string>, 1: string, 2: array<string, mixed>}>
     */
    public static function sizeProvider(): iterable
    {
        yield from [
            'string max'              => [['max:255'], 'string', ['maxLength' => 255]],
            'string fractional max'   => [['max:2.5'], 'string', ['maxLength' => 2]],
            'string exact size'       => [['size:4'], 'string', ['minLength' => 4, 'maxLength' => 4]],
            'integer bounds'          => [['min:1', 'max:5'], 'integer', ['minimum' => 1, 'maximum' => 5]],
            'integer exact size'      => [['size:4'], 'integer', ['minimum' => 4, 'maximum' => 4]],
            'number between'          => [['between:1.5,9'], 'number', ['minimum' => 1.5, 'maximum' => 9]],
            'array between'           => [['between:1,3'], 'array', ['minItems' => 1, 'maxItems' => 3]],
            'array max'               => [['max:3'], 'array', ['maxItems' => 3]],
            'boolean size'            => [['min:1'], 'boolean', []],
            'required array'          => [['required'], 'array', ['minItems' => 1]],
            'filled array'            => [['filled'], 'array', ['minItems' => 1]],
            'required array above'    => [['required', 'min:3'], 'array', ['minItems' => 3]],
            'required array below'    => [['required', 'between:0,2'], 'array', ['minItems' => 1, 'maxItems' => 2]],
            'present array'           => [['present'], 'array', []],
            'required string'         => [['required', 'min:0'], 'string', ['minLength' => 0]],
            'tightest lower'          => [['min:1', 'gte:5'], 'integer', ['minimum' => 5]],
            'tightest lower reverse'  => [['gte:5', 'min:1'], 'integer', ['minimum' => 5]],
            'tightest upper'          => [['max:9', 'lte:5', 'max:7'], 'integer', ['maximum' => 5]],
            'tightest string sizes'   => [['min:5', 'min:2', 'max:4', 'max:9'], 'string', ['minLength' => 5, 'maxLength' => 4]],
            'between and min'         => [['between:1,10', 'min:3'], 'integer', ['minimum' => 3, 'maximum' => 10]],
            'size and max'            => [['max:9', 'size:4'], 'string', ['minLength' => 4, 'maxLength' => 4]],
            'between one argument'    => [['between:5'], 'integer', ['minimum' => 5]],
            'fractional integer'      => [['min:1.5'], 'integer', ['minimum' => 1.5]],
            'exponent bound'          => [['max:1e-2', 'min:-2E1'], 'number', ['minimum' => -20.0, 'maximum' => 0.01]],
            'non-numeric bound'       => [['min:abc'], 'string', []],
            'bound after non-number'  => [['min:abc', 'min:2', 'max:x', 'max:7'], 'string', ['minLength' => 2, 'maxLength' => 7]],
            'equal lower keeps first' => [['min:5', 'gte:5.0'], 'number', ['minimum' => 5]],
            'equal upper keeps first' => [['max:5', 'lte:5.0'], 'number', ['maximum' => 5]],
            'argumentless rules'      => [['min', 'gt', 'digits', 'multiple_of'], 'integer', []],
        ];
    }

    /**
     * Test that the size rules export the tightest bounds the type takes.
     *
     * @param  list<string>  $tokens
     * @param  string  $type
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('sizeProvider')]
    public function testSizeRulesExportTheTightestBounds(array $tokens, string $type, array $expected): void
    {
        self::assertSame($expected, (new RangeConstraintBuilder)->build($tokens, $type));
    }

    /**
     * Provide comparison, divisor and digit rules with the keywords exported.
     *
     * @return iterable<string, array{0: list<string>, 1: string, 2: array<string, mixed>}>
     */
    public static function numericProvider(): iterable
    {
        yield from [
            'exclusive and inclusive' => [['gt:0', 'lte:100'], 'number', ['maximum' => 100, 'exclusiveMinimum' => 0]],
            'inclusive comparisons'   => [['gte:-2', 'lt:9.5'], 'integer', ['minimum' => -2, 'exclusiveMaximum' => 9.5]],
            'tightest exclusive'      => [['gt:4', 'gt:1', 'lt:3', 'lt:8'], 'integer', ['exclusiveMinimum' => 4, 'exclusiveMaximum' => 3]],
            'string comparison'       => [['gt:3', 'gte:1', 'lt:9', 'lte:8'], 'string', []],
            'array comparison'        => [['gte:1'], 'array', []],
            'field comparison'        => [['gt:other', 'gte:other', 'lt:other', 'lte:other'], 'integer', []],
            'multiple of fraction'    => [['multiple_of:0.5'], 'number', ['multipleOf' => 0.5]],
            'multiple of negative'    => [['multiple_of:-3'], 'integer', ['multipleOf' => 3]],
            'multiple of zero'        => [['multiple_of:0'], 'integer', []],
            'multiple of field'       => [['multiple_of:other'], 'integer', []],
            'multiple of string'      => [['multiple_of:2'], 'string', []],
            'first multiple of'       => [['multiple_of:4', 'multiple_of:6'], 'integer', ['multipleOf' => 4]],
            'integer digits'          => [['digits:4'], 'integer', ['minimum' => 1000, 'maximum' => 9999]],
            'number digits'           => [['digits:2'], 'number', ['minimum' => 10, 'maximum' => 99]],
            'single digit'            => [['digits:1'], 'integer', ['minimum' => 0, 'maximum' => 9]],
            'most digits'             => [['digits:18'], 'integer', ['minimum' => 100000000000000000, 'maximum' => 999999999999999999]],
            'too many digits'         => [['digits:19'], 'integer', []],
            'twenty digits'           => [['digits:20'], 'integer', []],
            'malformed digits'        => [['digits:x'], 'integer', []],
            'string digits'           => [['digits:4', 'digits_between:2,3'], 'string', []],
            'digits and bounds'       => [['digits:4', 'min:1', 'max:5000'], 'integer', ['minimum' => 1000, 'maximum' => 5000]],
            'digits between'          => [['digits_between:2,3'], 'integer', ['minimum' => 10, 'maximum' => 999]],
            'digits between one'      => [['digits_between:2'], 'integer', ['minimum' => 10]],
            'digits between too many' => [['digits_between:1,20'], 'integer', ['minimum' => 0]],
        ];
    }

    /**
     * Test that a numeric field's comparisons, divisor and digits bound it.
     *
     * @param  list<string>  $tokens
     * @param  string  $type
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('numericProvider')]
    public function testNumericRulesBoundTheValue(array $tokens, string $type, array $expected): void
    {
        self::assertSame($expected, (new RangeConstraintBuilder)->build($tokens, $type));
    }
}
