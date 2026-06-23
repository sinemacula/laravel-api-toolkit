<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Concerns\RespondsWithExport;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Fixtures\Controllers\TestingExportController;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * End-to-end tests covering export content negotiation.
 *
 * A real HTTP request resolves a controller route using the
 * export-capable controller surface with the real resource exporter
 * registered; the Accept header drives the negotiation and the test
 * asserts the response body is the exported payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(RespondsWithExport::class)]
final class ExportNegotiationTest extends TestCase
{
    /** @var string The export endpoint under test. */
    private const string USERS_URI = '/api/users';

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('api-toolkit.exports.enabled', true);
        config()->set('api-toolkit.exports.supported_formats', ['csv', 'xml']);

        Route::middleware(ParseApiQuery::class)->get(self::USERS_URI, [TestingExportController::class, 'index']);

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    }

    /**
     * Test that a CSV Accept header negotiates a CSV export containing the
     * exported records.
     *
     * @return void
     */
    public function testCsvAcceptHeaderReturnsExportedCsvPayload(): void
    {
        $response = $this->get(self::USERS_URI, ['Accept' => 'text/csv']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="export.csv"');

        $content = (string) $response->baseResponse->getContent();

        static::assertStringContainsString('Alice', $content);
        static::assertStringContainsString('alice@example.com', $content);
        static::assertStringContainsString('Bob', $content);
        static::assertStringContainsString('bob@example.com', $content);
    }

    /**
     * Test that an XML Accept header negotiates an XML export containing
     * the exported records.
     *
     * @return void
     */
    public function testXmlAcceptHeaderReturnsExportedXmlPayload(): void
    {
        $response = $this->get(self::USERS_URI, ['Accept' => 'application/xml']);

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="export.xml"');

        $content_type = (string) $response->headers->get('Content-Type');

        static::assertStringStartsWith('application/xml', $content_type);

        $content = (string) $response->baseResponse->getContent();

        static::assertStringStartsWith('<?xml', $content);
        static::assertStringContainsString('Alice', $content);
        static::assertStringContainsString('bob@example.com', $content);
    }

    /**
     * Test that a request without an export Accept header falls back to the
     * standard JSON collection response.
     *
     * @return void
     */
    public function testWithoutExportAcceptHeaderReturnsJsonCollection(): void
    {
        $response = $this->getJson(self::USERS_URI);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
    }

    /**
     * Test that the export negotiation is disabled when exports are turned
     * off, falling back to the JSON response even for a CSV Accept header.
     *
     * @return void
     */
    public function testCsvAcceptHeaderFallsBackToJsonWhenExportsDisabled(): void
    {
        config()->set('api-toolkit.exports.enabled', false);

        $response = $this->get(self::USERS_URI, ['Accept' => 'text/csv']);

        $response->assertOk();

        $content_type = (string) $response->headers->get('Content-Type');

        static::assertStringStartsWith('application/json', $content_type);
    }

    /**
     * Get the package providers.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            ExporterServiceProvider::class,
        ]);
    }
}
