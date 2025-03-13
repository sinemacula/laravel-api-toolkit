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
 * @copyright   2025 Sine Macula Limited.
 */
class LogMessage extends Model
{
    use HasUuids, MassPrunable;

    /** @var bool Indicates if the model should be timestamped */
    public $timestamps = false;

    /** @var string The table associated with the model */
    protected $table = 'logs';

    /** @var array<int, string> The attributes that are mass assignable */
    protected $fillable = ['level', 'message', 'context', 'created_at'];

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
            'created_at' => 'immutable_datetime'
        ];
    }

    /**
     * Get the prunable model query.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDays(config('logging.channels.database.days')));
    }
}
