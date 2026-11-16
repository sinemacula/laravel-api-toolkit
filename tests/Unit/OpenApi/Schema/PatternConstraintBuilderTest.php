<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\PatternConstraintBuilder;
use Tests\TestCase;

/**
 * Tests for the pattern-constraint builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PatternConstraintBuilder::class)]
final class PatternConstraintBuilderTest extends TestCase
{
    /**
     * Provide string-matching rules with the pattern keywords they export.
     *
     * @return iterable<string, array{0: list<string>, 1: string|null, 2: array<string, mixed>}>
     */
    public static function patternProvider(): iterable
    {
        yield from [
            'no pattern rule'        => [['string'], 'string', []],
            'regex'                  => [['regex:/^a$/'], null, ['pattern' => '^a$']],
            'regex two char pairs'   => [['regex://'], null, ['pattern' => '']],
            'regex dotall newline'   => [["regex:/a\nb/"], null, ['pattern' => "a\nb"]],
            'regex no delimiters'    => [['regex:a'], null, ['pattern' => 'a']],
            'regex caret anchored'   => [['regex:a/b/'], null, ['pattern' => 'a/b/']],
            'regex brace delimiters' => [['regex:{^a{2}$}'], null, ['pattern' => '^a{2}$']],
            'regex paren delimiters' => [['regex:(a|b)'], null, ['pattern' => 'a|b']],
            'regex square delimiter' => [['regex:[a]u'], null, ['pattern' => 'a']],
            'regex angle delimiters' => [['regex:<a>'], null, ['pattern' => 'a']],
            'regex bracket modifier' => [['regex:{a}i'], null, []],
            'regex leading space'    => [["regex: \t/^a$/"], null, ['pattern' => '^a$']],
            'regex modifier spacing' => [["regex:/^a$/u \r\n"], null, ['pattern' => '^a$']],
            'regex spaced modifier'  => [['regex:/^a$/ i'], null, []],
            'digits after malformed' => [['digits:x', 'digits:4'], null, ['pattern' => '^[0-9]{4}$']],
            'range after malformed'  => [['digits_between:4,2', 'digits_between:1,2'], null, ['pattern' => '^[0-9]{1,2}$']],
            'alpha after other rule' => [['string', 'alpha'], 'string', ['pattern' => '^[\p{L}\p{M}]+$']],
            'regex dollar trailer'   => [['regex:/abc/;'], null, ['pattern' => '/abc/;']],
            'regex modifier drops'   => [['regex:/x/i'], null, []],
            'regex unicode kept'     => [['regex:/x/u'], null, ['pattern' => 'x']],
            'regex portable pair'    => [['regex:/x/Du'], null, ['pattern' => 'x']],
            'regex mixed modifier'   => [['regex:/x/ui'], null, []],
            'regex after a drop'     => [['regex:/x/i', 'regex:/y/'], null, ['pattern' => 'y']],
            'regex every token'      => [['regex:/a/', 'regex:/b/'], null, ['allOf' => [['pattern' => 'a'], ['pattern' => 'b']]]],
            'regex and affix'        => [['regex:/^a/', 'starts_with:b'], null, ['allOf' => [['pattern' => '^a'], ['pattern' => '^(?:b)']]]],
            'patterns deduplicate'   => [['alpha', 'alpha'], null, ['pattern' => '^[\p{L}\p{M}]+$']],
            'duplicates leave list'  => [['regex:/a/', 'regex:/a/', 'regex:/b/'], null, ['allOf' => [['pattern' => 'a'], ['pattern' => 'b']]]],
            'digits'                 => [['digits:4'], null, ['pattern' => '^[0-9]{4}$']],
            'string digits between'  => [['digits_between:2,4'], 'string', ['pattern' => '^[0-9]{2,4}$']],
            'equal digits between'   => [['digits_between:3,3'], null, ['pattern' => '^[0-9]{3,3}$']],
            'reversed digits'        => [['digits_between:4,2'], null, []],
            'malformed digits'       => [['digits:x', 'digits_between:2', 'digits_between:a,3', 'digits_between:2,b'], null, []],
            'two digit patterns'     => [['digits:4', 'digits_between:2,3'], null, ['allOf' => [['pattern' => '^[0-9]{4}$'], ['pattern' => '^[0-9]{2,3}$']]]],
            'integer digits'         => [['digits:4'], 'integer', []],
            'number digits'          => [['digits_between:2,3'], 'number', []],
            'starts with'            => [['starts_with:a.b,c'], null, ['pattern' => '^(?:a\.b|c)']],
            'ends with'              => [['ends_with:x,"y,z"'], 'string', ['pattern' => '(?:x|y,z)$']],
            'affix empty values'     => [['starts_with:,a,', 'ends_with:'], null, ['pattern' => '^(?:a)']],
            'affix only empty'       => [['starts_with:,'], null, []],
            'affix after empty'      => [['starts_with:,', 'starts_with:b'], null, ['pattern' => '^(?:b)']],
            'affix syntax escaped'   => [['starts_with:^$\.*+?()[]{}|/-'], null, ['pattern' => '^(?:\^\$\\\\\.\*\+\?\(\)\[\]\{\}\|/-)']],
            'two affix patterns'     => [['starts_with:a', 'ends_with:b'], null, ['allOf' => [['pattern' => '^(?:a)'], ['pattern' => '(?:b)$']]]],
            'alpha'                  => [['alpha'], null, ['pattern' => '^[\p{L}\p{M}]+$']],
            'alpha ascii'            => [['alpha:ascii'], null, ['pattern' => '^[a-zA-Z]+$']],
            'alpha num'              => [['alpha_num'], null, ['pattern' => '^[\p{L}\p{M}\p{N}]+$']],
            'alpha num ascii'        => [['alpha_num:ascii'], null, ['pattern' => '^[a-zA-Z0-9]+$']],
            'alpha dash'             => [['alpha_dash'], null, ['pattern' => '^[\p{L}\p{M}\p{N}_-]+$']],
            'alpha dash ascii'       => [['alpha_dash:ascii,x'], 'string', ['pattern' => '^[a-zA-Z0-9_-]+$']],
            'alpha other argument'   => [['alpha:other'], null, ['pattern' => '^[\p{L}\p{M}]+$']],
            'alpha colon argument'   => [['alpha:ascii:x'], null, ['pattern' => '^[\p{L}\p{M}]+$']],
            'two alpha patterns'     => [['alpha', 'alpha_num:ascii'], null, ['allOf' => [['pattern' => '^[\p{L}\p{M}]+$'], ['pattern' => '^[a-zA-Z0-9]+$']]]],
        ];
    }

    /**
     * Test that the string-matching rules export the patterns they apply.
     *
     * @param  list<string>  $tokens
     * @param  string|null  $type
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('patternProvider')]
    public function testStringMatchingRulesExportTheirPatterns(array $tokens, ?string $type, array $expected): void
    {
        self::assertSame($expected, (new PatternConstraintBuilder)->build($tokens, $type));
    }
}
