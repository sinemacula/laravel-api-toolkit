<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Concerns\HasVersion;
use Tests\Concerns\CreatesDocumentTables;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\Draft;
use Tests\TestCase;

/**
 * Tests for the HasVersion model trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(HasVersion::class)]
final class HasVersionTest extends TestCase
{
    use CreatesDocumentTables, RecordsStatements;

    /**
     * Test that an insert stores version 1 whatever was assigned.
     *
     * @return void
     */
    public function testInsertStartsTheVersionAtOne(): void
    {
        $document          = new Document(['title' => 'Draft']);
        $document->version = 7;

        $document->save();

        self::assertSame(1, $document->version);
        self::assertSame(1, $this->storedVersion($document));
    }

    /**
     * Test that a replicated model restarts its version when saved.
     *
     * @return void
     */
    public function testReplicaRestartsTheVersion(): void
    {
        $document = $this->documentAtVersion(5);

        $replica = $document->replicate();
        $replica->save();

        self::assertSame(1, $this->storedVersion($replica));
    }

    /**
     * Test that a dirty update stores the version after the stored one,
     * overwriting an assigned version.
     *
     * @return void
     */
    public function testUpdateAdvancesFromTheStoredVersionOverAnAssignedOne(): void
    {
        $document = $this->documentAtVersion(5);

        $document->title   = 'Revised';
        $document->version = 99;
        $document->save();

        self::assertSame(6, $document->version);
        self::assertSame(6, $this->storedVersion($document));
        self::assertFalse($document->isDirty());
    }

    /**
     * Test that a clean save neither advances the version nor runs a query.
     *
     * @return void
     */
    public function testCleanSaveRunsNoQueryAndKeepsTheVersion(): void
    {
        $document = $this->documentAtVersion(5);

        $statements = $this->recordStatements(static fn (): bool => $document->save());

        self::assertStatementCount(0, $statements);
        self::assertSame(5, $this->storedVersion($document));
    }

    /**
     * Test that an update whose listener restores every changed attribute
     * writes nothing.
     *
     * @return void
     */
    public function testUpdateLeftCleanByItsListenerRunsNoQueryAndKeepsTheVersion(): void
    {
        $draft = new Draft;
        $draft->fill(['title' => 'Draft'])->save();

        Draft::updating(static function (Draft $updating): void {
            $updating->setAttribute('title', $updating->getOriginal('title'));
        });

        $draft->setAttribute('title', 'Revised');

        $statements = $this->recordStatements(static fn (): bool => $draft->save());

        self::assertStatementCount(0, $statements);
        self::assertSame(1, DB::table('drafts')->where('id', $draft->getKey())->value('revision'));
    }

    /**
     * Test that a write from a created listener advances from the version the
     * insert wrote.
     *
     * @return void
     */
    public function testWriteFromACreatedListenerAdvancesFromTheInsertedVersion(): void
    {
        Document::created(static function (Document $created): void {
            $created->update(['title' => 'Titled']);
        });

        $document = Document::create(['title' => 'Draft']);

        self::assertSame(2, $document->version);
        self::assertSame(2, $this->storedVersion($document));
    }

    /**
     * Test that a write from a saved listener advances from the version the
     * update wrote, not the original the model has yet to sync.
     *
     * @return void
     */
    public function testWriteFromASavedListenerAdvancesFromTheUpdatedVersion(): void
    {
        $document = $this->documentAtVersion(5);

        Document::saved(static function (Document $saved): void {
            if ($saved->title === 'Revised') {
                $saved->update(['title' => 'Revised again']);
            }
        });

        $document->update(['title' => 'Revised']);

        self::assertSame(7, $document->version);
        self::assertSame(7, $this->storedVersion($document));
    }

    /**
     * Test that a refreshed model advances from the version it read back, not
     * the older one it wrote.
     *
     * @return void
     */
    public function testRefreshedModelAdvancesFromTheVersionItReadBack(): void
    {
        $document = Document::create(['title' => 'Draft']);

        Document::query()->whereKey($document->id)->update(['version' => 5]);

        $document->refresh()->update(['title' => 'Revised']);

        self::assertSame(6, $this->storedVersion($document));
    }

    /**
     * Test that quiet saves and saves without events still advance the version.
     *
     * @return void
     */
    public function testQuietSavesAdvanceTheVersion(): void
    {
        $document = $this->documentAtVersion(5);

        $document->title = 'Quiet';
        $document->saveQuietly();

        Document::withoutEvents(static function () use ($document): void {
            $document->title = 'Silent';
            $document->save();
        });

        self::assertSame(7, $this->storedVersion($document));
    }

    /**
     * Test that a clean model marked as changed writes the next version.
     *
     * @return void
     */
    public function testMarkingACleanModelAdvancesItsVersionOnSave(): void
    {
        $document = $this->documentAtVersion(5);

        $document->markVersionChanged();

        self::assertTrue($document->save());
        self::assertSame(6, $document->version);
        self::assertSame(6, $this->storedVersion($document));
    }

    /**
     * Test that a dirty model loaded without its version refuses to save rather
     * than overwrite the stored version.
     *
     * @return void
     */
    public function testUpdateWithoutTheLoadedVersionThrows(): void
    {
        $document = Document::query()->findOrFail($this->documentAtVersion(5)->id, ['id', 'title']);

        $document->title = 'Blind';

        $this->expectExceptionObject(new \LogicException(Document::class . ' cannot advance its [version] version, as the stored version was not loaded or is null.'));

        $document->save();
    }

    /**
     * Test that a model naming its own version column advances that column.
     *
     * @return void
     */
    public function testVersionConstantRenamesTheColumn(): void
    {
        $draft = new Draft;

        $draft->fill(['title' => 'Draft'])->save();
        $draft->fill(['title' => 'Revised'])->save();

        self::assertSame('revision', $draft->getVersionColumn());
        self::assertSame(2, $draft->getAttribute('revision'));
        self::assertArrayNotHasKey('version', $draft->getAttributes());
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createDocumentTables();

        Schema::dropIfExists('drafts');
        Schema::create('drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('revision')->default(1);
        });
    }

    /**
     * Create a document and set its stored version.
     *
     * @param  int  $version
     * @return \Tests\Fixtures\Models\Document
     */
    private function documentAtVersion(int $version): Document
    {
        $document = Document::create(['title' => 'Draft']);

        Document::query()->whereKey($document->id)->update(['version' => $version]);

        return Document::query()->findOrFail($document->id);
    }

    /**
     * Read the version stored for the given document.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $document
     * @return int
     */
    private function storedVersion(Model $document): int
    {
        $version = Document::query()->whereKey($document->getKey())->value('version');

        assert(is_numeric($version));

        return (int) $version;
    }
}
