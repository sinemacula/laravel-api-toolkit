<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Concurrency;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\Versioned;
use SineMacula\ApiToolkit\Http\Concurrency\EntityTag;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\Tag;
use Tests\TestCase;

/**
 * Tests for the EntityTag class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EntityTag::class)]
final class EntityTagTest extends TestCase
{
    /**
     * Test that the tag is a weak tag over the morph class, key and version.
     *
     * @return void
     */
    public function testTagIsAWeakHashOfTheMorphClassKeyAndVersion(): void
    {
        $tag = EntityTag::for($this->document(['id' => 4, 'version' => 6]));

        self::assertSame('W/"' . sha1(Document::class . '|4|6') . '"', $tag);
    }

    /**
     * Test that a model that is not versioned has no tag.
     *
     * @return void
     */
    public function testModelThatIsNotVersionedHasNoTag(): void
    {
        self::assertNull(EntityTag::for((new Tag)->setRawAttributes(['id' => 4, 'version' => 6])));
    }

    /**
     * Test that a model loaded without its version has no tag, even when
     * reading a missing attribute throws.
     *
     * @return void
     */
    public function testModelLoadedWithoutItsVersionHasNoTag(): void
    {
        Model::preventAccessingMissingAttributes();

        try {
            self::assertNull(EntityTag::for($this->document(['id' => 4])));
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }
    }

    /**
     * Test that a null version, or a model without a key, has no tag.
     *
     * @return void
     */
    public function testNullVersionOrMissingKeyHasNoTag(): void
    {
        self::assertNull(EntityTag::for($this->document(['id' => 4, 'version' => null])));
        self::assertNull(EntityTag::for($this->document(['version' => 6])));
    }

    /**
     * Test that an integer version and a driver's numeric string tag alike.
     *
     * @return void
     */
    public function testIntegerAndNumericStringVersionsTagAlike(): void
    {
        self::assertSame(
            EntityTag::for($this->document(['id' => 4, 'version' => 6])),
            EntityTag::for($this->document(['id' => '4', 'version' => '6'])),
        );
    }

    /**
     * Test that a timestamp version tags equal instants alike, whatever offset
     * the driver reads back.
     *
     * @return void
     */
    public function testEqualInstantsTagAlikeAcrossOffsets(): void
    {
        $naive  = $this->versionedBy('updated_at', ['id' => 4, 'updated_at' => '2026-03-01 10:00:00.250000']);
        $offset = $this->versionedBy('updated_at', ['id' => 4, 'updated_at' => '2026-03-01 12:00:00.250000+02']);
        $later  = $this->versionedBy('updated_at', ['id' => 4, 'updated_at' => '2026-03-01 10:00:00.251000']);

        self::assertSame(EntityTag::for($naive), EntityTag::for($offset));
        self::assertNotSame(EntityTag::for($naive), EntityTag::for($later));
    }

    /**
     * Test that the tag differs by key and by morph class.
     *
     * @return void
     */
    public function testTagDiffersByKeyAndByMorphClass(): void
    {
        $tag = EntityTag::for($this->document(['id' => 4, 'version' => 6]));

        self::assertNotSame($tag, EntityTag::for($this->document(['id' => 5, 'version' => 6])));
        self::assertNotSame($tag, EntityTag::for($this->versionedBy('version', ['id' => 4, 'version' => 6])));
    }

    /**
     * Build a document holding the given raw attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return \Tests\Fixtures\Models\Document
     */
    private function document(array $attributes): Document
    {
        return (new Document)->setRawAttributes($attributes, true);
    }

    /**
     * Build a model versioned by the given column, holding the given raw
     * attributes.
     *
     * @param  string  $column
     * @param  array<string, mixed>  $attributes
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function versionedBy(string $column, array $attributes): Model
    {
        $model = new class extends Model implements Versioned {
            /** @var string The column holding the version */
            public string $versionColumn = 'version';

            /**
             * Get the name of the column holding the version.
             *
             * @return string
             */
            #[\Override]
            public function getVersionColumn(): string
            {
                return $this->versionColumn;
            }

            /**
             * Advance the version.
             *
             * @return void
             */
            #[\Override]
            public function markVersionChanged(): void
            {
                $this->updateTimestamps();
            }
        };

        $model->versionColumn = $column;

        return $model->setRawAttributes($attributes, true);
    }
}
