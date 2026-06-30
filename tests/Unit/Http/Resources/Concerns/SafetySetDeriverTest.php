<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use Tests\TestCase;

/**
 * Tests for the SafetySetDeriver safety-set composition class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SafetySetDeriver::class)]
final class SafetySetDeriverTest extends TestCase
{
    /** @var \PHPUnit\Framework\MockObject\Stub&\SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider */
    private SchemaIntrospectionProvider $introspector;

    /** @var \SineMacula\ApiToolkit\Schema\SafetySetDeriver */
    private SafetySetDeriver $deriver;

    /**
     * Set up the test environment with a mock introspector.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->introspector = self::createStub(SchemaIntrospectionProvider::class);
        $this->deriver      = new SafetySetDeriver($this->introspector);
    }

    /**
     * Test that the safety set contains the model's declared primary key, not
     * the assumed 'id'.
     *
     * @return void
     */
    public function testIncludesPrimaryKeyFromModelKeyName(): void
    {
        $model = $this->makeModel('uuid');

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['uuid', 'name']);

        $columns = $this->deriver->derive($model, [], []);

        self::assertContains('uuid', $columns);
        self::assertNotContains('id', $columns);
    }

    /**
     * Test that the soft-delete column is included when the port reports it as
     * non-null.
     *
     * @return void
     */
    public function testIncludesSoftDeleteColumnWhenPresent(): void
    {
        $model = $this->makeModel('id');

        $this->introspector->method('getDeletedAtColumn')->willReturn('deleted_at');
        $this->introspector->method('getColumns')->willReturn(['id', 'deleted_at', 'name']);

        $columns = $this->deriver->derive($model, [], []);

        self::assertContains('deleted_at', $columns);
    }

    /**
     * Test that no soft-delete column appears when the port reports null.
     *
     * @return void
     */
    public function testOmitsSoftDeleteColumnWhenAbsent(): void
    {
        $model = $this->makeModel('id');

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'name']);

        $columns = $this->deriver->derive($model, [], []);

        self::assertNotContains('deleted_at', $columns);
    }

    /**
     * Test that parent-side relation keys, including morph type and id, are
     * unioned for eager-loaded relations.
     *
     * @return void
     */
    public function testUnionsRelationParentKeysForEagerLoadedRelations(): void
    {
        $model    = $this->makeModel('id');
        $relation = self::createStub(Relation::class);

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturn($relation);
        $this->introspector->method('parentKeysFor')->willReturn(['taggable_type', 'taggable_id']);
        $this->introspector->method('getColumns')->willReturn(['id', 'taggable_type', 'taggable_id', 'name']);

        $columns = $this->deriver->derive($model, ['taggable'], []);

        self::assertContains('taggable_type', $columns);
        self::assertContains('taggable_id', $columns);
    }

    /**
     * Test that an unresolvable relation contributes nothing and does not
     * throw.
     *
     * @return void
     */
    public function testUnresolvableRelationContributesNothing(): void
    {
        $model = $this->makeModel('id');

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'name']);

        $columns = $this->deriver->derive($model, ['nonexistent'], []);

        self::assertSame(['id'], $columns);
    }

    /**
     * Test that order columns that are real columns appear in the result.
     *
     * @return void
     */
    public function testUnionsOrderColumns(): void
    {
        $model = $this->makeModel('id');

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'name', 'created_at']);

        $columns = $this->deriver->derive($model, [], ['created_at']);

        self::assertContains('created_at', $columns);
    }

    /**
     * Test that a column name not present in the model's real columns is
     * dropped from the result.
     *
     * @return void
     */
    public function testIntersectsAgainstRealColumns(): void
    {
        $model = $this->makeModel('id');

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'name']);

        $columns = $this->deriver->derive($model, [], ['virtual_order']);

        self::assertNotContains('virtual_order', $columns);
    }

    /**
     * Test that an appends entry backed by a real column is retained and a
     * virtual append is dropped.
     *
     * @return void
     */
    public function testUnionsRealAppendSourceColumns(): void
    {
        $model = $this->makeModel('id', ['status', 'virtual_flag']);

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'status']);

        $columns = $this->deriver->derive($model, [], []);

        self::assertContains('status', $columns);
        self::assertNotContains('virtual_flag', $columns);
    }

    /**
     * Test that an unresolvable relation is skipped so a later resolvable
     * relation still contributes its parent keys.
     *
     * @return void
     */
    public function testSkipsUnresolvableRelationButProcessesLaterOnes(): void
    {
        $model    = $this->makeModel('id');
        $relation = self::createStub(Relation::class);

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturnCallback(
            fn (string $key): ?Relation => $key === 'author' ? $relation : null,
        );
        $this->introspector->method('parentKeysFor')->willReturn(['author_id']);
        $this->introspector->method('getColumns')->willReturn(['id', 'author_id', 'name']);

        $columns = $this->deriver->derive($model, ['missing', 'author'], []);

        self::assertContains('author_id', $columns);
    }

    /**
     * Test that parent keys accumulate across every resolvable relation rather
     * than only retaining the last one's keys.
     *
     * @return void
     */
    public function testAccumulatesParentKeysAcrossMultipleRelations(): void
    {
        $model     = $this->makeModel('id');
        $relationA = self::createStub(Relation::class);
        $relationB = self::createStub(Relation::class);

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturnCallback(
            fn (string $key): ?Relation => match ($key) {
                'author'   => $relationA,
                'category' => $relationB,
                default    => null,
            },
        );
        $this->introspector->method('parentKeysFor')->willReturnCallback(
            fn (Relation $relation): array => $relation === $relationA ? ['author_id'] : ['category_id'],
        );
        $this->introspector->method('getColumns')->willReturn(['id', 'author_id', 'category_id', 'name']);

        $columns = $this->deriver->derive($model, ['author', 'category'], []);

        self::assertContains('author_id', $columns);
        self::assertContains('category_id', $columns);
    }

    /**
     * Test that the derived safety set is de-duplicated and re-indexed into a
     * contiguous list.
     *
     * @return void
     */
    public function testDeduplicatesAndReindexesColumns(): void
    {
        $model = $this->makeModel('id');

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'name']);

        $columns = $this->deriver->derive($model, [], ['id', 'ghost', 'name']);

        self::assertSame(['id', 'name'], $columns);
    }

    /**
     * Build a Model mock stub with the given primary key name and appended
     * attributes.
     *
     * @param  string  $keyName
     * @param  array<int, string>  $appendedAttributes
     * @return \Illuminate\Database\Eloquent\Model&\PHPUnit\Framework\MockObject\Stub
     */
    private function makeModel(string $keyName, array $appendedAttributes = []): Model
    {
        $model = self::createStub(Model::class);

        $model->method('getKeyName')->willReturn($keyName);
        $model->method('getAppends')->willReturn($appendedAttributes);

        return $model;
    }
}
