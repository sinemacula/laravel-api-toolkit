<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Resolves the declared success body of an action.
 *
 * All or nothing: one indescribable class in a union resolves the whole body to
 * null.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResponseSchemaResolver
{
    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder  $envelope
     */
    public function __construct(

        /** The metadata catalogue naming the registered resources. */
        private MetadataCatalogue $catalogue,

        /** The builder assembling the shared response envelopes. */
        private EnvelopeBuilder $envelope,
    ) {}

    /**
     * Determine whether the action carries a #[ResponseSchema] directive.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return bool
     */
    public function declares(?string $controllerClass, string $action): bool
    {
        return $this->directive($controllerClass, $action) !== null;
    }

    /**
     * Determine whether the action's #[ResponseSchema] directive names the
     * given resource and no other class, in the given shape.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  class-string  $resourceClass
     * @param  bool  $collection
     * @return bool
     */
    public function declaresResource(?string $controllerClass, string $action, string $resourceClass, bool $collection): bool
    {
        $directive = $this->directive($controllerClass, $action);

        return $directive !== null
            && $directive->collection            === $collection
            && array_unique($directive->schemas) === [$resourceClass];
    }

    /**
     * Resolve the success-body schema declared by the action.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<string, mixed>|null
     */
    public function resolve(?string $controllerClass, string $action): ?array
    {
        $directive = $this->directive($controllerClass, $action);

        if ($directive === null) {
            return null;
        }

        $data = $this->dataSchema($directive->schemas);

        if ($data === null) {
            return null;
        }

        return $directive->collection
            ? $this->envelope->collectionEnvelopeFor($data)
            : $this->envelope->singleEnvelopeFor($data);
    }

    /**
     * Read the #[ResponseSchema] directive declared on the action, if any.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return \SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema|null
     */
    private function directive(?string $controllerClass, string $action): ?ResponseSchema
    {
        if ($controllerClass === null || !method_exists($controllerClass, $action)) {
            return null;
        }

        $attributes = (new \ReflectionMethod($controllerClass, $action))->getAttributes(ResponseSchema::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Resolve the data schema node for the declared classes, a `oneOf` for
     * several shapes.
     *
     * @param  non-empty-list<class-string>  $classes
     * @return array<string, mixed>|null
     */
    private function dataSchema(array $classes): ?array
    {
        $nodes = [];

        foreach ($classes as $class) {
            $node = $this->itemSchema($class);

            if ($node === null) {
                return null;
            }

            if (in_array($node, $nodes, true)) {
                continue;
            }

            $nodes[] = $node;
        }

        return count($nodes) === 1 ? $nodes[0] : ['oneOf' => $nodes];
    }

    /**
     * Resolve the inner data schema node for the named class.
     *
     * @param  string  $class
     * @return array<string, mixed>|null
     */
    private function itemSchema(string $class): ?array
    {
        if ($this->isRegisteredResource($class)) {
            return ['$ref' => self::SCHEMA_REF_PREFIX . SchemaComponentName::fromResource($class)];
        }

        if (is_subclass_of($class, DefinesSchema::class)) {
            return $class::schema();
        }

        return null;
    }

    /**
     * Determine whether the class is a resource whose component schema is
     * built, so a reference never dangles.
     *
     * @param  string  $class
     * @return bool
     *
     * @phpstan-assert-if-true class-string<\Illuminate\Http\Resources\Json\JsonResource> $class
     */
    private function isRegisteredResource(string $class): bool
    {
        return is_subclass_of($class, JsonResource::class)
            && in_array($class, $this->catalogue->getResourceMap(), true);
    }
}
