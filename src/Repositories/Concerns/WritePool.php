<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Buffers insert attribute arrays in memory grouped by table
 * and flushes them as chunked bulk INSERT statements.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePool
{
    /** @var array<string, list<array<string, mixed>>> Records buffered by table name. */
    private array $buffer = [];

    /**
     * Create a new write pool instance.
     *
     * @param  int  $chunkSize
     * @param  int  $poolLimit
     * @return void
     */
    public function __construct(
        private readonly int $chunkSize,
        private readonly int $poolLimit,
    ) {}

    /**
     * Add an attribute array to the buffer for the given table.
     *
     * When the total buffered record count reaches or exceeds the
     * configured pool limit, an automatic flush is triggered.
     *
     * @param  string  $table
     * @param  array<string, mixed>  $attributes
     * @return void
     */
    public function add(string $table, array $attributes): void
    {
        $this->buffer[$table][] = $attributes;

        if ($this->count() >= $this->poolLimit) {
            $this->flush();
        }
    }

    /**
     * Flush all buffered records as chunked bulk INSERT statements.
     *
     * Records are grouped by table and split into chunks of at most
     * the configured chunk size. If a chunk fails, the error is logged
     * and remaining chunks are still attempted.
     *
     * @return void
     */
    public function flush(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        foreach ($this->buffer as $table => $records) {

            $chunks = array_chunk($records, $this->chunkSize);

            foreach ($chunks as $chunk) {

                try {
                    DB::table($table)->insert($chunk);
                } catch (\Throwable $e) {

                    Log::error("WritePool flush failed for table [{$table}]", [
                        'table'      => $table,
                        'chunk_size' => count($chunk),
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->buffer = [];
    }

    /**
     * Get the total number of buffered records across all tables.
     *
     * @return int
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this->buffer as $records) {
            $count += count($records);
        }

        return $count;
    }

    /**
     * Determine whether the buffer is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
