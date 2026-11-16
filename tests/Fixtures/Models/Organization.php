<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Fixture organization model.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['name', 'slug'])]
#[Table('organizations')]
final class Organization extends Model
{
    /**
     * Get the organization's users.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the posts written by the organization's users.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough<\Tests\Fixtures\Models\Post, \Tests\Fixtures\Models\User, $this>
     */
    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class);
    }
}
