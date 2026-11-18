<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use SineMacula\ApiToolkit\Concerns\HasVersion;
use SineMacula\ApiToolkit\Contracts\Versioned;

/**
 * Fixture model whose every write advances its version.
 *
 * @property int $id
 * @property string $title
 * @property int $version
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @phpstan-ignore sineMaculaLaravel.modelBehaviour
 */
#[Fillable(['title'])]
#[Table('documents')]
final class Document extends Model implements Versioned
{
    use HasVersion;

    /**
     * Get the document's tags.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Tests\Fixtures\Models\Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Get the same tags through a model that touches its documents.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Tests\Fixtures\Models\TouchingTag, $this>
     */
    public function touchingTags(): BelongsToMany
    {
        return $this->belongsToMany(TouchingTag::class, 'document_tag', 'document_id', 'tag_id');
    }
}
