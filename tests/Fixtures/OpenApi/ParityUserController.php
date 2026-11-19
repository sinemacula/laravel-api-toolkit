<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;

/**
 * Fixture resource controller whose reads render the resource the map names for
 * users and answer the toolkit's query parameters, so a response and a refused
 * query each take the shape the document gives them.
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
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function index(UserRepository $users): ApiResourceCollection
    {
        return new ApiResourceCollection($users->withApiCriteria()->paginate(), $this->resourceOf($users));
    }

    /**
     * Show a bound user.
     *
     * @param  \Tests\Fixtures\Repositories\UserRepository  $users
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResource
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function show(UserRepository $users, User $user): ApiResource
    {
        $resource = $this->resourceOf($users);

        return new $resource($user, true);
    }

    /**
     * Resolve the resource the map names for users.
     *
     * @param  \Tests\Fixtures\Repositories\UserRepository  $users
     * @return class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resourceOf(UserRepository $users): string
    {
        $resource = $users->getResourceClass();

        assert(is_string($resource) && is_a($resource, ApiResource::class, true));

        return $resource;
    }
}
