<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException;

/**
 * The generation every toolkit metadata key is namespaced by.
 *
 * The generation lives in the shared cache store, so replacing it makes every
 * metadata entry written under the old one unreachable in every process at
 * once, including processes that never registered those entries and so could
 * never forget them.
 *
 * A generation is a random token rather than a counter. A counter that is lost
 * from the store restarts at a value it has held before and serves the entries
 * written under it back, however stale; a token is never minted twice, so a
 * lost generation costs a cold cache and nothing more. Replacing a token is one
 * plain write, which every store performs atomically.
 *
 * The generation is read once and memoised for the life of the process, or
 * until {@see forget()} is called at a lifecycle boundary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataGeneration
{
    /** @var string|null The generation this process resolved, if any. */
    private ?string $current = null;

    /**
     * Return the current generation, minting one when the store holds none.
     *
     * A racing process may mint a different token first; whichever write lands
     * is the one read back, and a process left holding the losing token only
     * writes entries nothing else will read.
     *
     * @return string
     */
    public function current(): string
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $key    = CacheKeys::METADATA_GENERATION->resolveKey();
        $stored = Cache::get($key);

        if (!$this->isToken($stored)) {

            $minted = $this->mint();

            // Adding would leave an unusable value in place for good.
            $stored === null ? Cache::add($key, $minted) : Cache::forever($key, $minted);

            $stored = Cache::get($key);
            $stored = $this->isToken($stored) ? $stored : $minted;
        }

        return $this->current = $stored;
    }

    /**
     * Replace the generation in the store, orphaning every metadata entry
     * written under the previous one.
     *
     * A rejected write leaves the previous generation, and every entry written
     * under it, in place, so it is surfaced rather than reported as success.
     *
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException
     */
    public function advance(): string
    {
        $minted = $this->mint();

        if (!Cache::forever(CacheKeys::METADATA_GENERATION->resolveKey(), $minted)) {
            throw new MetadataInvalidationException('The cache store rejected the new metadata generation, so the cached metadata was not invalidated.');
        }

        return $this->current = $minted;
    }

    /**
     * Drop the memoised generation so the next read goes back to the store.
     *
     * @return void
     */
    public function forget(): void
    {
        $this->current = null;
    }

    /**
     * Mint a generation token that has never been used before.
     *
     * @return string
     */
    private function mint(): string
    {
        return Str::random(16);
    }

    /**
     * Determine whether the given stored value is a usable generation token.
     *
     * @param  mixed  $value
     * @return bool
     *
     * @phpstan-assert-if-true non-empty-string $value
     */
    private function isToken(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
