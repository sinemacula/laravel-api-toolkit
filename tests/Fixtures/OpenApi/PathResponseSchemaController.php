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
 * reference would dangle, and a bare action carrying no directive at all.
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
     * Respond without declaring a response body.
     *
     * @return void
     */
    public function bare(): void {}
}
