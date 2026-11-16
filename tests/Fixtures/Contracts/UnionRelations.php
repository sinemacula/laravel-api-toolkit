<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Fixture relation methods that declare union return types.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface UnionRelations
{
    /**
     * Get a tag through a relation that loads one record either way.
     *
     * @return (
     *     \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\Tag, \Tests\Fixtures\Models\User>
     *     |\Illuminate\Database\Eloquent\Relations\HasOne<\Tests\Fixtures\Models\Tag, \Tests\Fixtures\Models\User>
     * )
     */
    public function either(): BelongsTo|HasOne;

    /**
     * Get tags through a relation that may load one record or many.
     *
     * @return (
     *     \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Tag, \Tests\Fixtures\Models\User>
     *     |\Illuminate\Database\Eloquent\Relations\HasOne<\Tests\Fixtures\Models\Tag, \Tests\Fixtures\Models\User>
     * )
     */
    public function varied(): HasMany|HasOne;
}
