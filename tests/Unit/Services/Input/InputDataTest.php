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

    /**
     * Test that from() skips constructor parameters that are not promoted, so
     * such parameters are never hydrated from the validated input.
     *
     * @return void
     */
    public function testFromSkipsNonPromotedConstructorParameters(): void
    {
        $definition = new class extends InputData {
            /** @var string The city, from a non-promoted parameter. */
            public readonly string $city;

            /**
             * Create a new instance from a non-promoted and a promoted param.
             *
             * @param  string  $cityInput
             * @param  int|null  $age
             */
            public function __construct(

                // The city name from a non-promoted parameter.
                string $cityInput = 'default',

                /** The age, hydrated from validated input. */
                public readonly ?int $age = null,
            ) {
                $this->city = $cityInput;
            }

            /**
             * Validate both the promoted and the non-promoted parameter names.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'age'       => ['nullable', 'integer'],
                    'cityInput' => ['nullable', 'string'],
                ];
            }
        };

        $result = $definition::from(['age' => 42, 'cityInput' => 'from-input']);

        self::assertSame(42, $result->age);
        self::assertSame('default', $result->city);
    }

    /**
     * Test that toArray() skips public properties that are not promoted.
     *
     * @return void
     */
    public function testToArraySkipsNonPromotedPublicProperties(): void
    {
        $input = new class extends InputData {
            /** @var string A public property that is not promoted. */
            public string $manual = 'manual-value';

            /**
             * Create a new instance with a single promoted property.
             *
             * @param  string  $city
             */
            public function __construct(

                /** The promoted city property. */
                public readonly string $city = 'Y',
            ) {}
        };

        self::assertSame(['city' => 'Y'], $input->toArray());
    }

    /**
     * Test that toArray() skips promoted properties that are uninitialised.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testToArraySkipsUninitialisedPromotedProperties(): void
    {
        $reflection = new \ReflectionClass(SampleInput::class);

        /** @var \Tests\Fixtures\Input\SampleInput $input */
        $input = $reflection->newInstanceWithoutConstructor();

        // Initialise the first and last properties, leaving the middle one
        // uninitialised, so the loop must skip the gap and keep the later
        // value.
        $reflection->getProperty('city')->setValue($input, 'OnlyCity');
        $reflection->getProperty('status')->setValue($input, StubStatusEnum::ACTIVE);

        self::assertSame(
            ['city' => 'OnlyCity', 'status' => StubStatusEnum::ACTIVE],
            $input->toArray(),
        );
    }

    /**
     * Test that a value for a parameter without a named type is left unchanged.
     *
     * The enum cast only applies when the parameter declares a single named
     * type, so a union-typed parameter must receive its value verbatim.
     *
     * @return void
     */
    public function testCastValueLeavesNonNamedTypeValueUnchanged(): void
    {
        $definition = new class (data: '') extends InputData {
            /**
             * Create a new instance with a union-typed promoted property.
             *
             * @param  int|string  $data
             */
            public function __construct(

                /** A union-typed value that must not be cast. */
                public readonly int|string $data = '',
            ) {}

            /**
             * Require the union-typed field.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['data' => ['required']];
            }
        };

        $result = $definition::from(['data' => 'hello']);

        self::assertSame('hello', $result->data);
    }

    /**
     * Test that a non-string value for an enum parameter is passed through.
     *
     * The enum cast only applies to string values, so an already-constructed
     * enum instance must be returned unchanged rather than re-cast.
     *
     * @return void
     */
    public function testCastValueLeavesNonStringEnumValueUnchanged(): void
    {
        $definition = new class (status: StubStatusEnum::ACTIVE) extends InputData {
            /**
             * Create a new instance with an enum-typed promoted property.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
             */
            public function __construct(

                /** An enum value given as an instance, not a string. */
                public readonly ?StubStatusEnum $status = null,
            ) {}

            /**
             * Accept the status field without transforming it.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['status' => []];
            }
        };

        $result = $definition::from(['status' => StubStatusEnum::ACTIVE]);

        self::assertSame(StubStatusEnum::ACTIVE, $result->status);
    }

    /**
     * Test that the base rules() returns an empty rule set when a subclass does
     * not override it, permitting construction from an empty source.
     *
     * @return void
     */
    public function testBaseRulesReturnEmptyArrayWhenNotOverridden(): void
    {
        $definition = new class extends InputData {
            /**
             * Create a new instance with a defaulted promoted property.
             *
             * @param  string  $city
             */
            public function __construct(

                /** The promoted city property. */
                public readonly string $city = 'base',
            ) {}
        };

        self::assertSame([], $definition::rules());

        $result = $definition::from([]);

        self::assertInstanceOf(InputData::class, $result);
        self::assertSame('base', $result->city);
    }
}
