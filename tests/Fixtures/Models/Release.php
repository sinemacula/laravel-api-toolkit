<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Enums\Platform;

/**
 * Fixture release model, casting its platform to a pure enum.
 *
 * @property int $id
 * @property string $name
 * @property \Tests\Fixtures\Enums\Platform $platform
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Fillable(['name', 'platform'])]
#[Table('releases')]
final class Release extends Model
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
            'platform' => Platform::class,
        ];
    }
}
