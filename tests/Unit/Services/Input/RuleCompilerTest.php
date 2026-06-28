<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\RuleCompiler;
use Tests\Fixtures\Services\Input\AllTypesInput;
use Tests\Fixtures\Services\Input\AttributedInput;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;

/**
 * Tests for the RuleCompiler class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RuleCompiler::class)]
final class RuleCompilerTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Services\Input\RuleCompiler */
    private RuleCompiler $compiler;

    /**
     * Set up the compiler under test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        $this->compiler = new RuleCompiler;
    }

    /**
     * Test that base rules are derived from primitive PHP types.
     *
     * @return void
     */
    public function testCompilesBaseRulesFromTypes(): void
    {
        $rules = $this->compiler->compile(AllTypesInput::class);

        self::assertSame(['string'], $rules['name']);
        self::assertSame(['integer'], $rules['age']);
        self::assertSame(['numeric'], $rules['score']);
        self::assertSame(['boolean'], $rules['active']);
        self::assertSame(['array'], $rules['tags']);
    }

    /**
     * Test that a nullable type contributes the nullable rule fragment.
     *
     * @return void
     */
    public function testNullableTypeAddsNullableRule(): void
    {
        $rules = $this->compiler->compile(AllTypesInput::class);

        self::assertSame(['nullable', 'string'], $rules['nullable']);
    }

    /**
     * Test that an enum-typed property yields an enum rule fragment.
     *
     * @return void
     */
    public function testCompilesEnumRule(): void
    {
        $rules = $this->compiler->compile(AllTypesInput::class);

        self::assertSame(['enum:' . StubStatusEnum::class], $rules['status']);
    }

    /**
     * Test that ValidationAttribute fragments layer onto the base rule.
     *
     * @return void
     */
    public function testLayersAttributeFragments(): void
    {
        $rules = $this->compiler->compile(AttributedInput::class);

        self::assertSame(['string', 'max:100', 'min:1', 'required', 'email'], $rules['email']);
        self::assertSame(['integer'], $rules['count']);
    }

    /**
     * Test that an override key replaces the compiled rules for that property.
     *
     * @return void
     */
    public function testOverrideReplacesComputedRules(): void
    {
        $rules = $this->compiler->compile(
            AllTypesInput::class,
            ['name' => ['required', 'string', 'max:255']],
        );

        self::assertSame(['required', 'string', 'max:255'], $rules['name']);
        self::assertSame(['integer'], $rules['age']);
    }

    /**
     * Test that an override key with no matching property is added to output.
     *
     * @return void
     */
    public function testOverrideAddsUnmatchedKey(): void
    {
        $rules = $this->compiler->compile(
            AllTypesInput::class,
            ['extra_field' => ['required', 'string']],
        );

        self::assertArrayHasKey('extra_field', $rules);
        self::assertSame(['required', 'string'], $rules['extra_field']);
    }
}
