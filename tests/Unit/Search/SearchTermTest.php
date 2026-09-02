<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\TestCase;

/**
 * Tests for the SearchTerm value object.
 *
 * Every bound is exercised at exactly its limit and one beyond it, and every
 * metacharacter a rendering could smuggle is asserted through the rendering
 * that has to neutralise it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SearchTerm::class)]
final class SearchTermTest extends TestCase
{
    /** @var string The whole rejection message for a term below the minimum length */
    private const string TOO_SHORT = 'The search term must be at least 3 characters.';

    /** @var string The whole rejection message for a term above the maximum length */
    private const string TOO_LONG = 'The search term may not be longer than 128 characters.';

    /** @var string The whole rejection message for a term carrying too many words */
    private const string TOO_MANY_WORDS = 'The search term may not carry more than 10 words.';

    /**
     * Provide raw terms with the normalised value they reduce to.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function normalisationProvider(): iterable
    {
        yield 'surrounding whitespace' => ['   smith   ', 'smith'];
        yield 'collapsed runs'         => ['john    smith', 'john smith'];
        yield 'newline separates'      => ["john\nsmith", 'john smith'];
        yield 'tab separates'          => ["john\tsmith", 'john smith'];
        yield 'no-break space'         => ["john\u{00A0}smith", 'john smith'];
        yield 'control stripped'       => ["jo\x00hn smith", 'john smith'];
    }

    /**
     * Provide each strategy with the pattern a term of "50%" renders to.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\SearchStrategy, string}>
     */
    public static function patternProvider(): iterable
    {
        yield 'exact'     => [SearchStrategy::EXACT, '50%'];
        yield 'prefix'    => [SearchStrategy::PREFIX, '50\\%%'];
        yield 'substring' => [SearchStrategy::SUBSTRING, '%50\\%%'];
    }

    /**
     * Provide terms carrying a metacharacter with the substring pattern they
     * render to.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function escapingProvider(): iterable
    {
        yield 'percent'          => ['a%b', '%a\\%b%'];
        yield 'underscore'       => ['a_b', '%a\\_b%'];
        yield 'escape character' => ['a\\b', '%a\\\\b%'];
        yield 'only wildcards'   => ['%%%', '%\\%\\%\\%%'];
        yield 'nothing to escape' => ['smith', '%smith%'];
    }

    /**
     * Test that a raw term is trimmed, collapsed, and stripped of the
     * characters no engine can match on.
     *
     * @param  string  $raw
     * @param  string  $expected
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    #[DataProvider('normalisationProvider')]
    public function testNormalisesTheRawTerm(string $raw, string $expected): void
    {
        self::assertSame($expected, SearchTerm::from($raw)->value());
    }

    /**
     * Test that a term of exactly the minimum length is accepted.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testTermAtTheMinimumLengthIsAccepted(): void
    {
        self::assertSame('abc', SearchTerm::from('abc')->value());
    }

    /**
     * Test that a term one character below the minimum is rejected, carrying
     * the whole rejection message under the search parameter.
     *
     * @return void
     */
    public function testTermBelowTheMinimumLengthIsRejected(): void
    {
        $this->assertRejects('ab', self::TOO_SHORT);
    }

    /**
     * Test that a term of only whitespace normalises to nothing and is rejected
     * on length rather than reaching a driver.
     *
     * @return void
     */
    public function testWhitespaceOnlyTermIsRejected(): void
    {
        $this->assertRejects("  \t \n ", self::TOO_SHORT);
    }

    /**
     * Test that a term that is not valid UTF-8 is rejected rather than reaching
     * a pattern as an unmatchable byte sequence.
     *
     * @return void
     */
    public function testInvalidUtf8TermIsRejected(): void
    {
        $this->assertRejects("\xB1\x31smith", self::TOO_SHORT);
    }

    /**
     * Test that the minimum length counts characters rather than bytes, so a
     * three-character multibyte term is accepted.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testMinimumLengthCountsCharactersNotBytes(): void
    {
        self::assertSame('ábç', SearchTerm::from('ábç')->value());
    }

    /**
     * Test that a term of exactly the maximum length is accepted.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testTermAtTheMaximumLengthIsAccepted(): void
    {
        $term = str_repeat('a', SearchTerm::MAX_LENGTH);

        self::assertSame($term, SearchTerm::from($term)->value());
    }

    /**
     * Test that a term one character above the maximum is rejected.
     *
     * @return void
     */
    public function testTermAboveTheMaximumLengthIsRejected(): void
    {
        $this->assertRejects(str_repeat('a', SearchTerm::MAX_LENGTH + 1), self::TOO_LONG);
    }

    /**
     * Test that a term carrying exactly the maximum number of words is
     * accepted.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testTermAtTheWordLimitIsAccepted(): void
    {
        $term = implode(' ', array_fill(0, SearchTerm::MAX_WORDS, 'abc'));

        self::assertSame($term, SearchTerm::from($term)->value());
    }

    /**
     * Test that a term carrying one word more than the maximum is rejected.
     *
     * @return void
     */
    public function testTermAboveTheWordLimitIsRejected(): void
    {
        $this->assertRejects(implode(' ', array_fill(0, SearchTerm::MAX_WORDS + 1, 'abc')), self::TOO_MANY_WORDS);
    }

    /**
     * Test that each strategy renders the term for the match it performs, with
     * only the pattern-matching strategies escaping the wildcard.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $expected
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    #[DataProvider('patternProvider')]
    public function testRendersThePatternForTheStrategy(SearchStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, SearchTerm::from('50%')->pattern($strategy));
    }

    /**
     * Test that every metacharacter a pattern reads is escaped, so a term of
     * wildcards matches those characters literally rather than every row.
     *
     * @param  string  $raw
     * @param  string  $expected
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    #[DataProvider('escapingProvider')]
    public function testEscapesEveryPatternMetacharacter(string $raw, string $expected): void
    {
        self::assertSame($expected, SearchTerm::from($raw)->pattern(SearchStrategy::SUBSTRING));
    }

    /**
     * Test that the escape character is itself escaped before the wildcards, so
     * an escaped wildcard cannot be reassembled from the term.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testEscapesTheEscapeCharacterBeforeTheWildcards(): void
    {
        self::assertSame('%a\\\\\\%b%', SearchTerm::from('a\\%b')->pattern(SearchStrategy::SUBSTRING));
    }

    /**
     * Test that the term is wrapped as a quoted phrase.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testRendersTheTermAsAQuotedPhrase(): void
    {
        self::assertSame('"john smith"', SearchTerm::from('john smith')->phrase());
    }

    /**
     * Test that a double quote carried by the term is dropped rather than
     * ending the phrase and leaving the remainder to be read as operators.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testPhraseDropsAnEmbeddedDoubleQuote(): void
    {
        self::assertSame('"ab smith"', SearchTerm::from('a"b smith')->phrase());
    }

    /**
     * Test that a term of nothing but delimiters yields an empty phrase, which
     * matches no rows rather than every row.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testPhraseOfOnlyDelimitersIsEmpty(): void
    {
        self::assertSame('""', SearchTerm::from('"""')->phrase());
    }

    /**
     * Test that the escape character is the single backslash the pattern
     * bindings and any ESCAPE clause have to agree on.
     *
     * @return void
     */
    public function testEscapeCharacterIsASingleBackslash(): void
    {
        self::assertSame('\\', SearchTerm::ESCAPE_CHARACTER);
    }

    /**
     * Assert that the raw term is rejected with the given whole message under
     * the search parameter.
     *
     * @param  string  $raw
     * @param  string  $message
     * @return void
     */
    private function assertRejects(string $raw, string $message): void
    {
        try {
            SearchTerm::from($raw);

            self::fail('Expected a rejection for the term "' . $raw . '".');
        } catch (ValidationException $exception) {
            self::assertSame([$message], $exception->errors()['search']);
        }
    }
}
