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
 * here: a term of "%" matches a literal percent sign rather than every row, and
 * a term carrying a double quote cannot close the phrase it is wrapped in.
 *
 * The bounds are refusals, never silent truncations, and each is operator
 * tunable. The minimum is the shortest term the supported indexes answer both
 * correctly and without scanning; below it one engine returns nothing at all
 * and another falls back to reading the whole table, and neither failure is
 * visible to the caller. Configuration may raise that minimum but never lower
 * it, so the floor cannot be configured back into the hazard it exists to
 * close.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SearchTerm
{
    /** @var int The shortest term every supported index answers correctly and without scanning, and the floor no configured minimum may fall below */
    public const int MIN_LENGTH = 3;

    /** @var int The shipped longest term accepted, bounding the work one search may ask for */
    public const int MAX_LENGTH = 128;

    /** @var int The shipped ceiling on the whitespace-separated words one term may carry */
    public const int MAX_WORDS = 10;

    /** @var string The character escaping a wildcard within a rendered pattern */
    public const string ESCAPE_CHARACTER = '\\';

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
        $length  = mb_strlen($value);
        $minimum = max(self::MIN_LENGTH, self::configured('min_length', self::MIN_LENGTH));
        $maximum = self::configured('max_length', self::MAX_LENGTH);
        $words   = self::configured('max_words', self::MAX_WORDS);

        if ($length < $minimum) {
            self::reject(sprintf('The search term must be at least %d characters.', $minimum));
        }

        if ($length > $maximum) {
            self::reject(sprintf('The search term may not be longer than %d characters.', $maximum));
        }

        if (count(explode(' ', $value)) > $words) {
            self::reject(sprintf('The search term may not carry more than %d words.', $words));
        }

        return new self($value);
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
     * The phrase syntax has no escape for its own delimiter, so a double quote
     * carried by the term is dropped rather than allowed to end the phrase
     * early and let the remainder be read as operators. A term of nothing but
     * delimiters therefore yields an empty phrase, which matches no rows.
     *
     * @return string
     */
    public function phrase(): string
    {
        return '"' . str_replace('"', '', $this->value) . '"';
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
     * Whitespace is collapsed before the control characters are removed, so a
     * newline separates two words rather than joining them. A term that is not
     * valid UTF-8 leaves nothing behind and is rejected on length.
     *
     * @param  string  $term
     * @return string
     */
    private static function normalise(string $term): string
    {
        $normalised = preg_replace(['/[\s\p{Z}]+/u', '/\p{C}/u'], [' ', ''], $term);

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
