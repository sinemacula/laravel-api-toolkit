<?php

declare(strict_types = 1);

namespace Tests\Unit\Validation\Rules;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\TestCase;

/**
 * Tests for the PureEnum validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PureEnum::class)]
final class PureEnumTest extends TestCase
{
    /**
     * Provide the wire name of every case.
     *
     * @return iterable<string, array{string}>
     */
    public static function wireNameProvider(): iterable
    {
        yield 'web' => ['web'];
        yield 'mobile app' => ['mobile_app'];
        yield 'desktop' => ['desktop'];
    }

    /**
     * Test that the lowercase name of every case passes.
     *
     * @param  string  $value
     * @return void
     */
    #[DataProvider('wireNameProvider')]
    public function testAcceptsEachLowercaseCaseName(string $value): void
    {
        self::assertSame([], $this->errors($value, new PureEnum(Platform::class)));
    }

    /**
     * Provide values the rule must refuse.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function refusedValueProvider(): iterable
    {
        yield 'unknown name' => ['console'];
        yield 'upper case name' => ['WEB'];
        yield 'mixed case name' => ['Web'];
        yield 'integer' => [1];
        yield 'array' => [['web']];
        yield 'enum case' => [Platform::WEB];
    }

    /**
     * Test that a value naming no case in its exact lowercase form fails with
     * the enum rule's message.
     *
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('refusedValueProvider')]
    public function testRefusesAValueThatIsNotALowercaseCaseName(mixed $value): void
    {
        self::assertSame(
            ['platform' => ['The selected platform is invalid.']],
            $this->errors($value, new PureEnum(Platform::class)),
        );
    }

    /**
     * Test that the failure message is read from the enum rule's translation
     * key, so an application's override applies.
     *
     * @return void
     */
    public function testFailureUsesTheEnumTranslationKey(): void
    {
        Lang::addLines(['validation.enum' => 'Pick a known :attribute.'], 'en');

        self::assertSame(
            ['platform' => ['Pick a known platform.']],
            $this->errors('console', new PureEnum(Platform::class)),
        );
    }

    /**
     * Test that only() narrows the accepted cases to those given.
     *
     * @return void
     */
    public function testOnlyNarrowsTheAcceptedCases(): void
    {
        $rule = (new PureEnum(Platform::class))->only([Platform::WEB, Platform::DESKTOP]);

        self::assertSame([], $this->errors('web', $rule));
        self::assertSame([], $this->errors('desktop', $rule));
        self::assertArrayHasKey('platform', $this->errors('mobile_app', $rule));
    }

    /**
     * Test that only() accepts a single case and an arrayable list.
     *
     * @return void
     */
    public function testOnlyAcceptsASingleCaseAndAnArrayable(): void
    {
        $single    = (new PureEnum(Platform::class))->only(Platform::WEB);
        $arrayable = (new PureEnum(Platform::class))->only(collect([Platform::DESKTOP]));

        self::assertSame([], $this->errors('web', $single));
        self::assertArrayHasKey('platform', $this->errors('desktop', $single));
        self::assertSame([], $this->errors('desktop', $arrayable));
        self::assertArrayHasKey('platform', $this->errors('web', $arrayable));
    }

    /**
     * Test that except() refuses the cases given and accepts the rest.
     *
     * @return void
     */
    public function testExceptRefusesTheGivenCases(): void
    {
        $rule = (new PureEnum(Platform::class))->except(Platform::WEB);

        self::assertArrayHasKey('platform', $this->errors('web', $rule));
        self::assertSame([], $this->errors('mobile_app', $rule));
        self::assertSame([], $this->errors('desktop', $rule));
    }

    /**
     * Test that except() accepts an arrayable list.
     *
     * @return void
     */
    public function testExceptAcceptsAnArrayable(): void
    {
        $rule = (new PureEnum(Platform::class))->except(collect([Platform::WEB, Platform::DESKTOP]));

        self::assertArrayHasKey('platform', $this->errors('web', $rule));
        self::assertArrayHasKey('platform', $this->errors('desktop', $rule));
        self::assertSame([], $this->errors('mobile_app', $rule));
    }

    /**
     * Test that only() wins over except() when both are given, as it does on
     * Laravel's enum rule.
     *
     * @return void
     */
    public function testOnlyWinsOverExcept(): void
    {
        $rule = (new PureEnum(Platform::class))->only(Platform::WEB)->except(Platform::WEB);

        self::assertSame([], $this->errors('web', $rule));
        self::assertArrayHasKey('platform', $this->errors('desktop', $rule));
    }

    /**
     * Test that names() lists the wire name of every accepted case in
     * declaration order, narrowed by only() and except().
     *
     * @return void
     */
    public function testNamesListsTheAcceptedWireNames(): void
    {
        self::assertSame(['web', 'mobile_app', 'desktop'], (new PureEnum(Platform::class))->names());
        self::assertSame(['web', 'desktop'], (new PureEnum(Platform::class))->only([Platform::DESKTOP, Platform::WEB])->names());
        self::assertSame(['web', 'mobile_app'], (new PureEnum(Platform::class))->except(Platform::DESKTOP)->names());
    }

    /**
     * Test that a backed enum is refused at construction, pointing at Laravel's
     * enum rule.
     *
     * @return void
     */
    public function testBackedEnumIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(UserStatus::class . ' is a backed enum; validate it with Rule::enum() instead');

        new PureEnum(UserStatus::class);
    }

    /**
     * Test that a class which is not an enum is refused at construction.
     *
     * @return void
     */
    public function testNonEnumClassIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass is not an enum');

        new PureEnum(\stdClass::class); // @phpstan-ignore argument.type
    }

    /**
     * Test that the rule exposes the enum it validates against.
     *
     * @return void
     */
    public function testExposesTheEnumClass(): void
    {
        self::assertSame(Platform::class, (new PureEnum(Platform::class))->enum);
    }

    /**
     * Validate one platform value against the rule and return the errors.
     *
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Validation\Rules\PureEnum  $rule
     * @return array<string, array<int, string>>
     */
    private function errors(mixed $value, PureEnum $rule): array
    {
        return Validator::make(['platform' => $value], ['platform' => [$rule]])->errors()->toArray();
    }
}
