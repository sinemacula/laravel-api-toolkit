<?php

namespace SineMacula\ApiToolkit\Support\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Auto-discovers model-to-resource mappings from configured roots.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
class ResourceMapAutoDiscoverer
{
    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(private readonly Application $app) {}

    /**
     * Discover model-to-resource mappings.
     *
     * @return array<class-string, class-string>
     */
    public function discover(): array
    {
        if (!config('api-toolkit.resources.auto_discovery.enabled', false)) {
            return [];
        }

        if (!$this->isCacheEnabled()) {
            return $this->discoverUncached();
        }

        $cached = Cache::remember(
            $this->resolveCacheKey(),
            now()->addSeconds($this->resolveCacheTtl()),
            fn (): array => $this->discoverUncached(),
        );

        return $this->normalizeDiscoveredMap($cached);
    }

    /**
     * Discover model-to-resource mappings without cache.
     *
     * @return array<class-string, class-string>
     */
    private function discoverUncached(): array
    {
        $paths   = new DiscoveryPathResolver($this->app);
        $scanner = new ClassScanner;
        $roots   = $this->resolveRoots($paths);

        if ($roots === []) {
            return [];
        }

        $map = [];

        foreach ($scanner->discover($roots) as $class => $root_namespace) {
            if (!is_subclass_of($class, Model::class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $resource = $this->resolveResourceClassFromModel($class);

            if ($resource !== null) {
                $map[$class] = $resource;
            }
        }

        return $map;
    }

    /**
     * Resolve root definitions used for model scanning.
     *
     * @param  \SineMacula\ApiToolkit\Support\Discovery\DiscoveryPathResolver  $paths
     * @return array<int, array{path: string, namespace: string}>
     */
    private function resolveRoots(DiscoveryPathResolver $paths): array
    {
        $roots = $paths->resolveConfiguredRoots((array) config('api-toolkit.resources.auto_discovery.roots', []));

        if (config('api-toolkit.resources.auto_discovery.include_standard_root', true)) {
            $standard = $paths->resolveStandardRoot('Models', 'Models\\');

            if ($standard !== null) {
                $roots[] = $standard;
            }
        }

        return array_merge(
            $roots,
            $paths->resolveModuleRoots('Models', 'Models\\'),
        );
    }

    /**
     * Resolve the resource class name from a model class.
     *
     * @param  class-string  $model_class
     * @return class-string|null
     */
    private function resolveResourceClassFromModel(string $model_class): ?string
    {
        $model_segment    = $this->resolveStringConfig('api-toolkit.resources.auto_discovery.model_namespace_segment', '\Models\\');
        $resource_segment = $this->resolveStringConfig('api-toolkit.resources.auto_discovery.resource_namespace_segment', '\Http\Resources\\');
        $resource_suffix  = $this->resolveStringConfig('api-toolkit.resources.auto_discovery.resource_suffix', 'Resource');

        $resource_class = null;

        if (str_contains($model_class, $model_segment)) {
            $candidate = str_replace($model_segment, $resource_segment, $model_class) . $resource_suffix;

            if (class_exists($candidate) && in_array(ApiResourceInterface::class, class_implements($candidate) ?: [], true)) {
                $resource_class = $candidate;
            }
        }

        return $resource_class;
    }

    /**
     * Normalize a discovered map payload into class-string mapping.
     *
     * @param  mixed  $discovered
     * @return array<class-string, class-string>
     */
    private function normalizeDiscoveredMap(mixed $discovered): array
    {
        if (!is_array($discovered)) {
            return [];
        }

        $map = [];

        foreach ($discovered as $model_class => $resource_class) {
            if (!is_string($model_class) || !is_string($resource_class)) {
                continue;
            }

            if (!class_exists($model_class) || !class_exists($resource_class)) {
                continue;
            }

            $map[$model_class] = $resource_class;
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
     * Resolve cache key for discovered resources.
     *
     * @return string
     */
    private function resolveCacheKey(): string
    {
        $fingerprint = sha1((string) json_encode([
            'namespace'             => $this->app->getNamespace(),
            'include_standard_root' => config('api-toolkit.resources.auto_discovery.include_standard_root', true),
            'roots'                 => config('api-toolkit.resources.auto_discovery.roots', []),
            'modules'               => config('api-toolkit.auto_discovery.modules', []),
            'segments'              => [
                'model'    => config('api-toolkit.resources.auto_discovery.model_namespace_segment', '\Models\\'),
                'resource' => config('api-toolkit.resources.auto_discovery.resource_namespace_segment', '\Http\Resources\\'),
                'suffix'   => config('api-toolkit.resources.auto_discovery.resource_suffix', 'Resource'),
            ],
        ]));

        return CacheKeys::AUTO_DISCOVERED_RESOURCE_MAP->resolveKey([$fingerprint]);
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
