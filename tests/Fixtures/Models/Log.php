<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture log model backed by the JSON-column logs table.
 *
 * The `context` column is a JSON payload cast to an array, giving a filterable
 * JSON column for the `$contains` containment operator. The primary key is a
 * string UUID generated on create, and the table carries only a `created_at`
 * timestamp, so automatic timestamps are disabled.
 *
 * @property string $id
 * @property string $level
 * @property string $message
 * @property array<string, mixed>|null $context
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Log extends Model
{
    use HasUuids;

    /** @var bool */
    public $incrementing = false;

    /** @var bool */
    public $timestamps = false;

    /** @var string|null */
    protected $table = 'logs';

    /** @var string */
    protected $keyType = 'string';

    /** @var array<int, string> */
    protected $fillable = ['level', 'message', 'context', 'created_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }
}
