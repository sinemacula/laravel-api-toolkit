<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaNameCollisionGuard;

/**
 * Builds one titled components.schemas entry per referenced DefinesSchema
 * class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DefinedSchemaBuilder
{
    /**
     * Build the schema map for the referenced classes, claiming each name.
     *
     * A title the fragment declares overrides the component name.
     *
     * @param  list<class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema>>  $classes
     * @param  array<string, string>  $claimed
     * @return array<string, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException
     */
    public function build(array $classes, array &$claimed): array
    {
        $guard   = new SchemaNameCollisionGuard;
        $schemas = [];

        foreach ($classes as $class) {

            $name = SchemaComponentName::fromClass($class);

            $guard->claim($claimed, $name, $class);

            $schemas[$name] = ['title' => $name, ...$class::schema()];
        }

        return $schemas;
    }
}
