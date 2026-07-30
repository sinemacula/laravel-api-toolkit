<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\NotDocumentedIn;

/**
 * Fixture controller excluded from the public audience at the class level.
 *
 * Used to prove class-level exclusion, and that a method-level inclusion
 * overrides it for the audiences the method names.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[NotDocumentedIn('public')]
final class NotDocumentedInPublicController
{
    /**
     * Action inheriting only the class-level exclusion.
     *
     * @return void
     */
    public function none(): void {}

    /**
     * Action re-including the public audience at the method level.
     *
     * @return void
     */
    #[DocumentedIn('public')]
    public function documentedInPublic(): void {}
}
