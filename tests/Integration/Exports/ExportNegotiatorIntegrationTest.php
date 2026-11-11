<?php

declare(strict_types = 1);

namespace Tests\Integration\Exports;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Http\Resources\ToolkitCollection;
use SineMacula\ApiToolkit\Http\Resources\ToolkitResource;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ExportableUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests for export content negotiation via the ExportNegotiator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitResource::class)]
#[CoversClass(ToolkitCollection::class)]
#[CoversClass(ApiResource::class)]
#[CoversTrait(DerivesTabularSchema::class)]
final class ExportNegotiatorIntegrationTest extends TestCase
{
    /**
     * Set up each test with seeded users and negotiator routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->seedUsers();
        $this->registerRoutes();
    }

    /**
     * Tear down, clearing the schema compiler cache.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that a collection returns the JSON envelope and Vary header.
     *
     * @return void
     */
    public function testCollectionReturnsJsonEnvelopeByDefault(): void
    {
        $response = $this->get('/export/users');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Vary', 'Accept');
        $response->assertJsonStructure(['data', 'links', 'meta']);
        $response->assertHeader('Total-Count', '2');
    }

    /**
     * Test that an application/json Accept header returns the JSON envelope.
     *
     * @return void
     */
    public function testCollectionReturnsJsonEnvelopeForJsonAcceptHeader(): void
    {
        $response = $this->get('/export/users', ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Vary', 'Accept');
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    /**
     * Test that a collection streams CSV for an Accept: text/csv header.
     *
     * @return void
     */
    public function testCollectionStreamsCsvForCsvAcceptHeader(): void
    {
        $response = $this->get('/export/users', ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Vary', 'Accept');

        $content = $response->streamedContent();

        self::assertNotEmpty($content);
        self::assertStringContainsString('Alice', $content);
    }

    /**
     * Test that a collection streams TSV for a ?format=tsv query parameter.
     *
     * @return void
     */
    public function testCollectionStreamsTsvForFormatQueryParameter(): void
    {
        $response = $this->get('/export/users?format=tsv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/tab-separated-values; charset=UTF-8');
        $response->assertHeader('Vary', 'Accept');

        $content = $response->streamedContent();

        self::assertNotEmpty($content);
        self::assertStringContainsString('Alice', $content);
    }

    /**
     * Test that a collection streams XML for an Accept: application/xml header.
     *
     * @return void
     */
    public function testCollectionStreamsXmlForXmlAcceptHeader(): void
    {
        $response = $this->get('/export/users', ['Accept' => 'application/xml']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertHeader('Vary', 'Accept');

        $content = $response->streamedContent();

        self::assertNotEmpty($content);
        self::assertStringContainsString('Alice', $content);
    }

    /**
     * Test that a collection streams NDJSON for an x-ndjson Accept header.
     *
     * @return void
     */
    public function testCollectionStreamsNdjsonForNdjsonAcceptHeader(): void
    {
        $response = $this->get('/export/users', ['Accept' => 'application/x-ndjson']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/x-ndjson; charset=UTF-8');
        $response->assertHeader('Vary', 'Accept');

        $content = $response->streamedContent();

        self::assertNotEmpty($content);
        self::assertStringContainsString('Alice', $content);
    }

    /**
     * Test that a collection returns 406 for a tabular format with no schema.
     *
     * @return void
     */
    public function testCollectionReturnsSixForTabularFormatWithoutTabularSchema(): void
    {
        $response = $this->get('/export/basic-users', ['Accept' => 'text/csv']);

        $response->assertStatus(406);
    }

    /**
     * Test that an item returns the JSON envelope and Vary header by default.
     *
     * @return void
     */
    public function testItemReturnsJsonEnvelopeByDefault(): void
    {
        $user     = User::query()->firstOrFail();
        $response = $this->get('/export/users/' . $user->id);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Vary', 'Accept');
        $response->assertJsonStructure(['data']);
    }

    /**
     * Test that a single item streams CSV for an Accept: text/csv header.
     *
     * @return void
     */
    public function testItemStreamsCsvForCsvAcceptHeader(): void
    {
        $user     = User::query()->firstOrFail();
        $response = $this->get('/export/users/' . $user->id, ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Vary', 'Accept');

        $content = $response->streamedContent();

        self::assertNotEmpty($content);
        self::assertStringContainsString('Alice', $content);
    }

    /**
     * Test that a single item returns 406 for a tabular format with no schema.
     *
     * @return void
     */
    public function testItemReturnsSixForTabularFormatWithoutTabularSchema(): void
    {
        $user     = User::query()->firstOrFail();
        $response = $this->get('/export/basic-users/' . $user->id, ['Accept' => 'text/csv']);

        $response->assertStatus(406);
    }

    /**
     * Test that the derived tabular schema resolves the computed label column.
     *
     * @return void
     */
    public function testDerivedTabularSchemaResolvesComputedColumn(): void
    {
        $response = $this->get('/export/users', ['Accept' => 'text/csv']);
        $content  = $response->streamedContent();

        self::assertStringContainsString('Alice <alice@example.com>', $content);
    }

    /**
     * Test that the derived tabular schema exports timestamps as ISO 8601.
     *
     * @return void
     */
    public function testDerivedTabularSchemaResolvesTimestampAccessor(): void
    {
        $response = $this->get('/export/users', ['Accept' => 'text/csv']);
        $content  = $response->streamedContent();

        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $content);
    }

    /**
     * Test that relation fields are excluded from the derived tabular schema.
     *
     * @return void
     */
    public function testDerivedTabularSchemaExcludesRelationFields(): void
    {
        $response = $this->get('/export/users', ['Accept' => 'text/csv']);
        $content  = $response->streamedContent();

        self::assertStringNotContainsString('organization', strtolower($content));
    }

    /**
     * Register the exporter service provider alongside the toolkit.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ExporterServiceProvider::class,
        ];
    }

    /**
     * Seed the database with two users for the export tests.
     *
     * @return void
     */
    private function seedUsers(): void
    {
        User::create([
            'name'   => 'Alice',
            'email'  => 'alice@example.com',
            'status' => 'active',
        ]);

        User::create([
            'name'   => 'Bob',
            'email'  => 'bob@example.com',
            'status' => 'active',
        ]);
    }

    /**
     * Register the HTTP routes used by the negotiator test scenarios.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        Route::get('/export/users', static fn () => ExportableUserResource::collection(User::paginate(10)));

        Route::get('/export/users/{id}', static fn (int $id) => new ExportableUserResource(User::findOrFail($id)));

        Route::get('/export/basic-users', static fn () => UserResource::collection(User::paginate(10)));

        Route::get('/export/basic-users/{id}', static fn (int $id) => new UserResource(User::findOrFail($id)));
    }
}
