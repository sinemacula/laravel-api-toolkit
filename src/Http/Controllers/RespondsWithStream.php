<?php

namespace SineMacula\ApiToolkit\Http\Controllers;

use Closure;
use Illuminate\Support\Facades\Response;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Exporter\Facades\Exporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responds with stream trait.
 *
 * Handles the streaming of datasets within controllers.
 *
 * @author      Michael Stivala <michael.stivala@verifast.app>
 * @copyright   2025 Verifast, Inc.
 */
trait RespondsWithStream
{
    /**
     * Stream a repository's data as a CSV file.
     *
     * @param  ApiRepository  $repository
     * @param  int  $chunk_size
     * @return StreamedResponse
     */
    public function streamRepositoryToCsv(ApiRepository $repository, int $chunk_size = 1500): StreamedResponse
    {
        $limit = ApiQuery::getLimit();

        $transformer = $this->makeTransformer($repository);

        $stream = function () use ($repository, $transformer, $chunk_size, $limit): void {
            $is_first_chunk = true;
            $processed      = 0;

            $repository->chunkById($chunk_size, function ($chunk) use ($transformer, &$is_first_chunk, &$processed, $limit): bool {
                if ($limit && $processed >= $limit) {
                    return false; // Stop chunking
                }

                $items = $limit ? $chunk->take($limit - $processed) : $chunk;
                $processed += $items->count();

                $csv = $this->formatChunkAsCsv($transformer($items->all()), $is_first_chunk);

                // Remove trailing newline to prevent blank lines between chunks
                echo rtrim($csv);

                ob_flush();
                flush();

                return true;
            });
        };

        return $this->createStreamedResponse($stream, 'text/csv', 'export.csv');
    }

    /**
     * Create a transformer closure for the given repository.
     *
     * @param  ApiRepository  $repository
     * @return \Closure
     */
    protected function makeTransformer(ApiRepository $repository): \Closure
    {
        if (!$resource_class = $repository->getResourceClass()) {
            throw new \InvalidArgumentException('Unable to resolve resource class from repository.');
        }

        return fn ($items) => $resource_class::collection($items)->resolve();
    }

    /**
     * Process a chunk of verifications and write to CSV.
     *
     * Uses the vendor Exporter for consistent formatting (column name conversion,
     * escaping, etc.) but handles header row output to avoid duplicates per chunk.
     *
     * @param  array  $rows
     * @param  bool  $is_first_chunk
     * @return string
     */
    protected function formatChunkAsCsv(array $rows, bool &$is_first_chunk): string
    {
        $exporter = Exporter::format('csv')
            ->withoutFields(config('api-toolkit.exports.ignored_fields', []));

        if (!$is_first_chunk) {
            $exporter->withoutHeaders();
        }

        $is_first_chunk = false;
        return $exporter->exportArray($rows);
    }

    /**
     * Create a streamed response.
     *
     * @param  callable  $callback
     * @param  string  $content_type
     * @param  string  $filename
     * @return StreamedResponse
     */
    protected function createStreamedResponse(callable $callback, string $content_type, string $filename): StreamedResponse
    {
        return Response::streamDownload($callback, $filename, [
            'Content-Type' => $content_type,
        ]);
    }
}
