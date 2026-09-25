<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * The single sanctioned path for reading and writing toolkit metadata.
 *
 * Every key is stored under the current metadata generation, so replacing the
 * generation retires every entry at once in every process sharing the store. A
 * read or write that bypassed this writer would miss the generation and serve
 * an entry that an invalidation was meant to retire.
 *
 * Entries live in the shared store and outlast any one request, job, or worker,
 * so every process reading the same schema shares them. Nothing here forgets an
 * entry: a lifecycle boundary resets in-process state only, and only replacing
 * the generation retires what the store holds. A caller must therefore not
 * store an answer that can go stale without a schema change, such as the empty
 * column listing of a table that has not been created yet.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class MetadataCacheWriter
{
    /**
     * Create a new metadata cache writer instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataGeneration  $generation
     * @return void
     */
    public function __construct(

        /** The generation every metadata key is namespaced by. */
        private MetadataGeneration $generation,
    ) {}

    /**
     * Return the key the store holds the given metadata key under.
     *
     * @param  string  $key
     * @return string
     */
    public function storageKey(string $key): string
    {
        return $key . ':' . $this->generation->current();
    }

    /**
     * Store a forever-memoised metadata value under the current generation.
     *
     * @template TValue
     *
     * @param  string  $key
     * @param  callable():TValue  $callback
     * @return TValue
     */
    public function rememberMetadataForever(string $key, callable $callback): mixed
    {
        return Cache::memo()->rememberForever($this->storageKey($key), static fn () => $callback()); // @phpstan-ignore method.notFound
    }

    /**
     * Read a metadata value stored under the current generation.
     *
     * @template TMissing
     *
     * @param  string  $key
     * @param  TMissing  $missing
     * @return mixed
     */
    public function readMetadata(string $key, mixed $missing = null): mixed
    {
        return Cache::memo()->get($this->storageKey($key), $missing); // @phpstan-ignore method.notFound
    }

    /**
     * Store a metadata value under the current generation with a time-to-live.
     *
     * Mirrors {@see rememberMetadataForever()} but bounds the entry with an
     * expiry, so a value keyed by unbounded client input cannot accumulate
     * permanently.
     *
     * @template TValue
     *
     * @param  string  $key
     * @param  callable():TValue  $callback
     * @param  int  $ttl
     * @return TValue
     */
    public function rememberMetadata(string $key, callable $callback, int $ttl): mixed
    {
        return Cache::memo()->remember($this->storageKey($key), $ttl, static fn () => $callback()); // @phpstan-ignore method.notFound
    }
}
