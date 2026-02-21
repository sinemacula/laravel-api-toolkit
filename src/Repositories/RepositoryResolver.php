<?php

namespace SineMacula\ApiToolkit\Repositories;

use SineMacula\ApiToolkit\Exceptions\RepositoryResolutionException;
use SineMacula\Repositories\Contracts\RepositoryInterface;

/**
 * Repository resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class RepositoryResolver
{
    /** @var array<string, string> */
    private static array $map;

    /** @var array<string, \SineMacula\Repositories\Contracts\RepositoryInterface> */
    private static array $repositories = [];

    /**
     * Return the map of the repositories.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return self::$map ??= self::resolveMap();
    }

    /**
     * Resolve a repository instance dynamically.
     *
     * @param  string  $name
     * @return \SineMacula\Repositories\Contracts\RepositoryInterface
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\RepositoryResolutionException
     */
    public static function get(string $name): RepositoryInterface
    {
        if (!self::has($name)) {
            throw new RepositoryResolutionException("Repository '{$name}' not found in registry.");
        }

        $repository_class = self::map()[$name];
        $repository       = resolve($repository_class);

        if (!$repository instanceof RepositoryInterface) {
            throw new RepositoryResolutionException("Repository '{$name}' does not resolve to a valid repository instance.");
        }

        if (!self::shouldCacheResolvedInstances()) {
            return $repository;
        }

        return self::$repositories[$name] ??= $repository;
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

        self::$map = self::resolveMap();

        unset(self::$repositories[$key]);
    }

    /**
     * Flush all cached repository instances.
     *
     * This method clears the internal static cache of repository instances,
     * forcing the resolver to create new instances on the next get() call.
     * This is primarily useful for testing to prevent repository instances
     * from leaking across test cases, which can cause issues with stale
     * model instances that have cleared global scopes.
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$repositories = [];
    }

    /**
     * Determine whether resolved repository instances should be cached.
     *
     * @return bool
     */
    public static function shouldCacheResolvedInstances(): bool
    {
        if (!config('api-toolkit.repositories.cache_resolved_instances', true)) {
            return false;
        }

        return !self::isRunningUnderOctane();
    }

    /**
     * Determine whether the current runtime is Laravel Octane.
     *
     * @return bool
     */
    private static function isRunningUnderOctane(): bool
    {
        if (!function_exists('app')) {
            return false;
        }

        try {
            return app()->bound('octane');
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Resolve and normalize repository map configuration values.
     *
     * @return array<string, string>
     */
    private static function resolveMap(): array
    {
        $map = config('api-toolkit.repositories.repository_map', []);

        if (!is_array($map)) {
            return [];
        }

        $resolved = [];

        foreach ($map as $key => $class) {
            if (is_string($key) && is_string($class) && $class !== '') {
                $resolved[$key] = $class;
            }
        }

        return $resolved;
    }
}
