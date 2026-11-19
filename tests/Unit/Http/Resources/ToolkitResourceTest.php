<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Concurrency\EntityTag;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ToolkitResource;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Concerns\CreatesDocumentTables;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Reports\SalesSummaryResource;
use Tests\Fixtures\Reports\SalesSummaryRow;
use Tests\Fixtures\Resources\DocumentResource;
use Tests\TestCase;

/**
 * Tests for the shared toolkit single-item resource base.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitResource::class)]
final class ToolkitResourceTest extends TestCase
{
    use CreatesDocumentTables;

    /**
     * Test that toResponse returns the native JSON response, without the export
     * negotiation Vary header, when the exporter is not bound.
     *
     * @return void
     */
    public function testToResponseFallsBackToNativeResponseWhenExporterUnbound(): void
    {
        self::assertFalse(App::bound(ExportNegotiator::class));

        $resource = new SalesSummaryResource(new SalesSummaryRow('2026-01', 42, '4821.00'));

        $response = $resource->toResponse(Request::create('/', HttpMethod::GET->getVerb()));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->has('Vary'));
    }

    /**
     * Test that a versioned item response carries the item's entity tag.
     *
     * @return void
     */
    public function testVersionedItemResponseCarriesItsEntityTag(): void
    {
        $document = Document::create(['title' => 'Draft']);

        $response = (new DocumentResource($document))->toResponse($this->request());

        self::assertSame(EntityTag::for($document), $response->headers->get('ETag'));
        self::assertStringStartsWith('W/"', (string) $response->headers->get('ETag'));
    }

    /**
     * Test that an item response that is not a versioned model carries no
     * entity tag.
     *
     * @return void
     */
    public function testItemThatIsNotAVersionedModelCarriesNoEntityTag(): void
    {
        $response = (new SalesSummaryResource(new SalesSummaryRow('2026-01', 42, '4821.00')))->toResponse($this->request());

        self::assertFalse($response->headers->has('ETag'));
    }

    /**
     * Test that a collection of versioned items carries no entity tag.
     *
     * @return void
     */
    public function testCollectionCarriesNoEntityTag(): void
    {
        Document::create(['title' => 'Draft']);

        $response = (new ApiResourceCollection(Document::query()->get(), DocumentResource::class))->toResponse($this->request());

        self::assertFalse($response->headers->has('ETag'));
    }

    /**
     * Test that a versioned item nested in another response tags nothing.
     *
     * @return void
     */
    public function testNestedVersionedItemCarriesNoEntityTag(): void
    {
        $nested = new DocumentResource(Document::create(['title' => 'Draft']));

        $response = (new class ($nested) extends ToolkitResource {
            /**
             * Wrap the nested resource.
             *
             * @param  \Illuminate\Http\Request  $request
             * @return array<string, mixed>
             */
            #[\Override]
            public function toArray(Request $request): array
            {
                return ['document' => $this->resource];
            }
        })->toResponse($this->request());

        self::assertSame('Draft', data_get(json_decode((string) $response->getContent(), true), 'data.document.title'));
        self::assertFalse($response->headers->has('ETag'));
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
     * Build a GET request.
     *
     * @return \Illuminate\Http\Request
     */
    private function request(): Request
    {
        return Request::create('/', HttpMethod::GET->getVerb());
    }
}
