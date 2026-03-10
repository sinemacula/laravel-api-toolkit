<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateRelationMethods;
use Tests\Fixtures\Models\User;

/**
 * Tests for the ValidateRelationMethods validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateRelationMethods::class)]
class ValidateRelationMethodsTest extends TestCase
{
    /**
     * Test no errors for relation methods that exist on model.
     *
     * @return void
     */
    public function testNoErrorsForRelationMethodsThatExistOnModel(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: 'Tests\Fixtures\Resources\OrganizationResource',
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
                'posts' => new CompiledFieldDefinition(
                    accessor: 'posts',
                    compute: null,
                    relation: 'posts',
                    resource: 'Tests\Fixtures\Resources\PostResource',
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate('Tests\Fixtures\Resources\UserResource', User::class, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test reports relation method not existing on model.
     *
     * @return void
     */
    public function testReportsRelationMethodNotExistingOnModel(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'nonexistent' => new CompiledFieldDefinition(
                    accessor: 'nonexistent',
                    compute: null,
                    relation: 'nonexistent_relation',
                    resource: 'Tests\Fixtures\Resources\OrganizationResource',
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate('Tests\Fixtures\Resources\UserResource', User::class, $schema);

        static::assertCount(1, $errors);
        static::assertSame('nonexistent', $errors[0]->fieldKey);
        static::assertStringContainsString('nonexistent_relation', $errors[0]->defect);
        static::assertStringContainsString(User::class, $errors[0]->defect);
    }

    /**
     * Test skips when model class is null.
     *
     * @return void
     */
    public function testSkipsWhenModelClassIsNull(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: 'Tests\Fixtures\Resources\OrganizationResource',
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate('Tests\Fixtures\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test reports relation method missing on model for count definition.
     *
     * @return void
     */
    public function testReportsRelationMethodMissingOnModelForCountDefinition(): void
    {
        $schema = new CompiledSchema(
            fields: [],
            counts: [
                'nonexistent_count' => new CompiledCountDefinition(
                    presentKey: 'nonexistent_count',
                    relation: 'nonexistent_relation',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate('Tests\Fixtures\Resources\UserResource', User::class, $schema);

        static::assertCount(1, $errors);
        static::assertSame('nonexistent_count', $errors[0]->fieldKey);
        static::assertStringContainsString('nonexistent_relation', $errors[0]->defect);
        static::assertStringContainsString(User::class, $errors[0]->defect);
    }
}
