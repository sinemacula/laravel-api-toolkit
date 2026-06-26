<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Concerns;

use Illuminate\Support\Facades\Response;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Exporter\Facades\Exporter;
use SineMacula\Http\Enums\Charset;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responds with stream trait.
 *
 * Handles the streaming of datasets within controllers.
 *
 * @author      Michael Stivala <michael.stivala@verifast.com>
 * @copyright   2026 Sine Macula Limited.
 */
trait RespondsWithStream
{
    /**
     * Stream a repository's data as a CSV file.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model>  $repository
     * @param  int  $chunkSize
     * @param  string  $filename
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function streamRepositoryToCsv(ApiRepository $repository, int $chunkSize = 1500, string $filename = 'export.csv'): StreamedResponse
    {
        $limit = ApiQuery::getLimit();

        $transformer = $this->makeTransformer($repository);

        // Strip any user-defined ordering before chunking.
        $repository->addScope(fn ($query) => $query->getQuery()->reorder());

        $stream = function () use ($repository, $transformer, $chunkSize, $limit): void {

            $isFirstChunk = true;
            $processed    = 0;

            // @phpstan-ignore staticMethod.dynamicCall (chunkById() is forwarded to the builder via Repository::__call())
            $repository->chunkById($chunkSize, function ($chunk) use ($transformer, &$isFirstChunk, &$processed, $limit): bool {

                if ($limit && $processed >= $limit) {
                    return false; // Stop chunking
                }

                $items = $limit ? $chunk->take($limit - $processed) : $chunk;
                $processed += $items->count();

                $csv = $this->formatChunkAsCsv($transformer($items->all()), $isFirstChunk);

                // Remove trailing newline to prevent blank lines between chunks
                echo rtrim($csv, "\n");

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();

                return true;
            });
        };

        return $this->createStreamedResponse($stream, MediaType::TEXT_CSV->withCharset(Charset::UTF_8), $filename);
    }

    /**
     * Create a transformer closure for the given repository.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model>  $repository
     * @return \Closure(array<int, \Illuminate\Database\Eloquent\Model>): array<int, array<string, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    protected function makeTransformer(ApiRepository $repository): \Closure
    {
        if (!$resourceClass = $repository->getResourceClass()) {
            throw new \InvalidArgumentException('Unable to resolve resource class from repository.');
        }

        /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource> $resourceClass */
        return fn (array $items): array => $resourceClass::collection($items)->resolve();
    }

    /**
     * Format a chunk of rows as a CSV string.
     *
     * Uses the vendor Exporter for consistent formatting (column name
     * conversion, escaping, etc.) but handles header row output to avoid
     * duplicates per chunk.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  bool  $isFirstChunk
     * @return string
     */
    protected function formatChunkAsCsv(array $rows, bool &$isFirstChunk): string
    {
        $exporter = Exporter::format('csv')
            ->withoutFields(config('api-toolkit.exports.ignored_fields', []));

        if (!$isFirstChunk) {
            // @phpstan-ignore method.notFound (withoutHeaders() exists on the CSV exporter but is not part of the Exporter contract)
            $exporter->withoutHeaders();
        }

        $isFirstChunk = false;

        return $exporter->exportArray($rows);
    }

    /**
     * Create a streamed response.
     *
     * @param  callable(): void  $callback
     * @param  string  $contentType
     * @param  string  $filename
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function createStreamedResponse(callable $callback, string $contentType, string $filename): StreamedResponse
    {
        return Response::streamDownload($callback, $filename, [
            HttpHeader::CONTENT_TYPE->getName() => $contentType,
        ]);
    }
}
