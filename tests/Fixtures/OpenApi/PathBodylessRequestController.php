<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Fixture controller whose directives reach no documentable body.
 *
 * Carries one action naming a rules source that describes a top-level list
 * rather than named fields, and one naming a class that is no rules source at
 * all, so both dead ends can be exercised through the directive branch.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathBodylessRequestController
{
    /**
     * Create a resource from a top-level list payload.
     *
     * @return void
     */
    #[RequestSchema(TopLevelListRequestInput::class)]
    public function listPayload(): void {}

    /**
     * Create a resource from a directive naming no rules source.
     *
     * @return void
     */
    #[RequestSchema(NonRulesRequestSource::class)]
    public function foreignSource(): void {}
}
