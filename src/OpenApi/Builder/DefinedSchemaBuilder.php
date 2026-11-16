<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
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
    /** The message describing an enum referenced both as a shape and as an enum */
    private const string ENUM_SHAPE_MESSAGE = 'The OpenAPI component schema name "%s" is derived by [%s] both as an enum and as a'
        . ' #[ResponseSchema] shape. Declare the response shape on a class that is not an enum.';

    /**
     * Build the schema map for the referenced classes, claiming each name.
     *
     * A title the fragment declares replaces the default title. A class also
     * referenced as an enum fails, since both schemas derive its one name.
     *
     * @param  list<class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema>>  $classes
     * @param  list<class-string>  $enums
     * @param  array<string, string>  $claimed
     * @return array<string, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException
     */
    public function build(array $classes, array $enums, array &$claimed): array
    {
        $guard   = new SchemaNameCollisionGuard;
        $schemas = [];

        foreach ($classes as $class) {

            $name = SchemaComponentName::fromClass($class);

            if (in_array($class, $enums, true)) {
                throw new SchemaNameCollisionException(sprintf(self::ENUM_SHAPE_MESSAGE, $name, $class));
            }

            $guard->claim($claimed, $name, $class);

            $schemas[$name] = ['title' => $name, ...$class::schema()];
        }

        return $schemas;
    }
}
