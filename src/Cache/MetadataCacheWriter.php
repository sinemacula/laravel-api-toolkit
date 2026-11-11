<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;

/**
 * The single path for reading and writing toolkit metadata.
 *
 * Keys are namespaced by the metadata generation, which is the only thing that
 * retires a stored entry besides its expiry. Store only arrays and scalars:
 * Laravel's serializable_classes setting can stop objects reading back.
 *
 * A throwing store is bypassed for the retry window, so an outage costs
 * performance, not the request. The window is process-static so it outlives
 * Octane's per-request application.
 *
 * @managed-static
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataCacheWriter
{
    /** @var string Config key for the seconds the store is bypassed after a failure */
    public const string RETRY_AFTER_KEY = 'api-toolkit.cache.retry_after';

    /** @var int */
    public const int DEFAULT_RETRY_AFTER = 30;

    /** @var int|null Unix time the store may next be tried after a failure */
    private static ?int $bypassedUntil = null;

    /** @var int|null Unix time a rejected write may next be reported */
    private static ?int $rejectionReportedUntil = null;

    /**
     * Create a new metadata cache writer instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataGeneration  $generation
     * @return void
     */
    public function __construct(

        /** The generation every metadata key is namespaced by */
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
     * Store a metadata value forever under the current generation.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function storeMetadataForever(string $key, mixed $value): void
    {
        $this->persist($key, $this->attempt('generation', $key, fn (): string => $this->storageKey($key)), $value, null);
    }

    /**
     * Read a metadata value stored under the current generation.
     *
     * A stored value the decoder rejects by returning null is a miss.
     *
     * @template TMissing
     *
     * @param  string  $key
     * @param  TMissing  $missing
     * @param  (callable(mixed): mixed)|null  $decode
     * @return mixed
     */
    public function readMetadata(string $key, mixed $missing = null, ?callable $decode = null): mixed
    {
        $storageKey = $this->attempt('generation', $key, fn (): string => $this->storageKey($key));
        $stored     = $storageKey === null ? null : $this->attempt('get', $key, static fn (): mixed => Cache::memo()->get($storageKey)); // @phpstan-ignore method.notFound

        if ($stored !== null && $decode !== null) {
            $stored = $decode($stored);
        }

        return $stored ?? value($missing);
    }

    /**
     * Store a metadata value under the current generation with a time-to-live.
     *
     * A time-to-live of zero or less stores nothing.
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
     * End the bypass and rejection-report windows.
     *
     * A lifecycle flush leaves the windows alone; this is for test isolation.
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
     * The callback runs outside the store guards, so its exceptions propagate.
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

        $this->persist($key, $storageKey, $value, $ttl);

        return $value;
    }

    /**
     * Write the value under the storage key, reporting a rejected write.
     *
     * @param  string  $key
     * @param  string|null  $storageKey
     * @param  mixed  $value
     * @param  int|null  $ttl
     * @return void
     */
    private function persist(string $key, ?string $storageKey, mixed $value, ?int $ttl): void
    {
        if ($storageKey === null || $this->attempt('write', $key, static fn (): bool => self::write($storageKey, $value, $ttl)) !== false) {
            return;
        }

        self::reportRejectedWrite($key);
    }

    /**
     * Run a store operation, or return null when bypassed or it throws.
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
     * The generation is forgotten so it is re-read once the window ends.
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
     * The null store rejects every write by design, so it is never reported.
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
     * Return the name of the default cache store.
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
     * Write the value, forever when no time-to-live is given.
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
