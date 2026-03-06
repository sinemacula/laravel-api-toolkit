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
