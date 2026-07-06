<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Conflict;

use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource claiming the same model as its sibling; sorted second, so
 * discovery must ignore this binding and warn.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ForModel(User::class)]
final class SecondUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'second_users';

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
