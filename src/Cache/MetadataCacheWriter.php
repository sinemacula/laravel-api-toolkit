<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;

/**
 * The single sanctioned path for reading and writing toolkit metadata.
 *
 * Every key is stored under the current metadata generation, so replacing the
 * generation retires every entry at once in every process sharing the store. A
 * read or write that bypassed this writer would miss the generation and serve
 * an entry that an invalidation was meant to retire.
 *
 * Entries live in the shared store and outlast any one request, job, or worker,
 * so every process reading the same schema shares them. A lifecycle boundary
 * resets in-process state only, so a stored entry is retired by replacing the
 * generation or by the expiry it was written with, and by nothing else. A
 * caller must therefore not store an answer that can go stale without a schema
 * change unless it bounds that answer with an expiry, and never one such as the
 * empty column listing of a table that has not been created yet.
 *
 * The store is optional. When a store operation throws, the failure is reported
 * and the store is bypassed for the configured retry window: reads miss and
 * values are computed without being stored, so an outage costs performance
 * rather than the request. A write the store rejects is reported at most once a
 * window but bypasses nothing, since a store that rejects writes may still
 * serve its warm entries. Only store operations are guarded; an exception from
 * a caller's callback is the caller's own and propagates.
 *
 * The windows are held for the process, not the application instance, so they
 * outlast the per-request application Octane serves each request from as well
 * as the lifecycle flushes; a long-lived worker therefore retries the store and
 * reports once a window rather than on every request. Under PHP-FPM no state
 * outlives a request, so there the windows last one request.
 *
 * @managed-static
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataCacheWriter
{
    /** @var string The config key holding the seconds the store is bypassed for after it fails. */
    public const string RETRY_AFTER_KEY = 'api-toolkit.cache.retry_after';

    /** @var int The seconds the store is bypassed for when none are configured. */
    public const int DEFAULT_RETRY_AFTER = 30;

    /** @var int|null The Unix time the store may next be tried, while it is bypassed after a failure. */
    private static ?int $bypassedUntil = null;

    /** @var int|null The Unix time a rejected write may next be reported. */
    private static ?int $rejectionReportedUntil = null;

    /**
     * Create a new metadata cache writer instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataGeneration  $generation
     * @return void
     */
    public function __construct(

        /** The generation every metadata key is namespaced by. */
        private readonly MetadataGeneration $generation,
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
        return $this->remember($key, $callback, null);
    }

    /**
     * Read a metadata value stored under the current generation.
     *
     * The missing value is resolved outside the store guard, so a closure given
     * for it runs on every miss, a failed read included, and an exception it
     * throws is the caller's own.
     *
     * @template TMissing
     *
     * @param  string  $key
     * @param  TMissing  $missing
     * @return mixed
     */
    public function readMetadata(string $key, mixed $missing = null): mixed
    {
        $storageKey = $this->attempt('generation', $key, fn (): string => $this->storageKey($key));
        $stored     = $storageKey === null ? null : $this->attempt('get', $key, static fn (): mixed => Cache::memo()->get($storageKey)); // @phpstan-ignore method.notFound

        return $stored ?? value($missing);
    }

    /**
     * Store a metadata value under the current generation with a time-to-live.
     *
     * Mirrors {@see rememberMetadataForever()} but bounds the entry with an
     * expiry, so a value keyed by unbounded client input cannot accumulate
     * permanently. A time-to-live of zero or less stores nothing, since a store
     * forgets rather than keeps a value written with no lifetime.
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
        if ($ttl <= 0) {
            return $callback();
        }

        return $this->remember($key, $callback, $ttl);
    }

    /**
     * End both windows, so the next operation tries the store and a rejected
     * write is reported again.
     *
     * A lifecycle flush deliberately leaves the windows alone; this is for a
     * boundary that must forget them, such as the start of a test.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$bypassedUntil          = null;
        self::$rejectionReportedUntil = null;
    }

    /**
     * Serve the stored value under the key, or compute and store it.
     *
     * The read and the write are guarded separately and the callback runs
     * outside both, so a store failure never runs the callback twice and a
     * callback failure is never mistaken for a store failure. A null value is a
     * miss, as it is for the framework's remember().
     *
     * @template TValue
     *
     * @param  string  $key
     * @param  callable():TValue  $callback
     * @param  int|null  $ttl
     * @return TValue
     */
    private function remember(string $key, callable $callback, ?int $ttl): mixed
    {
        $storageKey = $this->attempt('generation', $key, fn (): string => $this->storageKey($key));
        $stored     = $storageKey === null ? null : $this->attempt('get', $key, static fn (): mixed => Cache::memo()->get($storageKey)); // @phpstan-ignore method.notFound

        if ($stored !== null) {
            return $stored;
        }

        $value = $callback();

        if ($storageKey !== null && $this->attempt('write', $key, static fn (): bool => self::write($storageKey, $value, $ttl)) === false) {
            self::reportRejectedWrite($key);
        }

        return $value;
    }

    /**
     * Run a store operation, returning null when the store is bypassed or the
     * operation throws.
     *
     * @template TResult
     *
     * @param  string  $operation
     * @param  string  $key
     * @param  callable(): TResult  $callback
     * @return TResult|null
     */
    private function attempt(string $operation, string $key, callable $callback): mixed
    {
        if (self::$bypassedUntil !== null && now()->getTimestamp() < self::$bypassedUntil) {
            return null;
        }

        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->bypass(new MetadataCacheUnavailableException(
                'The cache store failed a metadata operation, so metadata is computed without the cache until it is retried.',
                self::context($operation, $key),
                $exception,
            ));

            return null;
        }
    }

    /**
     * Bypass the store for the retry window and report why.
     *
     * The generation is forgotten, so once the window ends it is read from the
     * store again rather than reused from before the failure.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException  $exception
     * @return void
     */
    private function bypass(MetadataCacheUnavailableException $exception): void
    {
        self::$bypassedUntil = now()->getTimestamp() + self::retryAfter();

        $this->generation->forget();

        FailureReporter::report($exception, 'Metadata cache store unavailable');
    }

    /**
     * Report a write the store rejected, at most once a retry window.
     *
     * The null store rejects every write by design, so its rejections are not
     * reported.
     *
     * @param  string  $key
     * @return void
     */
    private static function reportRejectedWrite(string $key): void
    {
        $now = now()->getTimestamp();

        if (self::driver() === 'null' || (self::$rejectionReportedUntil !== null && $now < self::$rejectionReportedUntil)) {
            return;
        }

        self::$rejectionReportedUntil = $now + self::retryAfter();

        FailureReporter::report(new MetadataCacheUnavailableException(
            'The cache store rejected a metadata write, so the value is served without being stored.',
            self::context('write', $key),
        ), 'Metadata cache write rejected');
    }

    /**
     * Return the report context for an operation on the given metadata key.
     *
     * @param  string  $operation
     * @param  string  $key
     * @return array<string, string|null>
     */
    private static function context(string $operation, string $key): array
    {
        return ['operation' => $operation, 'store' => self::store(), 'key' => $key];
    }

    /**
     * Return the name of the default cache store, which the memoised repository
     * wraps.
     *
     * @return string|null
     */
    private static function store(): ?string
    {
        $store = Config::get('cache.default');

        return is_string($store) ? $store : null;
    }

    /**
     * Return the driver of the default cache store.
     *
     * @return string|null
     */
    private static function driver(): ?string
    {
        $driver = Config::get('cache.stores.' . self::store() . '.driver');

        return is_string($driver) ? $driver : null;
    }

    /**
     * Return the seconds the store is bypassed for after it fails.
     *
     * @return int
     */
    private static function retryAfter(): int
    {
        $seconds = Config::get(self::RETRY_AFTER_KEY, self::DEFAULT_RETRY_AFTER);

        return is_numeric($seconds) && $seconds >= 0 ? (int) $seconds : self::DEFAULT_RETRY_AFTER;
    }

    /**
     * Write the value under the storage key, forever when no time-to-live is
     * given.
     *
     * @param  string  $storageKey
     * @param  mixed  $value
     * @param  int|null  $ttl
     * @return bool
     */
    private static function write(string $storageKey, mixed $value, ?int $ttl): bool
    {
        return $ttl === null
            ? Cache::memo()->forever($storageKey, $value) // @phpstan-ignore method.notFound
            : Cache::memo()->put($storageKey, $value, $ttl); // @phpstan-ignore method.notFound
    }
}
