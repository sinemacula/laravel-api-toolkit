<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\LedgerEntry;

/**
 * Fixture authorized controller over a model with a multi-word name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(LedgerEntry::class)]
final class LedgerEntryController extends AuthorizedController
{
    /**
     * Update a ledger entry. Guarded by the update ability.
     *
     * @param  \Tests\Fixtures\Models\LedgerEntry  $ledgerEntry
     * @return \Illuminate\Http\JsonResponse
     *
     * @SuppressWarnings("php:S1172")
     */
    public function update(LedgerEntry $ledgerEntry): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }
}
