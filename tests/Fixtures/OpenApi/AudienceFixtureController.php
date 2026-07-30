<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\NotDocumentedIn;

/**
 * Fixture controller with no class-level audience directives.
 *
 * Each method carries a distinct method-level directive permutation so the
 * audience resolver can be exercised against method attributes in isolation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AudienceFixtureController
{
    /**
     * Action with no audience directives.
     *
     * @return void
     */
    public function none(): void {}

    /**
     * Action included in the partner audience.
     *
     * @return void
     */
    #[DocumentedIn('partner')]
    public function documentedInPartner(): void {}

    /**
     * Action included in the public audience.
     *
     * @return void
     */
    #[DocumentedIn('public')]
    public function documentedInPublic(): void {}

    /**
     * Action both included in and excluded from the same audience.
     *
     * @return void
     */
    #[DocumentedIn('partner')]
    #[NotDocumentedIn('partner')]
    public function conflict(): void {}
}
