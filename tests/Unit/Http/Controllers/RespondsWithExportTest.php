<?php

namespace Tests\Unit\Http\Controllers;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Controllers\RespondsWithExport;
use SineMacula\Exporter\Facades\Exporter;
use Tests\TestCase;

/**
 * Tests for the RespondsWithExport trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RespondsWithExport::class)]
class RespondsWithExportTest extends TestCase
{
    private const string CONTENT_TYPE_CSV = 'text/csv';

    /**
     * Test that exportFromArray with CSV accept header returns a CSV response.
     *
     * @return void
     */
    public function testExportFromArrayWithCsvAcceptHeaderReturnsCsvResponse(): void
    {
        $controller = $this->createController();
        $data       = [['id' => 1, 'name' => 'Alice']];

        Request::macro('expectsCsv', fn () => true);
        Request::macro('expectsXml', fn () => false);

        $exporter = $this->createMockExporter("id,name\n1,Alice");

        Exporter::swap($exporter);

        /** @var \Illuminate\Http\Response $response */
        $response = $controller->exportFromArray($data); // @phpstan-ignore method.notFound

        static::assertInstanceOf(HttpResponse::class, $response);
        static::assertSame(self::CONTENT_TYPE_CSV, $response->headers->get('Content-Type'));
        static::assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    /**
     * Test that exportFromArray with XML accept header returns an XML response.
     *
     * @return void
     */
    public function testExportFromArrayWithXmlAcceptHeaderReturnsXmlResponse(): void
    {
        $controller = $this->createController();
        $data       = [['id' => 1, 'name' => 'Alice']];

        Request::macro('expectsCsv', fn () => false);
        Request::macro('expectsXml', fn () => true);

        $exporter = $this->createMockExporter('<root><item><id>1</id></item></root>');

        Exporter::swap($exporter);

        /** @var \Illuminate\Http\Response $response */
        $response = $controller->exportFromArray($data); // @phpstan-ignore method.notFound

        static::assertInstanceOf(HttpResponse::class, $response);
        static::assertSame('application/xml', $response->headers->get('Content-Type'));
    }

    /**
     * Test that exportFromArray with unsupported format throws
     * InvalidArgumentException.
     *
     * @return void
     */
    public function testExportFromArrayWithUnsupportedFormatThrowsInvalidArgumentException(): void
    {
        $controller = $this->createController();
        $data       = [['id' => 1, 'name' => 'Alice']];

        Request::macro('expectsCsv', fn () => false);
        Request::macro('expectsXml', fn () => false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported export format');

        $controller->exportFromArray($data); // @phpstan-ignore method.notFound
    }

    /**
     * Test that createExportResponse sets correct headers.
     *
     * @return void
     */
    public function testCreateExportResponseSetsCorrectHeaders(): void
    {
        $controller = $this->createController();

        $reflection = new \ReflectionMethod($controller, 'createExportResponse');

        /** @var \Illuminate\Http\Response $response */
        $response = $reflection->invoke($controller, 'test-data', self::CONTENT_TYPE_CSV, false, 'export.csv');

        static::assertSame(self::CONTENT_TYPE_CSV, $response->headers->get('Content-Type'));
        static::assertSame((string) strlen('test-data'), $response->headers->get('Content-Length'));
        static::assertNull($response->headers->get('Content-Disposition'));
    }

    /**
     * Test that download flag adds Content-Disposition header.
     *
     * @return void
     */
    public function testDownloadFlagAddsContentDispositionHeader(): void
    {
        $controller = $this->createController();

        $reflection = new \ReflectionMethod($controller, 'createExportResponse');

        /** @var \Illuminate\Http\Response $response */
        $response = $reflection->invoke($controller, 'test-data', self::CONTENT_TYPE_CSV, true, 'export.csv');

        static::assertSame('attachment; filename="export.csv"', $response->headers->get('Content-Disposition'));
    }

    /**
     * Create a test controller that uses the RespondsWithExport trait.
     *
     * @return object
     */
    private function createController(): object
    {
        return new class {
            use RespondsWithExport;
        };
    }

    /**
     * Create a mock exporter for testing.
     *
     * @param  string  $output
     * @return object
     */
    private function createMockExporter(string $output): object
    {
        return new class ($output) {
            /** @var string */
            private string $output;

            /**
             * Create a new instance.
             *
             * @param  string  $output
             */
            public function __construct(string $output)
            {
                $this->output = $output;
            }

            /**
             * Get the export format.
             *
             * @SuppressWarnings("php:S1172")
             *
             * @param  ?string  $_format
             * @return self
             */
            public function format(?string $_format = null): self
            {
                return $this;
            }

            /**
             * @SuppressWarnings("php:S1172")
             *
             * @param  array<int, string>|string  $_fields
             * @return self
             */
            public function withoutFields(array|string $_fields): self
            {
                return $this;
            }

            /**
             * @SuppressWarnings("php:S1172")
             *
             * @param  array<int, array<string, mixed>>  $_data
             * @return string
             */
            public function exportArray(array $_data): string
            {
                return $this->output;
            }

            /**
             * @SuppressWarnings("php:S1172")
             *
             * @param  string  $_method
             * @param  array<int, mixed>  $_parameters
             * @return mixed
             */
            public function __call(string $_method, array $_parameters): mixed
            {
                return $this;
            }
        };
    }
}
