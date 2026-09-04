<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Normalised and bounded free-text search term.
 *
 * Parses the raw client term once and renders it for whichever match shape a
 * driver applies, so no driver ever builds a pattern out of client input
 * itself. Every metacharacter the rendering could otherwise smuggle is escaped
 * here: a term of "%" matches a literal percent sign rather than every row. The
 * one character no rendering can carry is the double quote, which delimits the
 * phrase a full-text match is bound as and has no escape of its own; it is
 * dropped during normalisation, before any bound is measured, so every engine
 * is asked about the same term rather than one seeing it and another not.
 *
 * The escape character carries no meaning of its own inside a string literal,
 * so the ESCAPE clause naming it reads the same way on every engine and
 * whatever the connection is configured to make of a backslash. A backslash
 * there would escape the quote that closes the clause, running the literal on
 * into the comparison beside it and hiding the placeholder that comparison
 * binds.
 *
 * The bounds are refusals, never silent truncations, and each is operator
 * tunable. The minimum is applied to every word rather than to the term as a
 * whole, because that is the unit the indexes answer: a full-text parser emits
 * no token for a word shorter than its token size and drops it from the phrase,
 * which widens the match, while a trigram index cannot serve a chunk shorter
 * than a trigram and reads the whole table instead. A term whose words clear
 * the minimum is answered the same way by both. Configuration may raise that
 * minimum but never lower it, so the floor cannot be configured back into the
 * hazard it exists to close.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SearchTerm
{
    /** @var int The shortest word every supported index answers correctly and without scanning, and the floor no configured minimum may fall below */
    public const int MIN_WORD_LENGTH = 3;

    /** @var int The shipped longest term accepted, bounding the work one search may ask for */
    public const int MAX_LENGTH = 128;

    /** @var int The shipped ceiling on the whitespace-separated words one term may carry */
    public const int MAX_WORDS = 10;

    /** @var string The character escaping a wildcard within a rendered pattern, and one no string literal reads as anything but itself */
    public const string ESCAPE_CHARACTER = '!';

    /**
     * Constructor.
     *
     * @param  string  $value
     * @return void
     */
    private function __construct(

        /** The normalised term */
        private string $value,
    ) {}

    /**
     * Parse and validate a raw client term.
     *
     * @param  string  $term
     * @return self
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function from(string $term): self
    {
        $value   = self::normalise($term);
        $words   = explode(' ', $value);
        $minimum = self::minimumWordLength();
        $maximum = self::maximumLength();

        foreach ($words as $word) {

            if (mb_strlen($word) >= $minimum) {
                continue;
            }

            self::reject(sprintf('Every word in the search term must be at least %d characters.', $minimum));
        }

        if (mb_strlen($value) > $maximum) {
            self::reject(sprintf('The search term may not be longer than %d characters.', $maximum));
        }

        if (count($words) > ($ceiling = self::maximumWords())) {
            self::reject(sprintf('The search term may not carry more than %d words.', $ceiling));
        }

        return new self($value);
    }

    /**
     * Return the shortest word a search term may carry, held at the floor no
     * configured value may fall below.
     *
     * @return int
     */
    public static function minimumWordLength(): int
    {
        return max(self::MIN_WORD_LENGTH, self::configured('min_word_length', self::MIN_WORD_LENGTH));
    }

    /**
     * Return the longest term a search may carry.
     *
     * @return int
     */
    public static function maximumLength(): int
    {
        return self::configured('max_length', self::MAX_LENGTH);
    }

    /**
     * Return the most whitespace-separated words a search term may carry.
     *
     * @return int
     */
    public static function maximumWords(): int
    {
        return self::configured('max_words', self::MAX_WORDS);
    }

    /**
     * Return the normalised term.
     *
     * @return string
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Render the term for the given match strategy.
     *
     * A pattern-matching strategy receives the escaped term wrapped in its
     * wildcards; an equality strategy receives the term as it stands, since a
     * comparison reads no metacharacters.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return string
     */
    public function pattern(SearchStrategy $strategy): string
    {
        return $strategy->matchesByPattern()
            ? $strategy->wrapWildcards($this->escaped())
            : $this->value;
    }

    /**
     * Render the term as a quoted phrase for a boolean-mode match.
     *
     * The delimiter the phrase is wrapped in is already gone from the term, so
     * nothing here can close the phrase early and let the remainder be read as
     * operators.
     *
     * @return string
     */
    public function phrase(): string
    {
        return '"' . $this->value . '"';
    }

    /**
     * Resolve a configured bound, falling back to the shipped default.
     *
     * @param  string  $key
     * @param  int  $default
     * @return int
     */
    private static function configured(string $key, int $default): int
    {
        $value = Config::get('api-toolkit.search.' . $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Collapse whitespace and strip the characters no engine can match on.
     *
     * Whitespace is collapsed before the control characters and the phrase
     * delimiter are removed, so a newline separates two words rather than
     * joining them. A term that is not valid UTF-8 leaves nothing behind and is
     * rejected on length.
     *
     * @param  string  $term
     * @return string
     */
    private static function normalise(string $term): string
    {
        $normalised = preg_replace(['/[\s\p{Z}]+/u', '/[\p{C}"]/u'], [' ', ''], $term);

        return is_string($normalised) ? trim($normalised) : '';
    }

    /**
     * Escape the wildcards and the escape character carried by the term.
     *
     * @return string
     */
    private function escaped(): string
    {
        $escape = self::ESCAPE_CHARACTER;

        return str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $this->value,
        );
    }

    /**
     * Reject the term with a validation error naming the search parameter.
     *
     * @param  string  $message
     * @return never
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['search' => $message]);
    }
}
