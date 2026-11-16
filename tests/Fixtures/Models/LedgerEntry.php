<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model whose timestamps use custom column names.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Table('ledger_entries')]
final class LedgerEntry extends Model
{
    /** @var string */
    public const string CREATED_AT = 'created';

    /** @var string */
    public const string UPDATED_AT = 'modified';
}
