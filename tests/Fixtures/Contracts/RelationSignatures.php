<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Fixture relation method signatures, one per to-one kind and two unions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface RelationSignatures
{
    /**
     * Get the profile.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\Tests\Fixtures\Models\Profile, \Tests\Fixtures\Models\User>
     */
    public function profile(): HasOne;

    /**
     * Get the latest note.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne<\Tests\Fixtures\Models\Comment, \Tests\Fixtures\Models\User>
     */
    public function note(): MorphOne;

    /**
     * Get the organization.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\Organization, \Tests\Fixtures\Models\User>
     */
    public function organization(): BelongsTo;

    /**
     * Get the commented subject.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, \Tests\Fixtures\Models\User>
     */
    public function subject(): MorphTo;

    /**
     * Get the first profile through the organization.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough<\Tests\Fixtures\Models\Profile, \Tests\Fixtures\Models\Organization, \Tests\Fixtures\Models\User>
     */
    public function firstProfile(): HasOneThrough;

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
