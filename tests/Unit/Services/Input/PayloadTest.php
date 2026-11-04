<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;
use SineMacula\ApiToolkit\Services\Input\Payload;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Input\SampleInput;
use Tests\Fixtures\Services\Input\Concerns\DescribesInput;
use Tests\Fixtures\Services\Input\Concerns\SensitiveCredentials;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\Fixtures\Services\Input\PlatformInput;
use Tests\Fixtures\Services\Input\SensitiveBaseInput;
use Tests\Fixtures\Services\Input\SensitiveInput;
use Tests\TestCase;

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
     * Test that an invalid enum value degrades to a validation failure when the
     * subclass omits Rule::enum, so the caller sees a 422 rather than a fatal
     * ValueError from the string-to-enum cast.
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
     * Test that a pure enum parameter is cast from the lowercase case name
     * under its snake_case body key.
     *
     * @return void
     */
    public function testFromHydratesPureEnumTypedFieldFromLowercaseName(): void
    {
        $input = PlatformInput::from(['primary_platform' => 'mobile_app']);

        self::assertSame(Platform::MOBILE_APP, $input->primaryPlatform);
    }

    /**
     * Test that a nullable pure enum parameter hydrates as null when the body
     * sends null or omits the key.
     *
     * @return void
     */
    public function testFromHydratesNullablePureEnumFieldAsNull(): void
    {
        self::assertNull(PlatformInput::from(['primary_platform' => null])->primaryPlatform);
        self::assertNull(PlatformInput::from([])->primaryPlatform);
    }

    /**
     * Test that a pure enum value in any form other than the exact lowercase
     * case name fails validation, keyed by the body key.
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
     * Test that a pure enum parameter whose rules omit the enum rule is still
     * cast from its lowercase case name.
     *
     * @return void
     */
    public function testFromCastsPureEnumFieldWithoutEnumRule(): void
    {
        self::assertSame(Platform::DESKTOP, PlatformInput::from(['fallback_platform' => 'desktop'])->fallbackPlatform);
    }

    /**
     * Test that an unknown pure enum value whose rules omit the enum rule fails
     * as a validation error naming the body key, rather than a fatal error.
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
     * Test that a non-string value for a pure enum parameter whose rules admit
     * it fails as a validation error naming the body key, rather than a type
     * error from the constructor.
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
     * Test that a value of the wrong type for a string-backed enum parameter
     * fails as a validation error naming the body key, rather than a type error
     * from the enum lookup.
     *
     * @return void
     */
    public function testFromRefusesWrongTypeForBackedEnumWithoutEnumRule(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with a backed enum promoted property.
             *
             * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum|null  $status
             */
            public function __construct(

                /** A backed enum property whose rules admit any value. */
                public readonly ?StubStatusEnum $status = null,
            ) {}

            /**
             * Require the status without constraining its type.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['status' => ['required']];
            }
        };

        try {
            $definition::from(['status' => 42]);

            self::fail('Expected a ValidationException for the wrongly typed enum value.');
        } catch (ValidationException $exception) {
            self::assertSame(['status' => ['The selected status is invalid.']], $exception->errors());
        }
    }

    /**
     * Test that an int-backed enum parameter is cast from an integer body
     * value, and from the numeric string a form body sends, both of which
     * Laravel's enum rule accepts.
     *
     * @return void
     */
    public function testFromHydratesIntBackedEnumFromInteger(): void
    {
        $definition = new class extends Payload {
            /**
             * Create a new instance with an int-backed enum promoted property.
             *
             * @param  \Tests\Fixtures\Enums\UserLevel|null  $level
             */
            public function __construct(

                /** An int-backed enum property. */
                public readonly ?UserLevel $level = null,
            ) {}

            /**
             * Validate the level against its enum.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function rules(): array
            {
                return ['level' => ['required', Rule::enum(UserLevel::class)]];
            }
        };

        self::assertSame(UserLevel::SILVER, $definition::from(['level' => 2])->level);
        self::assertSame(UserLevel::SILVER, $definition::from(['level' => '2'])->level);
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
     * Test that from() skips constructor parameters that are not promoted, so
     * such parameters are never hydrated from the validated input.
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
     * The enum cast only applies to string values, so an already-constructed
     * enum instance must be returned unchanged rather than re-cast.
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
     * Test that the base rules() returns an empty rule set when a subclass does
     * not override it, permitting construction from an empty source.
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
     * Test that Payload and its subclasses satisfy the input-schema contract,
     * so the exporter can read the rules through the shared interface.
     *
     * @return void
     */
    public function testPayloadImplementsInputSchemaContract(): void
    {
        self::assertTrue((new \ReflectionClass(Payload::class))->implementsInterface(DefinesInputSchema::class));
        self::assertInstanceOf(DefinesInputSchema::class, new SampleInput(city: 'London'));
    }

    /**
     * Test that the rules can be read statically with no instance or request,
     * which is what lets the exporter read them faithfully.
     *
     * @return void
     */
    public function testRulesAreReadableStaticallyWithoutInstance(): void
    {
        self::assertArrayHasKey('city', SampleInput::rules());
    }

    /**
     * Test that toArray() omits a promoted parameter marked sensitive while
     * keeping its unmarked siblings.
     *
     * @return void
     */
    public function testToArrayOmitsSensitiveParameters(): void
    {
        $input = new SensitiveInput(username: 'alice', password: 'super-secret');

        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a sensitive value hydrated by from() stays readable on the
     * instance even though toArray() leaves it out.
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
     * Test that a sensitive property promoted by an inherited parent
     * constructor is omitted.
     *
     * @return void
     */
    public function testToArrayOmitsSensitiveParameterFromInheritedConstructor(): void
    {
        $input = new class (username: 'alice', password: 'super-secret') extends SensitiveBaseInput {};

        self::assertSame(['username' => 'alice'], $input->toArray());
    }

    /**
     * Test that a sensitive property promoted by a parent constructor stays
     * omitted when the subclass redeclares its own constructor.
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
     * Test that a sensitive property promoted by a trait constructor is
     * omitted.
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
     * Test that a sensitive property promoted by an aliased trait constructor
     * stays omitted when the payload declares its own constructor.
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
     * Test that a trait without a constructor leaves the summary unchanged
     * while a sibling trait's sensitive property is still omitted.
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
     * Test that a subclass overriding toArray() keeps full control of the
     * summary, even for a parameter marked sensitive.
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
     * Test that sensitivity is resolved per class, so a marked field on one
     * payload does not hide a same-named unmarked field on another.
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
     * Test that a property named exactly after its rule key still hydrates,
     * including a snake_case property.
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
     * Test that a rule key matching a property exactly wins over another key
     * whose camelCase form is the same name, whichever is declared first.
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
     * Test that the first declared of two keys camelCasing to the same name
     * hydrates the property.
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
     * Test that dotted rule keys map by their first segment, hydrating the
     * property with the nested data unchanged.
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
     * Test that a rule key with no parameter is only validated, and that a
     * parameter with no rule keeps its default even when the body sends it.
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
        self::assertSame(['city' => 'Paris', 'countryCode' => 'GB'], $input->toArray());

        $this->expectException(ValidationException::class);

        $definition::from(['city' => 'Paris']);
    }

    /**
     * Test that toArray() keys each mapped property by its rule key, so the
     * summary of a hydrated payload has the body's keys.
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
     * Test that toArray() keeps a property's own name when no rule key maps to
     * it.
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
     * Test that a backed enum on a mapped property is cast, and that an invalid
     * value without Rule::enum fails under the body key.
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
     * Test that an invalid enum under a camelCase body key names the field in
     * the validator's display form.
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
     * Test that a sensitive camelCase property hydrated from its snake_case key
     * is readable but omitted from toArray().
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
     * Test that key maps are resolved per class, so one payload's rule keys do
     * not rename a same-named property on another.
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
}
