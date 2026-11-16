<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use SineMacula\ApiToolkit\Enums\FlushStrategy;

/**
 * Immutable parameters for flushing one target's chunks in a WritePool flush.
 *
 * A target is a table on a connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class WritePoolFlushContext
{
    /**
     * Create a new write pool flush context instance.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @param  string  $connection
     * @param  string  $table
     * @param  list<list<array<string, mixed>>>  $chunks
     * @param  list<array{string, string}>  $targets
     * @param  int  $targetIndex
     * @param  int|null  $chunkIndex
     * @return void
     */
    public function __construct(

        /** The failure handling strategy for the flush operation. */
        private FlushStrategy $strategy,

        /** The connection of the target currently being flushed. */
        private string $connection,

        /** The table of the target currently being flushed. */
        private string $table,

        /** The chunks of rows queued for the current target. */
        private array $chunks,

        /** The connection and table pairs participating in the flush. */
        private array $targets,

        /** The index of the current target within the target list. */
        private int $targetIndex,

        /** The index of the current chunk, or null when not chunked. */
        private ?int $chunkIndex = null,
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
     * Get the name of the connection being flushed.
     *
     * @return string
     */
    public function connection(): string
    {
        return $this->connection;
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
     * Get the chunked records for the target being flushed.
     *
     * @return list<list<array<string, mixed>>>
     */
    public function chunks(): array
    {
        return $this->chunks;
    }

    /**
     * Get the ordered connection and table pairs in the flush.
     *
     * @return list<array{string, string}>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * Get the index of the current target within the target list.
     *
     * @return int
     */
    public function targetIndex(): int
    {
        return $this->targetIndex;
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
            $this->connection,
            $this->table,
            $this->chunks,
            $this->targets,
            $this->targetIndex,
            $chunkIndex,
        );
    }
}
