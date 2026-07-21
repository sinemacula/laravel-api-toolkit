<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Invalid;

use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource whose declared model does not exist; discovery must skip it
 * with a warning.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @phpstan-ignore argument.type
 */
#[ForModel('Tests\Fixtures\Models\DoesNotExist')]
final class MissingModelResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'missing_models';

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
