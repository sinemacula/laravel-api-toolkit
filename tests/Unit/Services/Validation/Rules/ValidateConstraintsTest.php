<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateConstraints;

/**
 * Tests for the ValidateConstraints validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 * @SuppressWarnings("php:S3011")
 *
 * @internal
 */
#[CoversClass(ValidateConstraints::class)]
class ValidateConstraintsTest extends TestCase
{
    /**
     * Test that no errors are returned for Closure constraints on fields and
     * count definitions.
     *
     * @return void
     */
    public function testNoErrorsForClosureConstraints(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: 'App\Http\Resources\OrganizationResource',
                    fields: null,
                    constraint: fn () => true,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [
                'posts_count' => new CompiledCountDefinition(
                    presentKey: 'posts_count',
                    relation: 'posts',
                    constraint: fn () => true,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that an error is reported for a non-Closure constraint on a count
     * definition.
     *
     * @return void
     */
    public function testReportsNonClosureConstraintOnCountDefinition(): void
    {
        $schema = $this->createSchemaWithUntypedCount('posts_count', 'not-a-closure');

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('posts_count', $errors[0]->fieldKey);
        static::assertSame('Constraint must be a Closure', $errors[0]->defect);
    }

    /**
     * Test that fields and counts without constraints produce no errors.
     *
     * @return void
     */
    public function testSkipsNullConstraints(): void
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
            counts: [
                'posts_count' => new CompiledCountDefinition(
                    presentKey: 'posts_count',
                    relation: 'posts',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateConstraints;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Create a CompiledSchema containing a single count definition with an
     * untyped constraint value, bypassing the ?\Closure type enforcement
     * via reflection.
     *
     * @param  string  $presentKey
     * @param  mixed  $constraint
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema
     */
    private function createSchemaWithUntypedCount(string $presentKey, mixed $constraint): CompiledSchema
    {
        $fakeCount             = new \stdClass;
        $fakeCount->presentKey = $presentKey;
        $fakeCount->constraint = $constraint;

        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, []);
        $reflection->getProperty('counts')->setValue($schema, [$presentKey => $fakeCount]);

        return $schema;
    }
}
