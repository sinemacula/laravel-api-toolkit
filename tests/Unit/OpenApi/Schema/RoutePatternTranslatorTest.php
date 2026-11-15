<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;

/**
 * Tests for the route pattern translator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RoutePatternTranslator::class)]
final class RoutePatternTranslatorTest extends TestCase
{
    /**
     * Provide each constraint helper and the schema its pattern translates to.
     *
     * @return iterable<string, array{0: \Closure(\Illuminate\Routing\Route): \Illuminate\Routing\Route, 1: array<string, mixed>}>
     */
    public static function helperProvider(): iterable
    {
        yield 'uuid' => [static fn (Route $route): Route => $route->whereUuid('p'), ['type' => 'string', 'format' => 'uuid']];
        yield 'number' => [static fn (Route $route): Route => $route->whereNumber('p'), ['type' => 'integer', 'minimum' => 0]];
        yield 'in' => [static fn (Route $route): Route => $route->whereIn('p', ['draft', 'published']), ['type' => 'string', 'enum' => ['draft', 'published']]];
        yield 'alpha' => [static fn (Route $route): Route => $route->whereAlpha('p'), ['type' => 'string', 'pattern' => '^[a-zA-Z]+$']];
        yield 'alphanumeric' => [static fn (Route $route): Route => $route->whereAlphaNumeric('p'), ['type' => 'string', 'pattern' => '^[a-zA-Z0-9]+$']];
        yield 'ulid' => [static fn (Route $route): Route => $route->whereUlid('p'), ['type' => 'string', 'pattern' => '^[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}$']];
    }

    /**
     * Test that each constraint helper's pattern translates to its schema.
     *
     * @param  \Closure(\Illuminate\Routing\Route): \Illuminate\Routing\Route  $helper
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('helperProvider')]
    public function testTranslatesEachConstraintHelper(\Closure $helper, array $expected): void
    {
        $pattern = $helper(new Route('GET', '{p}', []))->wheres['p'];

        self::assertIsString($pattern);
        self::assertSame($expected, (new RoutePatternTranslator)->translate($pattern));
    }

    /**
     * Provide constraints whose anchors the route compiler strips.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>|null}>
     */
    public static function anchorProvider(): iterable
    {
        yield 'caret and dollar' => ['^[0-9]+$', ['type' => 'integer', 'minimum' => 0]];
        yield 'string anchors' => ['\A[0-9]+\z', ['type' => 'integer', 'minimum' => 0]];
        yield 'caret only' => ['^[0-9]+', ['type' => 'integer', 'minimum' => 0]];
        yield 'end anchor only' => ['[0-9]+\z', ['type' => 'integer', 'minimum' => 0]];
        yield 'one caret stripped' => ['^^a', null];
        yield 'inner end anchor kept' => ['a\zb', null];
        yield 'escaped dollar stripped' => ['a\$', null];
        yield 'nothing but anchors' => ['^$', null];
        yield 'empty' => ['', null];
    }

    /**
     * Test that one leading and one trailing anchor are stripped first.
     *
     * @param  string  $pattern
     * @param  array<string, mixed>|null  $expected
     * @return void
     */
    #[DataProvider('anchorProvider')]
    public function testStripsAnchorsAsTheRouteCompilerDoes(string $pattern, ?array $expected): void
    {
        self::assertSame($expected, (new RoutePatternTranslator)->translate($pattern));
    }

    /**
     * Provide literal alternations and the enum each lists.
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function literalProvider(): iterable
    {
        yield 'single literal' => ['draft', ['draft']];
        yield 'alternation' => ['draft|published', ['draft', 'published']];
        yield 'duplicates collapse in order' => ['b|b|a|b', ['b', 'a']];
        yield 'numbers stay strings' => ['1|01', ['1', '01']];
        yield 'punctuation literal' => ['en-gb|fr_fr', ['en-gb', 'fr_fr']];
    }

    /**
     * Test that a list of literal alternatives becomes a string enum.
     *
     * @param  string  $pattern
     * @param  list<string>  $enum
     * @return void
     */
    #[DataProvider('literalProvider')]
    public function testLiteralAlternativesBecomeAnEnum(string $pattern, array $enum): void
    {
        self::assertSame(['type' => 'string', 'enum' => $enum], (new RoutePatternTranslator)->translate($pattern));
    }

    /**
     * Provide portable patterns and the anchored pattern each exports.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function portableProvider(): iterable
    {
        yield 'digit class' => ['[0-9]{4}', '^[0-9]{4}$'];
        yield 'bounded class' => ['[a-z-]{3,8}', '^[a-z-]{3,8}$'];
        yield 'open bound' => ['a{2,}', '^a{2,}$'];
        yield 'exact bound' => ['a{2}', '^a{2}$'];
        yield 'escaped dot' => ['\.json', '^\.json$'];
        yield 'lazy group' => ['(?:ab)+?', '^(?:ab)+?$'];
        yield 'negated class' => ['[^/]+', '^[^/]+$'];
        yield 'capturing group' => ['(ab)*', '^(ab)*$'];
        yield 'class escapes' => ['[\-\]\\\]', '^[\-\]\\\]$'];
        yield 'escaped slash' => ['a\/b', '^a\/b$'];
        yield 'metacharacter alternative' => ['v1\.0|v2\.0', '^(?:v1\.0|v2\.0)$'];
        yield 'empty alternative' => ['a|', '^(?:a|)$'];
        yield 'alternation' => ['v[0-9]+|latest', '^(?:v[0-9]+|latest)$'];
        yield 'nested groups' => ['((a)|b)?', '^(?:((a)|b)?)$'];
        yield 'empty group' => ['a()', '^a()$'];
    }

    /**
     * Test that a pattern inside the portable subset is exported anchored.
     *
     * @param  string  $pattern
     * @param  string  $exported
     * @return void
     */
    #[DataProvider('portableProvider')]
    public function testPortablePatternIsExported(string $pattern, string $exported): void
    {
        self::assertSame(['type' => 'string', 'pattern' => $exported], (new RoutePatternTranslator)->translate($pattern));
    }

    /**
     * Provide patterns outside the portable subset, one per rejected construct.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'unicode digit' => ['\d+'];
        yield 'unicode word' => ['\w+'];
        yield 'unicode space' => ['\s'];
        yield 'word boundary' => ['\b'];
        yield 'unicode property' => ['\p{L}+'];
        yield 'hex escape' => ['\x41'];
        yield 'back-reference' => ['(a)\1'];
        yield 'verb' => ['(*UTF)a'];
        yield 'inline flag' => ['(?i)a'];
        yield 'atomic group' => ['(?>a)'];
        yield 'named group' => ['(?<n>a)'];
        yield 'lookahead' => ['(?=a)a'];
        yield 'lookbehind' => ['(?<=/)a'];
        yield 'possessive quantifier' => ['a++'];
        yield 'posix class' => ['[[:alpha:]]+'];
        yield 'class opening with bracket' => ['[]a]'];
        yield 'negated class opening with bracket' => ['[^]a]'];
        yield 'negated class holding only a bracket' => ['[^]a[b]'];
        yield 'spaced quantifier' => ['a{ 2}'];
        yield 'non-numeric quantifier' => ['a{x}'];
        yield 'inner anchor' => ['a^b'];
        yield 'dot' => ['v.+'];
        yield 'dash escape outside class' => ['a\-b'];
        yield 'unclosed class' => ['[ab'];
        yield 'stray bracket' => ['a]'];
        yield 'stray brace' => ['a}'];
        yield 'leading quantifier' => ['+a'];
        yield 'quantifier after alternation' => ['a|*b'];
        yield 'quantifier after group open' => ['(+a)'];
        yield 'stacked quantifiers' => ['a{2}{3}'];
        yield 'doubled lazy quantifier' => ['a???'];
        yield 'unopened group' => ['a)'];
        yield 'unclosed group' => ['(a'];
        yield 'group closed before opened' => ['a)(b'];
        yield 'trailing escape' => ['a\\'];
        yield 'invalid utf-8' => ["[\xFF]"];
    }

    /**
     * Test that a pattern outside the portable subset yields no schema.
     *
     * @param  string  $pattern
     * @return void
     */
    #[DataProvider('rejectedProvider')]
    public function testPatternOutsideThePortableSubsetYieldsNothing(string $pattern): void
    {
        self::assertNull((new RoutePatternTranslator)->translate($pattern));
    }

    /**
     * Test that the UUID schema is the string uuid format.
     *
     * @return void
     */
    public function testUuidSchemaIsTheUuidFormat(): void
    {
        self::assertSame(['type' => 'string', 'format' => 'uuid'], (new RoutePatternTranslator)->uuidSchema());
    }

    /**
     * Test that the ULID schema anchors the pattern whereUlid applies.
     *
     * @return void
     */
    public function testUlidSchemaAnchorsTheUlidPattern(): void
    {
        self::assertSame(
            ['type' => 'string', 'pattern' => '^[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}$'],
            (new RoutePatternTranslator)->ulidSchema(),
        );
    }
}
