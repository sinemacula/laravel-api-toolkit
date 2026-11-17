<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Tests\Fixtures\Models\LedgerEntry;

/**
 * Fixture ledger entry policy allowing updates only while an entry is open.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LedgerEntryPolicy
{
    /**
     * Determine whether the actor may update the given ledger entry.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $actor
     * @param  \Tests\Fixtures\Models\LedgerEntry  $ledgerEntry
     * @return \Illuminate\Auth\Access\Response
     *
     * @SuppressWarnings("php:S1172")
     */
    public function update(Authenticatable $actor, LedgerEntry $ledgerEntry): Response
    {
        return $ledgerEntry->getAttribute('label') === 'open' ? Response::allow() : Response::deny();
    }
}
