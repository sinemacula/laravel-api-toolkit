<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;

/**
 * Artisan command to generate an API resource bound to its model.
 *
 * The resource declares only the model key, so nothing is queryable or returned
 * beyond it until fields are declared by hand.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MakeResourceCommand extends GeneratorCommand
{
    /** @var string The package stub the resource is generated from */
    public const string STUB = __DIR__ . '/../../resources/stubs/api-toolkit.resource.stub';

    /** @var array<int, string> The lowercased names the generated file imports */
    private const array IMPORTED_NAMES = ['field', 'apiresource', 'formodel'];

    /** @var string The console command signature */
    protected $signature = <<<'EOD'
        api-toolkit:make:resource
                {name : The name of the resource class}
                {--model= : The model the resource presents}
                {--type= : The resource type, defaulting to the snake-cased model name}
                {--force : Overwrite the resource if it already exists}
        EOD;

    /** @var string The console command description */
    protected $description = 'Create an API resource bound to a model, exposing only its key';

    /** @var class-string<\Illuminate\Database\Eloquent\Model> The model the resource presents */
    private string $model;

    /** @var string The resource type the resource declares */
    private string $resourceType;

    /**
     * Create a new make resource command instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct($files);

        $this->type = 'Resource';
    }

    /**
     * Execute the console command.
     *
     * @return int
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     *
     * @phpstan-ignore method.childReturnType
     */
    #[\Override]
    public function handle(): int
    {
        /** @var \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery $discovery */
        $discovery = $this->laravel->make(ResourceDiscovery::class);
        $class     = $this->qualifyClass($this->getNameInput());

        try {
            $this->model        = $this->resolveModel($class);
            $this->resourceType = $this->resolveType();
            $note               = $this->refuseConflicts($discovery, $class);
        } catch (\InvalidArgumentException $exception) {

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $warnings = array_filter([
            $this->discoveryReachWarning(dirname($this->getPath($class))),
            $this->compiledMapWarning($discovery),
        ], is_string(...));

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        // A model bound elsewhere never gains the resource's alias
        $note ??= $this->morphAliasNote();

        if ($note !== null) {
            $this->components->info($note);
        }

        return self::SUCCESS;
    }

    /**
     * Get the stub file for the generator, preferring a published override.
     *
     * @return string
     */
    #[\Override]
    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/api-toolkit.resource.stub');

        return file_exists($published) ? $published : self::STUB;
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  mixed  $rootNamespace
     * @return string
     */
    #[\Override]
    protected function getDefaultNamespace(mixed $rootNamespace): string
    {
        assert(is_string($rootNamespace));

        return $rootNamespace . '\Http\Resources';
    }

    /**
     * Build the class with the given name.
     *
     * @param  mixed  $name
     * @return string
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    #[\Override]
    protected function buildClass(mixed $name): string
    {
        assert(is_string($name));

        $stub = parent::buildClass($name);
        $key  = $this->keyName();
        $key  = $key === null ? null : $this->literal($key);

        if ($this->referencesModelQualified($name)) {
            $stub  = str_replace(["use {{ namespacedModel }};\r\n", "use {{ namespacedModel }};\n"], '', $stub);
            $model = '\\' . $this->model;
        } else {
            $model = class_basename($this->model);
        }

        // One pass, so a placeholder in a substituted value stays literal
        return strtr($stub, [
            '{{ namespacedModel }}' => $this->model,
            '{{ model }}'           => $model,
            '{{ type }}'            => $this->literal($this->resourceType),
            '{{ default }}'         => $key === null ? '[]' : '[' . $key . ']',
            '{{ fields }}'          => $key === null ? '' : "\n            Field::scalar(" . $key . "),\n        ",
        ]);
    }

    /**
     * Resolve the model the resource presents, guessing it from the class name
     * when no model is given.
     *
     * The application model wins over a same-named class outside it, such as a
     * framework facade alias, and a given name is otherwise used as is.
     *
     * @param  string  $class
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     *
     * @throws \InvalidArgumentException
     */
    private function resolveModel(string $class): string
    {
        $option = $this->option('model');
        $given  = is_string($option) && $option !== '' ? $option : null;
        $model  = $this->qualifyModel($given ?? Str::replaceEnd('Resource', '', class_basename($class)));

        if (!class_exists($model) && $given !== null && class_exists($given)) {
            $model = $given;
        }

        if (!class_exists($model)) {
            throw new \InvalidArgumentException(sprintf('The model [%s] does not exist.', $model));
        }

        $reflection = new \ReflectionClass($model);

        if (!$reflection->isSubclassOf(Model::class) || !$reflection->isInstantiable()) {
            throw new \InvalidArgumentException(sprintf('The class [%s] is not an instantiable Eloquent model.', $model));
        }

        return $reflection->getName();
    }

    /**
     * Resolve the resource type, defaulting to the snake-cased model name.
     *
     * A falsey type reads as unscoped, and brackets cannot key a query field
     * set.
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    private function resolveType(): string
    {
        $option = $this->option('type');
        $type   = strtolower(is_string($option) ? $option : Str::snake(class_basename($this->model)));

        if ($type === '' || $type === '0' || strpbrk($type, '[]') !== false) {
            throw new \InvalidArgumentException(sprintf('The resource type [%s] must not be empty, "0" or contain square brackets.', $type));
        }

        return $type;
    }

    /**
     * Refuse a class name, model binding or resource type that would fail the
     * next boot, returning the note for an explicit entry binding the model to
     * another class.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery  $discovery
     * @param  string  $class
     * @return string|null
     *
     * @throws \InvalidArgumentException
     */
    private function refuseConflicts(ResourceDiscovery $discovery, string $class): ?string
    {
        if (in_array(strtolower(class_basename($class)), self::IMPORTED_NAMES, true)) {
            throw new \InvalidArgumentException(sprintf('The class name [%s] clashes with a class the resource imports.', class_basename($class)));
        }

        $explicit = $discovery->explicitMap();
        $scanned  = $discovery->scan();

        $this->refuseClaimedType($explicit + $scanned, $class);

        if (array_key_exists($this->model, $explicit)) {
            return $explicit[$this->model] === $class ? null : sprintf(
                'The resource_map binds [%s] to [%s], so [%s] is not used for it until that entry changes.',
                $this->model,
                $explicit[$this->model],
                $class,
            );
        }

        $bound = $scanned[$this->model] ?? $class;

        if ($bound !== $class) {
            throw new \InvalidArgumentException(sprintf('The model [%s] is already bound to [%s]. Declare the canonical resource in the resource_map.', $this->model, $bound));
        }

        return null;
    }

    /**
     * Refuse a resource type another mapped resource already returns.
     *
     * @param  array<class-string, class-string>  $map
     * @param  string  $class
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    private function refuseClaimedType(array $map, string $class): void
    {
        foreach ($map as $resource) {

            if ($resource === $class || !method_exists($resource, 'getResourceType')) {
                continue;
            }

            if ($resource::getResourceType() === $this->resourceType) {
                throw new \InvalidArgumentException(sprintf('The resource type [%s] is already used by [%s].', $this->resourceType, $resource));
            }
        }
    }

    /**
     * Determine whether the model must be referenced by its qualified name,
     * because its short name clashes with a name the file declares or imports.
     *
     * @param  string  $class
     * @return bool
     */
    private function referencesModelQualified(string $class): bool
    {
        $names = [...self::IMPORTED_NAMES, strtolower(class_basename($class))];

        return !str_contains($this->model, '\\')
            || in_array(strtolower(class_basename($this->model)), $names, true);
    }

    /**
     * Get the name of the model key, or null for a model without one.
     *
     * @return string|null
     *
     * @phpstan-ignore return.unusedType
     */
    private function keyName(): ?string
    {
        return (new $this->model)->getKeyName();
    }

    /**
     * Render the given value as a single-quoted PHP string literal.
     *
     * @param  string  $value
     * @return string
     */
    private function literal(string $value): string
    {
        return '\'' . addcslashes($value, '\'\\') . '\'';
    }

    /**
     * Warn when the resource lies outside every discovery path.
     *
     * The default roots are compared as patterns, since the directory may not
     * exist yet. Paths are matched literally, except the module roots, which
     * are matched as discovery globs them.
     *
     * @param  string  $directory
     * @return string|null
     */
    private function discoveryReachWarning(string $directory): ?string
    {
        $configured = Config::get('api-toolkit.resources.paths');

        $patterns = match (true) {
            $configured === null  => [$this->pattern(app_path('Http/Resources')), app_path('*/Http/Resources')],
            is_array($configured) => array_map(fn (string $root): string => $this->pattern(rtrim($root, '/')), array_filter($configured, is_string(...))),
            default               => [],
        };

        if ($patterns === []) {
            return 'Resource discovery is disabled, so bind the resource in the resource_map.';
        }

        foreach ($patterns as $pattern) {
            if ($this->isWithin($directory, $pattern)) {
                return null;
            }
        }

        return sprintf('[%s] is outside every resource discovery path, so add it to the paths or bind the resource in the resource_map.', $directory);
    }

    /**
     * Determine whether the directory or one of its ancestors matches the
     * pattern.
     *
     * @param  string  $directory
     * @param  string  $pattern
     * @return bool
     */
    private function isWithin(string $directory, string $pattern): bool
    {
        do {

            if (fnmatch($pattern, $directory, FNM_PATHNAME)) {
                return true;
            }

            $child     = $directory;
            $directory = dirname($directory);
        } while ($directory !== $child);

        return false;
    }

    /**
     * Escape the given path so a pattern matches it literally.
     *
     * @param  string  $path
     * @return string
     */
    private function pattern(string $path): string
    {
        return addcslashes($path, '\*?[');
    }

    /**
     * Warn when discovery serves a compiled map that predates the resource.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery  $discovery
     * @return string|null
     */
    private function compiledMapWarning(ResourceDiscovery $discovery): ?string
    {
        if (!is_file($discovery->cachePath()) || Config::get('api-toolkit.resources.validate_schemas') === true) {
            return null;
        }

        return 'A compiled resource map exists, so run `php artisan api-toolkit:resources:cache` for discovery to serve the resource.';
    }

    /**
     * Note that polymorphic writes will store the new alias, when dynamic morph
     * mapping would register it.
     *
     * @return string|null
     */
    private function morphAliasNote(): ?string
    {
        $morphMap = Relation::morphMap();

        if (
            !Config::get('api-toolkit.resources.enable_dynamic_morph_mapping')
            || array_key_exists($this->resourceType, $morphMap)
            || in_array($this->model, $morphMap, true)
        ) {
            return null;
        }

        return sprintf(
            'Polymorphic relations will store [%s] for [%s] instead of its class name; existing rows keep the class name.',
            $this->resourceType,
            $this->model,
        );
    }
}
