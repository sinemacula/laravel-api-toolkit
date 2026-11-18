<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;
use SineMacula\ApiToolkit\Services\Input\Payload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Enums\Magnitude;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Input\OptionalRulePayload;
use Tests\Fixtures\Input\SampleInput;
use Tests\Fixtures\Input\StorePayload;
use Tests\Fixtures\Services\Input\BackedEnumInput;
use Tests\Fixtures\Services\Input\Concerns\DescribesInput;
use Tests\Fixtures\Services\Input\Concerns\SensitiveCredentials;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\Fixtures\Services\Input\PlatformInput;
use Tests\Fixtures\Services\Input\ScalarInput;
use Tests\Fixtures\Services\Input\SensitiveBaseInput;
use Tests\Fixtures\Services\Input\SensitiveInput;
use Tests\TestCase;
use Tests\Unit\Services\Input\Rules\EnumTest;

/**
 * Tests for the Payload abstract base.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Payload::class)]
final class PayloadTest extends TestCase
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
     * Test that an invalid enum without Rule::enum fails as a validation error.
     *
     * @return void
     */
    public function testFromDegradesInvalidEnumWithoutRuleToValidationFailure(): void
    {
        $definition = new class (status: StubStatusEnum::ACTIVE) extends Payload {
            /**
             * Create a new instance with an enum-typed promoted property.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
             */
            public function __construct(

                /** An enum property whose rules deliberately omit Rule::enum. */
                public readonly ?StubStatusEnum $status = null,
            ) {}

            /**
             * Validate the status as a plain string, without an enum rule.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['status' => ['sometimes', 'nullable', 'string']];
            }
        };

        try {
            $definition::from(['status' => 'not_a_valid_status']);

            self::fail('Expected a ValidationException for the invalid enum value.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('status', $exception->errors());
        }
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
     * Test that a pure enum parameter is cast from its lowercase case name.
     *
     * @return void
     */
    public function testFromHydratesPureEnumTypedFieldFromLowercaseName(): void
    {
        $input = PlatformInput::from(['primary_platform' => 'mobile_app']);

        self::assertSame(Platform::MOBILE_APP, $input->primaryPlatform);
    }

    /**
     * Test that a nullable pure enum parameter hydrates as null when absent.
     *
     * @return void
     */
    public function testFromHydratesNullablePureEnumFieldAsNull(): void
    {
        self::assertNull(PlatformInput::from(['primary_platform' => null])->primaryPlatform);
        self::assertNull(PlatformInput::from([])->primaryPlatform);
    }

    /**
     * Test that a pure enum value other than the lowercase name fails.
     *
     * @return void
     */
    public function testFromRefusesPureEnumValueNotInLowercaseForm(): void
    {
        try {
            PlatformInput::from(['primary_platform' => 'MOBILE_APP']);

            self::fail('Expected a ValidationException for the upper-case enum value.');
        } catch (ValidationException $exception) {
            self::assertSame(['primary_platform' => ['The selected primary platform is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that a pure enum parameter is cast even without the enum rule.
     *
     * @return void
     */
    public function testFromCastsPureEnumFieldWithoutEnumRule(): void
    {
        self::assertSame(Platform::DESKTOP, PlatformInput::from(['fallback_platform' => 'desktop'])->fallbackPlatform);
    }

    /**
     * Test that an unknown pure enum value without the enum rule fails.
     *
     * @return void
     */
    public function testFromDegradesUnknownPureEnumWithoutRuleToValidationFailure(): void
    {
        try {
            PlatformInput::from(['fallback_platform' => 'console']);

            self::fail('Expected a ValidationException for the unknown enum value.');
        } catch (ValidationException $exception) {
            self::assertSame(['fallback_platform' => ['The selected fallback platform is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that a non-string pure enum value fails as a validation error.
     *
     * @return void
     */
    public function testFromRefusesNonStringPureEnumValueWithoutEnumRule(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a pure enum promoted property.
             *
             * @param  \Tests\Fixtures\Enums\Platform|null  $platform
             */
            public function __construct(

                /** A pure enum property whose rules admit any value. */
                public readonly ?Platform $platform = null,
            ) {}

            /**
             * Require the platform without constraining its type.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['platform' => ['required']];
            }
        };

        try {
            $definition::from(['platform' => 42]);

            self::fail('Expected a ValidationException for the non-string enum value.');
        } catch (ValidationException $exception) {
            self::assertSame(['platform' => ['The selected platform is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that an int-backed enum hydrates from an integer or decimal string.
     *
     * @param  mixed  $value
     * @param  \Tests\Fixtures\Enums\Magnitude  $expected
     * @return void
     */
    #[DataProviderExternal(EnumTest::class, 'canonicalIntBackingProvider')]
    public function testFromHydratesIntBackedEnumFromCanonicalForm(mixed $value, Magnitude $expected): void
    {
        self::assertSame($expected, BackedEnumInput::from(['magnitude' => $value])->magnitude);
    }

    /**
     * Test that a non-canonical int-backed enum value fails validation.
     *
     * Laravel's enum rule alone would coerce it to a case.
     *
     * @param  mixed  $value
     * @return void
     */
    #[DataProviderExternal(EnumTest::class, 'nonCanonicalIntBackingProvider')]
    public function testFromRefusesNonCanonicalIntBackedEnumValue(mixed $value): void
    {
        try {
            BackedEnumInput::from(['magnitude' => $value]);

            self::fail('Expected a ValidationException for the non-canonical enum value.');
        } catch (ValidationException $exception) {
            self::assertSame(['magnitude' => ['The selected magnitude is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that a string-backed enum hydrates only from its string value.
     *
     * @return void
     */
    public function testFromHydratesStringBackedEnumOnlyFromAString(): void
    {
        self::assertSame(StubStatusEnum::ACTIVE, BackedEnumInput::from(['status' => 'active'])->status);

        foreach ([42, true, 1.5, ['active']] as $value) {
            try {
                BackedEnumInput::from(['status' => $value]);

                self::fail('Expected a ValidationException for the non-string enum value.');
            } catch (ValidationException $exception) {
                self::assertSame(['status' => ['The selected status is invalid.']], $exception->errors());
            }
        }
    }

    /**
     * Test that an enum failure is reported with the other failed fields.
     *
     * @return void
     */
    public function testEnumFailureIsReportedWithTheOtherFailedFields(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a required name and an enum.
             *
             * @param  string  $name
             * @param  \Tests\Fixtures\Enums\Magnitude|null  $magnitude
             */
            public function __construct(

                /** A required name. */
                public readonly string $name = '',

                /** An int-backed enum checked by Laravel's enum rule. */
                public readonly ?Magnitude $magnitude = null,
            ) {}

            /**
             * Require the name and validate the magnitude with the enum rule.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['name' => ['required', 'string'], 'magnitude' => [Rule::enum(Magnitude::class)]];
            }
        };

        try {
            $definition::from(['magnitude' => '01']);

            self::fail('Expected a ValidationException for every failed field.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['name' => ['The name field is required.'], 'magnitude' => ['The selected magnitude is invalid.']],
                $exception->errors(),
            );
        }

        try {
            BackedEnumInput::from(['magnitude' => '01', 'status' => 'archived']);

            self::fail('Expected a ValidationException for both enum fields.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['magnitude' => ['The selected magnitude is invalid.'], 'status' => ['The selected status is invalid.']],
                $exception->errors(),
            );
        }
    }

    /**
     * Test that a declared enum rule and the cast report one message.
     *
     * @return void
     */
    public function testDeclaredEnumRuleAndTheCastReportOneMessage(): void
    {
        try {
            SampleInput::from(['city' => 'Paris', 'status' => 'archived']);

            self::fail('Expected a ValidationException for the unknown status.');
        } catch (ValidationException $exception) {
            self::assertSame(['status' => ['The selected status is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that an enum failure follows the attribute names and the locale.
     *
     * @return void
     */
    public function testEnumFailureFollowsTheAttributeNamesAndTheLocale(): void
    {
        Lang::addLines(['validation.enum' => 'Der Wert für :attribute ist ungültig.', 'validation.attributes.magnitude' => 'Größe'], 'de');
        App::setLocale('de');

        try {
            BackedEnumInput::from(['magnitude' => '01']);

            self::fail('Expected a ValidationException for the non-canonical magnitude.');
        } catch (ValidationException $exception) {
            self::assertSame(['magnitude' => ['Der Wert für Größe ist ungültig.']], $exception->errors());
        }
    }

    /**
     * Test that an enum parameter without a rule key is never hydrated.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testEnumParameterWithoutARuleKeyIsNeverHydrated(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with an enum property no rule names.
             *
             * @param  \Tests\Fixtures\Enums\Magnitude  $magnitude
             */
            public function __construct(

                /** An enum property absent from the rules. */
                public readonly Magnitude $magnitude = Magnitude::ZERO,
            ) {}

            /**
             * Validate an unrelated field only.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['name' => ['sometimes', 'string']];
            }
        };

        self::assertSame(Magnitude::ZERO, $definition::from(['magnitude' => '01'])->magnitude);
    }

    /**
     * Test that a rule nested under an enum property's body key is refused.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testEnumPropertyWithANestedRuleThrows(): void
    {
        $nested = new class extends Payload {
            /**
             * Create a new instance with an enum ruled by a nested key.
             *
             * @param  \Tests\Fixtures\Enums\Magnitude|null  $magnitude
             */
            public function __construct(

                /** An enum property whose rules name only a nested key. */
                public readonly ?Magnitude $magnitude = null,
            ) {}

            /**
             * Rule the magnitude through a nested key only.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['magnitude.value' => ['sometimes']];
            }
        };

        $wildcard = new class extends Payload {
            /**
             * Create a new instance with an enum ruled by a wildcard key.
             *
             * @param  \Tests\Fixtures\Enums\Magnitude|null  $magnitude
             */
            public function __construct(

                /** An enum property whose rules name only its items. */
                public readonly ?Magnitude $magnitude = null,
            ) {}

            /**
             * Rule the magnitude through a wildcard key only.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['magnitude.*' => ['integer']];
            }
        };

        $parent = new class extends Payload {
            /**
             * Create a new instance with an enum ruled whole and nested.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $clientStatus
             */
            public function __construct(

                /** An enum property read from client_status. */
                public readonly ?StubStatusEnum $clientStatus = null,
            ) {}

            /**
             * Rule the status whole and through a nested key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['client_status' => ['sometimes'], 'client_status.name' => ['sometimes', 'string']];
            }
        };

        $anyKey = new class extends Payload {
            /**
             * Create a new instance with an enum under a nested wildcard key.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
             */
            public function __construct(

                /** An enum property every top-level key's rule reaches. */
                public readonly ?StubStatusEnum $status = null,
            ) {}

            /**
             * Rule a nested name under every top-level key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['*.name' => ['string']];
            }
        };

        $cases = [
            [$nested, ['magnitude' => ['value' => 1]], 'magnitude.value', 'magnitude'],
            [$wildcard, ['magnitude' => [1]], 'magnitude.*', 'magnitude'],
            [$parent, ['client_status' => StubStatusEnum::ACTIVE], 'client_status.name', 'clientStatus'],
            [$anyKey, ['status' => ['name' => 'active']], '*.name', 'status'],
        ];

        foreach ($cases as [$payload, $data, $rule, $property]) {
            try {
                $payload::from($data);

                self::fail('Expected a LogicException for the nested enum rule.');
            } catch (\LogicException $exception) {
                self::assertSame(
                    sprintf('The rule "%s" nests under %s::$%s, an enum read from the whole value', $rule, $payload::class, $property),
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * Test that a rule key sharing an enum key's prefix is not nested under it,
     * since the validator expands no partial wildcard at the top level.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testRuleKeySharingAnEnumKeyPrefixIsNotNested(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with an enum beside a similarly named key.
             *
             * @param  \Tests\Fixtures\Enums\Magnitude|null  $magnitude
             */
            public function __construct(

                /** An enum property ruled whole. */
                public readonly ?Magnitude $magnitude = null,
            ) {}

            /**
             * Rule the magnitude beside keys that only share its prefix.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['magnitude' => ['sometimes'], 'magnitudes.value' => ['sometimes'], 'magni*.value' => ['sometimes']];
            }
        };

        self::assertSame(Magnitude::ONE, $definition::from(['magnitude' => 1])->magnitude);
    }

    /**
     * Test that a top-level wildcard rule carries the enum rule to an enum
     * property.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testTopLevelWildcardRuleCarriesTheEnumRule(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with an enum ruled by a top-level wildcard.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
             */
            public function __construct(

                /** An enum property ruled only through the wildcard. */
                public readonly ?StubStatusEnum $status = null,
            ) {}

            /**
             * Rule every top-level key as a string.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['*' => ['string']];
            }
        };

        self::assertSame(StubStatusEnum::ACTIVE, $definition::from(['status' => 'active'])->status);

        try {
            $definition::from(['status' => 'archived']);

            self::fail('Expected a ValidationException for the unknown status.');
        } catch (ValidationException $exception) {
            self::assertSame(['status' => ['The selected status is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that a mapped body key carries the enum rule.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testMappedBodyKeyCarriesTheEnumRule(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a camelCase enum property.
             *
             * @param  \Tests\Fixtures\Enums\Magnitude|null  $clientMagnitude
             * @param  string  $clientName
             */
            public function __construct(

                /** The magnitude read from the client_magnitude body key. */
                public readonly ?Magnitude $clientMagnitude = null,

                /** The name read from the client_name body key. */
                public readonly string $clientName = '',
            ) {}

            /**
             * Accept the snake_case body keys.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['client_magnitude' => ['sometimes'], 'client_name' => ['sometimes', 'string']];
            }
        };

        self::assertSame(Magnitude::NEGATIVE, $definition::from(['client_magnitude' => '-3'])->clientMagnitude);

        try {
            $definition::from(['client_magnitude' => '01', 'client_name' => 5]);

            self::fail('Expected a ValidationException for both mapped keys.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['client_magnitude' => ['The selected client magnitude is invalid.'], 'client_name' => ['The client name field must be a string.']],
                $exception->errors(),
            );
        }
    }

    /**
     * Test that a payload without a constructor hydrates to an empty instance.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testPayloadWithoutAConstructorHydratesToAnEmptyInstance(): void
    {
        $definition = new class extends Payload {};

        self::assertSame([], $definition::from(['status' => 'active'])->toArray());
    }

    /**
     * Test that a rules() override contributes cross-field constraints.
     *
     * @return void
     */
    public function testRulesOverrideContributesRules(): void
    {
        $class = new class extends Payload {
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
     * Test that toArray() of a constructed payload includes its defaults.
     *
     * @return void
     */
    public function testToArrayOfAConstructedPayloadIncludesItsDefaults(): void
    {
        $input = new SampleInput(city: 'Rome');

        self::assertSame(['city' => 'Rome', 'age' => null, 'status' => null], $input->toArray());
    }

    /**
     * Test that toArray() of a hydrated payload leaves out the fields its input
     * omitted and keeps an explicit null.
     *
     * @return void
     */
    public function testToArrayLeavesOutTheFieldsTheInputOmitted(): void
    {
        $input = SampleInput::from(['city' => 'Berlin', 'age' => null]);

        self::assertNull($input->status);
        self::assertSame(['city' => 'Berlin', 'age' => null], $input->toArray());
    }

    /**
     * Test that the omitted fields stay omitted after a serialise round trip.
     *
     * @return void
     */
    public function testOmittedFieldsSurviveASerialiseRoundTrip(): void
    {
        $restored = unserialize(serialize(SampleInput::from(['city' => 'Oslo'])));

        self::assertInstanceOf(SampleInput::class, $restored);
        self::assertSame(['city' => 'Oslo'], $restored->toArray());
    }

    /**
     * Provide values the integer, numeric or boolean rule accepts, with the
     * property that casts them and the value it receives.
     *
     * @return iterable<string, array{string, mixed, bool|float|int}>
     */
    public static function castableScalarProvider(): iterable
    {
        yield 'int from a string' => ['count', '5', 5];
        yield 'int from a negative string' => ['count', '-5', -5];
        yield 'int from an integral float' => ['count', 5.0, 5];
        yield 'float from a decimal string' => ['ratio', '1.5', 1.5];
        yield 'float from an integer string' => ['ratio', '2', 2.0];
        yield 'bool from a one string' => ['enabled', '1', true];
        yield 'bool from a zero string' => ['enabled', '0', false];
        yield 'bool from one' => ['enabled', 1, true];
        yield 'bool from zero' => ['enabled', 0, false];
    }

    /**
     * Test that a scalar parameter is cast from a value its rule accepts.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  bool|float|int  $expected
     * @return void
     */
    #[DataProvider('castableScalarProvider')]
    public function testFromCastsAScalarParameterFromAValueItsRuleAccepts(string $key, mixed $value, bool|float|int $expected): void
    {
        self::assertSame([$key => $expected], ScalarInput::from([$key => $value])->toArray());
    }

    /**
     * Provide values no integer, numeric or boolean rule accepts, with the
     * property that would receive them.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function uncastableScalarProvider(): iterable
    {
        yield 'int from a decimal string' => ['count', '5.5'];
        yield 'int from a fractional float' => ['count', 5.5];
        yield 'int from a bool' => ['count', true];
        yield 'float from a word' => ['ratio', 'half'];
        yield 'bool from a word' => ['enabled', 'yes'];
        yield 'bool from a decimal string' => ['enabled', '1.0'];
        yield 'bool from two' => ['enabled', 2];
    }

    /**
     * Test that a value no scalar rule accepts reaches the constructor as is.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('uncastableScalarProvider')]
    public function testFromPassesAnUncastableScalarThroughUnchanged(string $key, mixed $value): void
    {
        $this->expectException(\TypeError::class);

        ScalarInput::from([$key => $value]);
    }

    /**
     * Test that a parameter without a default whose rule lets it be absent
     * fails naming the class, the property and the body key.
     *
     * @return void
     */
    public function testOptionalRuleOnARequiredParameterFailsClearly(): void
    {
        self::assertSame('Ada', OptionalRulePayload::from(['display_name' => 'Ada'])->displayName);

        try {
            OptionalRulePayload::from([]);

            self::fail('Expected a LogicException for the absent required parameter.');
        } catch (\LogicException $exception) {
            self::assertSame(
                sprintf('%s::$displayName has no default, but its rules let "display_name" be absent', OptionalRulePayload::class),
                $exception->getMessage(),
            );
        }
    }

    /**
     * Test that from() never hydrates non-promoted constructor parameters.
     *
     * @return void
     */
    public function testFromSkipsNonPromotedConstructorParameters(): void
    {
        $definition = new class extends Payload {
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
        $input = new class extends Payload {
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

        // Leave the middle property uninitialised so the loop must skip it
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
     * @return void
     */
    public function testCastValueLeavesNonNamedTypeValueUnchanged(): void
    {
        $definition = new class (data: '') extends Payload {
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
     * @return void
     */
    public function testCastValueLeavesNonStringEnumValueUnchanged(): void
    {
        $definition = new class (status: StubStatusEnum::ACTIVE) extends Payload {
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
     * Test that the base rules() is empty when a subclass does not override it.
     *
     * @return void
     */
    public function testBaseRulesReturnEmptyArrayWhenNotOverridden(): void
    {
        $definition = new class extends Payload {
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

        self::assertInstanceOf(Payload::class, $result);
        self::assertSame('base', $result->city);
    }

    /**
     * Test that Payload and its subclasses satisfy the input-schema contract.
     *
     * @return void
     */
    public function testPayloadImplementsInputSchemaContract(): void
    {
        self::assertTrue((new \ReflectionClass(Payload::class))->implementsInterface(DefinesInputSchema::class));
        self::assertInstanceOf(DefinesInputSchema::class, new SampleInput(city: 'London'));
    }

    /**
     * Test that the rules can be read statically with no instance or request.
     *
     * @return void
     */
    public function testRulesAreReadableStaticallyWithoutInstance(): void
    {
        self::assertArrayHasKey('city', SampleInput::rules());
    }

    /**
     * Test that toArray() omits a promoted parameter marked sensitive.
     *
     * @return void
     */
    public function testToArrayOmitsSensitiveParameters(): void
    {
        $input = new SensitiveInput(username: 'alice', password: 'super-secret');

        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a sensitive value stays readable though toArray() omits it.
     *
     * @return void
     */
    public function testFromKeepsSensitiveValueReadableOnInstance(): void
    {
        $input = SensitiveInput::from(['username' => 'alice', 'password' => 'super-secret']);

        self::assertSame('super-secret', $input->password);
        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a sensitive property promoted by a parent is omitted.
     *
     * @return void
     */
    public function testToArrayOmitsSensitiveParameterFromInheritedConstructor(): void
    {
        $input = new class (username: 'alice', password: 'super-secret') extends SensitiveBaseInput {};

        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a sensitive parent property stays omitted in a subclass.
     *
     * @return void
     */
    public function testToArrayOmitsSensitiveParameterWhenSubclassRedeclaresConstructor(): void
    {
        $input = new class (note: 'hello') extends SensitiveBaseInput {
            /**
             * Create a new instance promoting an extra field.
             *
             * @param  string  $note
             */
            public function __construct(

                /** A note promoted by the subclass constructor. */
                public readonly string $note = '',
            ) {
                parent::__construct(username: 'alice', password: 'super-secret');
            }
        };

        self::assertSame(['note' => 'hello', 'username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a sensitive property promoted by a trait is omitted.
     *
     * @return void
     */
    public function testToArrayOmitsSensitiveParameterFromTraitConstructor(): void
    {
        $input = new class (username: 'alice', password: 'super-secret') extends Payload {
            use SensitiveCredentials;
        };

        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a sensitive property from an aliased trait is omitted.
     *
     * @return void
     */
    public function testToArrayOmitsSensitiveParameterFromAliasedTraitConstructor(): void
    {
        $input = new class (note: 'hello') extends Payload {
            use SensitiveCredentials {
                __construct as private initCredentials;
            }

            /**
             * Create a new instance that delegates to the trait constructor.
             *
             * @param  string  $note
             */
            public function __construct(

                /** A note promoted by the payload constructor. */
                public readonly string $note = '',
            ) {
                $this->initCredentials(username: 'alice', password: 'super-secret');
            }
        };

        self::assertSame(['note' => 'hello', 'username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a trait without a constructor leaves the summary unchanged.
     *
     * @return void
     */
    public function testToArrayToleratesTraitWithoutConstructor(): void
    {
        $input = new class (username: 'alice', password: 'super-secret') extends Payload {
            use DescribesInput, SensitiveCredentials;
        };

        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a subclass overriding toArray() controls the summary fully.
     *
     * @return void
     */
    public function testToArrayOverrideTakesPrecedenceOverSensitiveMarker(): void
    {
        $input = new class (password: 'super-secret') extends Payload {
            /**
             * Create a new instance with a sensitive promoted property.
             *
             * @param  string  $password
             */
            public function __construct(

                /** The password; marked sensitive. */
                #[\SensitiveParameter]
                public readonly string $password = '',
            ) {}

            /**
             * Return a summary that deliberately includes the password.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public function toArray(): array
            {
                return ['password' => $this->password];
            }
        };

        self::assertSame(['password' => 'super-secret'], $input->toArray());
    }

    /**
     * Test that sensitivity is resolved per class.
     *
     * @return void
     */
    public function testSensitivityIsResolvedPerPayloadClass(): void
    {
        $marked = new SensitiveInput(username: 'alice', password: 'super-secret');

        $unmarked = new class (password: 'plain') extends Payload {
            /**
             * Create a new instance with an unmarked password property.
             *
             * @param  string  $password
             */
            public function __construct(

                /** The password; not marked sensitive. */
                public readonly string $password = '',
            ) {}
        };

        self::assertSame(['username' => 'alice'], $marked->toArray());
        self::assertSame(['password' => 'plain'], $unmarked->toArray());
        self::assertSame(['username' => 'alice'], $marked->toArray());
    }

    /**
     * Test that a snake_case body key hydrates its camelCase property.
     *
     * @return void
     */
    public function testFromHydratesCamelCasePropertyFromSnakeCaseKey(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a camelCase promoted property.
             *
             * @param  string  $clientName
             */
            public function __construct(

                /** The client name, read from the client_name body key. */
                public readonly string $clientName = '',
            ) {}

            /**
             * Validate the client name under its snake_case body key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['client_name' => ['required', 'string']];
            }
        };

        self::assertSame('Acme', $definition::from(['client_name' => 'Acme'])->clientName);
    }

    /**
     * Test that a body key ending in a digit hydrates its camelCase property.
     *
     * @return void
     */
    public function testFromHydratesCamelCasePropertyFromKeyEndingInDigit(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a property whose name ends in a digit.
             *
             * @param  string  $addressLine1
             */
            public function __construct(

                /** The first address line, read from address_line_1. */
                public readonly string $addressLine1 = '',
            ) {}

            /**
             * Validate the first address line under its snake_case body key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['address_line_1' => ['required', 'string']];
            }
        };

        self::assertSame('1 High Street', $definition::from(['address_line_1' => '1 High Street'])->addressLine1);
    }

    /**
     * Test that a property named exactly after its rule key hydrates.
     *
     * @return void
     */
    public function testFromHydratesExactNameProperties(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with exact-name promoted properties.
             *
             * @param  string  $city
             * @param  string  $postal_code
             */
            public function __construct(

                /** The city, named exactly after its rule key. */
                public readonly string $city = '',

                /** The postal code, a snake_case property named after its key. */
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps
                public readonly string $postal_code = '',
            ) {}

            /**
             * Validate the city and the postal code.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'city'        => ['required', 'string'],
                    'postal_code' => ['required', 'string'],
                ];
            }
        };

        $body  = ['city' => 'London', 'postal_code' => 'SW1A 1AA'];
        $input = $definition::from($body);

        self::assertSame('London', $input->city);
        self::assertSame('SW1A 1AA', $input->postal_code);
        self::assertSame($body, $input->toArray());
    }

    /**
     * Test that an exact-name rule key wins over a camelCase-equivalent one.
     *
     * @return void
     */
    public function testExactRuleKeyWinsOverCamelCaseMatch(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a camelCase promoted property.
             *
             * @param  string  $clientName
             */
            public function __construct(

                /** The client name, read from the exact clientName body key. */
                public readonly string $clientName = '',
            ) {}

            /**
             * Validate the snake_case key before the exact-name key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'client_name' => ['required', 'string'],
                    'clientName'  => ['required', 'string'],
                ];
            }
        };

        $input = $definition::from(['client_name' => 'Snake', 'clientName' => 'Exact']);

        self::assertSame('Exact', $input->clientName);
        self::assertSame(['clientName' => 'Exact'], $input->toArray());
    }

    /**
     * Test that the first of two keys camelCasing to one name hydrates.
     *
     * @return void
     */
    public function testFirstDeclaredKeyWinsWhenKeysCamelCaseToSameName(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a camelCase promoted property.
             *
             * @param  string  $clientName
             */
            public function __construct(

                /** The client name, read from the first declared matching key. */
                public readonly string $clientName = '',
            ) {}

            /**
             * Validate two keys that both camelCase to clientName.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'client_name' => ['required', 'string'],
                    'client-name' => ['required', 'string'],
                ];
            }
        };

        $input = $definition::from(['client_name' => 'First', 'client-name' => 'Second']);

        self::assertSame('First', $input->clientName);
        self::assertSame(['client_name' => 'First'], $input->toArray());
    }

    /**
     * Test that dotted rule keys map by their first segment.
     *
     * @return void
     */
    public function testFromHydratesPropertyFromFirstSegmentOfDottedRule(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with array-typed promoted properties.
             *
             * @param  array<string, string>  $billingAddress
             * @param  list<array<string, string>>  $items
             */
            public function __construct(

                /** The billing address, read whole from billing_address. */
                public readonly array $billingAddress = [],

                /** The line items, read whole from items. */
                public readonly array $items = [],
            ) {}

            /**
             * Validate only nested fields, with no rule on either parent.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'billing_address.line_1' => ['required', 'string'],
                    'items.*.sku'            => ['required', 'string'],
                ];
            }
        };

        $input = $definition::from([
            'billing_address' => ['line_1' => '1 High Street'],
            'items'           => [['sku' => 'A1'], ['sku' => 'B2']],
        ]);

        self::assertSame(['line_1' => '1 High Street'], $input->billingAddress);
        self::assertSame([['sku' => 'A1'], ['sku' => 'B2']], $input->items);
    }

    /**
     * Test that rule keys and parameters without a counterpart are left alone.
     *
     * @return void
     */
    public function testUnmatchedRuleKeysAndParametersAreIgnored(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a ruled and an unruled property.
             *
             * @param  string  $city
             * @param  string  $countryCode
             */
            public function __construct(

                /** The city, validated and hydrated. */
                public readonly string $city = '',

                /** The country code, which no rule validates. */
                public readonly string $countryCode = 'GB',
            ) {}

            /**
             * Validate the city and a key that no parameter reads.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'city'       => ['required', 'string'],
                    'terms_seen' => ['required', 'accepted'],
                ];
            }
        };

        $input = $definition::from(['city' => 'Paris', 'terms_seen' => 'yes', 'country_code' => 'FR']);

        self::assertSame('Paris', $input->city);
        self::assertSame('GB', $input->countryCode);
        self::assertSame(['city' => 'Paris'], $input->toArray());

        $this->expectException(ValidationException::class);

        $definition::from(['city' => 'Paris']);
    }

    /**
     * Test that toArray() keys each mapped property by its rule key.
     *
     * @return void
     */
    public function testToArrayReturnsRuleKeyForm(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with camelCase promoted properties.
             *
             * @param  string  $clientName
             * @param  int|null  $unitPrice
             */
            public function __construct(

                /** The client name, keyed as client_name in the summary. */
                public readonly string $clientName = '',

                /** The unit price, keyed as unit_price in the summary. */
                public readonly ?int $unitPrice = null,
            ) {}

            /**
             * Validate both fields under their snake_case body keys.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'client_name' => ['required', 'string'],
                    'unit_price'  => ['required', 'integer'],
                ];
            }
        };

        $body = ['client_name' => 'Acme', 'unit_price' => 250];

        self::assertSame($body, $definition::from($body)->toArray());
        self::assertSame(['client_name' => 'Direct', 'unit_price' => null], (new $definition(clientName: 'Direct'))->toArray());
    }

    /**
     * Test that toArray() keeps the property name when no rule key maps to it.
     *
     * @return void
     */
    public function testToArrayKeepsPropertyNameWhenNoRuleMapsIt(): void
    {
        $input = new class (nickName: 'Ace') extends Payload {
            /**
             * Create a new instance with a property no rule validates.
             *
             * @param  string  $nickName
             */
            public function __construct(

                /** The nickname, which no rule key maps. */
                public readonly string $nickName = '',
            ) {}
        };

        self::assertSame(['nickName' => 'Ace'], $input->toArray());
    }

    /**
     * Test that a backed enum on a mapped property is cast and validated.
     *
     * @return void
     */
    public function testFromCastsEnumOnMappedPropertyAndFailsUnderBodyKey(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a camelCase enum property.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $clientStatus
             */
            public function __construct(

                /** The client status, read from client_status. */
                public readonly ?StubStatusEnum $clientStatus = null,
            ) {}

            /**
             * Validate the status as a plain string, without an enum rule.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['client_status' => ['sometimes', 'nullable', 'string']];
            }
        };

        self::assertSame(StubStatusEnum::ACTIVE, $definition::from(['client_status' => 'active'])->clientStatus);

        try {
            $definition::from(['client_status' => 'not_a_valid_status']);

            self::fail('Expected a ValidationException for the invalid enum value.');
        } catch (ValidationException $exception) {
            self::assertSame(['client_status' => ['The selected client status is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that an invalid camelCase-keyed enum names the display field.
     *
     * @return void
     */
    public function testEnumCastFailureNamesCamelCaseKeyInDisplayForm(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with an exact-name enum property.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $clientStatus
             */
            public function __construct(

                /** The client status, read from the exact clientStatus key. */
                public readonly ?StubStatusEnum $clientStatus = null,
            ) {}

            /**
             * Validate the status as a plain string, without an enum rule.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['clientStatus' => ['sometimes', 'nullable', 'string']];
            }
        };

        try {
            $definition::from(['clientStatus' => 'not_a_valid_status']);

            self::fail('Expected a ValidationException for the invalid enum value.');
        } catch (ValidationException $exception) {
            self::assertSame(['clientStatus' => ['The selected client status is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that a sensitive camelCase property is readable but not summarised.
     *
     * @return void
     */
    public function testSensitiveMappedPropertyIsReadableButOmitted(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a sensitive camelCase property.
             *
             * @param  string  $username
             * @param  string  $apiSecret
             */
            public function __construct(

                /** The username; exposed in the summary. */
                public readonly string $username = '',

                /** The API secret, read from api_secret; left out of the summary. */
                #[\SensitiveParameter]
                public readonly string $apiSecret = '',
            ) {}

            /**
             * Validate the username and the API secret.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return [
                    'username'   => ['required', 'string'],
                    'api_secret' => ['required', 'string'],
                ];
            }
        };

        $input = $definition::from(['username' => 'alice', 'api_secret' => 'super-secret']);

        self::assertSame('super-secret', $input->apiSecret);
        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that key maps are resolved per class.
     *
     * @return void
     */
    public function testKeyMapsAreResolvedPerPayloadClass(): void
    {
        $mapped = new class (clientName: 'Mapped') extends Payload {
            /**
             * Create a new instance whose property a rule key maps.
             *
             * @param  string  $clientName
             */
            public function __construct(

                /** The client name, keyed as client_name. */
                public readonly string $clientName = '',
            ) {}

            /**
             * Validate the client name under its snake_case body key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['client_name' => ['required', 'string']];
            }
        };

        $unmapped = new class (clientName: 'Unmapped') extends Payload {
            /**
             * Create a new instance whose property no rule key maps.
             *
             * @param  string  $clientName
             */
            public function __construct(

                /** The client name, keyed by its own name. */
                public readonly string $clientName = '',
            ) {}
        };

        self::assertSame(['client_name' => 'Mapped'], $mapped->toArray());
        self::assertSame(['clientName' => 'Unmapped'], $unmapped->toArray());
        self::assertSame(['client_name' => 'Mapped'], $mapped->toArray());
    }

    /**
     * Test that an integer top-level rule key leaves body keys resolvable.
     *
     * @return void
     */
    public function testFromResolvesANumericTopLevelRuleKey(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a snake_case mapped property.
             *
             * @param  string  $clientName
             */
            public function __construct(

                /** The client name, keyed as client_name in the body. */
                public readonly string $clientName = '',
            ) {}

            /**
             * Validate a field keyed by an index beside the client name.
             *
             * @return array<array-key, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['0' => ['required', 'string'], 'client_name' => ['required', 'string']];
            }
        };

        $input = $definition::from(Request::create('/', 'POST', ['0' => 'first', 'client_name' => 'Acme']));

        self::assertSame('Acme', $input->clientName);
        self::assertSame(['client_name' => 'Acme'], $input->toArray());

        try {
            $definition::from(['client_name' => 'Acme']);

            self::fail('Expected a ValidationException for the missing index field.');
        } catch (ValidationException $exception) {
            self::assertSame(['0'], array_map(strval(...), array_keys($exception->errors())));
        }
    }

    /**
     * Test that messages() overrides a field's rule and a rule everywhere.
     *
     * @return void
     */
    public function testMessagesOverrideAFieldRuleAndARule(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with two required fields.
             *
             * @param  string  $title
             * @param  string  $summary
             */
            public function __construct(

                /** A required title. */
                public readonly string $title = '',

                /** A required summary. */
                public readonly string $summary = '',
            ) {}

            /**
             * Require both fields.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['title' => ['required'], 'summary' => ['required']];
            }

            /**
             * Reword the title's required rule and every other required rule.
             *
             * @return array<string, string>
             */
            #[\Override]
            public static function messages(): array
            {
                return ['title.required' => 'Name the widget.', 'required' => 'Fill in the :attribute.'];
            }
        };

        try {
            $definition::from([]);

            self::fail('Expected a ValidationException for both missing fields.');
        } catch (ValidationException $exception) {
            self::assertSame(['title' => ['Name the widget.'], 'summary' => ['Fill in the summary.']], $exception->errors());
        }
    }

    /**
     * Test that attributes() renames a field in its messages.
     *
     * @return void
     */
    public function testAttributesRenameTheFieldInItsMessages(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a mapped required field.
             *
             * @param  string  $clientName
             */
            public function __construct(

                /** The client name, read from client_name. */
                public readonly string $clientName = '',
            ) {}

            /**
             * Require the client name.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['client_name' => ['required']];
            }

            /**
             * Name the client name field for messages.
             *
             * @return array<string, string>
             */
            #[\Override]
            public static function attributes(): array
            {
                return ['client_name' => 'organisation name'];
            }
        };

        try {
            $definition::from([]);

            self::fail('Expected a ValidationException for the missing client name.');
        } catch (ValidationException $exception) {
            self::assertSame(['client_name' => ['The organisation name field is required.']], $exception->errors());
        }
    }

    /**
     * Test that one enum message rewords the declared rule and the cast.
     *
     * @return void
     */
    public function testEnumMessageRewordsTheDeclaredRuleAndTheCast(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with an int-backed enum.
             *
             * @param  \Tests\Fixtures\Enums\Magnitude|null  $magnitude
             */
            public function __construct(

                /** An int-backed enum checked by Laravel's enum rule. */
                public readonly ?Magnitude $magnitude = null,
            ) {}

            /**
             * Declare the enum rule on the magnitude.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['magnitude' => [Rule::enum(Magnitude::class)]];
            }

            /**
             * Reword the magnitude's enum failure.
             *
             * @return array<string, string>
             */
            #[\Override]
            public static function messages(): array
            {
                return ['magnitude.enum' => 'Pick a known :attribute.'];
            }

            /**
             * Name the magnitude field for messages.
             *
             * @return array<string, string>
             */
            #[\Override]
            public static function attributes(): array
            {
                return ['magnitude' => 'size'];
            }
        };

        // Laravel's rule refuses 7 and passes "01", which only the cast refuses
        foreach ([7, '01'] as $magnitude) {
            try {
                $definition::from(['magnitude' => $magnitude]);

                self::fail('Expected a ValidationException for the unknown magnitude.');
            } catch (ValidationException $exception) {
                self::assertSame(['magnitude' => ['Pick a known size.']], $exception->errors());
            }
        }
    }

    /**
     * Test that the base messages() and attributes() are empty.
     *
     * @return void
     */
    public function testBaseMessagesAndAttributesAreEmpty(): void
    {
        self::assertSame([], SampleInput::messages());
        self::assertSame([], SampleInput::attributes());
    }

    /**
     * Test that a precognitive request validates only the fields it names.
     *
     * @return void
     */
    public function testPrecognitiveRequestValidatesOnlyTheNamedFields(): void
    {
        try {
            StorePayload::from(self::precognitiveRequest(['quantity' => 'x'], 'quantity'));

            self::fail('Expected a ValidationException for the named quantity.');
        } catch (ValidationException $exception) {
            self::assertSame(['quantity' => ['The quantity field must be an integer.']], $exception->errors());
        }
    }

    /**
     * Test that a precognitive request keeps the enum check on a named field.
     *
     * @return void
     */
    public function testPrecognitiveRequestKeepsTheEnumCheckOnANamedField(): void
    {
        try {
            BackedEnumInput::from(self::precognitiveRequest(['magnitude' => '01'], 'magnitude'));

            self::fail('Expected a ValidationException for the named magnitude.');
        } catch (ValidationException $exception) {
            self::assertSame(['magnitude' => ['The selected magnitude is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that a precognitive request skips the enum check on a field it does
     * not name.
     *
     * @return void
     */
    public function testPrecognitiveRequestSkipsTheEnumCheckOnAnUnnamedField(): void
    {
        try {
            BackedEnumInput::from(self::precognitiveRequest(['magnitude' => '01', 'status' => 'active'], 'status'));

            self::fail('Expected the Precognition success abort.');
        } catch (HttpException $exception) {
            self::assertSame(204, $exception->getStatusCode());
        }
    }

    /**
     * Test that a precognitive request whose named fields pass aborts with a
     * Precognition success.
     *
     * @return void
     */
    public function testPrecognitiveRequestWhoseNamedFieldsPassAbortsWithSuccess(): void
    {
        try {
            StorePayload::from(self::precognitiveRequest(['quantity' => 3], 'quantity'));

            self::fail('Expected the Precognition success abort.');
        } catch (HttpException $exception) {
            self::assertSame(204, $exception->getStatusCode());
            self::assertSame(['Precognition-Success' => 'true'], $exception->getHeaders());
        }
    }

    /**
     * Test that the Validate-Only header is ignored on a request that is not
     * precognitive.
     *
     * @return void
     */
    public function testValidateOnlyHeaderIsIgnoredWithoutPrecognition(): void
    {
        $request = Request::create('/', 'POST', ['quantity' => 3]);
        $request->headers->set('Precognition-Validate-Only', 'quantity');

        try {
            StorePayload::from($request);

            self::fail('Expected a ValidationException for the missing title.');
        } catch (ValidationException $exception) {
            self::assertSame(['title' => ['The title field is required.']], $exception->errors());
        }
    }

    /**
     * Build a precognitive request that validates only the given fields.
     *
     * @param  array<string, mixed>  $body
     * @param  string  $validateOnly
     * @return \Illuminate\Http\Request
     */
    private static function precognitiveRequest(array $body, string $validateOnly): Request
    {
        $request = Request::create('/', 'POST', $body);

        $request->attributes->set('precognitive', true);
        $request->headers->set('Precognition-Validate-Only', $validateOnly);

        return $request;
    }
}
