<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseExample;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Fixture controller declaring response examples.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathResponseExampleController
{
    /**
     * Respond with a shape, showing two named examples.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::OK, 'signed-in', 'auth/signed-in.json')]
    #[ResponseExample(HttpStatus::OK, 'remembered', 'auth/remembered.json')]
    #[ResponseSchema(SignedInShape::class)]
    public function signIn(): void {}

    /**
     * Respond with either of two shapes, showing one example of each.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::OK, 'pending', 'auth/pending.json')]
    #[ResponseExample(HttpStatus::OK, 'signed-in', 'auth/signed-in.json')]
    #[ResponseSchema([SignedInShape::class, PendingSignInShape::class])]
    public function challenge(): void {}

    /**
     * Respond with either of two shapes no constant tells apart.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::OK, 'either', 'either.json')]
    #[ResponseSchema([WidgetShape::class, SignedInShape::class])]
    public function ambiguous(): void {}

    /**
     * Respond with a shape under a declared status.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::ACCEPTED, 'queued', 'auth/signed-in.json')]
    #[ResponseSchema(SignedInShape::class)]
    #[ResponseStatus(HttpStatus::ACCEPTED)]
    public function queue(): void {}

    /**
     * Respond with an undocumented body.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::OK, 'anything', 'anything.json')]
    public function undocumented(): void {}

    /**
     * Respond with a shape, naming a status the action does not answer with.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::CREATED, 'created', 'auth/signed-in.json')]
    #[ResponseSchema(SignedInShape::class)]
    public function misdirected(): void {}

    /**
     * Delete, with no body, naming the no-content status.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::NO_CONTENT, 'deleted', 'auth/signed-in.json')]
    #[ResponseSchema(SignedInShape::class)]
    public function destroy(): void {}

    /**
     * Respond with a shape, declaring one example name twice.
     *
     * @return void
     */
    #[ResponseExample(HttpStatus::OK, 'signed-in', 'auth/signed-in.json')]
    #[ResponseExample(HttpStatus::OK, 'signed-in', 'auth/remembered.json')]
    #[ResponseSchema(SignedInShape::class)]
    public function duplicated(): void {}

    /**
     * Respond with a shape, declaring no example.
     *
     * @return void
     */
    #[ResponseSchema(SignedInShape::class)]
    public function bare(): void {}
}
