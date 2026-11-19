<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixture user carrying the fields the API documentation's examples name.
 *
 * Reads the users table once the test serving the examples has added the name
 * parts, the age and the soft-delete column to it.
 *
 * @property int $id
 * @property string $name
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property int $age
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['name', 'first_name', 'last_name', 'email', 'age', 'organization_id'])]
#[Table('users')]
final class ManualUser extends Model
{
    use SoftDeletes;

    /**
     * Get the user's organization.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user's posts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    /**
     * Get the user's comments.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id');
    }
}
