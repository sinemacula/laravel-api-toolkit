<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model without timestamps, over a table that has no timestamp columns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('import_rows', timestamps: false)]
final class ImportRow extends Model {}
