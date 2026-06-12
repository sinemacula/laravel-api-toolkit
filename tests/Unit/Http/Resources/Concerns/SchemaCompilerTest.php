<?php

namespace Tests\Unit\Http\Resources\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Concerns\SchemaCompiler;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;

/**
 * Tests for the SchemaCompiler static compilation and caching class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaCompiler::class)]
class SchemaCompilerTest extends TestCase
{
    private const STUB_ORGANIZATION_RESOURCE = 'App\Http\Resources\OrganizationResource';

    /**
     * Reset the schema compiler cache between tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that relation and resource entries are only extracted when they are
     * strings, and malformed values are normalized to null.
     *
     * NOTE: This test is intentionally declared first in the class. Mutations
     * to the isset/is_string narrowing in buildFieldDefinition emit PHP
     * warnings, which stop the mutation test run at the first defect; the
     * first executed test must therefore be the one that fails.
     *
     * @return void
     */
    public function testCompileNormalizesRelationAndResourceExtraction(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation' => 'organization',
                'resource' => self::STUB_ORGANIZATION_RESOURCE,
            ],
            'malformed' => [
                'relation' => 123,
                'resource' => 456,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $organization = $schema->getField('organization');
        static::assertInstanceOf(CompiledFieldDefinition::class, $organization);
        static::assertSame('organization', $organization->relation);
        static::assertSame(self::STUB_ORGANIZATION_RESOURCE, $organization->resource);

        $malformed = $schema->getField('malformed');
        static::assertInstanceOf(CompiledFieldDefinition::class, $malformed);
        static::assertNull($malformed->relation);
        static::assertNull($malformed->resource);
    }

    /**
     * Test that compile returns a CompiledSchema from a raw schema array.
     *
     * @return void
     */
    public function testCompileReturnsCompiledSchemaFromRawSchema(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name' => [],
        ]);

        $result = SchemaCompiler::compile($resourceClass);

        static::assertInstanceOf(CompiledSchema::class, $result);
    }

    /**
     * Test that scalar fields produce CompiledFieldDefinition with null
     * relation and null compute.
     *
     * @return void
     */
    public function testCompileCreatesCompiledFieldDefinitionsForScalarFields(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'name'  => [],
            'email' => ['accessor' => 'contact.email'],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $nameField = $schema->getField('name');
        static::assertInstanceOf(CompiledFieldDefinition::class, $nameField);
        static::assertNull($nameField->relation);
        static::assertNull($nameField->compute);
        static::assertNull($nameField->accessor);

        $emailField = $schema->getField('email');
        static::assertInstanceOf(CompiledFieldDefinition::class, $emailField);
        static::assertSame('contact.email', $emailField->accessor);
        static::assertNull($emailField->relation);
        static::assertNull($emailField->compute);
    }

    /**
     * Test that relation fields produce CompiledFieldDefinition with relation
     * and resource populated.
     *
     * @return void
     */
    public function testCompileCreatesCompiledFieldDefinitionsForRelationFields(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation' => 'organization',
                'resource' => self::STUB_ORGANIZATION_RESOURCE,
                'fields'   => ['name', 'slug'],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $field = $schema->getField('organization');
        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame('organization', $field->relation);
        static::assertSame(self::STUB_ORGANIZATION_RESOURCE, $field->resource);
        static::assertSame(['name', 'slug'], $field->fields);
    }

    /**
     * Test that count entries produce CompiledCountDefinition with the correct
     * present key.
     *
     * @return void
     */
    public function testCompileCreatesCompiledCountDefinitionsForCountMetrics(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:posts' => [
                'key'      => 'posts',
                'metric'   => 'count',
                'relation' => 'posts',
                'default'  => true,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        static::assertSame([], $schema->getFieldKeys());

        $counts = $schema->getCountDefinitions();
        static::assertCount(1, $counts);
        static::assertArrayHasKey('posts', $counts);

        $count = $counts['posts'];
        static::assertInstanceOf(CompiledCountDefinition::class, $count);
        static::assertSame('posts', $count->presentKey);
        static::assertSame('posts', $count->relation);
        static::assertTrue($count->isDefault);
    }

    /**
     * Test that calling compile twice returns the same cached instance.
     *
     * @return void
     */
    public function testCompileCachesResultForSameResourceClass(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'id' => [],
        ]);

        $first  = SchemaCompiler::compile($resourceClass);
        $second = SchemaCompiler::compile($resourceClass);

        static::assertSame($first, $second);
    }

    /**
     * Test that clearCache forces a fresh compilation on subsequent calls.
     *
     * @return void
     */
    public function testClearCacheRemovesCachedSchemas(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'id' => [],
        ]);

        $first = SchemaCompiler::compile($resourceClass);

        SchemaCompiler::clearCache();

        $second = SchemaCompiler::compile($resourceClass);

        static::assertNotSame($first, $second);
        static::assertInstanceOf(CompiledSchema::class, $second);
    }

    /**
     * Test that an empty schema produces a CompiledSchema with no fields and
     * no counts.
     *
     * @return void
     */
    public function testCompileHandlesEmptySchema(): void
    {
        $resourceClass = $this->createStubResourceClass([]);

        $schema = SchemaCompiler::compile($resourceClass);

        static::assertSame([], $schema->getFieldKeys());
        static::assertSame([], $schema->getCountDefinitions());
    }

    /**
     * Test that guards and transformers from the raw schema appear in the
     * compiled definitions.
     *
     * @return void
     */
    public function testCompilePreservesGuardsAndTransformers(): void
    {
        $guard       = fn ($resource, $request) => true;
        $transformer = fn ($resource, $value) => strtoupper($value);

        $resourceClass = $this->createStubResourceClass([
            'secret' => [
                'guards'       => [$guard],
                'transformers' => [$transformer],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $field = $schema->getField('secret');
        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame([$guard], $field->guards);
        static::assertSame([$transformer], $field->transformers);
    }

    /**
     * Test that Closure constraints appear in both compiled field and count
     * definitions.
     *
     * @return void
     */
    public function testCompilePreservesConstraintClosures(): void
    {
        $fieldConstraint = fn ($query) => $query->where('active', true);
        $countConstraint = fn ($query) => $query->where('published', true);

        $resourceClass = $this->createStubResourceClass([
            'organization' => [
                'relation'   => 'organization',
                'resource'   => self::STUB_ORGANIZATION_RESOURCE,
                'constraint' => $fieldConstraint,
            ],
            '__count__:articles' => [
                'key'        => 'articles',
                'metric'     => 'count',
                'relation'   => 'articles',
                'constraint' => $countConstraint,
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $field = $schema->getField('organization');
        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame($fieldConstraint, $field->constraint);

        $counts = $schema->getCountDefinitions();
        static::assertSame($countConstraint, $counts['articles']->constraint);
    }

    /**
     * Test that a count with a __count__: prefix is normalized to the correct
     * present key when no explicit key is provided.
     *
     * @return void
     */
    public function testCompileHandlesCountWithPrefixedKey(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:comments' => [
                'metric'   => 'count',
                'relation' => 'comments',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        $counts = $schema->getCountDefinitions();
        static::assertCount(1, $counts);
        static::assertArrayHasKey('comments', $counts);
        static::assertSame('comments', $counts['comments']->presentKey);
        static::assertSame('comments', $counts['comments']->relation);
    }

    /**
     * Test that field entries declared after a count entry are still compiled.
     *
     * @return void
     */
    public function testCompileProcessesFieldsDeclaredAfterCountEntries(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:posts' => [
                'metric'   => 'count',
                'relation' => 'posts',
            ],
            'name' => [],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        static::assertInstanceOf(CompiledFieldDefinition::class, $schema->getField('name'));
        static::assertArrayHasKey('posts', $schema->getCountDefinitions());
    }

    /**
     * Test that an explicit count key overrides the prefix-stripped schema key
     * while the relation is taken from the definition.
     *
     * @return void
     */
    public function testCompileUsesExplicitCountKeyOverSchemaKey(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:posts' => [
                'key'      => 'published_posts',
                'metric'   => 'count',
                'relation' => 'posts',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $counts = $schema->getCountDefinitions();

        static::assertArrayHasKey('published_posts', $counts);
        static::assertSame('published_posts', $counts['published_posts']->presentKey);
        static::assertSame('posts', $counts['published_posts']->relation);
    }

    /**
     * Test that counts without a default flag compile as non-default and that
     * count guards are preserved.
     *
     * @return void
     */
    public function testCompileDefaultsCountToNonDefaultAndPreservesGuards(): void
    {
        $guard = fn ($resource, $request) => true;

        $resourceClass = $this->createStubResourceClass([
            '__count__:comments' => [
                'metric'   => 'count',
                'relation' => 'comments',
                'guards'   => [$guard],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $count  = $schema->getCountDefinitions()['comments'];

        static::assertFalse($count->isDefault);
        static::assertSame([$guard], $count->guards);
    }

    /**
     * Test that a truthy non-boolean count default is normalized to a real
     * boolean.
     *
     * @return void
     */
    public function testCompileCastsTruthyCountDefaultToBoolean(): void
    {
        $resourceClass = $this->createStubResourceClass([
            '__count__:likes' => [
                'metric'   => 'count',
                'relation' => 'likes',
                'default'  => ['truthy'],
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);

        static::assertTrue($schema->getCountDefinitions()['likes']->isDefault);
    }

    /**
     * Test that a scalar extras entry is normalized to an array on the
     * compiled field definition.
     *
     * @return void
     */
    public function testCompileNormalizesExtrasToArray(): void
    {
        $resourceClass = $this->createStubResourceClass([
            'avatar' => [
                'extras' => 'media',
            ],
        ]);

        $schema = SchemaCompiler::compile($resourceClass);
        $field  = $schema->getField('avatar');

        static::assertInstanceOf(CompiledFieldDefinition::class, $field);
        static::assertSame(['media'], $field->extras);
    }

    /**
     * Create an anonymous class with a static schema() method returning the
     * given raw schema.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return class-string
     */
    private function createStubResourceClass(array $rawSchema): string
    {
        $stub = new class ($rawSchema) {
            /** @var array<string, array<string, mixed>> */
            private static array $schema = [];

            /**
             * @param  array<string, array<string, mixed>>  $schema
             */
            public function __construct(array $schema)
            {
                self::$schema = $schema;
            }

            /**
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return self::$schema;
            }
        };

        return $stub::class;
    }
}
