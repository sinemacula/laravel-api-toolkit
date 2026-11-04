<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture non-resource controller declaring response bodies.
 *
 * Carries one action per #[ResponseSchema] resolution branch: a registered
 * resource reference, its collection variant, an inlined self-describing DTO, a
 * plain class that resolves to nothing, an unregistered resource whose
 * reference would dangle, and a bare action carrying no directive at all. The
 * union actions name several classes: two fragments, a resource beside a
 * fragment, a union of collection items, repeated members, and unions holding a
 * plain class or an unregistered resource that leave the body undocumented.
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
     * Respond with an inlined self-describing DTO.
     *
     * @return void
     */
    #[ResponseSchema(WidgetShape::class)]
    public function widget(): void {}

    /**
     * Respond with a collection of an inlined self-describing DTO.
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
