<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Fixture controller whose bodies differ in whether an empty one passes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathBodyPresenceController
{
    /**
     * Upload a document through a file rule object.
     *
     * @return void
     */
    #[RequestSchema(FileObjectRequestInput::class)]
    public function upload(): void {}

    /**
     * Update a resource from optional fields only.
     *
     * @return void
     */
    #[RequestSchema(OptionalRequestInput::class)]
    public function optional(): void {}

    /**
     * Create a resource from either of two fields.
     *
     * @return void
     */
    #[RequestSchema(EitherRequestInput::class)]
    public function either(): void {}

    /**
     * Create a resource from one field of each of two pairs.
     *
     * @return void
     */
    #[RequestSchema(EitherPairsRequestInput::class)]
    public function eitherPairs(): void {}

    /**
     * Create a resource whose fields are required only alongside others.
     *
     * @return void
     */
    #[RequestSchema(ConditionalRequestInput::class)]
    public function conditional(): void {}
}
