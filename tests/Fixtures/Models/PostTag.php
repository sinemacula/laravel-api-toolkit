<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Tests\Fixtures\Enums\TagRole;

/**
 * Fixture post-tag pivot, which casts the tag's role and tagging time.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PostTag extends Pivot
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'role'      => TagRole::class,
            'tagged_at' => 'datetime',
        ];
    }
}
