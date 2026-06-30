<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource exposing an aliased scalar field.
 *
 * The scalar's canonical name ('email_address') differs from the key it is
 * exposed under ('email'). The exposed key is the single source of truth: it is
 * both the attribute the serializer reads from the model and the column the
 * narrower selects, so a narrowed SELECT can never drop a column the renderer
 * needs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AliasedScalarUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'aliased_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

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
            Field::scalar('name'),
            Field::scalar('email_address', 'email'),
        );
    }
}
