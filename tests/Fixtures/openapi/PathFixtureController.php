<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

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
final class PathFixtureController extends AuthorizedController
{
    /** @var string */
    public const string RESOURCE_MODEL = User::class;

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
     */
    public function show(): void {}

    /**
     * Create a resource.
     *
     * @return void
     */
    public function store(): void {}

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
