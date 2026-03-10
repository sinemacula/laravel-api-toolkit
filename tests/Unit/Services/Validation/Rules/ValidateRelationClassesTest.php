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
}
