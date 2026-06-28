<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\Attributes\Rule;

/**
 * Tests for the Rule validation attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Rule::class)]
final class RuleTest extends TestCase
{
    /**
     * Test that toRules passes multiple fragments through unchanged.
     *
     * @return void
     */
    public function testToRulesPassesFragmentsThrough(): void
    {
        self::assertSame(['email', 'lowercase'], (new Rule('email', 'lowercase'))->toRules());
    }

    /**
     * Test that toRules returns an empty array when no fragments given.
     *
     * @return void
     */
    public function testToRulesHandlesNoFragments(): void
    {
        self::assertSame([], (new Rule)->toRules());
    }

    /**
     * Test that toRules passes a single fragment through unchanged.
     *
     * @return void
     */
    public function testToRulesHandlesSingleFragment(): void
    {
        self::assertSame(['email'], (new Rule('email'))->toRules());
    }
}
