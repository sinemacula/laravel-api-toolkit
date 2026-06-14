<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateRelationClasses;
use Tests\Fixtures\Resources\OrganizationResource;

/**
 * Tests for the ValidateRelationClasses validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateRelationClasses::class)]
class ValidateRelationClassesTest extends TestCase
{
    /**
     * Test no errors for schema with existing relation classes.
     *
     * @return void
     */
    public function testNoErrorsForSchemaWithExistingRelationClasses(): void
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

        $rule   = new ValidateRelationClasses;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test reports non-existent relation class.
     *
     * @return void
     */
    public function testReportsNonExistentRelationClass(): void
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

        $rule   = new ValidateRelationClasses;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('organization', $errors[0]->fieldKey);
        static::assertStringContainsString('App\NonExistent\Resource', $errors[0]->defect);
        static::assertStringContainsString('does not exist', $errors[0]->defect);
    }

    /**
     * Test skips fields without resource.
     *
     * @return void
     */
    public function testSkipsFieldsWithoutResource(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'name' => new CompiledFieldDefinition(
                    accessor: 'name',
                    compute: null,
                    relation: null,
                    resource: null,
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

        $rule   = new ValidateRelationClasses;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that validation continues past fields without resources and still
     * reports later defects.
     *
     * @return void
     */
    public function testContinuesPastFieldsWithoutResource(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'clean' => $this->makeField(null),
                'bad'   => $this->makeField('App\NonExistent\Resource'),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationClasses;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that every non-existent relation class is reported.
     *
     * @return void
     */
    public function testReportsAllNonExistentRelationClasses(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'first'  => $this->makeField('App\NonExistent\FirstResource'),
                'second' => $this->makeField('App\NonExistent\SecondResource'),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationClasses;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(2, $errors);
        static::assertSame('first', $errors[0]->fieldKey);
        static::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Create a compiled field definition with the given resource class.
     *
     * @param  string|null  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition
     */
    private function makeField(?string $resource): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: 'relation',
            compute: null,
            relation: $resource === null ? null : 'relation',
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
