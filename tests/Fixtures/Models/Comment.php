<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixture comment model.
 *
 * A soft-deleting child used to prove that trashed visibility cascades to an
 * eager-loaded relation only when the child's own resource opts in.
 *
 * @property int $id
 * @property int $user_id
 * @property string $body
 * @property \Tests\Fixtures\Models\User|null $author
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Comment extends Model
{
    use SoftDeletes;

    /** @var string|null */
    protected $table = 'comments';

    /** @var array<int, string> */
    protected $fillable = ['user_id', 'body'];

    /**
     * Get the comment's author.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
