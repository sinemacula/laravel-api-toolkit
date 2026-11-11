<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Normalised and bounded free-text search term.
 *
 * Escapes wildcards and drops double quotes, which no rendering can carry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SearchTerm
{
    /** @var int The shortest word every supported index serves without scanning; also the floor */
    public const int MIN_WORD_LENGTH = 3;

    /** @var int The shipped longest term accepted, bounding the work one search may ask for */
    public const int MAX_LENGTH = 128;

    /** @var int The shipped ceiling on the whitespace-separated words one term may carry */
    public const int MAX_WORDS = 10;

    /** @var string Escapes wildcards; unlike a backslash, no string literal treats it specially */
    public const string ESCAPE_CHARACTER = '!';

    /** @var string The setting naming the shortest word a term may carry */
    public const string MIN_WORD_LENGTH_KEY = 'min_word_length';

    /** @var string The setting naming the longest term a search may carry */
    public const string MAX_LENGTH_KEY = 'max_length';

    /** @var string The setting naming the most words a term may carry */
    public const string MAX_WORDS_KEY = 'max_words';

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
     * Return the shortest word a term may carry, never below the floor.
     *
     * @return int
     */
    public static function minimumWordLength(): int
    {
        return max(self::MIN_WORD_LENGTH, self::configured(self::MIN_WORD_LENGTH_KEY, self::MIN_WORD_LENGTH));
    }

    /**
     * Return the longest term a search may carry.
     *
     * @return int
     */
    public static function maximumLength(): int
    {
        return self::configured(self::MAX_LENGTH_KEY, self::MAX_LENGTH);
    }

    /**
     * Return the most whitespace-separated words a search term may carry.
     *
     * @return int
     */
    public static function maximumWords(): int
    {
        return self::configured(self::MAX_WORDS_KEY, self::MAX_WORDS);
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
     * Quotes are stripped on parse, so nothing can close the phrase early.
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
     * Whitespace collapses first, so a newline still separates two words.
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
