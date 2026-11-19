<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Concurrency\IfMatch;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\RequireIfMatch;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Http\Resources\ToolkitResource;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Tests\Concerns\CreatesDocumentTables;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Repositories\DocumentRepository;
use Tests\Fixtures\Resources\DocumentResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Services\RetitleDocumentService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Feature tests for conditional writes through entity tags and If-Match.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitResource::class)]
#[CoversClass(IfMatch::class)]
#[CoversClass(ApiRepository::class)]
#[CoversClass(RequireIfMatch::class)]
final class OptimisticConcurrencyTest extends TestCase
{
    use CreatesDocumentTables, RecordsStatements, RegistersApiExceptionHandler;

    /** @var int The document every test reads and writes */
    private int $id;

    /**
     * Set up the document routes and a document to write.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [DocumentResource::class, TagResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware([ParseApiQuery::class, RequireIfMatch::class])->group(function (): void {

            Route::get('/documents/{id}', static function (DocumentRepository $documents, int $id): DocumentResource {

                $document = $documents->usingResource(DocumentResource::class)->withApiCriteria()->scopeById($id)->first();

                assert($document instanceof Document);

                return new DocumentResource($document);
            });

            Route::patch('/documents/{id}', static function (Request $request, int $id): DocumentResource {

                $document = RetitleDocumentService::make(new ArrayInput(['id' => $id, 'title' => $request->input('title')]))
                    ->withContext(ServiceContext::fromRequest($request, new StubActor))
                    ->runOrFail();

                return new DocumentResource($document);
            });

            Route::delete('/documents/{id}', static fn (): never => throw new PreconditionFailedHttpException);
        });

        $this->id = Document::create(['title' => 'Draft'])->id;
    }

    /**
     * Test that a write sending the tag it read succeeds and answers the new
     * tag, and a write sending the old tag is refused and changes nothing.
     *
     * @return void
     */
    public function testWriteWithTheReadTagSucceedsAndALaterWriteWithItIsRefused(): void
    {
        $read = (string) $this->getJson('/documents/' . $this->id)->assertOk()->headers->get('ETag');

        self::assertStringStartsWith('W/"', $read);

        $written = $this->patchJson('/documents/' . $this->id, ['title' => 'Revised'], ['If-Match' => $read])
            ->assertOk()
            ->assertJsonPath('data.title', 'Revised');

        $fresh = (string) $written->headers->get('ETag');

        self::assertNotSame($read, $fresh);
        self::assertSame($fresh, $this->getJson('/documents/' . $this->id)->headers->get('ETag'));

        $this->patchJson('/documents/' . $this->id, ['title' => 'Overwritten'], ['If-Match' => $read])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 10114);

        self::assertSame('Revised', Document::query()->findOrFail($this->id)->title);
        self::assertSame(2, Document::query()->findOrFail($this->id)->version);
    }

    /**
     * Test that a narrowed read keeps the version column and so its tag.
     *
     * @return void
     */
    public function testNarrowedReadStillCarriesTheTag(): void
    {
        $full = $this->getJson('/documents/' . $this->id)->headers->get('ETag');

        $narrowed   = null;
        $statements = $this->recordStatements(function () use (&$narrowed): void {
            $narrowed = $this->getJson('/documents/' . $this->id . '?' . http_build_query(['fields' => ['documents' => 'title']]))->assertOk();
        });

        self::assertNotNull($full);
        self::assertSame($full, $narrowed?->headers->get('ETag'));
        self::assertStringNotContainsString('created_at', $statements[0]);
    }

    /**
     * Test that a write without the field on a route requiring it is refused.
     *
     * @return void
     */
    public function testWriteWithoutTheFieldIsRefusedWhereItIsRequired(): void
    {
        $this->patchJson('/documents/' . $this->id, ['title' => 'Blind'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 10115);

        self::assertSame('Draft', Document::query()->findOrFail($this->id)->title);
    }

    /**
     * Test that a precondition failure the application throws renders as one.
     *
     * @return void
     */
    public function testApplicationPreconditionFailureRendersWithItsCode(): void
    {
        $this->deleteJson('/documents/' . $this->id, [], ['If-Match' => '*'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 10114);
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
}
