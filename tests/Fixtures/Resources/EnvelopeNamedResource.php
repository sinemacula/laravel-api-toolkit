<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource whose #[SchemaName] override takes the name of a shared
 * pagination envelope schema, so the assembler can assert the collision fails
 * loud rather than one schema silently replacing the other.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[SchemaName('PaginationMeta')]
final class EnvelopeNamedResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'envelope_named';

    /** @var array<int, string> */
    protected static array $default = ['id'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
        );
    }
}
