<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\ColumnNarrower;
use SineMacula\ApiToolkit\Schema\FieldColumnMap;
use Tests\Fixtures\Casts\PostalAddress;
use Tests\Fixtures\Casts\SluggedTitleCast;
use Tests\Fixtures\Enums\CastablePlatform;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Models\SluggedTitleArticle;

/**
 * Tests for the ColumnNarrower domain rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnNarrower::class)]
final class ColumnNarrowerTest extends TestCase
{
    /** @var array<int, string> */
    private const array REAL_COLUMNS = ['id', 'title', 'slug', 'first_name', 'last_name', 'email', 'deleted_at'];

    /** @var \SineMacula\ApiToolkit\Schema\ColumnNarrower */
    private ColumnNarrower $narrower;

    /**
     * Set up the narrower instance for each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->narrower = new ColumnNarrower;
    }

    /**
     * Test that all mapped fields produce a narrow decision with the union of
     * mapped columns and the safety set.
     *
     * @return void
     */
    public function testNarrowsWhenAllFieldsMapped(): void
    {
        $map = FieldColumnMap::make(['name' => ['first_name', 'last_name']], ['email'], ['name', 'email']);

        $decision = $this->narrower->decide($map, ['name', 'email'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['first_name', 'last_name', 'email', 'id'], $decision->columns());
    }

    /**
     * Test that a single unmapped field produces a fall-back decision whose
     * reason equals that field key.
     *
     * @return void
     */
    public function testFallsBackOnFirstUnmappedField(): void
    {
        $map = FieldColumnMap::make([], [], ['name']);

        $decision = $this->narrower->decide($map, ['name'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('name', $decision->reason());
    }

    /**
     * Test that when multiple fields are unmapped the reason is the first
     * unmapped field in resolution order.
     *
     * @return void
     */
    public function testFallsBackReasonIsFirstUnmappedInOrder(): void
    {
        $map = FieldColumnMap::make([], [], ['alpha', 'beta']);

        $decision = $this->narrower->decide($map, ['alpha', 'beta'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('alpha', $decision->reason());
    }

    /**
     * Test that overlapping mapped columns and safety-set columns are
     * deduplicated with first-seen order preserved.
     *
     * @return void
     */
    public function testNarrowedColumnsUnionDedupesNeededAndSafetySet(): void
    {
        $map = FieldColumnMap::make(['name' => ['first_name', 'id']], [], ['name']);

        $decision = $this->narrower->decide($map, ['name'], ['id', 'deleted_at'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['first_name', 'id', 'deleted_at'], $decision->columns());
    }

    /**
     * Test that an empty resolved field set narrows to the safety set only.
     *
     * @return void
     */
    public function testEmptyResolvedFieldsNarrowsToSafetySetOnly(): void
    {
        $map = FieldColumnMap::make([], [], []);

        $decision = $this->narrower->decide($map, [], ['id', 'deleted_at'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['id', 'deleted_at'], $decision->columns());
    }

    /**
     * Test that a mapped field with an empty declared column list does not
     * force a fall-back.
     *
     * @return void
     */
    public function testMappedFieldWithEmptyColumnsDoesNotForceFallback(): void
    {
        $map = FieldColumnMap::make(['virtual' => []], [], ['virtual']);

        $decision = $this->narrower->decide($map, ['virtual'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['id'], $decision->columns());
    }

    /**
     * Test that columns shared across fields and the safety set are
     * de-duplicated and the result is re-indexed into a contiguous list.
     *
     * @return void
     */
    public function testNarrowDeduplicatesAndReindexesColumns(): void
    {
        $map = FieldColumnMap::make(['a' => ['id', 'title'], 'b' => ['title', 'slug']], [], ['a', 'b']);

        $decision = $this->narrower->decide($map, ['a', 'b'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['id', 'title', 'slug'], $decision->columns());
    }

    /**
     * Test that a name the schema does not declare reads no column, since the
     * resource renders nothing for it.
     *
     * @return void
     */
    public function testSkipsANameTheSchemaDoesNotDeclare(): void
    {
        $map = FieldColumnMap::make([], ['email'], ['email']);

        $decision = $this->narrower->decide($map, ['_type', 'email', 'unknown'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['email', 'id'], $decision->columns());
    }

    /**
     * Test that the aggregates payload narrows only while it reads no column,
     * and otherwise falls back naming it.
     *
     * @return void
     */
    public function testAggregatesPayloadNarrowsOnlyWhileItReadsNoColumn(): void
    {
        $free    = FieldColumnMap::make(['aggregates' => []], ['email'], ['email', 'aggregates']);
        $guarded = FieldColumnMap::make([], ['email'], ['email', 'aggregates']);

        $narrowed = $this->narrower->decide($free, ['email', 'aggregates'], ['id'], $this->plainModel(), self::REAL_COLUMNS);
        $fallback = $this->narrower->decide($guarded, ['email', 'aggregates'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertSame(['email', 'id'], $narrowed->columns());
        self::assertFalse($fallback->shouldNarrow());
        self::assertSame('aggregates', $fallback->reason());
    }

    /**
     * Test that an inferred key the table lacks falls back naming the field.
     *
     * @return void
     */
    public function testFallsBackForAnInferredKeyMissingFromTheTable(): void
    {
        $map = FieldColumnMap::make([], ['id', 'headline'], ['id', 'headline']);

        $decision = $this->narrower->decide($map, ['id', 'headline'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('headline', $decision->reason());
    }

    /**
     * Test that a declared column the table lacks falls back naming the field.
     *
     * @return void
     */
    public function testFallsBackForADeclaredColumnMissingFromTheTable(): void
    {
        $map = FieldColumnMap::make(['full_name' => ['first_name', 'surname']], ['id'], ['id', 'full_name']);

        $decision = $this->narrower->decide($map, ['id', 'full_name'], ['id'], $this->plainModel(), self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('full_name', $decision->reason());
    }

    /**
     * Test that an unreadable column listing falls back for a plain field.
     *
     * @return void
     */
    public function testFallsBackForAnEmptyColumnListing(): void
    {
        $map = FieldColumnMap::make([], ['id'], ['id']);

        $decision = $this->narrower->decide($map, ['id'], [], $this->plainModel(), []);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('id', $decision->reason());
    }

    /**
     * Test that an inferred real column read through an accessor falls back.
     *
     * @return void
     */
    public function testFallsBackForAnInferredColumnWithAnAttributeAccessor(): void
    {
        $decision = $this->narrower->decide(FieldColumnMap::make([], ['id', 'title'], ['id', 'title']), ['id', 'title'], ['id'], new SluggedTitleArticle, self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('title', $decision->reason());
    }

    /**
     * Test that an inferred real column read through a legacy accessor falls
     * back.
     *
     * @return void
     */
    public function testFallsBackForAnInferredColumnWithALegacyAccessor(): void
    {
        $model = new class extends Model {
            /**
             * Get the title in upper case.
             *
             * @param  string|null  $value
             * @return string
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function getTitleAttribute(?string $value): string
            {
                return mb_strtoupper((string) $value);
            }
        };

        $decision = $this->narrower->decide(FieldColumnMap::make([], ['title'], ['title']), ['title'], ['id'], $model, self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('title', $decision->reason());
    }

    /**
     * Test that a public property shadowing an inferred column falls back.
     *
     * @return void
     */
    public function testFallsBackForAnInferredColumnShadowedByAPublicProperty(): void
    {
        $model = new class extends Model {
            /** @var string|null */
            public ?string $title = null;
        };

        $decision = $this->narrower->decide(FieldColumnMap::make([], ['id', 'title'], ['id', 'title']), ['id', 'title'], ['id'], $model, self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('title', $decision->reason());
    }

    /**
     * Test that a protected model property named like a column still narrows,
     * since reading it from outside goes through the attribute.
     *
     * @return void
     */
    public function testNarrowsForAnInferredColumnNamedLikeAProtectedProperty(): void
    {
        $decision = $this->narrower->decide(FieldColumnMap::make([], ['visible'], ['visible']), ['visible'], ['id'], $this->plainModel(), ['id', 'visible']);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['visible', 'id'], $decision->columns());
    }

    /**
     * Test that a public static property named like a column still narrows,
     * since an instance read of it goes through the attribute.
     *
     * @return void
     */
    public function testNarrowsForAnInferredColumnNamedLikeAPublicStaticProperty(): void
    {
        $model = new class extends Model {
            /** @var string */
            public static string $title = 'static';
        };

        $decision = $this->narrower->decide(FieldColumnMap::make([], ['title'], ['title']), ['title'], ['id'], $model, self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['title', 'id'], $decision->columns());
    }

    /**
     * Test that a set-only attribute mutator does not block narrowing.
     *
     * @return void
     */
    public function testNarrowsForAnInferredColumnWithASetOnlyAttribute(): void
    {
        $model = new class extends Model {
            /**
             * Store the title in lower case.
             *
             * @return \Illuminate\Database\Eloquent\Casts\Attribute
             */
            protected function title(): Attribute
            {
                return Attribute::make(set: fn (string $value): string => mb_strtolower($value));
            }
        };

        $decision = $this->narrower->decide(FieldColumnMap::make([], ['title'], ['title']), ['title'], ['id'], $model, self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['title', 'id'], $decision->columns());
    }

    /**
     * Test that a declared field narrows to its columns despite an accessor.
     *
     * @return void
     */
    public function testNarrowsADeclaredFieldOverAnAccessorToItsDeclaredColumns(): void
    {
        $decision = $this->narrower->decide(FieldColumnMap::make(['title' => ['title', 'slug']], [], ['title']), ['title'], ['id'], new SluggedTitleArticle, self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['title', 'slug', 'id'], $decision->columns());
    }

    /**
     * Provide casts that may read attributes beyond their own column.
     *
     * @return iterable<string, array{string}>
     */
    public static function untrustedCastProvider(): iterable
    {
        yield 'custom caster' => [SluggedTitleCast::class];
        yield 'castable value object' => [PostalAddress::class];
        yield 'castable enum' => [CastablePlatform::class];
        yield 'unknown cast type' => ['uppercase'];
        yield 'class outside the eloquent casts namespace' => [Collection::class];
    }

    /**
     * Test that a cast which may read other attributes blocks narrowing.
     *
     * @param  string  $cast
     * @return void
     */
    #[DataProvider('untrustedCastProvider')]
    public function testFallsBackForAnInferredColumnWithAnUnprovenCast(string $cast): void
    {
        $decision = $this->narrower->decide(FieldColumnMap::make([], ['id', 'title'], ['id', 'title']), ['id', 'title'], ['id'], $this->modelCasting($cast), self::REAL_COLUMNS);

        self::assertFalse($decision->shouldNarrow());
        self::assertSame('title', $decision->reason());
    }

    /**
     * Provide casts that read only their own column.
     *
     * @return iterable<string, array{string}>
     */
    public static function trustedCastProvider(): iterable
    {
        yield 'plain enum' => [Platform::class];
        yield 'eloquent cast class' => [AsCollection::using(Collection::class)];
        yield 'array' => ['array'];
        yield 'datetime' => ['datetime'];
        yield 'datetime with a format' => ['datetime:Y-m-d'];
        yield 'decimal with a precision' => ['decimal:2'];
        yield 'encrypted array' => ['encrypted:array'];
        yield 'timestamp' => ['timestamp'];
    }

    /**
     * Test that a cast proven to read only its own column still narrows.
     *
     * @param  string  $cast
     * @return void
     */
    #[DataProvider('trustedCastProvider')]
    public function testNarrowsForAnInferredColumnWithATrustedCast(string $cast): void
    {
        $decision = $this->narrower->decide(FieldColumnMap::make([], ['title'], ['title']), ['title'], ['id'], $this->modelCasting($cast), self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['title', 'id'], $decision->columns());
    }

    /**
     * Test that a cast on another column never blocks an uncast field.
     *
     * @return void
     */
    public function testNarrowsForAnUncastFieldBesideAnUnprovenCast(): void
    {
        $model = $this->modelCasting(SluggedTitleCast::class);

        $decision = $this->narrower->decide(FieldColumnMap::make([], ['slug'], ['slug']), ['slug'], ['id'], $model, self::REAL_COLUMNS);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['slug', 'id'], $decision->columns());
    }

    /**
     * Build a model with no accessors or casts beyond the defaults.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function plainModel(): Model
    {
        return new class extends Model {};
    }

    /**
     * Build a model that casts the title column with the given cast.
     *
     * @param  string  $cast
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function modelCasting(string $cast): Model
    {
        return (new class extends Model {})->mergeCasts(['title' => $cast]);
    }
}
