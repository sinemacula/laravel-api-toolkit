<?php

declare(strict_types = 1);

namespace Tests\Integration\Repositories;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\PreconditionFailedException;
use SineMacula\ApiToolkit\Http\Concurrency\EntityTag;
use SineMacula\ApiToolkit\Http\Concurrency\IfMatch;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Concerns\CreatesDocumentTables;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Repositories\DocumentRepository;
use Tests\TestCase;

/**
 * Integration tests for the locked, conditional read of a row to write, run in
 * transactions the test owns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
final class FindForUpdateTest extends TestCase
{
    use CommitsRealTransactions, CreatesDocumentTables;

    /** @var string The refusal of a locked read outside a transaction */
    private const string UNLOCKED_MESSAGE = '%s::findForUpdate() must run inside a transaction on the [%s] connection, or its lock is released at once.';

    /**
     * Test that a locked read outside any transaction is refused.
     *
     * @return void
     */
    public function testReadOutsideATransactionIsRefused(): void
    {
        $document = Document::create(['title' => 'Draft']);

        $this->expectExceptionObject(new \LogicException(sprintf(self::UNLOCKED_MESSAGE, DocumentRepository::class, 'testing')));

        $this->documents()->findForUpdate($document->id);
    }

    /**
     * Test that a write another writer committed after the client's read fails
     * the client's precondition and rolls back the client's transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testWriteAfterTheClientsReadFailsThePreconditionAndRollsBack(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $read     = (string) EntityTag::for($document);

        Document::query()->findOrFail($document->id)->update(['title' => 'Theirs']);

        try {
            DB::connection()->transaction(function () use ($document, $read): void {
                Document::create(['title' => 'Written before the check']);

                $this->documents()->findForUpdate($document->id, $this->ifMatch($read));
            });

            self::fail('A stale precondition must be refused.');
        } catch (PreconditionFailedException) {
            self::assertSame(['Theirs'], Document::query()->pluck('title')->all());
        }
    }

    /**
     * Test that a precondition naming the stored tag reads the row inside the
     * transaction that writes it.
     *
     * @return void
     */
    public function testCurrentPreconditionReadsTheRowToWrite(): void
    {
        $document = Document::create(['title' => 'Draft']);

        DB::transaction(function () use ($document): void {
            $locked = $this->documents()->findForUpdate($document->id, $this->ifMatch((string) EntityTag::for($document)));

            $locked->update(['title' => 'Mine']);
        });

        self::assertSame(2, Document::query()->findOrFail($document->id)->version);
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
    }

    /**
     * Resolve a document repository.
     *
     * @return \Tests\Fixtures\Repositories\DocumentRepository
     */
    private function documents(): DocumentRepository
    {
        assert($this->app !== null);

        return $this->app->make(DocumentRepository::class);
    }

    /**
     * Read the precondition the given If-Match field sets.
     *
     * @param  string  $field
     * @return \SineMacula\ApiToolkit\Http\Concurrency\IfMatch
     */
    private function ifMatch(string $field): IfMatch
    {
        $ifMatch = IfMatch::fromRequest(Request::create('/', 'PATCH', server: ['HTTP_IF_MATCH' => $field]));

        assert($ifMatch !== null);

        return $ifMatch;
    }
}
