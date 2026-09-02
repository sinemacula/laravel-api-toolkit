<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use PHPUnit\Framework\Assert;

/**
 * Provides the assertion that a compiled statement carries one placeholder for
 * every value bound to it.
 *
 * The placeholders are counted the way the layer that binds the values counts
 * them: by scanning the statement itself and reading a backslash inside a
 * quoted literal as escaping the character after it. A literal left open that
 * way runs on into the clause beside it and swallows its placeholder, so the
 * last value has nothing to bind to and the statement is refused by the engine
 * rather than by a compiled-SQL assertion, which sees a placeholder the binder
 * never does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait AssertsBoundPlaceholders
{
    /** @var string The pattern matching a quoted literal the way the layer binding the values reads one */
    private const string QUOTED_LITERAL = '/"(?:\\\.|[^"\\\])*"|\'(?:\\\.|[^\'\\\])*\'/s';

    /**
     * Assert that the statement carries one bindable placeholder per binding.
     *
     * @param  string  $sql
     * @param  array<int, mixed>  $bindings
     * @return void
     */
    protected static function assertPlaceholderPerBinding(string $sql, array $bindings): void
    {
        $unquoted = preg_replace(self::QUOTED_LITERAL, '', $sql);

        // Counting the raw statement would count placeholders inside literals,
        // which is the reading this assertion exists to refuse.
        Assert::assertIsString($unquoted, 'The quoted literals must be stripped before the placeholders are counted.');

        Assert::assertSame(
            count($bindings),
            substr_count($unquoted, '?'),
            sprintf('The statement must carry one placeholder for each of its bindings: %s', $sql),
        );
    }
}
