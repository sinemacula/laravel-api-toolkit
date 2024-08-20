<?php

namespace SineMacula\ApiToolkit\Http\Controllers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use InvalidArgumentException;
use SineMacula\Exporter\Facades\Exporter;

/**
 * Responds with export trait.
 *
 * Handles the exporting of datasets within controllers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
trait RespondsWithExport
{
    /**
     * Determine if the request wants an export.
     *
     * @return bool
     */
    protected function wantsExport(): bool
    {
        return config('api-toolkit.exports.enabled') && ($this->wantsCsv() || $this->wantsXml());
    }

    /**
     * Determine if the request wants a CSV export.
     *
     * @return bool
     */
    protected function wantsCsv(): bool
    {
        return strtolower(Request::header('Accept')) === 'text/csv'
            && in_array('csv', config('api-toolkit.exports.supported_formats', []));
    }

    /**
     * Determine if the request wants an XML export.
     *
     * @return bool
     */
    protected function wantsXml(): bool
    {
        return strtolower(Request::header('Accept')) === 'application/xml'
            && in_array('xml', config('api-toolkit.exports.supported_formats', []));
    }

    /**
     * Export the given collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  bool  $download
     * @return \Illuminate\Http\Response
     */
    public function exportFromCollection(ResourceCollection $collection, bool $download = true): HttpResponse
    {
        return match (true) {
            $this->wantsCsv() => $this->exportCollectionToCsv($collection, $download),
            $this->wantsXml() => $this->exportCollectionToXml($collection, $download),
            default           => throw new InvalidArgumentException('Unsupported export format'),
        };
    }

    /**
     * Export the given collection to CSV.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  bool  $download
     * @return \Illuminate\Http\Response
     */
    public function exportCollectionToCsv(ResourceCollection $collection, bool $download = true): HttpResponse
    {
        $csv = Exporter::format('csv')
            ->withoutFields(config('api-toolkit.exports.ignored_fields', []))
            ->exportCollection($collection);

        return $this->createExportResponse($csv, 'text/csv', $download, 'export.csv');
    }

    /**
     * Export the given collection to XML.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  bool  $download
     * @return \Illuminate\Http\Response
     */
    public function exportCollectionToXml(ResourceCollection $collection, bool $download = true): HttpResponse
    {
        $xml = Exporter::format('xml')
            ->withoutFields(config('api-toolkit.exports.ignored_fields', []))
            ->exportCollection($collection);

        return $this->createExportResponse($xml, 'application/xml', $download, 'export.xml');
    }

    /**
     * Export the given resource.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  bool  $download
     * @return \Illuminate\Http\Response
     */
    public function exportFromItem(JsonResource $resource, bool $download = true): HttpResponse
    {
        return match (true) {
            $this->wantsCsv() => $this->exportItemToCsv($resource, $download),
            $this->wantsXml() => $this->exportItemToXml($resource, $download),
            default           => throw new InvalidArgumentException('Unsupported export format'),
        };
    }

    /**
     * Export the given item to CSV.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  bool  $download
     * @return \Illuminate\Http\Response
     */
    public function exportItemToCsv(JsonResource $resource, bool $download = true): HttpResponse
    {
        $csv = Exporter::format('csv')
            ->withoutFields(config('api-toolkit.exports.ignored_fields', []))
            ->exportItem($resource);

        return $this->createExportResponse($csv, 'text/csv', $download, 'export.csv');
    }

    /**
     * Export the given item to XML.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  bool  $download
     * @return \Illuminate\Http\Response
     */
    public function exportItemToXml(JsonResource $resource, bool $download = true): HttpResponse
    {
        $xml = Exporter::format('xml')
            ->withoutFields(config('api-toolkit.exports.ignored_fields', []))
            ->exportItem($resource);

        return $this->createExportResponse($xml, 'application/xml', $download, 'export.xml');
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
            ->header('Content-Type', $content_type)
            ->header('Content-Length', strlen($data));

        if ($download) {
            $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        }

        return $response;
    }
}
