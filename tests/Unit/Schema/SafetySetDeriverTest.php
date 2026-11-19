<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the SafetySetDeriver.
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

        $columns = $this->deriver->derive($model, [], [])->columns();

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

        $columns = $this->deriver->derive($model, [], [])->columns();

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

        $columns = $this->deriver->derive($model, [], [])->columns();

        self::assertNotContains('deleted_at', $columns);
    }

    /**
     * Test that a versioned model keeps its version column beside its key, so a
     * narrowed read can still tag the response.
     *
     * @return void
     */
    public function testKeepsTheVersionColumnOfAVersionedModel(): void
    {
        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'title', 'version']);

        self::assertSame(['id', 'version'], $this->deriver->derive(new Document, [], [])->columns());
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

        $columns = $this->deriver->derive($model, ['taggable'], [])->columns();

        self::assertContains('taggable_type', $columns);
        self::assertContains('taggable_id', $columns);
    }

    /**
     * Test that a relation that does not resolve falls back, naming it.
     *
     * @return void
     */
    public function testFallsBackForAnUnresolvableRelation(): void
    {
        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'name']);

        $decision = $this->deriver->derive($this->makeModel('id'), ['nonexistent'], []);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('nonexistent', $decision->reason());
    }

    /**
     * Test that a relation of a class whose parent keys are unknown falls back,
     * naming it.
     *
     * @return void
     */
    public function testFallsBackForARelationOfAnUnrecognisedClass(): void
    {
        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturn(self::createStub(Relation::class));
        $this->introspector->method('parentKeysFor')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'name']);

        $decision = $this->deriver->derive($this->makeModel('id'), ['custom'], []);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('custom', $decision->reason());
    }

    /**
     * Test that a chaperoned relation falls back, since it replaces its nested
     * loads with the narrowed parent.
     *
     * @return void
     */
    public function testFallsBackForAChaperonedRelation(): void
    {
        $user = new User;

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturnCallback(static fn (string $key): ?Relation => match ($key) {
            'comments' => $user->comments(),
            'posts'    => $user->posts()->chaperone('user'),
            default    => null,
        });
        $this->introspector->method('parentKeysFor')->willReturn(['id']);
        $this->introspector->method('getColumns')->willReturn(['id', 'name']);

        self::assertTrue($this->deriver->derive($user, ['comments'], [])->shouldNarrow());

        $decision = $this->deriver->derive($user, ['comments', 'posts'], []);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('posts', $decision->reason());
    }

    /**
     * Test that each eager-load entry names its relation by its first segment,
     * whether listed, keyed or carrying a column selection.
     *
     * @return void
     */
    public function testReadsTheRelationNameOfEveryEagerLoadEntry(): void
    {
        $resolved = [];
        $relation = self::createStub(Relation::class);

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('resolveRelation')->willReturnCallback(static function (string $key) use (&$resolved, $relation): Relation {
            $resolved[] = $key;

            return $relation;
        });
        $this->introspector->method('parentKeysFor')->willReturn(['author_id']);
        $this->introspector->method('getColumns')->willReturn(['id', 'author_id']);

        $decision = $this->deriver->derive($this->makeModel('id'), ['author.posts', 'tags' => static fn (): null => null, 'editor:id,name', 7, 'author'], []);

        self::assertSame(['author', 'tags', 'editor'], $resolved);
        self::assertSame(['id', 'author_id'], $decision->columns());
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

        $columns = $this->deriver->derive($model, [], ['created_at'])->columns();

        self::assertContains('created_at', $columns);
    }

    /**
     * Test that an appended attribute is never retained, even when it names a
     * real column.
     *
     * @return void
     */
    public function testOmitsAppendedAttributesThatNameRealColumns(): void
    {
        $model = (new class extends Model {})->setAppends(['status']);

        $this->introspector->method('getDeletedAtColumn')->willReturn(null);
        $this->introspector->method('getColumns')->willReturn(['id', 'status']);

        self::assertSame(['id'], $this->deriver->derive($model, [], [])->columns());
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

        $columns = $this->deriver->derive($model, [], ['virtual_order'])->columns();

        self::assertNotContains('virtual_order', $columns);
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

        $columns = $this->deriver->derive($model, ['author', 'category'], [])->columns();

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

        $columns = $this->deriver->derive($model, [], ['id', 'ghost', 'name'])->columns();

        self::assertSame(['id', 'name'], $columns);
    }

    /**
     * Build a Model mock stub with the given primary key name.
     *
     * @param  string  $keyName
     * @return \Illuminate\Database\Eloquent\Model&\PHPUnit\Framework\MockObject\Stub
     */
    private function makeModel(string $keyName): Model
    {
        $model = self::createStub(Model::class);

        $model->method('getKeyName')->willReturn($keyName);

        return $model;
    }
}
