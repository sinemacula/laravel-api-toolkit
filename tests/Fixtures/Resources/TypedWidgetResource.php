<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource emitting every typed widget column unchanged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TypedWidgetResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'typed_widgets';

    /** @var list<string> The columns stored with a nullable twin */
    private const array TWINNED = [
        'price', 'weight', 'released_on', 'seen_at', 'opens_at', 'active',
        'flagged', 'settings', 'aliases', 'metadata', 'reference', 'status',
    ];

    /** @var list<string> The columns stored without a nullable twin */
    private const array SINGLE = [
        'type_big_integer', 'type_integer', 'type_medium_integer', 'type_small_integer', 'type_tiny_integer',
        'type_float', 'type_real', 'type_double', 'type_char', 'type_string', 'type_tiny_text', 'type_text',
        'type_medium_text', 'type_long_text', 'type_enum', 'type_json', 'type_jsonb', 'type_ulid', 'type_date',
        'type_date_time_tz', 'type_time_tz', 'type_timestamp', 'type_timestamp_tz', 'type_binary',
        'cast_integer', 'cast_float', 'cast_string', 'cast_timestamp', 'cast_datetime', 'cast_immutable_date',
        'cast_formatted_date', 'cast_formatted_immutable_datetime', 'cast_encrypted', 'cast_hashed',
        'cast_stringable', 'cast_uri', 'cast_json', 'cast_object', 'cast_encrypted_object', 'cast_array_object',
        'cast_custom',
    ];

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
        return Field::set(...array_map(Field::scalar(...), [
            'id',
            'user_id',
            ...self::TWINNED,
            ...array_map(static fn (string $column): string => 'nullable_' . $column, self::TWINNED),
            ...self::SINGLE,
            'created_at',
            'updated_at',
        ]));
    }
}
