<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource carrying a full author-declared OpenAPI contract.
 *
 * The `tier` field is computed - so it is opaque to inference - yet declares a
 * complete openapi() override (type, format, enum, example, description, and
 * nullability) so the emission path can be proven to surface the declared
 * contract verbatim rather than flagging the field undocumented.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeclaredOpenApiUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'declared_openapi_users';

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
            Field::compute('tier', static fn ($resource): string => 'gold')
                ->openapi()
                ->type('string')
                ->format('color')
                ->enum(['bronze', 'silver', 'gold'])
                ->example('gold')
                ->description('The membership tier of the user')
                ->nullable()
                ->end(),
        );
    }
}
