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
 * Serves the compiled catalogue from bootstrap/cache unless schemas are
 * validated.
 *
 * @phpstan-type Catalogue array{
 *     bindings: array<class-string, class-string>,
 *     resources: list<class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>>,
 * }
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResourceDiscovery
{
    /** @var string The config key recording every resource found under the paths */
    public const string RESOURCES_KEY = 'api-toolkit.resources.discovered_resources';

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
     * Discover the model to resource bindings, preferring the compiled map.
     *
     * @return array<class-string, class-string>
     */
    public function discover(): array
    {
        return $this->catalogue()['bindings'];
    }

    /**
     * Merge the discovered bindings beneath the explicit resource map and
     * record the discovered resources.
     *
     * Records its bindings so later merges can tell them from explicit ones.
     *
     * @return void
     */
    public function merge(): void
    {
        $catalogue  = $this->catalogue();
        $discovered = $catalogue['bindings'];

        if ($discovered === [] && $catalogue['resources'] === [] && $this->contributedMap() === []) {
            return;
        }

        Config::set(self::RESOURCES_KEY, $catalogue['resources']);

        $explicit    = $this->explicitMap();
        $contributed = array_diff_key($discovered, $explicit);

        Config::set('api-toolkit.resources.resource_map', $explicit + $contributed);
        Config::set(self::CONTRIBUTED_KEY, $contributed);
    }

    /**
     * Scan the configured paths for bindings and resources, ignoring any
     * compiled catalogue.
     *
     * @return Catalogue
     */
    public function scan(): array
    {
        $configured = Config::get('api-toolkit.resources.paths');

        // Null means the default roots; a non-array disables discovery
        $paths = match (true) {
            $configured === null  => [app_path('Http/Resources'), ...(glob(app_path('*/Http/Resources')) ?: [])],
            is_array($configured) => $configured,
            default               => [],
        };

        $paths = array_filter($paths, static fn (mixed $path): bool => is_string($path) && is_dir($path));

        return $this->scanFiles($this->files($paths));
    }

    /**
     * Get the path of the compiled discovery catalogue.
     *
     * @return string
     */
    public function cachePath(): string
    {
        return $this->app->bootstrapPath('cache/api-toolkit-resources.php');
    }

    /**
     * Get the configured resource map without the merge-contributed bindings.
     *
     * @return array<class-string, class-string>
     */
    public function explicitMap(): array
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
     * Read the compiled catalogue, or scan when schemas are validated or there
     * is none.
     *
     * @return Catalogue
     */
    private function catalogue(): array
    {
        if (Config::get('api-toolkit.resources.validate_schemas') === true) {
            return $this->scan();
        }

        return $this->cached() ?? $this->scan();
    }

    /**
     * Load the compiled discovery catalogue, or null when there is none.
     *
     * Not require_once, so each boot in a long-lived process reads it afresh.
     *
     * @return Catalogue|null
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
            $catalogue = require $path;
        } catch (\ErrorException) { // @phpstan-ignore catch.neverThrown
            // The error handler throws for a catalogue cleared after is_file().
            return null;
        }

        $complete = is_array($catalogue) && is_array($catalogue['bindings'] ?? null) && is_array($catalogue['resources'] ?? null);

        /** @var Catalogue|null */
        return $complete ? $catalogue : null;
    }

    /**
     * Scan the given files and compile the discovered bindings and resources.
     *
     * A class that is not autoloadable is skipped.
     *
     * @param  array<int, string>  $files
     * @return Catalogue
     */
    private function scanFiles(array $files): array
    {
        $bindings  = [];
        $resources = [];

        foreach ($files as $file) {
            foreach ($this->classesFromFile($file) as $class) {

                if (!class_exists($class)) {
                    continue;
                }

                $bindings = $this->bindClass($bindings, $class);

                if (!self::isResource($class)) {
                    continue;
                }

                $resources[$class] = $class;
            }
        }

        return ['bindings' => $bindings, 'resources' => array_values($resources)];
    }

    /**
     * Determine whether the given class is an instantiable API resource.
     *
     * @param  class-string  $class
     * @return bool
     *
     * @phpstan-assert-if-true class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface> $class
     */
    private static function isResource(string $class): bool
    {
        $reflection = new \ReflectionClass($class);

        return $reflection->isInstantiable() && $reflection->implementsInterface(ApiResourceInterface::class);
    }

    /**
     * Merge the bindings declared by the given class into the map.
     *
     * The first binding wins.
     *
     * @param  array<class-string, class-string>  $map
     * @param  class-string  $class
     * @return array<class-string, class-string>
     */
    private function bindClass(array $map, string $class): array
    {
        foreach ((new \ReflectionClass($class))->getAttributes(ForModel::class) as $attribute) {

            $model = $attribute->newInstance()->model;

            if (isset($map[$model])) {
                $this->reportConflict($map, $class, $model);
                continue;
            }

            $diagnosis = $this->diagnoseBinding($class, $model);

            if ($diagnosis !== null) {
                Log::warning($diagnosis);
                continue;
            }

            $map[$model] = $class;
        }

        return $map;
    }

    /**
     * Report a duplicate claim on an already-bound model.
     *
     * Silent for the same class or an explicit entry; fatal when validating.
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
     * Diagnose the discovered binding, returning the rejection message or null.
     *
     * @param  class-string  $class
     * @param  string  $model
     * @return string|null
     */
    private function diagnoseBinding(string $class, string $model): ?string
    {
        if (!self::isResource($class)) {
            return sprintf('Resource discovery skipped [%s]: it declares a model binding but is not an instantiable API resource.', $class);
        }

        if (!class_exists($model)) {
            return sprintf('Resource discovery skipped [%s]: its declared model [%s] does not exist.', $class, $model);
        }

        return null;
    }

    /**
     * Get the bindings the merge contributed to the configured resource map.
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
     * List every PHP file beneath a single path.
     *
     * A directory removed mid-scan yields an empty list.
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
     * Read a file's contents, or null when it was removed mid-scan.
     *
     * @param  string  $file
     * @return string|null
     */
    private function readFileContents(string $file): ?string
    {
        try {
            $contents = file_get_contents($file);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            // The error handler throws on the read warning for a removed file
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
     * Tokenises the file so scanning it has no side effects on the process.
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
     * Resolve the name declared by the namespace keyword at the given index.
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
     * Resolve the name declared by the class keyword at the given index.
     *
     * Null for a ::class reference, an anonymous class or a trailing keyword.
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
