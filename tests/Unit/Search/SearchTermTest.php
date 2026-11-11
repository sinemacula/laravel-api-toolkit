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
     * Test that a raw term is trimmed, collapsed and normalised.
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
     * Test that a term one character below the minimum is rejected.
     *
     * @return void
     */
    public function testTermBelowTheMinimumLengthIsRejected(): void
    {
        $this->assertRejects('ab', self::TOO_SHORT);
    }

    /**
     * Test that each word of a multi-word term is measured against the minimum.
     *
     * Engines disagree on a short word: one drops it, another scans the table.
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
     * Test that a long enough term is rejected when one word is too short.
     *
     * @return void
     */
    public function testShortWordIsRejectedEvenWhereTheWholeTermClearsTheMinimum(): void
    {
        $this->assertRejects('a bc', self::TOO_SHORT);
    }

    /**
     * Test that a whitespace-only term is rejected on length.
     *
     * @return void
     */
    public function testWhitespaceOnlyTermIsRejected(): void
    {
        $this->assertRejects("  \t \n ", self::TOO_SHORT);
    }

    /**
     * Test that a term that is not valid UTF-8 is rejected.
     *
     * @return void
     */
    public function testInvalidUtf8TermIsRejected(): void
    {
        $this->assertRejects("\xB1\x31smith", self::TOO_SHORT);
    }

    /**
     * Test that the minimum length counts characters, not bytes.
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
     * Test that a term of exactly the maximum number of words is accepted.
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
     * Test that a configured minimum above the shipped floor is enforced.
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
     * Test that a configured minimum below the shipped floor is held at it.
     *
     * @return void
     */
    public function testConfiguredMinimumLengthBelowTheFloorIsHeldAtTheFloor(): void
    {
        Config::set('api-toolkit.search.min_word_length', 1);

        $this->assertRejects('ab', self::TOO_SHORT);
    }

    /**
     * Test that a configured maximum length is enforced.
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
     * Test that a configured word cap is enforced.
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
     * Test that a fractional bound is truncated to a whole number.
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
     * Test that a non-numeric bound falls back to the shipped default.
     *
     * @return void
     */
    public function testNonNumericBoundFallsBackToTheShippedDefault(): void
    {
        Config::set('api-toolkit.search.max_length', 'unbounded');

        $this->assertRejects(str_repeat('a', SearchTerm::MAX_LENGTH + 1), self::TOO_LONG);
    }

    /**
     * Test that the maximum length is reported as the configured value.
     *
     * @return void
     */
    public function testMaximumLengthReportsTheConfiguredValue(): void
    {
        Config::set('api-toolkit.search.max_length', 64);

        self::assertSame(64, SearchTerm::maximumLength());
    }

    /**
     * Test that the maximum length falls back to the shipped default.
     *
     * @return void
     */
    public function testMaximumLengthFallsBackToTheShippedDefault(): void
    {
        Config::set('api-toolkit.search.max_length', 'unbounded');

        self::assertSame(SearchTerm::MAX_LENGTH, SearchTerm::maximumLength());
    }

    /**
     * Test that the maximum word count is reported as the configured value.
     *
     * @return void
     */
    public function testMaximumWordsReportsTheConfiguredValue(): void
    {
        Config::set('api-toolkit.search.max_words', 4);

        self::assertSame(4, SearchTerm::maximumWords());
    }

    /**
     * Test that the maximum word count falls back to the shipped default.
     *
     * @return void
     */
    public function testMaximumWordsFallsBackToTheShippedDefault(): void
    {
        Config::set('api-toolkit.search.max_words', 'unbounded');

        self::assertSame(SearchTerm::MAX_WORDS, SearchTerm::maximumWords());
    }

    /**
     * Test that the minimum word length is reported as the configured value.
     *
     * @return void
     */
    public function testMinimumWordLengthReportsTheConfiguredValue(): void
    {
        Config::set('api-toolkit.search.min_word_length', 5);

        self::assertSame(5, SearchTerm::minimumWordLength());
    }

    /**
     * Test that the reported minimum word length is held at the shipped floor.
     *
     * @return void
     */
    public function testMinimumWordLengthIsHeldAtTheShippedFloor(): void
    {
        Config::set('api-toolkit.search.min_word_length', 1);

        self::assertSame(SearchTerm::MIN_WORD_LENGTH, SearchTerm::minimumWordLength());
    }

    /**
     * Test that the minimum word length falls back to the shipped default.
     *
     * @return void
     */
    public function testMinimumWordLengthFallsBackToTheShippedDefault(): void
    {
        Config::set('api-toolkit.search.min_word_length', 'unbounded');

        self::assertSame(SearchTerm::MIN_WORD_LENGTH, SearchTerm::minimumWordLength());
    }

    /**
     * Provide each strategy with the pattern a term of "50%" renders to.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\SearchStrategy, string}>
     */
    public static function patternProvider(): iterable
    {
        yield 'exact' => [SearchStrategy::EXACT, '50%'];
        yield 'prefix' => [SearchStrategy::PREFIX, '50!%%'];
        yield 'substring' => [SearchStrategy::SUBSTRING, '%50!%%'];
    }

    /**
     * Test that each strategy renders the term for the match it performs.
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
     * Provide raw terms with the substring pattern each renders to.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function escapingProvider(): iterable
    {
        yield 'percent' => ['a%b', '%a!%b%'];
        yield 'underscore' => ['a_b', '%a!_b%'];
        yield 'escape character' => ['a!b', '%a!!b%'];
        yield 'only wildcards' => ['%%%', '%!%!%!%%'];
        yield 'backslash' => ['a\b', '%a\b%'];
        yield 'nothing to escape' => ['smith', '%smith%'];
    }

    /**
     * Test that every pattern metacharacter is escaped.
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
     * Test that the escape character is escaped before the wildcards.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testEscapesTheEscapeCharacterBeforeTheWildcards(): void
    {
        self::assertSame('%a!!!%b%', SearchTerm::from('a!%b')->pattern(SearchStrategy::SUBSTRING));
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
     * Test that a double quote is dropped from every rendering.
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
     * Test that a term of nothing but phrase delimiters is rejected.
     *
     * @return void
     */
    public function testTermOfOnlyDelimitersIsRejected(): void
    {
        $this->assertRejects('"""', self::TOO_SHORT);
    }

    /**
     * Test that the escape character is one a quoted literal carries literally.
     *
     * @return void
     */
    public function testEscapeCharacterIsCarriedLiterallyByAQuotedLiteral(): void
    {
        self::assertSame('!', SearchTerm::ESCAPE_CHARACTER);
    }

    /**
     * Assert that the raw term is rejected with the whole message under search.
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
