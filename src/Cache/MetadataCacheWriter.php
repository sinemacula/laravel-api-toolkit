<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * The single sanctioned path for writing forever-memoised toolkit metadata.
 *
 * Every write registers its key with the MetadataKeyRegistry so a scoped flush
 * can forget exactly the toolkit's own keys. A metadata write that bypasses
 * this writer would not be registered and would survive the flush.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataCacheWriter
{
    /**
     * Create a new metadata cache writer instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry  $registry
     * @return void
     */
    public function __construct(

        /** The registry tracking live toolkit metadata keys for flushing. */
        private readonly MetadataKeyRegistry $registry,
    ) {}

    /**
     * Store a forever-memoised metadata value and register its key.
     *
     * The key is registered before the memo write so it is tracked even when
     * the value is already warm and the callback is never invoked.
     *
     * @template TValue
     *
     * @param  string  $key
     * @param  callable():TValue  $callback
     * @return TValue
     */
    public function rememberMetadataForever(string $key, callable $callback): mixed
    {
        $this->registry->register($key);

        return Cache::memo()->rememberForever($key, static fn () => $callback()); // @phpstan-ignore method.notFound
    }
}
