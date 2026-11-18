<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture resource controller whose reads answer the toolkit's query
 * parameters, so a refused query fails the way the document says it can.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[AuthorizesResource(User::class, except: ['index', 'show'])]
final class ParityUserController extends AuthorizedController
{
    /**
     * List the users the query selects.
     *
     * @param  \Tests\Fixtures\Repositories\UserRepository  $users
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     */
    public function index(UserRepository $users): ApiResourceCollection
    {
        return new ApiResourceCollection($users->usingResource(UserResource::class)->withApiCriteria()->paginate(), UserResource::class);
    }

    /**
     * Show a bound user.
     *
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \Tests\Fixtures\Resources\UserResource
     */
    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }
}
