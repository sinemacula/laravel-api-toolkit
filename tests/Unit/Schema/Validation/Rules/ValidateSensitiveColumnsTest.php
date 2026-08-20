<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
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
            counts: [],
        );

        self::assertSame([], (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that a sensitive column carrying no query declaration is accepted,
     * so presenting the field is untouched by the rule.
     *
     * @return void
     */
    public function testNoErrorsForUndeclaredSensitiveColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField()],
            counts: [],
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
            counts: [],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('password', $errors[0]->fieldKey);
        self::assertSame(self::PASSWORD_FILTERABLE_DEFECT, $errors[0]->defect);
    }

    /**
     * Test that a sensitive column declared sortable is reported under the sort
     * declaration rather than the filter one.
     *
     * @return void
     */
    public function testReportsSortableSensitiveColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['verified' => $this->makeField(sortable: 'email_verified_at')],
            counts: [],
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
            counts: [],
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
            counts: [],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('password', $errors[0]->fieldKey);
        self::assertSame('token', $errors[1]->fieldKey);
    }

    /**
     * Test that a column merely containing a sensitive name is accepted, so the
     * list matches whole column names rather than fragments.
     *
     * @return void
     */
    public function testNoErrorsForColumnMerelyContainingASensitiveName(): void
    {
        $schema = new CompiledSchema(
            fields: ['password_set_at' => $this->makeField(filterable: 'password_set_at')],
            counts: [],
        );

        self::assertSame([], (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that the rule reads the configured list, so an application may both
     * add a column of its own and drop a shipped default.
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
            counts: [],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('api_secret', $errors[0]->fieldKey);
    }

    /**
     * Test that a non-string entry in the configured list is ignored rather
     * than compared against a column name.
     *
     * @return void
     */
    public function testIgnoresNonStringConfiguredEntries(): void
    {
        Config::set('api-toolkit.resources.sensitive_columns', [42, 'password']);

        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField(filterable: 'password')],
            counts: [],
        );

        $errors = (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(self::PASSWORD_FILTERABLE_DEFECT, $errors[0]->defect);
    }

    /**
     * Test that a configured value that is not a list degrades to no sensitive
     * columns rather than throwing at boot.
     *
     * @return void
     */
    public function testNonArrayConfigurationYieldsNoSensitiveColumns(): void
    {
        Config::set('api-toolkit.resources.sensitive_columns', 'password');

        $schema = new CompiledSchema(
            fields: ['password' => $this->makeField(filterable: 'password')],
            counts: [],
        );

        self::assertSame([], (new ValidateSensitiveColumns)->validate(UserResource::class, null, $schema));
    }

    /**
     * Create a compiled field definition with the given query declarations.
     *
     * @param  string|null  $filterable
     * @param  string|null  $sortable
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(?string $filterable = null, ?string $sortable = null): CompiledFieldDefinition
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
        );
    }
}
