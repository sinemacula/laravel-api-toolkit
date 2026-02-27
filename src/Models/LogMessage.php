<?php

namespace SineMacula\ApiToolkit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * The log message model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class LogMessage extends Model
{
    use HasUuids, MassPrunable;

    /** @var bool Indicates if the model should be timestamped */
    public $timestamps = false;

    /** @var non-empty-string The table associated with the model */
    protected $table = 'logs';

    /** @var array<int, string> The attributes that are mass assignable */
    protected $fillable = ['level', 'message', 'context', 'created_at'];

    /** @var non-empty-string The storage format of the model's date columns */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Get the prunable model query.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function prunable(): Builder
    {
        /** @phpstan-ignore staticMethod.notFound (Eloquent model provides where() via magic) */
        return static::where('created_at', '<=', now()->subDays(config('logging.channels.database.days')));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level'      => 'string',
            'message'    => 'string',
            'context'    => AsArrayObject::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
