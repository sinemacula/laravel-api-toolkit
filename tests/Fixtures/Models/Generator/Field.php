<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models\Generator;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model whose short name matches a class a generated resource imports.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('posts')]
final class Field extends Model {}
