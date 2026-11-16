<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationLimits;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use Tests\Fixtures\Contracts\UnionRelations;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateRelationLimits validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateRelationLimits::class)]
final class ValidateRelationLimitsTest extends TestCase
{
    /**
     * Test that a limit on a to-many relation with a child resource passes.
     *
     * @return void
     */
    public function testLimitOnAHasManyPasses(): void
    {
        $errors = $this->validate(['posts' => self::field('posts', limit: 5)]);

        self::assertSame([], $errors);
    }

    /**
     * Test that a limit on a relation rendered through an accessor fails.
     *
     * @return void
     */
    public function testLimitOnAnAccessorRelationFails(): void
    {
        $errors = $this->validate(['post_titles' => self::field('posts', limit: 5, resource: null, accessor: 'title')]);

        self::assertCount(1, $errors);
        self::assertSame('post_titles', $errors[0]->fieldKey);
        self::assertSame(UserResource::class, $errors[0]->resourceClass);
        self::assertSame('Relation "posts" declares a limit but renders no child resource', $errors[0]->defect);
    }

    /**
     * Test that a limit on a relation with no child resource fails.
     *
     * @return void
     */
    public function testLimitOnARelationWithoutAChildResourceFails(): void
    {
        $errors = $this->validate(['posts' => self::field('posts', limit: 5, resource: null)]);

        self::assertCount(1, $errors);
        self::assertSame('Relation "posts" declares a limit but renders no child resource', $errors[0]->defect);
    }

    /**
     * Test that a limit on a relation method returning a to-one fails.
     *
     * @return void
     */
    public function testLimitOnABelongsToReturnTypeFails(): void
    {
        $errors = $this->validate(['organization' => self::field('organization', limit: 5, resource: OrganizationResource::class)]);

        self::assertCount(1, $errors);
        self::assertSame('organization', $errors[0]->fieldKey);
        self::assertSame('Relation "organization" declares a limit but its method returns a to-one relation', $errors[0]->defect);
    }

    /**
     * Test that a limit on a relation method returning a union of to-one
     * relations fails, while a union reaching a to-many passes.
     *
     * @return void
     */
    public function testLimitOnAUnionOfToOneReturnTypesFails(): void
    {
        $errors = (new ValidateRelationLimits)->validate(UserResource::class, UnionRelations::class, new CompiledSchema(fields: [
            'either' => self::field('either', limit: 5),
            'varied' => self::field('varied', limit: 5),
        ], counts: []));

        self::assertCount(1, $errors);
        self::assertSame('either', $errors[0]->fieldKey);
        self::assertSame('Relation "either" declares a limit but its method returns a to-one relation', $errors[0]->defect);
    }

    /**
     * Test that a to-one relation shared by fields of differing effective
     * limits passes, since it is never bounded.
     *
     * @return void
     */
    public function testToOneRelationSharedByDifferingFieldsPasses(): void
    {
        $errors = $this->validate([
            'organization'      => self::field('organization', resource: OrganizationResource::class),
            'organization_name' => self::field('organization', resource: null, accessor: 'name'),
        ]);

        self::assertSame([], $errors);
    }

    /**
     * Test that two fields over one relation with differing limits fail.
     *
     * @return void
     */
    public function testConflictingLimitsOverOneRelationFail(): void
    {
        $errors = $this->validate([
            'posts'        => self::field('posts', limit: 5),
            'recent_posts' => self::field('posts', limit: 10),
        ]);

        self::assertCount(1, $errors);
        self::assertSame('recent_posts', $errors[0]->fieldKey);
        self::assertSame('Relation "posts" is loaded once, so every field over it must share one limit, not 5 and 10', $errors[0]->defect);
    }

    /**
     * Test that a declared limit conflicts with the default of a sibling.
     *
     * @return void
     */
    public function testDeclaredLimitConflictsWithTheDefaultOfASibling(): void
    {
        $errors = $this->validate([
            'posts'        => self::field('posts'),
            'recent_posts' => self::field('posts', limit: 5),
            'all_posts'    => self::field('posts'),
        ]);

        self::assertCount(1, $errors);
        self::assertSame('recent_posts', $errors[0]->fieldKey);
        self::assertSame('Relation "posts" is loaded once, so every field over it must share one limit, not 25 and 5', $errors[0]->defect);
    }

    /**
     * Test that two fields sharing one limit over one relation pass.
     *
     * @return void
     */
    public function testMatchingLimitsOverOneRelationPass(): void
    {
        $errors = $this->validate([
            'posts'        => self::field('posts', limit: 5),
            'recent_posts' => self::field('posts', limit: 5),
            'organization' => self::field('organization', resource: OrganizationResource::class),
        ]);

        self::assertSame([], $errors);
    }

    /**
     * Test that a schema key reserved for the truncation list fails.
     *
     * @return void
     */
    public function testTruncatedSchemaKeyFails(): void
    {
        $errors = $this->validate([
            '_truncated' => self::field(null),
            'name'       => self::field(null),
        ]);

        self::assertCount(1, $errors);
        self::assertSame('_truncated', $errors[0]->fieldKey);
        self::assertSame('The key "_truncated" is reserved for the relations a response truncates', $errors[0]->defect);
    }

    /**
     * Test that a limit is not refused when the relation method cannot be read.
     *
     * @return void
     */
    public function testLimitPassesWhereTheRelationMethodCannotBeRead(): void
    {
        $rule = new ValidateRelationLimits;

        self::assertSame([], $this->validate(['missing' => self::field('missing', limit: 5)]));
        self::assertSame([], $rule->validate(UserResource::class, null, new CompiledSchema(fields: [
            'organization' => self::field('organization', limit: 5, resource: OrganizationResource::class),
        ], counts: [])));
    }

    /**
     * Test that every defect is reported, past a missing field definition.
     *
     * @return void
     */
    public function testEveryDefectIsReportedPastAMissingDefinition(): void
    {
        $schema = new CompiledSchema(fields: [ // @phpstan-ignore argument.type
            'ghost'        => null,
            'organization' => self::field('organization', limit: 5, resource: OrganizationResource::class),
            'post_titles'  => self::field('posts', limit: 5, resource: null, accessor: 'title'),
        ], counts: []);

        $errors = (new ValidateRelationLimits)->validate(UserResource::class, User::class, $schema);

        self::assertSame(['organization', 'post_titles'], array_map(static fn (SchemaValidationError $error): string => $error->fieldKey, $errors));
    }

    /**
     * Validate the given fields as the user resource's schema.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition>  $fields
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    private function validate(array $fields): array
    {
        return (new ValidateRelationLimits)->validate(UserResource::class, User::class, new CompiledSchema(fields: $fields, counts: []));
    }

    /**
     * Build a compiled field over the given relation.
     *
     * @param  string|null  $relation
     * @param  int|null  $limit
     * @param  string|null  $resource
     * @param  string|null  $accessor
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private static function field(?string $relation, ?int $limit = null, ?string $resource = PostResource::class, ?string $accessor = null): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: $accessor,
            compute: null,
            relation: $relation,
            resource: $relation === null ? null : $resource,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
            limit: $limit,
        );
    }
}
