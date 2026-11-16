<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;

/**
 * Fixture user resource that counts how often its schema is built.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SchemaCountingUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'users';

    /** @var int The number of times the schema has been built */
    public static int $schemaCalls = 0;

    /** @var array<int, string> */
    protected static array $default = ['id', 'name'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        self::$schemaCalls++;

        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
            Relation::to('organization', OrganizationResource::class),
        );
    }
}
