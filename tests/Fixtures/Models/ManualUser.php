<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixture user carrying the fields the API documentation's examples name.
 *
 * Reads the users table once the test serving the examples has added the name
 * parts, age, location, start time, tags and soft-delete column to it.
 *
 * @property int $id
 * @property string $name
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property int $age
 * @property string $status
 * @property float|null $latitude
 * @property float|null $longitude
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property string|null $tags
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['name', 'first_name', 'last_name', 'email', 'age', 'status', 'latitude', 'longitude', 'starts_at', 'organization_id'])]
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

    /**
     * Scope the query to users whose start time is still ahead.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now());
    }

    /**
     * Scope the query to users within a box of km kilometres about the point.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @param  float  $lat
     * @param  float  $lng
     * @param  int  $km
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    #[Scope]
    protected function near(Builder $query, float $lat, float $lng, int $km): void
    {
        $degrees = $km / 111;

        $query->whereBetween('latitude', [$lat - $degrees, $lat + $degrees])
            ->whereBetween('longitude', [$lng - $degrees, $lng + $degrees]);
    }
}
