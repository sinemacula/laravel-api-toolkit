<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\DocumentedIn;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\Undocumented;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PlainJsonResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture non-resource controller declaring response bodies.
 *
 * Carries one action per #[ResponseSchema] resolution branch: a registered
 * resource reference, its collection variant, a self-describing DTO exported as
 * a component, a plain class that resolves to nothing, an unregistered resource
 * whose reference would dangle, and a bare action carrying no directive at all.
 * The union actions name several classes: two discriminated shapes, a resource
 * beside a shape, a union of collection items, repeated members, distinct
 * classes describing one shape, shapes sharing or lacking a discriminating
 * constant, two resources, a resource that pins no type, an internal-only
 * union, and unions holding a plain class or an unregistered resource that
 * leave the body undocumented.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathResponseSchemaController
{
    /**
     * Respond with a single registered resource.
     *
     * @return void
     */
    #[ResponseSchema(UserResource::class)]
    public function single(): void {}

    /**
     * Respond with a collection of a registered resource.
     *
     * @return void
     */
    #[ResponseSchema(UserResource::class, collection: true)]
    public function list(): void {}

    /**
     * Respond with a self-describing DTO.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    public function widget(): void {}

    /**
     * Respond with a collection of a self-describing DTO.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class, collection: true)]
    public function widgets(): void {}

    /**
     * Respond with a plain class that is neither a resource nor
     * self-describing.
     *
     * @return void
     */
    #[ResponseSchema(User::class)]
    public function plain(): void {}

    /**
     * Respond with a resource absent from the resource map.
     *
     * @return void
     */
    #[ResponseSchema(PostResource::class)]
    public function unregistered(): void {}

    /**
     * Respond with either of two self-describing shapes.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, PendingSignInShape::class])]
    public function union(): void {}

    /**
     * Respond with either a registered resource or a self-describing shape.
     *
     * @return void
     */
    #[ResponseSchema([UserResource::class, SignedInShape::class])]
    public function mixed(): void {}

    /**
     * Respond with a collection whose items are either of two shapes.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, PendingSignInShape::class], collection: true)]
    public function unionList(): void {}

    /**
     * Respond with a registered resource named twice.
     *
     * @return void
     */
    #[ResponseSchema([UserResource::class, UserResource::class])]
    public function duplicated(): void {}

    /**
     * Respond with a union repeating its first member after the second.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, PendingSignInShape::class, SignedInShape::class])]
    public function repeated(): void {}

    /**
     * Respond with a union repeating its first member before naming another.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, SignedInShape::class, PendingSignInShape::class])]
    public function repeatedBeforeAnother(): void {}

    /**
     * Respond with a union of two distinct classes describing the same shape.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, SessionStartedShape::class])]
    public function equivalent(): void {}

    /**
     * Respond with either a shape or a shape named by #[SchemaName].
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, MfaChallengeShape::class])]
    public function named(): void {}

    /**
     * Respond with either of two shapes pinning one status constant.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, TokenRefreshedShape::class])]
    public function clashing(): void {}

    /**
     * Respond with either of two registered resources.
     *
     * @return void
     */
    #[ResponseSchema([UserResource::class, OrganizationResource::class])]
    public function resources(): void {}

    /**
     * Respond with either a shape pinning no constant or a pinned shape.
     *
     * @return void
     */
    #[ResponseSchema([WidgetShape::class, SignedInShape::class])]
    public function unpinned(): void {}

    /**
     * Respond with either a registered resource or a plain registered JSON
     * resource.
     *
     * @return void
     */
    #[ResponseSchema([UserResource::class, PlainJsonResource::class])]
    public function plainResource(): void {}

    /**
     * Respond, in the internal audience only, with either of two shapes.
     *
     * @return void
     */
    #[DocumentedIn('internal')]
    #[ResponseSchema([SignedInShape::class, MfaChallengeShape::class])]
    #[Undocumented]
    public function internalUnion(): void {}

    /**
     * Respond with a union whose second member is a plain class.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, User::class])]
    public function partlyPlain(): void {}

    /**
     * Respond with a union whose second member is an unregistered resource.
     *
     * @return void
     */
    #[ResponseSchema([SignedInShape::class, PostResource::class])]
    public function partlyUnregistered(): void {}

    /**
     * Respond without declaring a response body.
     *
     * @return void
     */
    public function bare(): void {}
}
