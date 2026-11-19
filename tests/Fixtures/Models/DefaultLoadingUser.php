<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Fixture user model loading its posts by default.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @inheritable
 */
#[Table('users')]
class DefaultLoadingUser extends Model
{
    /** @var array<int, string> */
    protected $with = ['posts']; // @phpstan-ignore property.phpDocType

    /**
     * Return the user's posts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    /**
     * Return a user, whose own posts a dotted path reaches.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id');
    }

    /**
     * Return the user's organization.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Return a model of any type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the user's label, an accessor rather than a relation.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    public function label(): Attribute
    {
        return Attribute::make(get: static fn (): string => 'Label');
    }
}
