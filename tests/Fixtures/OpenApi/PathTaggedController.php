<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\Tag;

/**
 * Fixture plain controller carrying explicit #[Tag] directives.
 *
 * The class-level tag names the group for actions that declare none, while a
 * method-level tag overrides it for the action it decorates, so the path
 * builder can be exercised against both tag precedence levels.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[Tag('Widgets')]
final class PathTaggedController
{
    /**
     * List the resources under the class-level tag.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Report under an overriding method-level tag.
     *
     * @return void
     */
    #[Tag('Reports')]
    public function report(): void {}
}
