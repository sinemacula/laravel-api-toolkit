<?php

namespace SineMacula\ApiToolkit\Http\Concerns;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use SineMacula\Exporter\Contracts\Exporter as ExporterContract;
use SineMacula\Exporter\Facades\Exporter;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;

/**
 * Responds with export trait.
 *
 * Handles the exporting of datasets within controllers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait RespondsWithExport
{
    /** @var string UTF-8 charset suffix appended to CSV content types. */
    private const string CHARSET_UTF8_SUFFIX = '; charset=utf-8';

    /** @var string Error message for unsupported export formats. */
    private const string UNSUPPORTED_FORMAT_MESSAGE = 'Unsupported export format';

    /**
     * Export the given array.
     *
     * @param  array<int|string, mixed>  $data
     * @param  bool  $download
     * @param  string|null  $filename
     * @return \Illuminate\Http\Response
     *
     * @throws \InvalidArgumentException
     */
    public function exportFromArray(array $data, bool $download = true, ?string $filename = null): HttpResponse
    {
        return match (true) {
            Request::expectsCsv() => $this->exportArrayToCsv($data, $download, $filename ?? 'export.csv'),
            Request::expectsXml() => $this->exportArrayToXml($data, $download, $filename ?? 'export.xml'),
            default               => throw new \InvalidArgumentException(self::UNSUPPORTED_FORMAT_MESSAGE),
        };
    }

    /**
     * Export the given array to CSV.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  bool  $download
     * @param  string  $filename
     * @return \Illuminate\Http\Response
     */
    public function exportArrayToCsv(array $data, bool $download = true, string $filename = 'export.csv'): HttpResponse
    {
        $csv = $this->buildExporter('csv')->exportArray($data);

        return $this->createExportResponse($csv, MediaType::TextCsv->value . self::CHARSET_UTF8_SUFFIX, $download, $filename);
    }

    /**
     * Export the given array to XML.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  bool  $download
     * @param  string  $filename
     * @return \Illuminate\Http\Response
     */
    public function exportArrayToXml(array $data, bool $download = true, string $filename = 'export.xml'): HttpResponse
    {
        $xml = $this->buildExporter('xml')->exportArray($data);

        return $this->createExportResponse($xml, MediaType::ApplicationXml->value, $download, $filename);
    }

    /**
     * Export the given collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  bool  $download
     * @param  string|null  $filename
     * @return \Illuminate\Http\Response
     *
     * @throws \InvalidArgumentException
     */
    public function exportFromCollection(ResourceCollection $collection, bool $download = true, ?string $filename = null): HttpResponse
    {
        return match (true) {
            Request::expectsCsv() => $this->exportCollectionToCsv($collection, $download, $filename ?? 'export.csv'),
            Request::expectsXml() => $this->exportCollectionToXml($collection, $download, $filename ?? 'export.xml'),
            default               => throw new \InvalidArgumentException(self::UNSUPPORTED_FORMAT_MESSAGE),
        };
    }

    /**
     * Export the given collection to CSV.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  bool  $download
     * @param  string  $filename
     * @return \Illuminate\Http\Response
     */
    public function exportCollectionToCsv(ResourceCollection $collection, bool $download = true, string $filename = 'export.csv'): HttpResponse
    {
        $csv = $this->buildExporter('csv')->exportCollection($collection);

        return $this->createExportResponse($csv, MediaType::TextCsv->value . self::CHARSET_UTF8_SUFFIX, $download, $filename);
    }

    /**
     * Export the given collection to XML.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  bool  $download
     * @param  string  $filename
     * @return \Illuminate\Http\Response
     */
    public function exportCollectionToXml(ResourceCollection $collection, bool $download = true, string $filename = 'export.xml'): HttpResponse
    {
        $xml = $this->buildExporter('xml')->exportCollection($collection);

        return $this->createExportResponse($xml, MediaType::ApplicationXml->value, $download, $filename);
    }

    /**
     * Export the given resource.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  bool  $download
     * @param  string|null  $filename
     * @return \Illuminate\Http\Response
     *
     * @throws \InvalidArgumentException
     */
    public function exportFromItem(JsonResource $resource, bool $download = true, ?string $filename = null): HttpResponse
    {
        return match (true) {
            Request::expectsCsv() => $this->exportItemToCsv($resource, $download, $filename ?? 'export.csv'),
            Request::expectsXml() => $this->exportItemToXml($resource, $download, $filename ?? 'export.xml'),
            default               => throw new \InvalidArgumentException(self::UNSUPPORTED_FORMAT_MESSAGE),
        };
    }

    /**
     * Export the given item to CSV.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  bool  $download
     * @param  string  $filename
     * @return \Illuminate\Http\Response
     */
    public function exportItemToCsv(JsonResource $resource, bool $download = true, string $filename = 'export.csv'): HttpResponse
    {
        $csv = $this->buildExporter('csv')->exportItem($resource);

        return $this->createExportResponse($csv, MediaType::TextCsv->value . self::CHARSET_UTF8_SUFFIX, $download, $filename);
    }

    /**
     * Export the given item to XML.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  bool  $download
     * @param  string  $filename
     * @return \Illuminate\Http\Response
     */
    public function exportItemToXml(JsonResource $resource, bool $download = true, string $filename = 'export.xml'): HttpResponse
    {
        $xml = $this->buildExporter('xml')->exportItem($resource);

        return $this->createExportResponse($xml, MediaType::ApplicationXml->value, $download, $filename);
    }

    /**
     * Create a response for the exported data.
     *
     * @param  string  $data
     * @param  string  $content_type
     * @param  bool  $download
     * @param  string  $filename
     * @return \Illuminate\Http\Response
     */
    protected function createExportResponse(string $data, string $content_type, bool $download, string $filename): HttpResponse
    {
        $response = Response::make($data)
            ->header(HttpHeader::ContentType->value, $content_type)
            ->header(HttpHeader::ContentLength->value, strlen($data));

        if ($download) {
            $response->header(HttpHeader::ContentDisposition->value, 'attachment; filename="' . $filename . '"');
        }

        return $response;
    }

    /**
     * Build a configured exporter instance for the given format.
     *
     * @param  string  $format
     * @return \SineMacula\Exporter\Contracts\Exporter
     */
    private function buildExporter(string $format): ExporterContract
    {
        return Exporter::format($format)
            ->withoutFields(config('api-toolkit.exports.ignored_fields', []));
    }
}
