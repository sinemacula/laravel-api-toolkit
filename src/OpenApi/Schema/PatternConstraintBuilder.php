<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Builds the pattern keywords of a field's string-matching rules.
 *
 * A regex is kept only when its modifiers leave its meaning unchanged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class PatternConstraintBuilder
{
    /** The types whose digit rules bound the value instead */
    private const array NUMERIC_TYPES = ['integer', 'number'];

    /** The character classes of the alpha rules, by rule and character set */
    private const array ALPHA_CLASSES = [
        'alpha'      => ['unicode' => '[\p{L}\p{M}]', 'ascii' => '[a-zA-Z]'],
        'alpha_num'  => ['unicode' => '[\p{L}\p{M}\p{N}]', 'ascii' => '[a-zA-Z0-9]'],
        'alpha_dash' => ['unicode' => '[\p{L}\p{M}\p{N}_-]', 'ascii' => '[a-zA-Z0-9_-]'],
    ];

    /** The regex modifiers under which a pattern keeps its meaning */
    private const string PORTABLE_MODIFIERS = 'uD';

    /** The whitespace PCRE skips among a regex's modifiers */
    private const string MODIFIER_SPACING = " \n\r";

    /** The whitespace PCRE skips before a regex's opening delimiter */
    private const string LEADING_SPACE = " \t\n\v\f\r";

    /** The bracket delimiters a regex may open with, mapped to their closers */
    private const array BRACKET_DELIMITERS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /** The pattern syntax characters a literal value must escape */
    private const string SYNTAX_CHARACTERS = '^$\.*+?()[]{}|';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RuleArguments  $arguments
     */
    public function __construct(

        /** The reader of the arguments the field's rules carry. */
        private RuleArguments $arguments = new RuleArguments,
    ) {}

    /**
     * Build the pattern keywords of the field's rules, combining several under
     * allOf.
     *
     * @param  list<string>  $tokens
     * @param  string|null  $type
     * @return array<string, mixed>
     */
    public function build(array $tokens, ?string $type): array
    {
        $patterns = array_values(array_unique([
            ...$this->regexPatterns($tokens),
            ...$this->digitPatterns($tokens, $type),
            ...$this->affixPatterns($tokens),
            ...$this->alphaPatterns($tokens),
        ]));

        return match (count($patterns)) {
            0       => [],
            1       => ['pattern' => $patterns[0]],
            default => ['allOf' => array_map(static fn (string $pattern): array => ['pattern' => $pattern], $patterns)],
        };
    }

    /**
     * Resolve the patterns of the regex tokens whose modifiers carry over.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function regexPatterns(array $tokens): array
    {
        $patterns = [];

        foreach ($this->arguments->of($tokens, 'regex') as $regex) {
            $pattern = $this->portablePattern($regex);

            if ($pattern === null) {
                continue;
            }

            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * Resolve the digit-count patterns of a field that is not numeric.
     *
     * A numeric field is bounded by value instead.
     *
     * @param  list<string>  $tokens
     * @param  string|null  $type
     * @return list<string>
     */
    private function digitPatterns(array $tokens, ?string $type): array
    {
        if (in_array($type, self::NUMERIC_TYPES, true)) {
            return [];
        }

        $patterns = [];

        foreach ($this->arguments->of($tokens, 'digits') as $count) {
            if (!ctype_digit($count)) {
                continue;
            }

            $patterns[] = '^[0-9]{' . $count . '}$';
        }

        foreach ($this->arguments->of($tokens, 'digits_between') as $range) {
            [$least, $most] = array_pad(explode(',', $range), 2, '');

            if (!ctype_digit($least) || !ctype_digit($most) || $least > $most) {
                continue;
            }

            $patterns[] = '^[0-9]{' . $least . ',' . $most . '}$';
        }

        return $patterns;
    }

    /**
     * Resolve the patterns of the starts_with and ends_with tokens.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function affixPatterns(array $tokens): array
    {
        $patterns = [];

        foreach (['starts_with' => '^(?:%s)', 'ends_with' => '(?:%s)$'] as $rule => $format) {
            foreach ($this->arguments->of($tokens, $rule) as $argument) {
                $values = array_filter(str_getcsv($argument, ',', '"', '\\'), static fn (?string $value): bool => $value !== null && $value !== '');

                if ($values === []) {
                    continue;
                }

                $patterns[] = sprintf($format, implode('|', array_map(static fn (string $value): string => addcslashes($value, self::SYNTAX_CHARACTERS), $values)));
            }
        }

        return $patterns;
    }

    /**
     * Resolve the anchored character-class patterns of the alpha tokens.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function alphaPatterns(array $tokens): array
    {
        $patterns = [];

        foreach ($tokens as $token) {
            $parts = explode(':', $token, 2);

            if (!isset(self::ALPHA_CLASSES[$parts[0]])) {
                continue;
            }

            $set        = explode(',', $parts[1] ?? '')[0] === 'ascii' ? 'ascii' : 'unicode';
            $patterns[] = '^' . self::ALPHA_CLASSES[$parts[0]][$set] . '+$';
        }

        return $patterns;
    }

    /**
     * Strip the delimiters from a regex, or drop it when a modifier would
     * change its meaning.
     *
     * A regex opened by a bracket is closed by its matching bracket.
     *
     * @param  string  $pattern
     * @return string|null
     */
    private function portablePattern(string $pattern): ?string
    {
        $regex  = ltrim($pattern, self::LEADING_SPACE);
        $opener = substr($regex, 0, 1);
        $closer = preg_quote(self::BRACKET_DELIMITERS[$opener] ?? $opener, '/');

        if (preg_match('/^.(.*)' . $closer . '([a-zA-Z' . self::MODIFIER_SPACING . ']*)$/s', $regex, $matches) !== 1) {
            return $pattern;
        }

        return strspn($matches[2], self::PORTABLE_MODIFIERS . self::MODIFIER_SPACING) === strlen($matches[2]) ? $matches[1] : null;
    }
}
