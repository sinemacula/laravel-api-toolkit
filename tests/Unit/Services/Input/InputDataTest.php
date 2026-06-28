<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\InputData;
use Tests\Fixtures\Input\SampleInput;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\TestCase;

/**
 * Tests for the InputData abstract base.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InputData::class)]
final class InputDataTest extends TestCase
{
    /**
     * Test that from(array) validates and hydrates a typed instance.
     *
     * @return void
     */
    public function testFromArrayValidatesAndHydrates(): void
    {
        $input = SampleInput::from(['city' => 'London', 'age' => 30]);

        self::assertInstanceOf(SampleInput::class, $input);
        self::assertSame('London', $input->city);
        self::assertSame(30, $input->age);
        self::assertNull($input->status);
    }

    /**
     * Test that from(Request) validates and hydrates a typed instance.
     *
     * @return void
     */
    public function testFromRequestValidatesAndHydrates(): void
    {
        $request = Request::create('/', 'GET', ['city' => 'Paris']);
        $input   = SampleInput::from($request);

        self::assertSame('Paris', $input->city);
        self::assertNull($input->age);
    }

    /**
     * Test that from() throws ValidationException on a missing required field.
     *
     * @return void
     */
    public function testFromThrowsValidationExceptionWhenRequiredFieldMissing(): void
    {
        $this->expectException(ValidationException::class);

        SampleInput::from([]);
    }

    /**
     * Test that from() throws ValidationException on an invalid enum value.
     *
     * @return void
     */
    public function testFromThrowsValidationExceptionWhenEnumValueIsInvalid(): void
    {
        $this->expectException(ValidationException::class);

        SampleInput::from(['city' => 'London', 'status' => 'not_a_valid_status']);
    }

    /**
     * Test that a nullable field is hydrated as null when absent from source.
     *
     * @return void
     */
    public function testFromHydratesNullableFieldAsNull(): void
    {
        $input = SampleInput::from(['city' => 'Berlin']);

        self::assertNull($input->age);
        self::assertNull($input->status);
    }

    /**
     * Test that a backed enum parameter is cast from its string value.
     *
     * @return void
     */
    public function testFromHydratesEnumTypedField(): void
    {
        $input = SampleInput::from(['city' => 'Rome', 'status' => 'active']);

        self::assertSame(StubStatusEnum::ACTIVE, $input->status);
    }

    /**
     * Test that a rules() override contributes cross-field constraints.
     *
     * Verifies that a subclass returning a confirmed rule from rules() causes
     * from() to reject input missing the confirmation field.
     *
     * @return void
     */
    public function testRulesOverrideContributesRules(): void
    {
        $class = new class extends InputData {
            /**
             * Create a new instance.
             *
             * @param  string  $city
             */
            public function __construct(

                /** City name subject to confirmation. */
                public readonly string $city = '',
            ) {}

            /**
             * Require confirmation of the city field.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['city' => ['required', 'string', 'confirmed']];
            }
        };

        $this->expectException(ValidationException::class);

        $class::from(['city' => 'London']);
    }

    /**
     * Test that direct named-argument construction produces a valid instance.
     *
     * @return void
     */
    public function testNamedArgumentConstructionWorks(): void
    {
        $input = new SampleInput(city: 'X');

        self::assertSame('X', $input->city);
        self::assertNull($input->age);
        self::assertNull($input->status);
    }

    /**
     * Test that instances are immutable and survive a serialise round-trip.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testInstanceIsImmutableAndSerialisable(): void
    {
        $input = new SampleInput(city: 'Rome', age: 25, status: StubStatusEnum::ACTIVE);

        /** @var \Tests\Fixtures\Input\SampleInput $restored */
        $restored = unserialize(serialize($input));

        self::assertSame('Rome', $restored->city);
        self::assertSame(25, $restored->age);
        self::assertSame(StubStatusEnum::ACTIVE, $restored->status);
        self::assertTrue((new \ReflectionProperty($input, 'city'))->isReadOnly());
    }

    /**
     * Test that toArray() returns the typed promoted-property map.
     *
     * @return void
     */
    public function testToArrayReturnsTypedPropertyMap(): void
    {
        $input = new SampleInput(city: 'Berlin', age: 40, status: StubStatusEnum::INACTIVE);

        self::assertSame(
            ['city' => 'Berlin', 'age' => 40, 'status' => StubStatusEnum::INACTIVE],
            $input->toArray(),
        );
    }
}
