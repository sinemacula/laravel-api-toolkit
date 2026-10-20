<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;

/**
 * Boot-time discovery of attribute-bound API resources.
 *
 * Scans the configured resource paths for classes carrying the ForModel
 * attribute and compiles them into a model to resource map. When a compiled map
 * exists under bootstrap/cache, written by the api-toolkit:resources:cache
 * command (which php artisan optimize runs), it is served as-is and no
 * directory is walked; otherwise the paths are scanned on every boot, which is
 * the development path. When schema validation is enabled the compiled map is
 * ignored so every diagnostic fires on every boot. The cache store is never
 * used, so discovery works without a reachable store.
 *
 * Files are visited in a deterministic (sorted) order; when two resources claim
 * the same model the first discovered binding wins and a warning is logged, or
 * the scan fails hard when schema validation is enabled - unless the model is
 * explicitly declared in the resource map, which resolves the ambiguity. A
 * binding whose class is not an instantiable API resource, or whose model does
 * not exist, is skipped with a warning.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResourceDiscovery
{
    /** @var string The config key recording the bindings the merge added */
    private const string CONTRIBUTED_KEY = 'api-toolkit.resources.discovered_bindings';

    /**
     * Create a new resource discovery instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function __construct(

        /** The application whose bootstrap cache holds the compiled map. */
        private Application $app,
    ) {}

    /**
     * Discover the model to resource bindings, serving the compiled map when
     * one exists.
     *
     * @return array<class-string, class-string>
     */
    public function discover(): array
    {
        if (Config::get('api-toolkit.resources.validate_schemas') === true) {
            return $this->scan();
        }

        return $this->cached() ?? $this->scan();
    }

    /**
     * Merge the discovered bindings beneath the explicitly configured resource
     * map.
     *
     * The bindings the merge contributes are recorded beside the map, so a
     * later merge or scan, including one reading a cached config the merge was
     * baked into, still tells them apart from explicit entries.
     *
     * @return void
     */
    public function merge(): void
    {
        $discovered = $this->discover();

        if ($discovered === [] && $this->contributedMap() === []) {
            return;
        }

        $explicit    = $this->explicitMap();
        $contributed = array_diff_key($discovered, $explicit);

        Config::set('api-toolkit.resources.resource_map', $explicit + $contributed);
        Config::set(self::CONTRIBUTED_KEY, $contributed);
    }

    /**
     * Scan the configured paths and compile the model to resource bindings,
     * ignoring any compiled map.
     *
     * @return array<class-string, class-string>
     */
    public function scan(): array
    {
        $configured = Config::get('api-toolkit.resources.paths');

        // A null value - or an absent key on a config that predates it -
        // resolves to the default roots; an explicit array is honoured as-is,
        // so an empty array still disables discovery, and any other type
        // disables it too.
        $paths = match (true) {
            $configured === null  => $this->defaultPaths(),
            is_array($configured) => $configured,
            default               => [],
        };

        $paths = array_filter($paths, static fn (mixed $path): bool => is_string($path) && is_dir($path));

        return $this->scanFiles($this->files($paths));
    }

    /**
     * The path of the compiled discovery map.
     *
     * @return string
     */
    public function cachePath(): string
    {
        return $this->app->bootstrapPath('cache/api-toolkit-resources.php');
    }

    /**
     * Load the compiled discovery map, or null when there is none.
     *
     * The map is required rather than required once so every boot in a
     * long-lived process reads it afresh.
     *
     * @return array<class-string, class-string>|null
     *
     * @SuppressWarnings("php:S2003")
     * @SuppressWarnings("php:S4833")
     */
    private function cached(): ?array
    {
        $path = $this->cachePath();

        if (!is_file($path)) {
            return null;
        }

        try {
            $map = require $path;
        } catch (\ErrorException) { // @phpstan-ignore catch.neverThrown
            // The error handler throws for a map cleared after is_file().
            return null;
        }

        /** @var array<class-string, class-string>|null */
        return is_array($map) ? $map : null;
    }

    /**
     * The default scan roots when no paths are configured.
     *
     * Resolved from app_path() at scan time rather than baked into the cached
     * config, so a newly added module is found on the next uncached boot or
     * rebuild of the compiled map, and a modular application - which repoints
     * app_path() at its module root - is covered with no coupling to any
     * modules package. The application's own resource directory is scanned
     * alongside each module's, the latter sitting one level below the flat
     * directory.
     *
     * @return array<int, string>
     */
    private function defaultPaths(): array
    {
        return array_merge(
            [app_path('Http/Resources')],
            glob(app_path('*/Http/Resources')) ?: [],
        );
    }

    /**
     * Scan the given files and compile the discovered bindings.
     *
     * @param  array<int, string>  $files
     * @return array<class-string, class-string>
     */
    private function scanFiles(array $files): array
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
     * names from file contents, so a stray file is not an error). A model that
     * is already bound is never rebound - the first discovered binding wins -
     * and the duplicate claim is reported.
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
        if ($map[$model] === $class || array_key_exists($model, $this->explicitMap())) {
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
     * Determine whether the discovered binding is valid, logging a warning when
     * it is not.
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
     * Diagnose the discovered binding, returning null when it is valid, or the
     * rejection message otherwise.
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
     * The configured resource map without the bindings the merge contributed.
     *
     * @return array<class-string, class-string>
     */
    private function explicitMap(): array
    {
        $map = Config::get('api-toolkit.resources.resource_map');

        if (!is_array($map)) {
            return [];
        }

        $contributed = $this->contributedMap();

        /** @var array<class-string, class-string> */
        return array_filter(
            $map,
            static fn (mixed $resource, mixed $model): bool => ($contributed[$model] ?? null) !== $resource,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The bindings the merge contributed to the configured resource map.
     *
     * @return array<class-string, class-string>
     */
    private function contributedMap(): array
    {
        $contributed = Config::get(self::CONTRIBUTED_KEY);

        /** @var array<class-string, class-string> */
        return is_array($contributed) ? $contributed : [];
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
            $files = array_merge($files, $this->listPhpFiles($path));
        }

        sort($files);

        return $files;
    }

    /**
     * List every PHP file directly discoverable beneath a single path.
     *
     * A directory removed between the discovery filter and this scan - as
     * happens under concurrent filesystem changes - yields an empty list rather
     * than propagating the iterator failure.
     *
     * @param  string  $path
     * @return array<int, string>
     */
    private function listPhpFiles(string $path): array
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
        } catch (\UnexpectedValueException) {
            return [];
        }

        $files = [];

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

        return $files;
    }

    /**
     * Read a file's contents, tolerating a file removed between enumeration and
     * read (a hot deploy swapping resources mid-scan, or a concurrent test) by
     * returning null rather than aborting discovery.
     *
     * @param  string  $file
     * @return string|null
     */
    private function readFileContents(string $file): ?string
    {
        try {
            $contents = file_get_contents($file);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            // A booted error handler turns the read warning for a file removed
            // mid-scan into a throwable; tolerate it rather than abort.
            return null;
        }

        if ($contents === false) {
            return null; // @codeCoverageIgnore
        }

        return $contents;
    }

    /**
     * Resolve the fully qualified class names declared in the given file.
     *
     * Tokenising avoids loading the file, so a scanned file that is not a class
     * (or is not autoloadable) has no side effects on the process. A
     * class keyword preceded by a double colon (a ::class constant) or by
     * new (an anonymous class) is not a declaration and is skipped.
     *
     * @param  string  $file
     * @return array<int, string>
     */
    private function classesFromFile(string $file): array
    {
        $contents = $this->readFileContents($file);

        if ($contents === null) {
            return [];
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
