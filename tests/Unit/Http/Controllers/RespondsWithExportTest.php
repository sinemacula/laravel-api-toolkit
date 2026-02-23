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

        $exporter = $this->createMockExporter('csv', "id,name\n1,Alice");

        Exporter::swap($exporter);

        $response = $controller->exportFromArray($data);

        static::assertInstanceOf(HttpResponse::class, $response);
        static::assertSame('text/csv', $response->headers->get('Content-Type'));
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

        $exporter = $this->createMockExporter('xml', '<root><item><id>1</id></item></root>');

        Exporter::swap($exporter);

        $response = $controller->exportFromArray($data);

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

        $controller->exportFromArray($data);
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

        $response = $reflection->invoke($controller, 'test-data', 'text/csv', false, 'export.csv');

        static::assertSame('text/csv', $response->headers->get('Content-Type'));
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

        $response = $reflection->invoke($controller, 'test-data', 'text/csv', true, 'export.csv');

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
     * @param  string  $format
     * @param  string  $output
     * @return object
     */
    private function createMockExporter(string $format, string $output): object
    {
        return new class ($format, $output) {
            /** @var string */
            private string $format;

            /** @var string */
            private string $output;

            /** @var array<int, string> */
            private array $withoutFields = [];

            /**
             * Create a new instance.
             *
             * @param  string  $format
             * @param  string  $output
             */
            public function __construct(string $format, string $output)
            {
                $this->format = $format;
                $this->output = $output;
            }

            /**
             * Get the export format.
             *
             * @param  ?string  $format
             * @return self
             */
            public function format(?string $format = null): self
            {
                return $this;
            }

            /**
             * @param  array<int, string>|string  $fields
             * @return self
             */
            public function withoutFields(array|string $fields): self
            {
                $this->withoutFields = (array) $fields;

                return $this;
            }

            /**
             * @param  array<int, array<string, mixed>>  $data
             * @return string
             */
            public function exportArray(array $data): string
            {
                return $this->output;
            }

            /**
             * @param  string  $method
             * @param  array<int, mixed>  $parameters
             * @return mixed
             */
            public function __call(string $method, array $parameters): mixed
            {
                return $this;
            }
        };
    }
}
