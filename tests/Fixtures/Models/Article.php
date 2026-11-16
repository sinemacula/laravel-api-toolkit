<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixture article model.
 *
 * A wide-table, soft-deleting model used to exercise the column-narrowing
 * safety set (primary key, soft-delete column, relation parent key, append
 * source) and the columns/bytes reduction assertion in isolation from User.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property string $summary
 * @property string $status
 * @property int $views
 * @property \Tests\Fixtures\Models\User|null $author
 * @property \Illuminate\Database\Eloquent\Collection<int, \Tests\Fixtures\Models\Article> $siblingArticles
 * @property \Illuminate\Database\Eloquent\Collection<int, \Tests\Fixtures\Models\Comment> $authorComments
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['user_id', 'title', 'slug', 'body', 'summary', 'status', 'views'])]
#[Table('articles')]
final class Article extends Model
{
    use SoftDeletes;

    /** @var array<int, string> */
    protected $appends = ['headline']; // @phpstan-ignore property.phpDocType

    /**
     * Get the article's author.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get every article by the same author, this one included.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Article, $this>
     */
    public function siblingArticles(): HasMany
    {
        return $this->hasMany(self::class, 'user_id', 'user_id');
    }

    /**
     * Get every comment by the article's author.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Comment, $this>
     */
    public function authorComments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id', 'user_id');
    }

    /**
     * Get the article headline derived from the title column.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function headline(): Attribute
    {
        return Attribute::make(
            get: fn (): string => mb_strtoupper($this->title),
        );
    }
}
