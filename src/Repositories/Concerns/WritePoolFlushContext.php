<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use SineMacula\ApiToolkit\Enums\FlushStrategy;

/**
 * Immutable carrier for the parameters required to flush a single
 * table's chunk set within a WritePool flush operation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WritePoolFlushContext
{
    /**
     * Create a new write pool flush context instance.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  string  $table
     * @param  list<list<array<string, mixed>>>  $chunks
     * @param  list<string>  $tables
     * @param  int  $tableIndex
     * @param  int|null  $chunkIndex
     * @return void
     */
    public function __construct(
        private readonly FlushStrategy $strategy,
        private readonly string $table,
        private readonly array $chunks,
        private readonly array $tables,
        private readonly int $tableIndex,
        private readonly ?int $chunkIndex = null,
    ) {}

    /**
     * Get the failure handling strategy for the flush operation.
     *
     * @return \SineMacula\ApiToolkit\Enums\FlushStrategy
     */
    public function strategy(): FlushStrategy
    {
        return $this->strategy;
    }

    /**
     * Get the name of the table being flushed.
     *
     * @return string
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Get the chunked records for the table being flushed.
     *
     * @return list<list<array<string, mixed>>>
     */
    public function chunks(): array
    {
        return $this->chunks;
    }

    /**
     * Get the ordered list of all table names in the flush.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * Get the index of the current table within the table list.
     *
     * @return int
     */
    public function tableIndex(): int
    {
        return $this->tableIndex;
    }

    /**
     * Get the index of the chunk currently being processed.
     *
     * @return int|null
     */
    public function chunkIndex(): ?int
    {
        return $this->chunkIndex;
    }

    /**
     * Return a new context with the chunk index set to the given value.
     *
     * @param  int  $chunkIndex
     * @return self
     */
    public function withChunkIndex(int $chunkIndex): self
    {
        return new self(
            $this->strategy,
            $this->table,
            $this->chunks,
            $this->tables,
            $this->tableIndex,
            $chunkIndex,
        );
    }
}
