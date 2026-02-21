<?php

namespace SineMacula\ApiToolkit\Support\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\Repositories\Contracts\RepositoryInterface;

/**
 * Auto-discovers repository alias mappings from configured roots.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
class RepositoryMapAutoDiscoverer
{
    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(private readonly Application $app) {}

    /**
     * Discover repository alias mappings.
     *
     * @param  array<string, string>  $existing_map
     * @return array<string, class-string>
     */
    public function discover(array $existing_map = []): array
    {
        if (!config('api-toolkit.repositories.auto_discovery.enabled', false)) {
            return [];
        }

        if (!$this->isCacheEnabled()) {
            return $this->normalizeDiscoveredMap($this->discoverUncached($existing_map));
        }

        $cached = Cache::remember(
            $this->resolveCacheKey($existing_map),
            now()->addSeconds($this->resolveCacheTtl()),
            fn (): array => $this->discoverUncached($existing_map),
        );

        return $this->normalizeDiscoveredMap($cached);
    }

    /**
     * Discover repository aliases without cache.
     *
     * @param  array<string, string>  $existing_map
     * @return array<string, string>
     */
    private function discoverUncached(array $existing_map): array
    {
        $paths   = new DiscoveryPathResolver($this->app);
        $scanner = new ClassScanner;
        $roots   = $this->resolveRoots($paths);

        if ($roots === []) {
            return [];
        }

        $map = [];

        foreach ($scanner->discover($roots) as $class => $root_namespace) {
            if (!$this->isDiscoverableRepository($class)) {
                continue;
            }

            $alias = $this->resolveAliasForRepository($class, $root_namespace);

            if ($alias === null || isset($existing_map[$alias]) || isset($map[$alias])) {
                continue;
            }

            $map[$alias] = $class;
        }

        return $map;
    }

    /**
     * Resolve root definitions used for repository scanning.
     *
     * @param  \SineMacula\ApiToolkit\Support\Discovery\DiscoveryPathResolver  $paths
     * @return array<int, array{path: string, namespace: string}>
     */
    private function resolveRoots(DiscoveryPathResolver $paths): array
    {
        $roots = $paths->resolveConfiguredRoots((array) config('api-toolkit.repositories.auto_discovery.roots', []));

        if (config('api-toolkit.repositories.auto_discovery.include_standard_root', true)) {
            $standard = $paths->resolveStandardRoot('Repositories', 'Repositories\\');

            if ($standard !== null) {
                $roots[] = $standard;
            }
        }

        return array_merge(
            $roots,
            $paths->resolveModuleRoots('Repositories', 'Repositories\\'),
        );
    }

    /**
     * Determine if a class is a discoverable repository.
     *
     * @param  class-string  $class
     * @return bool
     */
    private function isDiscoverableRepository(string $class): bool
    {
        if (!is_subclass_of($class, RepositoryInterface::class)) {
            return false;
        }

        if (!str_ends_with(class_basename($class), 'Repository')) {
            return false;
        }

        return !(new \ReflectionClass($class))->isAbstract();
    }

    /**
     * Resolve the alias to expose for the given repository class.
     *
     * @param  class-string  $class
     * @param  string  $root_namespace
     * @return string|null
     */
    private function resolveAliasForRepository(string $class, string $root_namespace): ?string
    {
        return $this->resolveAliasFromOverrides($class)
            ?? $this->resolveAliasFromConstant($class)
            ?? $this->resolveAliasFromStaticMethod($class)
            ?? $this->buildAliasFromClassPath($class, $root_namespace);
    }

    /**
     * Build repository alias from class path below the root namespace.
     *
     * @param  class-string  $class
     * @param  string  $root_namespace
     * @return string|null
     */
    private function buildAliasFromClassPath(string $class, string $root_namespace): ?string
    {
        $relative = str_starts_with($class, $root_namespace)
            ? substr($class, strlen($root_namespace))
            : class_basename($class);

        if ($relative === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('\\', $relative), static fn (string $segment): bool => $segment !== ''));
        $last     = array_pop($segments);

        if (!is_string($last) || !str_ends_with($last, 'Repository')) {
            return null;
        }

        $parts = [];

        foreach ($segments as $segment) {
            $parts[] = Str::singular(Str::studly($segment));
        }

        $repository_name = Str::replaceLast('Repository', '', $last);
        $parts[]         = Str::pluralStudly($repository_name);

        return Str::camel(implode('', $parts));
    }

    /**
     * Resolve alias from configured class override map.
     *
     * @param  class-string  $class
     * @return string|null
     */
    private function resolveAliasFromOverrides(string $class): ?string
    {
        $overrides = config('api-toolkit.repositories.auto_discovery.alias_overrides', []);

        if (!is_array($overrides)) {
            return null;
        }

        $alias = $overrides[$class] ?? null;

        return is_string($alias) && $alias !== '' ? $alias : null;
    }

    /**
     * Resolve alias from repository class constant.
     *
     * @param  class-string  $class
     * @return string|null
     */
    private function resolveAliasFromConstant(string $class): ?string
    {
        $constant_name = $this->resolveStringConfig('api-toolkit.repositories.auto_discovery.alias_constant', 'REPOSITORY_ALIAS');

        if (!defined($class . '::' . $constant_name)) {
            return null;
        }

        $alias = constant($class . '::' . $constant_name);

        return is_string($alias) && $alias !== '' ? $alias : null;
    }

    /**
     * Resolve alias from static repository alias method.
     *
     * @param  class-string  $class
     * @return string|null
     */
    private function resolveAliasFromStaticMethod(string $class): ?string
    {
        $method_name = $this->resolveStringConfig('api-toolkit.repositories.auto_discovery.alias_method', 'repositoryAlias');

        if (!method_exists($class, $method_name)) {
            return null;
        }

        $method = new \ReflectionMethod($class, $method_name);

        if (!$method->isPublic() || !$method->isStatic()) {
            return null;
        }

        $alias = $method->invoke(null);

        return is_string($alias) && $alias !== '' ? $alias : null;
    }

    /**
     * Normalize a discovered map payload into alias => class-string mapping.
     *
     * @param  mixed  $discovered
     * @return array<string, class-string>
     */
    private function normalizeDiscoveredMap(mixed $discovered): array
    {
        if (!is_array($discovered)) {
            return [];
        }

        $map = [];

        foreach ($discovered as $alias => $class) {
            if (!is_string($alias) || !is_string($class) || $alias === '' || !class_exists($class)) {
                continue;
            }

            $map[$alias] = $class;
        }

        return $map;
    }

    /**
     * Determine if auto-discovery map caching is enabled.
     *
     * @return bool
     */
    private function isCacheEnabled(): bool
    {
        return (bool) Config::get('api-toolkit.auto_discovery.cache.enabled', true);
    }

    /**
     * Resolve map cache TTL in seconds.
     *
     * @return int
     */
    private function resolveCacheTtl(): int
    {
        $ttl = Config::get('api-toolkit.auto_discovery.cache.ttl', 300);

        if (!is_numeric($ttl)) {
            return 300;
        }

        return max(1, (int) $ttl);
    }

    /**
     * Resolve cache key for discovered repositories.
     *
     * @param  array<string, string>  $existing_map
     * @return string
     */
    private function resolveCacheKey(array $existing_map): string
    {
        $fingerprint = sha1((string) json_encode([
            'namespace'             => $this->app->getNamespace(),
            'include_standard_root' => config('api-toolkit.repositories.auto_discovery.include_standard_root', true),
            'roots'                 => config('api-toolkit.repositories.auto_discovery.roots', []),
            'modules'               => config('api-toolkit.auto_discovery.modules', []),
            'existing_aliases'      => array_keys($existing_map),
            'overrides'             => config('api-toolkit.repositories.auto_discovery.alias_overrides', []),
            'alias'                 => [
                'constant' => config('api-toolkit.repositories.auto_discovery.alias_constant', 'REPOSITORY_ALIAS'),
                'method'   => config('api-toolkit.repositories.auto_discovery.alias_method', 'repositoryAlias'),
            ],
        ]));

        return CacheKeys::AUTO_DISCOVERED_REPOSITORY_MAP->resolveKey([$fingerprint]);
    }

    /**
     * Resolve string config value with a safe fallback.
     *
     * @param  string  $key
     * @param  string  $default
     * @return string
     */
    private function resolveStringConfig(string $key, string $default): string
    {
        $value = Config::get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
