<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;
use Tests\Fixtures\Models\Tag;

/**
 * Fixture authorized controller documented only in the internal audience.
 *
 * Maps to the tag leaf resource and combines a blanket #[Undocumented] with an
 * internal inclusion, the canonical "only in x" expression: the route and its
 * schema appear in the internal audience and nowhere else, so the tag schema
 * never leaks into another audience's components.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(Tag::class)]
#[DocumentedIn('internal')]
#[Undocumented]
final class InternalOnlyTagController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}
}
