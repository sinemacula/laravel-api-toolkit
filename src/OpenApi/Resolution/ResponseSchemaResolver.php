<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\DiscriminatorBuilder;

/**
 * Resolves the declared success body of an action.
 *
 * All or nothing: one indescribable class in a union resolves the whole body to
 * null. An enum is indescribable even when it implements DefinesSchema. Each
 * other DefinesSchema member is registered for export as a component.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResponseSchemaResolver
{
    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** @var \SineMacula\ApiToolkit\OpenApi\Schema\DiscriminatorBuilder */
    private DiscriminatorBuilder $discriminator;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder  $envelope
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry  $definedSchemas
     */
    public function __construct(

        /** The metadata catalogue naming the registered resources. */
        private MetadataCatalogue $catalogue,

        /** The builder assembling the shared response envelopes. */
        private EnvelopeBuilder $envelope,

        /** The registry collecting DefinesSchema classes referenced by a $ref. */
        private DefinedSchemaRegistry $definedSchemas,
    ) {
        $this->discriminator = new DiscriminatorBuilder;
    }

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
     * Resolve the data schema node for the declared classes, a discriminated
     * `oneOf` of references for several shapes.
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

            $nodes[$class] = $node;
        }

        $alternatives = [];

        foreach ($nodes as $class => $node) {
            [$reference, $pins] = $this->alternative($class, $node);

            $alternatives[$reference] = $pins;
        }

        return count($alternatives) === 1 ? ['$ref' => array_key_first($alternatives)] : $this->union($alternatives);
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
            return ['$ref' => $this->resourceReference($class)];
        }

        if (is_subclass_of($class, DefinesSchema::class) && !enum_exists($class)) {
            return $class::schema();
        }

        return null;
    }

    /**
     * Resolve the component reference and discriminator pins of a member,
     * registering a DefinesSchema member for export.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $node
     * @return array{string, array<string, string>}
     */
    private function alternative(string $class, array $node): array
    {
        if ($this->isRegisteredResource($class)) {
            $pins = is_a($class, ApiResourceInterface::class, true)
                ? [ResourceSchemaBuilder::TYPE_PROPERTY => $class::getResourceType()]
                : [];

            return [$this->resourceReference($class), $pins];
        }

        /** @var class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema> $defined */
        $defined = $class;

        $this->definedSchemas->register($defined);

        return [self::SCHEMA_REF_PREFIX . SchemaComponentName::fromClass($defined), $this->discriminator->pinnedValues($node)];
    }

    /**
     * Build the `oneOf` of the member references, discriminated when the
     * members allow it.
     *
     * @param  array<string, array<string, string>>  $alternatives
     * @return array<string, mixed>
     */
    private function union(array $alternatives): array
    {
        $union = [
            'oneOf' => array_map(static fn (string $reference): array => ['$ref' => $reference], array_keys($alternatives)),
        ];

        $discriminator = $this->discriminator->build($alternatives);

        if ($discriminator !== null) {
            $union['discriminator'] = $discriminator;
        }

        return $union;
    }

    /**
     * Build the component reference of a registered resource.
     *
     * @param  class-string  $class
     * @return string
     */
    private function resourceReference(string $class): string
    {
        return self::SCHEMA_REF_PREFIX . SchemaComponentName::fromResource($class);
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
