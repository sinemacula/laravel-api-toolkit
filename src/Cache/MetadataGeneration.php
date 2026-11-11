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
 * A random token, not a counter: a lost counter would restart at an old value
 * and serve stale entries back, while a lost token only costs a cold cache.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataGeneration
{
    /** @var string|null The generation this process resolved, if any */
    private ?string $current = null;

    /**
     * Return the current generation, minting one when the store holds none.
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
     * Replace the generation, orphaning every entry written under the old one.
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
