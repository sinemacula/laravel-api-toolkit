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
 * Resolves the declared success body of a non-resource action.
 *
 * A non-resource route names its response body with a #[ResponseSchema]
 * directive on the action. The named class is resolved to the inner `data`
 * schema wrapped by the shared envelope: a registered resource emits a
 * reference to its component schema, and a self-describing class inlines its
 * own JSON-Schema fragment. A reference is emitted only when the resource is a
 * value of the catalogue's resource map, so the referenced component is always
 * built by the resource pipeline and never dangles. Any other class - a plain
 * class that is neither a registered resource nor self-describing, or an action
 * carrying no directive - resolves to null, so the caller falls through to the
 * undocumented marker rather than asserting a body it cannot describe.
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
     * Resolve the success-body schema declared by the action, or null when the
     * action carries no directive or names a class that cannot be described.
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

        $item = $this->itemSchema($directive->schema);

        if ($item === null) {
            return null;
        }

        return $directive->collection
            ? $this->envelope->collectionEnvelopeFor($item)
            : $this->envelope->singleEnvelopeFor($item);
    }

    /**
     * Read the #[ResponseSchema] directive declared on the action, or null when
     * the route is not backed by a controller method carrying one.
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
     * Resolve the inner data schema node for the named class: a reference to a
     * registered resource component, an inlined self-describing fragment, or
     * null for any other class.
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
     * Determine whether the class is a resource whose component schema the
     * resource pipeline builds, guaranteeing a reference to it never dangles.
     *
     * @param  string  $class
     * @return bool
     */
    private function isRegisteredResource(string $class): bool
    {
        return is_subclass_of($class, JsonResource::class)
            && in_array($class, $this->catalogue->getResourceMap(), true);
    }
}
