<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

/**
 * A stream wrapper over an empty filesystem that records every attempt to open
 * a directory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DirectoryOpenRecorder
{
    /** @var string The scheme the recorder is registered under. */
    public const string SCHEME = 'directory-open-recorder';

    /** @var list<string> The paths a directory open was attempted on. */
    public static array $opened = [];

    /** @var resource|null The stream context assigned to every wrapper instance. */
    public mixed $context = null;

    /**
     * Register the recorder with no attempts recorded.
     *
     * @return void
     */
    public static function register(): void
    {
        self::$opened = [];

        stream_wrapper_register(self::SCHEME, self::class);
    }

    /**
     * Unregister the recorder.
     *
     * @return void
     */
    public static function unregister(): void
    {
        stream_wrapper_unregister(self::SCHEME);
    }

    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps,Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps

    /**
     * Report that the path does not exist.
     *
     * @SuppressWarnings("php:S100")
     *
     * @return false
     */
    public function url_stat(): false
    {
        return false;
    }

    /**
     * Record the attempt to open the directory, and fail it.
     *
     * @SuppressWarnings("php:S100")
     *
     * @param  string  $path
     * @return false
     */
    public function dir_opendir(string $path): false
    {
        self::$opened[] = $path;

        return false;
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps,Squiz.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}
