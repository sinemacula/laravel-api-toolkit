<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleArguments;
use Tests\TestCase;

/**
 * Tests for the rule-arguments reader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RuleArguments::class)]
final class RuleArgumentsTest extends TestCase
{
    /**
     * Test that every token of the rule yields its whole argument in order.
     *
     * @return void
     */
    public function testEveryTokenOfTheRuleYieldsItsArgument(): void
    {
        $tokens = ['min:1', 'max:2', 'min', 'minimum:3', 'min:', 'min:a:b'];

        self::assertSame(['1', '', 'a:b'], (new RuleArguments)->of($tokens, 'min'));
    }

    /**
     * Test that a rule no token carries yields no arguments.
     *
     * @return void
     */
    public function testAbsentRuleYieldsNoArguments(): void
    {
        self::assertSame([], (new RuleArguments)->of(['string', 'max:2'], 'min'));
    }
}
