<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;

/**
 * Fixture authorized controller exposing the two reflection gaps.
 *
 * Declares a mapped model so its routes take the full resource contract, then
 * carries an action with no doc comment while never declaring the destroy
 * action a route may still point at, so the documented throws can be reflected
 * against both an undocumented and an absent action.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class)]
final class PathReflectionGapController extends AuthorizedController
{
    // phpcs:disable Squiz.Commenting.FunctionComment.Missing
    public function index(): void {}
    // phpcs:enable Squiz.Commenting.FunctionComment.Missing
}
