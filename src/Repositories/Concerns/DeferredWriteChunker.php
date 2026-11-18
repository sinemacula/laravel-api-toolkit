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
    /** @var int The most bound values one INSERT carries, SQLite's default limit and below MySQL's and PostgreSQL's */
    private const int MAX_BINDINGS = 32766;

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
        $chunks = [];
        $run    = [];

        foreach ($records as $record) {

            if ($run !== [] && $this->columns($record) !== $this->columns($run[0])) {
                array_push($chunks, ...$this->chunkRun($run));
                $run = [];
            }

            $run[] = $record;
        }

        array_push($chunks, ...$this->chunkRun($run));

        return $chunks;
    }

    /**
     * Chunk a run of one column set by the chunk size, cut further so no chunk
     * binds more values than the database accepts.
     *
     * @param  list<array<string, mixed>>  $run
     * @return list<list<array<string, mixed>>>
     */
    private function chunkRun(array $run): array
    {
        $columns = max(1, count($run[0] ?? []));

        return array_chunk($run, max(1, min($this->chunkSize, intdiv(self::MAX_BINDINGS, $columns))));
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
