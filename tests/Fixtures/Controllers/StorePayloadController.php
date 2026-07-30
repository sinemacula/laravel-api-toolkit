<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Tests\Fixtures\Input\StorePayload;

/**
 * Fixture controller that type-hints a Payload argument.
 *
 * Receives a StorePayload resolved and validated by the container, and echoes
 * the hydrated values so a test can assert the action saw typed input.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StorePayloadController
{
    /**
     * Return the hydrated payload values as a JSON response.
     *
     * @param  \Tests\Fixtures\Input\StorePayload  $payload
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(StorePayload $payload): JsonResponse
    {
        return new JsonResponse([
            'title'    => $payload->title,
            'quantity' => $payload->quantity,
            'status'   => $payload->status?->value,
        ]);
    }
}
