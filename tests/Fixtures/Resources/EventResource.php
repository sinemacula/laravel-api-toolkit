<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture event resource, sortable by its microsecond timestamp.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EventResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'events';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'occurred_at'];

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
            Field::timestamp('occurred_at')->sortable(),
        );
    }
}
