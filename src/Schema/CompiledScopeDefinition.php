<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Database\Eloquent\Attributes\Scope as LocalScope;

/**
 * Typed representation of a single compiled named scope.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CompiledScopeDefinition
{
    /**
     * Create a new compiled scope definition.
     *
     * @param  string  $name
     * @param  string  $scope
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $arguments
     * @param  string|null  $description
     * @param  array<int, callable(mixed): bool>  $callerGuards
     */
    public function __construct(

        /** The name a client invokes the scope by */
        public string $name,

        /** The model's local scope */
        public string $scope,

        /** The validation rules for the scope's arguments */
        public array $parameters = [],

        /** The arguments the rules declare */
        public array $arguments = [],

        /** What the scope matches, for the documentation */
        public ?string $description = null,

        /** Caller guard closures that control availability */
        public array $callerGuards = [],
    ) {}

    /**
     * Compile a raw scope definition, keyed by its client name or else by the
     * name its schema key carries.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @return self
     */
    public static function fromRaw(string $schemaKey, array $definition): self
    {
        $name   = self::stringOr($definition['key'] ?? null, substr($schemaKey, strlen(Scope::KEY_PREFIX)));
        $guards = $definition['caller_guards'] ?? null;

        /** @var array<string, mixed> $parameters */
        $parameters = is_array($definition['parameters'] ?? null) ? $definition['parameters'] : [];

        return new self(
            name        : $name,
            scope       : self::stringOr($definition['scope'] ?? null, $name),
            parameters  : $parameters,
            arguments   : Scope::argumentsOf($parameters),
            description : self::stringOr($definition['description'] ?? null, null),
            callerGuards: is_array($guards) ? array_values(array_filter($guards, is_callable(...))) : [],
        );
    }

    /**
     * Reflect the method the model runs the scope through, or null when the
     * model defines no such scope.
     *
     * Resolved as the model resolves a named scope: the same-named method when
     * it carries the scope attribute and is not private, else the prefixed one.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \ReflectionMethod|null
     */
    public function method(string $modelClass): ?\ReflectionMethod
    {
        if (method_exists($modelClass, $this->scope)) {

            $method = new \ReflectionMethod($modelClass, $this->scope);

            if (!$method->isPrivate() && $method->getAttributes(LocalScope::class) !== []) {
                return $method;
            }
        }

        $prefixed = 'scope' . ucfirst($this->scope);

        return method_exists($modelClass, $prefixed) ? new \ReflectionMethod($modelClass, $prefixed) : null;
    }

    /**
     * Return the value when it is a string, else the default.
     *
     * @template TDefault of string|null
     *
     * @param  mixed  $value
     * @param  TDefault  $default
     * @return string|TDefault
     */
    private static function stringOr(mixed $value, ?string $default): ?string
    {
        return is_string($value) ? $value : $default;
    }
}
