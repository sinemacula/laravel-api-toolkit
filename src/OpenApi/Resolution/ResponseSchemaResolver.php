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
 * null. Each DefinesSchema member is registered for export as a component.
 *
 * @phpstan-type Member array{
 *     node: array<string, mixed>,
 *     reference: string,
 *     defined: class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema>|null,
 * }
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
     * Resolve the data schema node for the declared classes, a `oneOf` of
     * references for several shapes, discriminated when the members allow it.
     *
     * @param  non-empty-list<class-string>  $classes
     * @return array<string, mixed>|null
     */
    private function dataSchema(array $classes): ?array
    {
        $members = [];

        foreach ($classes as $class) {
            $member = $this->member($class);

            if ($member === null) {
                return null;
            }

            if (in_array($member['node'], array_column($members, 'node'), true)) {
                continue;
            }

            $members[$class] = $member;
        }

        foreach (array_filter(array_column($members, 'defined'), is_string(...)) as $defined) {
            $this->definedSchemas->register($defined);
        }

        return count($members) === 1 ? ['$ref' => reset($members)['reference']] : $this->union($members);
    }

    /**
     * Resolve the schema node and component reference of the named class.
     *
     * @param  string  $class
     * @return Member|null
     */
    private function member(string $class): ?array
    {
        if ($this->isRegisteredResource($class)) {
            $reference = $this->resourceReference($class);

            return ['node' => ['$ref' => $reference], 'reference' => $reference, 'defined' => null];
        }

        if (is_subclass_of($class, DefinesSchema::class)) {
            return [
                'node'      => $class::schema(),
                'reference' => self::SCHEMA_REF_PREFIX . SchemaComponentName::fromClass($class),
                'defined'   => $class,
            ];
        }

        return null;
    }

    /**
     * Build the `oneOf` of the member references, discriminated when the
     * members allow it.
     *
     * @param  array<class-string, Member>  $members
     * @return array<string, mixed>
     */
    private function union(array $members): array
    {
        $alternatives = [];

        foreach ($members as $class => $member) {
            $alternatives[$member['reference']] = $this->pins($class, $member);
        }

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
     * Resolve the discriminator pins of a member: a resource pins its type, a
     * DefinesSchema the required string constants of its schema.
     *
     * @param  class-string  $class
     * @param  Member  $member
     * @return array<string, string>
     */
    private function pins(string $class, array $member): array
    {
        if ($member['defined'] !== null) {
            return $this->discriminator->pinnedValues($member['node']);
        }

        return is_a($class, ApiResourceInterface::class, true)
            ? [ResourceSchemaBuilder::TYPE_PROPERTY => $class::getResourceType()]
            : [];
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
