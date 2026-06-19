<?php

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture country model with a non-incrementing string primary key.
 *
 * Used to exercise relation sync against a related model whose primary key is
 * not `id`.
 *
 * @method static static create(array<string, mixed> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class Country extends Model
{
    /** @var bool */
    public $incrementing = false;

    /** @var string|null */
    protected $table = 'countries';

    /** @var string */
    protected $primaryKey = 'code';

    /** @var string */
    protected $keyType = 'string';

    /** @var array<int, string> */
    protected $fillable = ['code', 'name'];
}
