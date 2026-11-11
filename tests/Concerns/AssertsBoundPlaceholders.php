<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use PHPUnit\Framework\Assert;

/**
 * Asserts that a compiled statement carries one placeholder per bound value.
 *
 * Placeholders are counted as the binding layer counts them, reading a
 * backslash inside a quoted literal as an escape.
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

        // Counting the raw statement would count placeholders inside literals
        Assert::assertIsString($unquoted, 'The quoted literals must be stripped before the placeholders are counted.');

        Assert::assertSame(
            count($bindings),
            substr_count($unquoted, '?'),
            sprintf('The statement must carry one placeholder for each of its bindings: %s', $sql),
        );
    }
}
