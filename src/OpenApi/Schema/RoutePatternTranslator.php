<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

use Illuminate\Routing\Route;

/**
 * Translates a route parameter's `where` constraint to a JSON Schema.
 *
 * A pattern is exported only inside the subset that matches identically under
 * PCRE and ECMA-262; any other pattern yields no schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RoutePatternTranslator
{
    /** One token of the portable subset: class, escape, group, alternation, quantifier or literal */
    private const string PORTABLE_TOKEN = '/\G(?:'
        . '\[(?!\^?\])\^?(?:[^\\\\\[\]]|\\\[\^$\\\.*+?()\[\]{}|\/-])+\]'
        . '|\\\[\^$\\\.*+?()\[\]{}|\/]'
        . '|\((?:\?:)?|\)|\|'
        . '|(?:[*+?]|\{[0-9]+(?:,[0-9]*)?\})\??'
        . '|[^\\\\\^$.|?*+()\[\]{}]'
        . ')/u';

    /** The characters that make an alternative more than a literal value */
    private const string METACHARACTERS = '\^$.|?*+()[]{}';

    /** The characters a quantifier token starts with */
    private const string QUANTIFIERS = '*+?{';

    /** The characters a token starts with when no quantifier may follow it */
    private const string UNQUANTIFIABLE = '*+?{(|';

    /** @var string The pattern Laravel's whereUuid applies */
    private string $uuid;

    /** @var string The pattern Laravel's whereUlid applies */
    private string $ulid;

    /** @var string The pattern Laravel's whereNumber applies */
    private string $number;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->uuid   = self::helperPattern(static fn (Route $route): Route => $route->whereUuid('p'));
        $this->ulid   = self::helperPattern(static fn (Route $route): Route => $route->whereUlid('p'));
        $this->number = self::helperPattern(static fn (Route $route): Route => $route->whereNumber('p'));
    }

    /**
     * Translate a constraint to a schema, or null outside the portable subset.
     *
     * @param  string  $pattern
     * @return array<string, mixed>|null
     */
    public function translate(string $pattern): ?array
    {
        $pattern = $this->stripAnchors($pattern);

        return match (true) {
            $pattern === ''                => null,
            $pattern === $this->uuid       => $this->uuidSchema(),
            $pattern === $this->number     => ['type' => 'integer', 'minimum' => 0],
            $this->isLiteralList($pattern) => ['type' => 'string', 'enum' => array_values(array_unique(explode('|', $pattern)))],
            $this->isPortable($pattern)    => $this->patternSchema($pattern),
            default                        => null,
        };
    }

    /**
     * Return the schema of a UUID, as whereUuid accepts it.
     *
     * @return array<string, mixed>
     */
    public function uuidSchema(): array
    {
        return ['type' => 'string', 'format' => 'uuid'];
    }

    /**
     * Return the schema of a ULID, as whereUlid accepts it.
     *
     * @return array<string, mixed>
     */
    public function ulidSchema(): array
    {
        return $this->patternSchema($this->ulid);
    }

    /**
     * Read the pattern a Laravel constraint helper applies to a parameter.
     *
     * @param  \Closure(\Illuminate\Routing\Route): \Illuminate\Routing\Route  $helper
     * @return string
     */
    private static function helperPattern(\Closure $helper): string
    {
        $pattern = $helper(new Route('GET', '{p}', []))->wheres['p'];

        assert(is_string($pattern));

        return $pattern;
    }

    /**
     * Strip one leading and one trailing anchor, as the route compiler does.
     *
     * @param  string  $pattern
     * @return string
     */
    private function stripAnchors(string $pattern): string
    {
        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        } elseif (str_starts_with($pattern, '\A')) {
            $pattern = substr($pattern, 2);
        }

        if (str_ends_with($pattern, '$')) {
            return substr($pattern, 0, -1);
        }

        return strpos($pattern, '\z') === strlen($pattern) - 2 ? substr($pattern, 0, -2) : $pattern;
    }

    /**
     * Determine whether every alternative of the pattern is a literal.
     *
     * @param  string  $pattern
     * @return bool
     */
    private function isLiteralList(string $pattern): bool
    {
        foreach (explode('|', $pattern) as $alternative) {
            if ($alternative === '' || strpbrk($alternative, self::METACHARACTERS) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the pattern lies inside the portable subset.
     *
     * @param  string  $pattern
     * @return bool
     */
    private function isPortable(string $pattern): bool
    {
        if (preg_match_all(self::PORTABLE_TOKEN, $pattern, $matches) === false || implode('', $matches[0]) !== $pattern) {
            return false;
        }

        $depth        = 0;
        $quantifiable = false;

        foreach ($matches[0] as $token) {

            $kind = $token[0];

            $depth += match ($kind) {
                '('     => 1,
                ')'     => -1,
                default => 0,
            };

            if ($depth < 0 || (!$quantifiable && str_contains(self::QUANTIFIERS, $kind))) {
                return false;
            }

            $quantifiable = !str_contains(self::UNQUANTIFIABLE, $kind);
        }

        return $depth === 0;
    }

    /**
     * Build the string schema matching the whole value against the pattern.
     *
     * @param  string  $pattern
     * @return array<string, mixed>
     */
    private function patternSchema(string $pattern): array
    {
        return ['type' => 'string', 'pattern' => '^' . (str_contains($pattern, '|') ? '(?:' . $pattern . ')' : $pattern) . '$'];
    }
}
