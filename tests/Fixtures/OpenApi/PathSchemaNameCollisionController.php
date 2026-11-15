<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;

/**
 * Fixture non-resource controller declaring shapes whose component names
 * collide.
 *
 * Each action names one shape whose component name is already derived by a
 * resource, a shared envelope, an enum or another shape. The alternate union is
 * documented only in the internal audience.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathSchemaNameCollisionController
{
    /**
     * Respond with a shape named like the user resource.
     *
     * @return void
     */
    #[ResponseSchema(UserNamedShape::class)]
    public function user(): void {}

    /**
     * Respond with a shape named like a shared pagination envelope.
     *
     * @return void
     */
    #[ResponseSchema(EnvelopeNamedShape::class)]
    public function envelope(): void {}

    /**
     * Respond with a shape named like the user status enum.
     *
     * @return void
     */
    #[ResponseSchema(StatusNamedShape::class)]
    public function status(): void {}

    /**
     * Respond with a shape sharing the signed-in shape's short name.
     *
     * @return void
     */
    #[ResponseSchema(Alternate\SignedInShape::class)]
    public function alternate(): void {}

    /**
     * Respond, in the internal audience only, with either the alternate
     * signed-in shape or the pending shape.
     *
     * @return void
     */
    #[DocumentedIn('internal')]
    #[ResponseSchema([Alternate\SignedInShape::class, PendingSignInShape::class])]
    #[Undocumented]
    public function alternateUnion(): void {}
}
