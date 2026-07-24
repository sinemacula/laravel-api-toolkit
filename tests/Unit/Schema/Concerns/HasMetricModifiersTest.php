<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Concerns;

use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Schema\Concerns\HasMetricModifiers;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Schema\MetricModifierStub;
use Tests\TestCase;

/**
 * Tests for the HasMetricModifiers trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(HasMetricModifiers::class)]
final class HasMetricModifiersTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that a fresh instance carries the modifier defaults.
     *
     * @return void
     */
    public function testDefaultsAreUnsetUntilConfigured(): void
    {
        $subject = $this->subject();

        self::assertNull($this->getProperty($subject, 'alias'));
        self::assertNull($this->getProperty($subject, 'constraint'));
        self::assertFalse($this->getProperty($subject, 'isDefault'));
    }

    /**
     * Test that as() sets the alias and returns the same instance for chaining.
     *
     * @return void
     */
    public function testAsSetsTheAliasAndReturnsSelf(): void
    {
        $subject = $this->subject();

        self::assertSame($subject, $subject->as('total'));
        self::assertSame('total', $this->getProperty($subject, 'alias'));
    }

    /**
     * Test that constrain() stores the closure and returns the same instance.
     *
     * @return void
     */
    public function testConstrainStoresTheClosureAndReturnsSelf(): void
    {
        $subject    = $this->subject();
        $constraint = static fn (): null => null;

        self::assertSame($subject, $subject->constrain($constraint));
        self::assertSame($constraint, $this->getProperty($subject, 'constraint'));
    }

    /**
     * Test that default() flips the flag on and returns the same instance.
     *
     * @return void
     */
    public function testDefaultMarksTheMetricDefaultAndReturnsSelf(): void
    {
        $subject = $this->subject();

        self::assertFalse($this->getProperty($subject, 'isDefault'));
        self::assertSame($subject, $subject->default());
        self::assertTrue($this->getProperty($subject, 'isDefault'));
    }

    /**
     * Create a fresh instance using the trait under test.
     *
     * @return \Tests\Fixtures\Schema\MetricModifierStub
     */
    private function subject(): MetricModifierStub
    {
        return new MetricModifierStub;
    }
}
