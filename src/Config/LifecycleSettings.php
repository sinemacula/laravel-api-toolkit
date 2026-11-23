<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Config;

use SineMacula\ApiToolkit\Enums\FlushStrategy;

/**
 * The typed settings of caching, deferred writes, lifecycle events and the
 * notification audit log.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class LifecycleSettings
{
    /**
     * Return the prefix of every toolkit cache key.
     *
     * @return string
     */
    public static function cachePrefix(): string
    {
        return ToolkitConfig::readString('cache.prefix');
    }

    /**
     * Return the seconds a cached schema metadata entry lives.
     *
     * @return int
     */
    public static function metadataTtl(): int
    {
        return ToolkitConfig::readInteger('cache.metadata_ttl', 1);
    }

    /**
     * Return the seconds a failing cache store is bypassed for.
     *
     * @return int
     */
    public static function cacheRetryAfter(): int
    {
        return ToolkitConfig::readInteger('cache.retry_after', 0);
    }

    /**
     * Return the most records one deferred INSERT carries.
     *
     * @return int
     */
    public static function chunkSize(): int
    {
        return ToolkitConfig::readInteger('deferred_writes.chunk_size', 1);
    }

    /**
     * Return the buffered record count that triggers a flush.
     *
     * @return int
     */
    public static function poolLimit(): int
    {
        return ToolkitConfig::readInteger('deferred_writes.pool_limit', 1);
    }

    /**
     * Return what a failed deferred chunk insert does.
     *
     * @return \SineMacula\ApiToolkit\Enums\FlushStrategy
     */
    public static function flushStrategy(): FlushStrategy
    {
        return ToolkitConfig::readEnum('deferred_writes.on_failure', FlushStrategy::class);
    }

    /**
     * Determine whether each table's deferred inserts are all-or-nothing.
     *
     * @return bool
     */
    public static function isFlushTransactional(): bool
    {
        return ToolkitConfig::readBoolean('deferred_writes.transactional');
    }

    /**
     * Determine whether a throwing flush failure is re-thrown at the boundary.
     *
     * @return bool
     */
    public static function shouldRethrowAtBoundary(): bool
    {
        return ToolkitConfig::readBoolean('deferred_writes.rethrow_at_boundary');
    }

    /**
     * Determine whether a boundary flush clears the query cache of its tables.
     *
     * @return bool
     */
    public static function shouldInvalidateQueryCache(): bool
    {
        return ToolkitConfig::readBoolean('deferred_writes.invalidate_query_cache');
    }

    /**
     * Determine whether a migration run invalidates the cached schema metadata.
     *
     * @return bool
     */
    public static function shouldInvalidateOnMigrations(): bool
    {
        return ToolkitConfig::readBoolean('lifecycle.migrations');
    }

    /**
     * Determine whether notification events are written to the audit log.
     *
     * @return bool
     */
    public static function shouldLogNotifications(): bool
    {
        return ToolkitConfig::readBoolean('notifications.enable_logging');
    }

    /**
     * Return the notification classes left out of the audit log.
     *
     * @return list<string>
     */
    public static function excludedNotifications(): array
    {
        return ToolkitConfig::readList('notifications.excluded_classes');
    }

    /**
     * Return the audit log channel, or null for the default channel.
     *
     * @return string|null
     */
    public static function notificationLogChannel(): ?string
    {
        return ToolkitConfig::readNullableString('notifications.log_channel');
    }
}
