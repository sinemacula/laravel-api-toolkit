<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller exposing the full REST action set.
 *
 * Carries one method per REST action plus a non-REST action, so the path
 * builder can be exercised against every response mapping and the skip rule for
 * unsupported actions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathFixtureController extends AuthorizedController
{
    /**
     * List the resources.
     *
     * @return void
     */
    public function index(): void {}

    /**
     * Show a single resource.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\NotFoundException
     */
    public function show(): void
    {
        throw new NotFoundException;
    }

    /**
     * Create a resource.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\ConflictException
     */
    public function store(): void
    {
        throw new ConflictException;
    }

    /**
     * Update a resource.
     *
     * @return void
     */
    public function update(): void {}

    /**
     * Delete a resource.
     *
     * @return void
     */
    public function destroy(): void {}

    /**
     * A non-REST action that must be skipped by the path builder.
     *
     * @return void
     */
    public function export(): void {}
}
