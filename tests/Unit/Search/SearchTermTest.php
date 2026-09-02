<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Support\Facades\Config;
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
    /** @var string The whole rejection message for a term carrying a word below the minimum length */
    private const string TOO_SHORT = 'Every word in the search term must be at least 3 characters.';

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
        yield 'collapsed runs' => ['john    smith', 'john smith'];
        yield 'newline separates' => ["john\nsmith", 'john smith'];
        yield 'tab separates' => ["john\tsmith", 'john smith'];
        yield 'no-break space' => ["john\u{00A0}smith", 'john smith'];
        yield 'control stripped' => ["jo\x00hn smith", 'john smith'];
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
     * Test that a multi-word term is measured word by word, at exactly the
     * minimum and one character below it: the bound is what each engine
     * answers, and a word beneath it is dropped from the phrase by one engine
     * and read out of the whole table by the other.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testEveryWordOfAMultiWordTermIsMeasuredAgainstTheMinimum(): void
    {
        self::assertSame('abc def', SearchTerm::from('abc def')->value());

        $this->assertRejects('abc de', self::TOO_SHORT);
    }

    /**
     * Test that a term long enough overall is still rejected when one of its
     * words is not, so the two engines cannot answer it with different rows.
     *
     * @return void
     */
    public function testShortWordIsRejectedEvenWhereTheWholeTermClearsTheMinimum(): void
    {
        $this->assertRejects('a bc', self::TOO_SHORT);
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
     * Test that the minimum length counts characters rather than bytes: a
     * three-character multibyte term is accepted, and a two-character one is
     * rejected even though its bytes would clear the bound.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testMinimumLengthCountsCharactersNotBytes(): void
    {
        self::assertSame('ábç', SearchTerm::from('ábç')->value());

        $this->assertRejects('áb', self::TOO_SHORT);
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
     * Test that a configured minimum above the shipped floor is enforced, at
     * exactly the configured length and one character below it.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testConfiguredMinimumLengthAboveTheFloorIsEnforced(): void
    {
        Config::set('api-toolkit.search.min_word_length', 5);

        self::assertSame('abcde', SearchTerm::from('abcde')->value());
        $this->assertRejects('abcd', 'Every word in the search term must be at least 5 characters.');
    }

    /**
     * Test that a configured minimum below the shipped floor is held at the
     * floor, so the measured bound cannot be configured away.
     *
     * @return void
     */
    public function testConfiguredMinimumLengthBelowTheFloorIsHeldAtTheFloor(): void
    {
        Config::set('api-toolkit.search.min_word_length', 1);

        $this->assertRejects('ab', self::TOO_SHORT);
    }

    /**
     * Test that a configured maximum length is enforced, at exactly the
     * configured length and one character above it.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testConfiguredMaximumLengthIsEnforced(): void
    {
        Config::set('api-toolkit.search.max_length', 4);

        self::assertSame('abcd', SearchTerm::from('abcd')->value());
        $this->assertRejects('abcde', 'The search term may not be longer than 4 characters.');
    }

    /**
     * Test that a configured word cap is enforced, at exactly the configured
     * count and one word beyond it.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testConfiguredWordCapIsEnforced(): void
    {
        Config::set('api-toolkit.search.max_words', 2);

        self::assertSame('abc abc', SearchTerm::from('abc abc')->value());
        $this->assertRejects('abc abc abc', 'The search term may not carry more than 2 words.');
    }

    /**
     * Test that a fractional bound is measured as the whole number it truncates
     * to, so a term of exactly that many characters is accepted.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testFractionalBoundIsTruncatedToAWholeNumber(): void
    {
        Config::set('api-toolkit.search.min_word_length', '4.7');

        self::assertSame('abcd', SearchTerm::from('abcd')->value());
    }

    /**
     * Test that a bound configured as something other than a number falls back
     * to the shipped default rather than to nothing at all.
     *
     * @return void
     */
    public function testNonNumericBoundFallsBackToTheShippedDefault(): void
    {
        Config::set('api-toolkit.search.max_length', 'unbounded');

        $this->assertRejects(str_repeat('a', SearchTerm::MAX_LENGTH + 1), self::TOO_LONG);
    }

    /**
     * Provide each strategy with the pattern a term of "50%" renders to.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\SearchStrategy, string}>
     */
    public static function patternProvider(): iterable
    {
        yield 'exact' => [SearchStrategy::EXACT, '50%'];
        yield 'prefix' => [SearchStrategy::PREFIX, '50\%%'];
        yield 'substring' => [SearchStrategy::SUBSTRING, '%50\%%'];
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
     * Provide terms carrying a metacharacter with the substring pattern they
     * render to.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function escapingProvider(): iterable
    {
        yield 'percent' => ['a%b', '%a\%b%'];
        yield 'underscore' => ['a_b', '%a\_b%'];
        yield 'escape character' => ['a\b', '%a\\\b%'];
        yield 'only wildcards' => ['%%%', '%\%\%\%%'];
        yield 'nothing to escape' => ['smith', '%smith%'];
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
        self::assertSame('%a\\\\\%b%', SearchTerm::from('a\%b')->pattern(SearchStrategy::SUBSTRING));
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
     * Test that a double quote carried by the term is dropped once, during
     * normalisation, so it can neither end the phrase it is wrapped in nor
     * leave one rendering matching a character the other has thrown away.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testDropsThePhraseDelimiterFromEveryRendering(): void
    {
        $term = SearchTerm::from('ab"cd smith');

        self::assertSame('abcd smith', $term->value());
        self::assertSame('"abcd smith"', $term->phrase());
        self::assertSame('%abcd smith%', $term->pattern(SearchStrategy::SUBSTRING));
    }

    /**
     * Test that a term of nothing but delimiters normalises to nothing and is
     * rejected, rather than reaching an engine as a phrase matching no rows.
     *
     * @return void
     */
    public function testTermOfOnlyDelimitersIsRejected(): void
    {
        $this->assertRejects('"""', self::TOO_SHORT);
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
