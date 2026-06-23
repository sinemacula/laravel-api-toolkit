<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateRelationInterfaces;
use Tests\Fixtures\Resources\OrganizationResource;

/**
 * Tests for the ValidateRelationInterfaces validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateRelationInterfaces::class)]
final class ValidateRelationInterfacesTest extends TestCase
{
    /**
     * Test no errors when relation class implements interface.
     *
     * @return void
     */
    public function testNoErrorsWhenRelationClassImplementsInterface(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test reports relation class not implementing interface.
     *
     * @return void
     */
    public function testReportsRelationClassNotImplementingInterface(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: \stdClass::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('organization', $errors[0]->fieldKey);
        static::assertStringContainsString(\stdClass::class, $errors[0]->defect);
        static::assertStringContainsString(ApiResourceInterface::class, $errors[0]->defect);
    }

    /**
     * Test skips non-existent classes in interface validation.
     *
     * @return void
     */
    public function testSkipsNonExistentClassesInInterfaceValidation(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: 'App\NonExistent\Resource',
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that null field definitions are skipped without errors.
     *
     * @return void
     */
    public function testSkipsNullFieldDefinitions(): void
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, [
            'ghost' => null,
            'bad'   => $this->makeField(\stdClass::class),
        ]);
        $reflection->getProperty('counts')->setValue($schema, []);

        $rule   = new ValidateRelationInterfaces;
        $errors = [];

        $warnings = $this->captureWarnings(fn () => $rule->validate('App\Http\Resources\UserResource', null, $schema), $errors);

        static::assertSame([], $warnings);
        static::assertCount(1, $errors);
        static::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that validation continues past non-existent classes and still
     * reports later defects.
     *
     * @return void
     */
    public function testContinuesPastNonExistentClasses(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'missing' => $this->makeField('App\NonExistent\Resource'),
                'bad'     => $this->makeField(\stdClass::class),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that every non-conforming relation class is reported.
     *
     * @return void
     */
    public function testReportsAllNonConformingRelationClasses(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'first'  => $this->makeField(\stdClass::class),
                'second' => $this->makeField(\SplStack::class),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationInterfaces;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(2, $errors);
        static::assertSame('first', $errors[0]->fieldKey);
        static::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Run the given callback while capturing any raised PHP warnings.
     *
     * @param  callable(): array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>  $callback
     * @param  array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>  $errors
     * @return array<int, string>
     */
    private function captureWarnings(callable $callback, array &$errors): array
    {
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $errors = $callback();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    /**
     * Create a compiled field definition with the given resource class.
     *
     * @param  string  $resource
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(string $resource): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: 'relation',
            compute: null,
            relation: 'relation',
            resource: $resource,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );
    }
}
