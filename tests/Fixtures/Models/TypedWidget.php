<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\AsUri;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Casts\SluggedTitleCast;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Fixture model holding one attribute per column type and cast.
 *
 * @property int $id
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('typed_widgets')]
#[Unguarded]
final class TypedWidget extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'price'                             => 'decimal:2',
            'nullable_price'                    => 'decimal:2',
            'released_on'                       => 'date',
            'nullable_released_on'              => 'date',
            'active'                            => 'boolean',
            'nullable_active'                   => 'boolean',
            'settings'                          => 'array',
            'nullable_settings'                 => 'array',
            'aliases'                           => 'array',
            'nullable_aliases'                  => 'array',
            'metadata'                          => 'collection',
            'nullable_metadata'                 => 'collection',
            'status'                            => UserStatus::class,
            'nullable_status'                   => UserStatus::class,
            'cast_integer'                      => 'integer',
            'cast_float'                        => 'float',
            'cast_string'                       => 'string',
            'cast_timestamp'                    => 'timestamp',
            'cast_datetime'                     => 'datetime',
            'cast_immutable_date'               => 'immutable_date',
            'cast_formatted_date'               => 'date:Y-m-d',
            'cast_formatted_immutable_datetime' => 'immutable_datetime:Y-m-d H:i',
            'cast_encrypted'                    => 'encrypted',
            'cast_hashed'                       => 'hashed',
            'cast_stringable'                   => AsStringable::class,
            'cast_uri'                          => AsUri::class,
            'cast_json'                         => 'json',
            'cast_object'                       => 'object',
            'cast_encrypted_object'             => 'encrypted:object',
            'cast_array_object'                 => AsArrayObject::class,
            'cast_custom'                       => SluggedTitleCast::class,
        ];
    }
}
