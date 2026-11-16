<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Fixture owner model whose relations and attribute handling feed the aggregate
 * validation rule.
 *
 * Three aggregate attributes are claimed by a cast, a setter and an attribute
 * setter, so an aggregate loaded under them would not read back unchanged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[Table('aggregate_owners')]
final class AggregateOwner extends Model
{
    /**
     * Get the lines the owner aggregates.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\AggregateLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AggregateLine::class, 'owner_id');
    }

    /**
     * Get the lines reached through the owner's posts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough<\Tests\Fixtures\Models\AggregateLine, \Tests\Fixtures\Models\Post, $this>
     */
    public function postLines(): HasManyThrough
    {
        return $this->hasManyThrough(AggregateLine::class, Post::class, 'user_id', 'owner_id');
    }

    /**
     * Get the polymorphic subject of the owner.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Write the setter-claimed aggregate attribute through a transformation.
     *
     * @param  mixed  $value
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function setAggregateSetterClaimedAttribute(mixed $value): void // phpcs:ignore SineMaculaLaravel.Eloquent.DisallowLegacyAttributeAccessor.Found
    {
        $this->attributes['aggregate_setter_claimed'] = is_numeric($value) ? $value * 2 : $value;
    }

    /**
     * Write the attribute-setter-claimed aggregate attribute through a
     * transformation.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function aggregateMutatorClaimed(): Attribute
    {
        return Attribute::make(set: static fn (mixed $value): mixed => is_numeric($value) ? $value * 2 : $value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'aggregate_cast_claimed' => 'integer',
        ];
    }
}
