<?php

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Fixture tag model.
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class Tag extends Model
{
    /** @var string|null */
    protected $table = 'tags';

    /** @var array<int, string> */
    protected $fillable = ['name'];

    /**
     * Get the posts associated with the tag.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Tests\Fixtures\Models\Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /**
     * Get the posts associated with the tag via a polymorphic many-to-many.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany<\Tests\Fixtures\Models\Post, $this>
     */
    public function articles(): MorphToMany
    {
        return $this->morphToMany(Post::class, 'taggable');
    }
}
