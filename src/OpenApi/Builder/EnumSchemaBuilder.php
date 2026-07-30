<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;

/**
 * Builds one components.schemas entry per referenced enum class.
 *
 * Given the set of enum classes a document references through a $ref, emits a
 * named schema per enum carrying only its backing type - integer for an
 * int-backed enum, string otherwise - since the reference by name is the point
 * and the value set is documented separately. The enum names share the map with
 * the resource and envelope schemas, so each enum is guarded against the names
 * already reserved by those and against the other enums, failing loud when two
 * different sources derive one name.
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
     */
    public function build(array $enums, array $reserved = []): array
    {
        $guard   = new SchemaNameCollisionGuard;
        $claimed = $reserved;
        $schemas = [];

        foreach ($enums as $enum) {
            $name = SchemaComponentName::fromEnum($enum);

            $guard->claim($claimed, $name, $enum);

            $schemas[$name] = ['type' => $this->backingType($enum)];
        }

        return $schemas;
    }

    /**
     * Resolve the JSON Schema type for the enum's backing type: integer for an
     * int-backed enum, string for a string-backed or non-backed enum.
     *
     * @param  class-string  $enum
     * @return string
     */
    private function backingType(string $enum): string
    {
        $backing = (new \ReflectionEnum($enum))->getBackingType();

        return $backing instanceof \ReflectionNamedType && $backing->getName() === 'int' ? 'integer' : 'string';
    }
}
