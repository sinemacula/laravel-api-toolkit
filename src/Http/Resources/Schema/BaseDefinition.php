<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Base class for schema definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \Illuminate\Contracts\Support\Arrayable<string, array<string, mixed>>
 */
abstract class BaseDefinition implements Arrayable
{
    /** @var array<int, callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): bool> Guards for conditional inclusion */
    protected array $guards = [];

    /** @var array<int, callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, mixed): mixed> Transformers for modifying resolved values */
    protected array $transformers = [];

    /** @var array<int, string> Extra eager-load paths */
    protected array $extras = [];

    /** @var \SineMacula\ApiToolkit\Http\Resources\Schema\OpenApiFieldDeclaration|null Declared OpenAPI contract; null until openapi() is called */
    protected ?OpenApiFieldDeclaration $openApiDeclaration = null;

    /** @var array<int, string> Declared base-table column reads for this field */
    protected array $needs = [];

    /**
     * Add a guard condition — return false to suppress the field.
     *
     * @param  callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): bool  $guard
     * @return static
     */
    public function guard(callable $guard): static
    {
        $this->guards[] = $guard;

        return $this;
    }

    /**
     * Get the guards attached to this definition.
     *
     * @return array<int, callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, \Illuminate\Http\Request|null): bool>
     */
    public function getGuards(): array
    {
        return $this->guards;
    }

    /**
     * Add a transformer to modify the resolved value.
     *
     * @param  callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, mixed): mixed  $transformer
     * @return static
     */
    public function transform(callable $transformer): static
    {
        $this->transformers[] = $transformer;

        return $this;
    }

    /**
     * Get the transformers attached to this definition.
     *
     * @return array<int, callable(\SineMacula\ApiToolkit\Http\Resources\ApiResource, mixed): mixed>
     */
    public function getTransformers(): array
    {
        return $this->transformers;
    }

    /**
     * Provide additional eager-load paths required by this field.
     *
     * @param  string  ...$paths
     * @return self
     */
    public function extras(string ...$paths): self
    {
        $this->extras = array_values(array_unique([...$this->extras, ...$paths]));

        return $this;
    }

    /**
     * Begin (or continue) declaring this field's OpenAPI contract.
     *
     * Returns the carrier for chaining; call end() to return to the
     * definition. The carrier is created lazily on first use, so a definition
     * that never calls openapi() carries no declaration and is unaffected.
     *
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\OpenApiFieldDeclaration
     */
    public function openapi(): OpenApiFieldDeclaration
    {
        return $this->openApiDeclaration ??= new OpenApiFieldDeclaration($this);
    }

    /**
     * Get the declared OpenAPI carrier, or null when no declaration was made.
     *
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\OpenApiFieldDeclaration|null
     */
    public function getOpenApiDeclaration(): ?OpenApiFieldDeclaration
    {
        return $this->openApiDeclaration;
    }

    /**
     * Declare the base-table columns this field reads so a narrowed SELECT can include them.
     *
     * @param  string  ...$columns
     * @return static
     */
    public function needs(string ...$columns): static
    {
        $this->needs = array_values(array_unique([...$this->needs, ...$columns]));

        return $this;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    abstract public function toArray(): array;
}
