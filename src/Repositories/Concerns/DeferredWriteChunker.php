<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Splits a target's deferred rows into INSERT chunks that each list the columns
 * their rows carry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DeferredWriteChunker
{
    /**
     * Create a new deferred write chunker instance.
     *
     * @param  int  $chunkSize
     * @return void
     */
    public function __construct(

        /** The maximum number of rows per chunk, at least one. */
        private int $chunkSize,
    ) {}

    /**
     * Split rows into consecutive runs of one column set, then chunk each run,
     * keeping the insertion order.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<list<array<string, mixed>>>
     */
    public function chunk(array $records): array
    {
        $size   = max(1, $this->chunkSize);
        $chunks = [];
        $run    = [];

        foreach ($records as $record) {

            if ($run !== [] && $this->columns($record) !== $this->columns($run[0])) {
                array_push($chunks, ...array_chunk($run, $size));
                $run = [];
            }

            $run[] = $record;
        }

        array_push($chunks, ...array_chunk($run, $size));

        return $chunks;
    }

    /**
     * Get the sorted column names of a record.
     *
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function columns(array $record): array
    {
        $columns = array_keys($record);

        sort($columns);

        return $columns;
    }
}
