<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;

/**
 * Fixture controller whose bodies exercise example generation.
 *
 * Carries a body with author examples, one from a FormRequest giving an
 * example, one requiring a value no generated example satisfies, one whose
 * author example its schema refuses, and a response requiring an undocumented
 * field.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PathExampleController
{
    /**
     * Sign a member up from a rules source giving field examples.
     *
     * @return void
     */
    #[RequestSchema(SignUpRequestInput::class)]
    public function signUp(): void {}

    /**
     * Rename a member from a FormRequest giving a field example.
     *
     * @param  \Tests\Fixtures\OpenApi\ExampledFormRequest  $request
     * @return void
     */
    public function rename(ExampledFormRequest $request): void {}

    /**
     * Redeem a code no generated value satisfies.
     *
     * @return void
     */
    #[RequestSchema(PatternedRequestInput::class)]
    public function redeem(): void {}

    /**
     * Redeem a code whose author example its pattern refuses.
     *
     * @return void
     */
    #[RequestSchema(MisexampledRequestInput::class)]
    public function misredeem(): void {}

    /**
     * Respond with a shape requiring an undocumented field.
     *
     * @return void
     */
    #[ResponseSchema(UndocumentedFieldShape::class)]
    public function opaque(): void {}
}
