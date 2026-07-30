<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller exercising the error-response reflection.
 *
 * Read actions declare a request body to prove the verb gate suppresses it, a
 * write action declares one to prove it survives, and the show action throws
 * two exceptions sharing a status plus one whose status coincides with a
 * baseline phrase so the merge, phrase, and slice behaviour can be pinned.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathErrorController extends AuthorizedController
{
    /**
     * List the resources, declaring a body a read verb must never carry.
     *
     * @return void
     */
    #[RequestSchema(ArticleRequestInput::class)]
    public function index(): void {}

    /**
     * Create a resource from a declared request-body source.
     *
     * @return void
     */
    #[RequestSchema(ArticleRequestInput::class)]
    public function store(): void {}

    /**
     * Show a resource, throwing exceptions the error reflection must document.
     *
     * @param  int  $variant
     * @return never
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidInputException
     * @throws \SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException
     * @throws \SineMacula\ApiToolkit\Exceptions\MaintenanceModeException
     */
    public function show(int $variant): never
    {
        throw match ($variant) {
            1       => new InvalidInputException,
            2       => new ServiceUnavailableException,
            default => new MaintenanceModeException,
        };
    }

    /**
     * Update a resource, throwing a class the reflection must ignore.
     *
     * @return never
     *
     * @throws \Tests\Fixtures\OpenApi\NonApiStatusException
     */
    public function update(): never
    {
        throw new NonApiStatusException;
    }
}
