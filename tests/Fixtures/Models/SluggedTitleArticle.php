<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture article model whose title accessor also reads the slug column.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('articles')]
final class SluggedTitleArticle extends Model
{
    /**
     * Get the title suffixed with the slug.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => $value . ' / ' . $this->slug,
        );
    }
}
