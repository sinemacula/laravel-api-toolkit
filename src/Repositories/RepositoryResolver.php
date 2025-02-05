<?php

namespace SineMacula\ApiToolkit\Repositories;

use RuntimeException;
use SineMacula\Repositories\Contracts\RepositoryInterface;

/**
 * Repository resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class RepositoryResolver
{
    /** @var array<string, class-string> */
    private static array $map;

    /** @var array<string, \SineMacula\Repositories\Contracts\RepositoryInterface> */
    private static array $repositories = [];

    /**
     * Return the map of the repositories.
     *
     * @return array<string, class-string>
     */
    public static function map(): array
    {
        return self::$map ??= config('api-toolkit.repositories.repository_map', []);
    }

    /**
     * Resolve a repository instance dynamically.
     *
     * @param  string  $name
     * @return \SineMacula\Repositories\Contracts\RepositoryInterface
     *
     * @throws \RuntimeException
     */
    public static function get(string $name): RepositoryInterface
    {
        if (!self::has($name)) {
            throw new RuntimeException("Repository '{$name}' not found in registry.");
        }

        return self::$repositories[$name] ??= resolve(self::map()[$name]);
    }

    /**
     * Check if a repository exists.
     *
     * @param  string  $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return array_key_exists($name, self::map());
    }

    /**
     * Register a new repository dynamically.
     *
     * @param  string  $key
     * @param  string  $class
     * @return void
     */
    public static function register(string $key, string $class): void
    {
        config()->set('api-toolkit.repositories.repository_map.' . $key, $class);

        self::$map = config('api-toolkit.repositories.repository_map', []);

        unset(self::$repositories[$key]);
    }
}
