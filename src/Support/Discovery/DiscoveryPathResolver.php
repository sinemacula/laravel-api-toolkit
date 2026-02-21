<?php

namespace SineMacula\ApiToolkit\Support\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

/**
 * Resolves filesystem and namespace roots for map autodiscovery.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
class DiscoveryPathResolver
{
    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(private readonly Application $app) {}

    /**
     * Resolve configured discovery roots.
     *
     * @param  array<int, mixed>  $roots
     * @return array<int, array{path: string, namespace: string}>
     */
    public function resolveConfiguredRoots(array $roots): array
    {
        $resolved = [];

        foreach ($roots as $root) {
            if (!is_array($root)) {
                continue;
            }

            $path      = $root['path']      ?? null;
            $namespace = $root['namespace'] ?? null;

            if (!is_string($path) || !is_string($namespace) || $path === '' || $namespace === '') {
                continue;
            }

            $normalized = $this->normalizeRoot($path, $namespace);

            if ($normalized !== null) {
                $resolved[] = $normalized;
            }
        }

        return $resolved;
    }

    /**
     * Resolve the conventional Laravel root for a given subdirectory.
     *
     * @param  string  $path_suffix
     * @param  string  $namespace_suffix
     * @return array{path: string, namespace: string}|null
     */
    public function resolveStandardRoot(string $path_suffix, string $namespace_suffix): ?array
    {
        if (!function_exists('app_path')) {
            return null;
        }

        $path      = app_path($path_suffix);
        $namespace = $this->app->getNamespace() . $namespace_suffix;

        return $this->normalizeRoot($path, $namespace);
    }

    /**
     * Resolve module roots for a given module-relative subdirectory.
     *
     * @param  string  $sub_directory
     * @param  string  $namespace_suffix
     * @return array<int, array{path: string, namespace: string}>
     */
    public function resolveModuleRoots(string $sub_directory, string $namespace_suffix): array
    {
        if (!config('api-toolkit.auto_discovery.modules.enabled', true)) {
            return [];
        }

        $module_namespace_prefixes = $this->resolveModuleNamespacePrefixes();
        $module_base_paths         = $this->resolveModuleBasePaths();

        if ($module_namespace_prefixes === [] || $module_base_paths === []) {
            return [];
        }

        $roots = [];

        foreach ($module_base_paths as $modules_path) {
            foreach ($this->listModuleDirectories($modules_path) as $module_name => $module_path) {
                foreach ($module_namespace_prefixes as $prefix) {
                    $namespace  = $prefix . Str::studly($module_name) . '\\' . $namespace_suffix;
                    $path       = $module_path . DIRECTORY_SEPARATOR . $sub_directory;
                    $normalized = $this->normalizeRoot($path, $namespace);

                    if ($normalized !== null) {
                        $roots[] = $normalized;
                    }
                }
            }
        }

        return $roots;
    }

    /**
     * Resolve module namespace prefixes used for class generation.
     *
     * @return array<int, string>
     */
    private function resolveModuleNamespacePrefixes(): array
    {
        $prefixes = config('api-toolkit.auto_discovery.modules.namespace_prefixes', []);

        if (!is_array($prefixes)) {
            return [];
        }

        $resolved = [];

        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                $resolved[] = trim($prefix, '\\') . '\\';
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Resolve module base paths from config and known helper conventions.
     *
     * @return array<int, string>
     */
    private function resolveModuleBasePaths(): array
    {
        $paths = config('api-toolkit.auto_discovery.modules.paths', []);

        if (!is_array($paths)) {
            $paths = [];
        }

        if (function_exists('module_path')) {
            $paths[] = module_path();
        }

        if (function_exists('base_path')) {
            $paths[] = base_path('modules');
        }

        $resolved = [];

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $real_path = realpath($path);

            if ($real_path !== false && is_dir($real_path)) {
                $resolved[] = $real_path;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * List module directories keyed by module folder name.
     *
     * @param  string  $modules_path
     * @return array<string, string>
     */
    private function listModuleDirectories(string $modules_path): array
    {
        $directories = [];

        foreach (scandir($modules_path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $modules_path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $directories[$entry] = $path;
            }
        }

        return $directories;
    }

    /**
     * Normalize a discovery root and validate that it exists.
     *
     * @param  string  $path
     * @param  string  $namespace
     * @return array{path: string, namespace: string}|null
     */
    private function normalizeRoot(string $path, string $namespace): ?array
    {
        $real_path = realpath($path);

        if ($real_path === false || !is_dir($real_path)) {
            return null;
        }

        return [
            'path'      => $real_path,
            'namespace' => trim($namespace, '\\') . '\\',
        ];
    }
}
