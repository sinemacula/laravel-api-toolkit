<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Article extends Model
{
    use SoftDeletes;

    /** @var string|null */
    protected $table = 'articles';

    /** @var array<int, string> */
    protected $fillable = ['user_id', 'title', 'slug', 'body', 'summary', 'status', 'views'];

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
     * Get the article headline derived from the title column.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    public function headline(): Attribute
    {
        return Attribute::make(
            get: fn (): string => mb_strtoupper($this->title),
        );
    }
}
