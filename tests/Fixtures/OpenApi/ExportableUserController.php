<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ExportableUserResource;

/**
 * Fixture resource controller listing users through a resource the exporter can
 * render in every negotiated format.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class, except: ['index'])]
final class ExportableUserController extends AuthorizedController
{
    /**
     * List the users.
     *
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     */
    public function index(): ApiResourceCollection
    {
        return new ApiResourceCollection(User::query()->paginate(), ExportableUserResource::class);
    }
}
