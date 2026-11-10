<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Schema\RawSchemaResource;

/**
 * Compiles a raw schema the way a resource declaring it is compiled.
 *
 * The compiled definitions carry whatever the raw schema declared, including
 * entries their types do not admit, which is what the schema validation rules
 * exist to report.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait CompilesRawSchemas
{
    /**
     * Compile the given raw schema through the schema compiler.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    protected function compileRawSchema(array $rawSchema): CompiledSchema
    {
        RawSchemaResource::declare($rawSchema);

        // Every call compiles the same resource, so the schema compiled for the
        // previous call is still cached under its name.
        SchemaCompiler::clearCache();

        return SchemaCompiler::compile(RawSchemaResource::class);
    }
}
