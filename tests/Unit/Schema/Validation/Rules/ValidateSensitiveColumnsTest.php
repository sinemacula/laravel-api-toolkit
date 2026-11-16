<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Sum;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSensitiveColumns;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateSensitiveColumns validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateSensitiveColumns::class)]
final class ValidateSensitiveColumnsTest extends TestCase
{
    /** @var string The defect reported for a filterable password declaration. */
    private const string PASSWORD_FILTERABLE_DEFECT = 'Field is declared filterable against "password", which is configured as a sensitive column and may never be queried';

    /**
     * Test that a field declaring an ordinary column is accepted.
     *
     * @return void
     */
    public function testNoErrorsForOrdinaryColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(filterable: 'name', sortable: 'name')],
        );

        self::assertSame([], (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that a sensitive column with no query declaration is accepted.
     *
     * @return void
     */
    public function testNoErrorsForUndeclaredSensitiveColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField()],
        );

        self::assertSame([], (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that a sensitive column declared filterable is reported.
     *
     * @return void
     */
    public function testReportsFilterableSensitiveColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField(filterable: 'password')],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('password', $errors[0]->fieldKey);
        self::assertSame(self::PASSWORD_FILTERABLE_DEFECT, $errors[0]->defect);
    }

    /**
     * Test that a sensitive sortable column is reported as a sort defect.
     *
     * @return void
     */
    public function testReportsSortableSensitiveColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['verified' => $this->makeField(sortable: 'email_verified_at')],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared sortable against "email_verified_at", which is configured as a sensitive column and may never be queried',
            $errors[0]->defect,
        );
    }

    /**
     * Test that both declarations on the same field are reported independently.
     *
     * @return void
     */
    public function testReportsBothDeclarationsOnTheSameField(): void
    {
        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField(filterable: 'password', sortable: 'password')],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(2, $errors);
        self::assertSame(self::PASSWORD_FILTERABLE_DEFECT, $errors[0]->defect);
        self::assertSame(
            'Field is declared sortable against "password", which is configured as a sensitive column and may never be queried',
            $errors[1]->defect,
        );
    }

    /**
     * Test that a sensitive searchable column is reported.
     *
     * @return void
     */
    public function testReportsSearchableSensitiveColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField(searchable: 'password')],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('password', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared searchable against "password", which is configured as a sensitive column and may never be queried',
            $errors[0]->defect,
        );
    }

    /**
     * Test that every offending field is reported, not just the first.
     *
     * @return void
     */
    public function testReportsEveryOffendingField(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'name'     => $this->makeField(filterable: 'name'),
                'password' => $this->makeField(filterable: 'password'),
                'token'    => $this->makeField(sortable: 'token'),
            ],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('password', $errors[0]->fieldKey);
        self::assertSame('token', $errors[1]->fieldKey);
    }

    /**
     * Test that an aggregate over a sensitive column is reported under its key,
     * alongside the field defects, while a columnless or ordinary one passes.
     *
     * @return void
     */
    public function testReportsAnAggregateOverASensitiveColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField(filterable: 'password')],
            aggregates: [
                'users_sum_token' => $this->makeAggregate(AggregateFunction::SUM, 'token'),
                'users_count'     => $this->makeAggregate(AggregateFunction::COUNT, null),
                'users_max_name'  => $this->makeAggregate(AggregateFunction::MAX, 'name'),
            ],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('password', $errors[0]->fieldKey);
        self::assertSame(self::PASSWORD_FILTERABLE_DEFECT, $errors[0]->defect);
        self::assertSame(UserResource::class, $errors[1]->resourceClass);
        self::assertSame('users_sum_token', $errors[1]->fieldKey);
        self::assertSame('Aggregate is declared over "token", which is configured as a sensitive column and may never be aggregated', $errors[1]->defect);
    }

    /**
     * Test that the aggregate check honours the configured list.
     *
     * @return void
     */
    public function testTheAggregateCheckHonoursTheConfiguredList(): void
    {
        Config::set('api-toolkit.resources.sensitive_columns', ['salary']);

        $defaults = Sum::of('users', 'token')->toArray();

        $schema = new CompiledSchema([], [
            'users_sum_token'  => $this->makeAggregate(AggregateFunction::SUM, 'token'),
            'users_sum_salary' => $this->makeAggregate(AggregateFunction::SUM, 'salary'),
        ]);

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertArrayHasKey('__aggregate__:users_sum_token', $defaults);
        self::assertSame(['users_sum_salary'], array_column($errors, 'fieldKey'));
    }

    /**
     * Test that a column merely containing a sensitive name is accepted.
     *
     * @return void
     */
    public function testNoErrorsForColumnMerelyContainingASensitiveName(): void
    {
        $schema = new CompiledSchema(
            fields: ['password_set_at' => $this->makeField(filterable: 'password_set_at')],
        );

        self::assertSame([], (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that the configured list can add and drop sensitive names.
     *
     * @return void
     */
    public function testHonoursTheConfiguredList(): void
    {
        Config::set('api-toolkit.resources.sensitive_columns', ['api_secret']);

        $schema = new CompiledSchema(
            fields: [
                'password'   => $this->makeField(filterable: 'password'),
                'api_secret' => $this->makeField(filterable: 'api_secret'),
            ],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('api_secret', $errors[0]->fieldKey);
    }

    /**
     * Test that a configured entry matches only with the same value and type.
     *
     * @return void
     */
    public function testConfiguredEntriesAreComparedStrictly(): void
    {
        Config::set('api-toolkit.resources.sensitive_columns', [42, 'password']);

        $schema = new CompiledSchema(
            fields: [
                'code'     => $this->makeField(filterable: '42'),
                'password' => $this->makeField(filterable: 'password'),
            ],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(self::PASSWORD_FILTERABLE_DEFECT, $errors[0]->defect);
    }

    /**
     * Test that a non-list configured value falls back to the shipped names.
     *
     * @return void
     */
    public function testNonArrayConfigurationFallsBackToTheShippedDefaults(): void
    {
        Config::set('api-toolkit.resources.sensitive_columns', 'password');

        $schema = new CompiledSchema(
            fields: ['token' => $this->makeField(filterable: 'token')],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared filterable against "token", which is configured as a sensitive column and may never be queried',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a config missing the list falls back to the shipped names.
     *
     * @return void
     */
    public function testConfigurationWithoutTheKeyFallsBackToTheShippedDefaults(): void
    {
        Config::set('api-toolkit.resources', ['fixed_fields' => ['id', '_type']]);

        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField(filterable: 'password')],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(self::PASSWORD_FILTERABLE_DEFECT, $errors[0]->defect);
    }

    /**
     * Test that the shipped config declares exactly the fallback names.
     *
     * @return void
     */
    public function testShippedConfigMatchesTheFallbackDefaults(): void
    {
        self::assertSame(ValidateSensitiveColumns::DEFAULTS, Config::get('api-toolkit.resources.sensitive_columns'));
    }

    /**
     * Create a compiled field definition with the given query declarations.
     *
     * @param  string|null  $filterable
     * @param  string|null  $sortable
     * @param  string|null  $searchable
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(?string $filterable = null, ?string $sortable = null, ?string $searchable = null): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
            filterable: $filterable,
            sortable: $sortable,
            searchable: $searchable,
        );
    }

    /**
     * Build a compiled aggregate over the users relation.
     *
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string|null  $column
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private function makeAggregate(AggregateFunction $function, ?string $column): CompiledAggregateDefinition
    {
        return new CompiledAggregateDefinition(
            presentKey: $function->defaultKey('users', $column),
            relation: 'users',
            function: $function,
            column: $column,
            constraint: null,
            isDefault: false,
            guards: [],
        );
    }
}
