<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Foundation\Http\FormRequest;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\Services\Contracts\DefinesInputSchema;

/**
 * Resolves the request body a write action documents from its rules source.
 *
 * Discovery follows a fixed precedence: a #[RequestSchema] directive naming a
 * rules source always wins, then a type-hinted action parameter that is a
 * FormRequest or a self-describing input, and otherwise no body is documented.
 * A self-describing source is read through its static rules() with no instance,
 * while a FormRequest is instantiated plainly and read through a guarded
 * rules() call so a rules() reaching for the request degrades to no body rather
 * than throwing. The rules are translated to an inlined schema and served as
 * multipart form data when any field is binary, otherwise as JSON.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RequestBodyResolver
{
    /** The media type a body with no binary field is served under */
    private const string MEDIA_JSON = 'application/json';

    /** The media type a body carrying a binary field is served under */
    private const string MEDIA_MULTIPART = 'multipart/form-data';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator  $translator
     */
    public function __construct(

        /** The translator turning a rules array into a schema fragment. */
        private RulesToSchemaTranslator $translator,
    ) {}

    /**
     * Resolve the request body object for the action, or null when no rules
     * source is discoverable or it yields no documentable field.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<string, mixed>|null
     */
    public function resolve(?string $controllerClass, string $action): ?array
    {
        $rules = $this->rulesFor($controllerClass, $action);

        if ($rules === null) {
            return null;
        }

        $schema = $this->translator->translate($rules);

        if (($schema['properties'] ?? []) === []) {
            return null;
        }

        $media = $this->isBinary($schema) ? self::MEDIA_MULTIPART : self::MEDIA_JSON;

        return [
            'required' => true,
            'content'  => [$media => ['schema' => $schema]],
        ];
    }

    /**
     * Resolve the rules array for the action from its discovered source.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<array-key, mixed>|null
     */
    private function rulesFor(?string $controllerClass, string $action): ?array
    {
        if ($controllerClass === null || !method_exists($controllerClass, $action)) {
            return null;
        }

        $source = $this->attributeSource($controllerClass, $action)
            ?? $this->parameterSource($controllerClass, $action);

        return $source === null ? null : $this->rulesFromSource($source);
    }

    /**
     * Read the rules-source class named by a #[RequestSchema] directive on the
     * action, or null when the action carries none.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return string|null
     */
    private function attributeSource(string $controllerClass, string $action): ?string
    {
        $attributes = (new \ReflectionMethod($controllerClass, $action))->getAttributes(RequestSchema::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->schema;
    }

    /**
     * Find the first action parameter type-hinting a rules source, or null when
     * no parameter is a rules source.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return string|null
     */
    private function parameterSource(string $controllerClass, string $action): ?string
    {
        foreach ((new \ReflectionMethod($controllerClass, $action))->getParameters() as $parameter) {

            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if ($this->isRulesSource($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Determine whether the class is a rules source: a self-describing input or
     * a FormRequest.
     *
     * @param  string  $class
     * @return bool
     */
    private function isRulesSource(string $class): bool
    {
        return is_subclass_of($class, DefinesInputSchema::class)
            || is_subclass_of($class, FormRequest::class);
    }

    /**
     * Read the rules array from the source, degrading to null when reading it
     * throws or it yields no rules.
     *
     * @param  string  $source
     * @return array<array-key, mixed>|null
     */
    private function rulesFromSource(string $source): ?array
    {
        try {
            $rules = $this->extractRules($source);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return null;
        }

        return is_array($rules) && $rules !== [] ? $rules : null;
    }

    /**
     * Extract the raw rules value from the source, statically for a
     * self-describing input and through a plain instance for a FormRequest.
     *
     * @param  string  $source
     * @return mixed
     */
    private function extractRules(string $source): mixed
    {
        if (is_subclass_of($source, DefinesInputSchema::class)) {
            return $source::rules();
        }

        if (is_subclass_of($source, FormRequest::class)) {
            return $this->formRequestRules($source);
        }

        return null;
    }

    /**
     * Read a FormRequest's rules through a plainly constructed instance.
     *
     * @param  class-string<\Illuminate\Foundation\Http\FormRequest>  $source
     * @return mixed
     */
    private function formRequestRules(string $source): mixed
    {
        $callable = [new $source, 'rules'];

        return is_callable($callable) ? $callable() : null;
    }

    /**
     * Determine whether the schema carries a binary-format field at any depth.
     *
     * @param  array<array-key, mixed>  $schema
     * @return bool
     */
    private function isBinary(array $schema): bool
    {
        if (($schema['format'] ?? null) === 'binary') {
            return true;
        }

        foreach ($schema as $value) {
            if (is_array($value) && $this->isBinary($value)) {
                return true;
            }
        }

        return false;
    }
}
