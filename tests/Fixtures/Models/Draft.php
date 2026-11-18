<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Concerns\HasVersion;
use SineMacula\ApiToolkit\Contracts\Versioned;

/**
 * Fixture model keeping its version in a column of its own naming.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('drafts')]
#[Unguarded]
#[WithoutTimestamps]
final class Draft extends Model implements Versioned
{
    use HasVersion;

    /** @var string The column holding the version */
    public const string VERSION = 'revision';
}
