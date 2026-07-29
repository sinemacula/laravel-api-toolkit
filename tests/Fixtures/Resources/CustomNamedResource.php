<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource carrying a #[SchemaName] component-name override.
 *
 * The override name differs from the basename derivation, so the naming helper
 * can assert the attribute wins over the derived name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[SchemaName('CustomName')]
final class CustomNamedResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'custom_named';

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
