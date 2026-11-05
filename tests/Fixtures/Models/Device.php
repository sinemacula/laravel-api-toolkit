<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Fixture device model.
 *
 * Casts one column to a pure enum and one to a backed enum, so a test can show
 * the pure-enum wire form translated without the backed column being touched.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property \Tests\Fixtures\Enums\Platform|null $platform
 * @property \Tests\Fixtures\Enums\UserStatus|null $status
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['user_id', 'name', 'platform', 'status'])]
#[Table('devices')]
final class Device extends Model
{
    /**
     * Get the user that owns the device.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'platform' => Platform::class,
            'status'   => UserStatus::class,
        ];
    }
}
