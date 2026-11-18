<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Fixture post model with a to-one relation of each kind, some declaring a
 * default model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('posts')]
final class AuthoredPost extends Model
{
    /**
     * Get the post's author, or an empty user when there is none.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withDefault();
    }

    /**
     * Get the post's editor, or an anonymous user when there is none.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withDefault(static fn (User $user): User => $user->forceFill(['name' => 'Anonymous']));
    }

    /**
     * Get the post's reviewer, whose empty default Eloquent treats as none.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withDefault([]);
    }

    /**
     * Get the author's profile, or a placeholder when there is none.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\Tests\Fixtures\Models\Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id', 'user_id')->withDefault(['bio' => 'No biography yet']);
    }

    /**
     * Get the author's profile through the author.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough<\Tests\Fixtures\Models\Profile, \Tests\Fixtures\Models\User, $this>
     */
    public function authorProfile(): HasOneThrough
    {
        return $this->hasOneThrough(Profile::class, User::class, 'id', 'user_id', 'user_id', 'id');
    }

    /**
     * Get the note pinned to the post.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne<\Tests\Fixtures\Models\Note, $this>
     */
    public function pinnedNote(): MorphOne
    {
        return $this->morphOne(Note::class, 'noteable');
    }
}
