<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Fixture note model.
 *
 * Carries every kind of key a repository write meets: plain and cast columns,
 * both mutator styles, a JSON column, a polymorphic parent and a polymorphic
 * many-to-many relation. The legacy mutator shadows a relation of its name.
 *
 * @property int $id
 * @property int|null $owner_id
 * @property int|null $noteable_id
 * @property string|null $noteable_type
 * @property int|null $primary_contact_id
 * @property string|null $title
 * @property string|null $slug
 * @property string|null $kind
 * @property int|null $priority
 * @property bool|null $pinned
 * @property \Tests\Fixtures\Enums\UserStatus|null $status
 * @property mixed $meta
 * @property array<string, mixed>|null $options
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('notes')]
final class Note extends Model
{
    /**
     * Get the user who owns the note.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get the model the note is attached to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the tags labelling the note.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany<\Tests\Fixtures\Models\Tag, $this>
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'labelable');
    }

    /**
     * Set the owner from the owning user's email address.
     *
     * @param  string  $email
     * @return void
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function setOwnerAttribute(string $email): void // phpcs:ignore SineMaculaLaravel.Eloquent.DisallowLegacyAttributeAccessor.Found
    {
        $this->attributes['owner_id'] = User::query()->where('email', $email)->value('id');
    }

    /**
     * Store the slug in its URL form.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function slug(): Attribute
    {
        return Attribute::make(
            set: static fn (string $slug): string => Str::slug($slug),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'pinned'   => 'boolean',
            'status'   => UserStatus::class,
            'meta'     => 'object',
            'options'  => 'array',
        ];
    }
}
