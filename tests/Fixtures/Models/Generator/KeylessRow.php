<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models\Generator;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model with no primary key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('import_rows', key: false)]
final class KeylessRow extends Model {}
