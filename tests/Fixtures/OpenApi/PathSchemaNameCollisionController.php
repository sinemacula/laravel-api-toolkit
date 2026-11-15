<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;
use Tests\Fixtures\Enums\SelfDescribingPlan;

/**
 * Fixture non-resource controller declaring shapes whose component names
 * collide.
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
     * Accept a plan and respond with the plan enum as a shape.
     *
     * @return void
     */
    #[RequestSchema(PlanRequestInput::class)]
    #[ResponseSchema(SelfDescribingPlan::class)]
    public function plan(): void {}

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
