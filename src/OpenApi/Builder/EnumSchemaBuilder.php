<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Builds one components.schemas entry per referenced enum class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EnumSchemaBuilder
{
    /**
     * Build the schema map for the referenced enums, keyed by component name.
     *
     * @param  list<class-string>  $enums
     * @param  array<string, string>  $reserved
     * @return array<string, array<string, mixed>>
     *
     * @throws \LogicException
     */
    public function build(array $enums, array $reserved = []): array
    {
        $guard   = new SchemaNameCollisionGuard;
        $claimed = $reserved;
        $schemas = [];

        foreach ($enums as $enum) {

            if (!enum_exists($enum)) {
                throw new \LogicException(sprintf('The referenced class [%s] is not an enum', $enum));
            }

            $name = SchemaComponentName::fromEnum($enum);

            $guard->claim($claimed, $name, $enum);

            $schemas[$name] = ['type' => $this->backingType($enum), 'enum' => $this->values($enum)];
        }

        return $schemas;
    }

    /**
     * List the enum's wire values in case declaration order.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @return list<int|string>
     */
    private function values(string $enum): array
    {
        $values = [];

        foreach ((new \ReflectionEnum($enum))->getCases() as $case) {
            $values[] = $case instanceof \ReflectionEnumBackedCase
                ? $case->getBackingValue()
                : PureEnumName::of($case->getValue());
        }

        return $values;
    }

    /**
     * Resolve the JSON Schema type for the enum's backing type.
     *
     * @param  class-string<\UnitEnum>  $enum
     * @return string
     */
    private function backingType(string $enum): string
    {
        $backing = (new \ReflectionEnum($enum))->getBackingType();

        return $backing instanceof \ReflectionNamedType && $backing->getName() === 'int' ? 'integer' : 'string';
    }
}
