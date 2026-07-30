<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource carrying an empty #[SchemaName] override.
 *
 * An empty override name is ignored, so the naming helper falls back to the
 * basename derivation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[SchemaName('')]
final class EmptyNamedResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'empty_named';

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
