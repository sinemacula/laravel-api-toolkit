<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource rendering its keys in the order the client requests
 * them, with a bounded relation and an aggregate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RequestOrderedUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'request_ordered_users';

    /** @var array<int, string> */
    protected static array $default = ['name', 'email', 'id'];

    /** @var \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy */
    protected FieldOrderingStrategy $fieldOrderingStrategy = FieldOrderingStrategy::BY_REQUESTED_FIELDS;

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
            Field::scalar('email'),
            Relation::to('posts', PostResource::class)->limit(1),
            Count::of('posts')->default(),
        );
    }
}
