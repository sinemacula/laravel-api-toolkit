<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Boot-time discovery of attribute-bound API resources.
 *
 * Scans the configured resource paths for classes carrying the ForModel
 * attribute and compiles them into a model to resource map. The compiled map
 * is memoised through the metadata cache under a key fingerprinted by the
 * scanned file list and modification times, so the expensive tokenise and
 * reflect pass runs only when a scanned file actually changes, while adding,
 * editing, or removing a resource rotates the key and triggers a rescan on
 * the next boot. When schema validation is enabled the cache is bypassed
 * entirely so every diagnostic fires on every boot.
 *
 * Files are visited in a deterministic (sorted) order; when two resources
 * claim the same model the first discovered binding wins and a warning is
 * logged, or the scan fails hard when schema validation is enabled - unless
 * the model is explicitly declared in the resource map, which resolves the
 * ambiguity. A binding whose class is not an instantiable API resource, or
 * whose model does not exist, is skipped with a warning.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResourceDiscovery
{
    /**
     * Create a new resource discovery instance.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataCacheWriter  $cacheWriter
     * @return void
     */
    public function __construct(

        /** The writer used to memoise the compiled discovery map. */
        private MetadataCacheWriter $cacheWriter,
    ) {}

    /**
     * Discover the model to resource bindings from the configured paths.
     *
     * @return array<class-string, class-string>
     */
    public function discover(): array
    {
        // The default covers consumers whose published config predates the
        // paths key; an explicit empty array still disables discovery.
        $paths = Config::get('api-toolkit.resources.paths', [app_path('Http/Resources')]);
        $paths = is_array($paths)
            ? array_filter($paths, static fn (mixed $path): bool => is_string($path) && is_dir($path))
            : [];

        $files = $this->files($paths);

        if ($files === []) {
            return [];
        }

        if (Config::get('api-toolkit.resources.validate_schemas') === true) {
            return $this->scan($files);
        }

        return $this->cacheWriter->rememberMetadataForever($this->cacheKey($files), fn (): array => $this->scan($files));
    }

    /**
     * Build the cache key for the given file list, fingerprinted by path and
     * modification time so any file change rotates the key.
     *
     * @param  array<int, string>  $files
     * @return string
     */
    private function cacheKey(array $files): string
    {
        $fingerprint = array_map(static fn (string $file): string => $file . ':' . (int) filemtime($file), $files);

        return CacheKeys::DISCOVERED_RESOURCES->resolveKey([md5(implode('|', $fingerprint))]);
    }

    /**
     * Scan the given files and compile the discovered bindings.
     *
     * @param  array<int, string>  $files
     * @return array<class-string, class-string>
     */
    private function scan(array $files): array
    {
        $map = [];

        foreach ($files as $file) {
            foreach ($this->classesFromFile($file) as $class) {
                $map = $this->bindClass($map, $class);
            }
        }

        return $map;
    }

    /**
     * Merge the bindings declared by the given class into the map.
     *
     * A class that is not autoloadable is skipped silently (the scan derives
     * names from file contents, so a stray file is not an error). A model
     * that is already bound is never rebound - the first discovered binding
     * wins - and the duplicate claim is reported.
     *
     * @param  array<class-string, class-string>  $map
     * @param  string  $class
     * @return array<class-string, class-string>
     */
    private function bindClass(array $map, string $class): array
    {
        if (!class_exists($class)) {
            return $map;
        }

        foreach ((new \ReflectionClass($class))->getAttributes(ForModel::class) as $attribute) {

            $model = $attribute->newInstance()->model;

            if (isset($map[$model])) {
                $this->reportConflict($map, $class, $model);
            } elseif ($this->permitsBinding($class, $model)) {
                $map[$model] = $class;
            }
        }

        return $map;
    }

    /**
     * Report a duplicate claim on an already-bound model: silently for a
     * re-declaration of the same class or a model resolved by an explicit
     * resource map entry, with a warning otherwise - or fatally when schema
     * validation is enabled.
     *
     * @param  array<class-string, class-string>  $map
     * @param  class-string  $class
     * @param  string  $model
     * @return void
     *
     * @throws \LogicException
     */
    private function reportConflict(array $map, string $class, string $model): void
    {
        if ($map[$model] === $class || $this->declaredExplicitly($model)) {
            return;
        }

        $message = sprintf(
            'Resource discovery found multiple resources for model [%s]: keeping [%s], ignoring [%s]. '
            . 'Declare the canonical resource in the resource_map to resolve the ambiguity.',
            $model,
            $map[$model],
            $class,
        );

        if (Config::get('api-toolkit.resources.validate_schemas') === true) {
            throw new \LogicException($message);
        }

        Log::warning($message);
    }

    /**
     * Determine whether the discovered binding is valid, logging a warning
     * when it is not.
     *
     * @param  class-string  $class
     * @param  string  $model
     * @return bool
     */
    private function permitsBinding(string $class, string $model): bool
    {
        $diagnosis = $this->diagnoseBinding($class, $model);

        if ($diagnosis === null) {
            return true;
        }

        Log::warning($diagnosis);

        return false;
    }

    /**
     * Diagnose the discovered binding, returning null when it is valid, or
     * the rejection message otherwise.
     *
     * @param  class-string  $class
     * @param  string  $model
     * @return string|null
     */
    private function diagnoseBinding(string $class, string $model): ?string
    {
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable() || !$reflection->implementsInterface(ApiResourceInterface::class)) {
            return sprintf('Resource discovery skipped [%s]: it declares a model binding but is not an instantiable API resource.', $class);
        }

        if (!class_exists($model)) {
            return sprintf('Resource discovery skipped [%s]: its declared model [%s] does not exist.', $class, $model);
        }

        return null;
    }

    /**
     * Determine whether the model is explicitly declared in the configured
     * resource map.
     *
     * @param  string  $model
     * @return bool
     */
    private function declaredExplicitly(string $model): bool
    {
        $map = Config::get('api-toolkit.resources.resource_map');

        return is_array($map) && array_key_exists($model, $map);
    }

    /**
     * List every PHP file beneath the given paths in a deterministic order.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function files(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $real = $file->getRealPath();

                if ($real === false) {
                    continue; // @codeCoverageIgnore
                }

                $files[] = $real;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Resolve the fully qualified class names declared in the given file.
     *
     * Tokenising avoids loading the file, so a scanned file that is not a
     * class (or is not autoloadable) has no side effects on the process. A
     * class keyword preceded by a double colon (a ::class constant) or by
     * new (an anonymous class) is not a declaration and is skipped.
     *
     * @param  string  $file
     * @return array<int, string>
     */
    private function classesFromFile(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            return []; // @codeCoverageIgnore
        }

        $tokens    = \PhpToken::tokenize($contents);
        $namespace = '';
        $previous  = null;
        $classes   = [];

        foreach ($tokens as $index => $token) {

            if ($token->isIgnorable()) {
                continue;
            }

            if ($token->id === T_NAMESPACE) {
                $namespace = $this->namespaceFromTokens($tokens, $index) ?? $namespace;
            } elseif ($token->id === T_CLASS) {

                $class = $this->declaredNameFromTokens($tokens, $index, $previous);

                if ($class !== null) {
                    $classes[] = ltrim($namespace . '\\' . $class, '\\');
                }
            }

            $previous = $token;
        }

        return $classes;
    }

    /**
     * Resolve the namespace name following the namespace keyword at the given
     * index, or null when the keyword declares no name.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @param  int  $index
     * @return string|null
     */
    private function namespaceFromTokens(array $tokens, int $index): ?string
    {
        for ($next = $index + 1; $next < count($tokens); $next++) {

            if ($tokens[$next]->isIgnorable()) {
                continue;
            }

            return in_array($tokens[$next]->id, [T_STRING, T_NAME_QUALIFIED], true)
                ? $tokens[$next]->text
                : null;
        }

        return null;
    }

    /**
     * Resolve the declared name following the class keyword at the given
     * index, or null when the keyword is not a declaration - a ::class
     * constant reference, an anonymous class expression, or a name-less
     * trailing keyword.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @param  int  $index
     * @param  \PhpToken|null  $previous
     * @return string|null
     */
    private function declaredNameFromTokens(array $tokens, int $index, ?\PhpToken $previous): ?string
    {
        if (in_array($previous?->id, [T_DOUBLE_COLON, T_NEW], true)) {
            return null;
        }

        for ($next = $index + 1; $next < count($tokens); $next++) {

            if ($tokens[$next]->isIgnorable()) {
                continue;
            }

            return $tokens[$next]->id === T_STRING
                ? $tokens[$next]->text
                : null;
        }

        return null;
    }
}
