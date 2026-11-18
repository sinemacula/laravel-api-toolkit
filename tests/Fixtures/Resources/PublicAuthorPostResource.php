<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture post resource presenting its author through the public user resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PublicAuthorPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'public_author_posts';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title'];

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
            Field::scalar('title')->filterable(Capability::EXACT),
            Relation::to('user', PublicUserResource::class, 'author')->traversable(),
        );
    }
}
