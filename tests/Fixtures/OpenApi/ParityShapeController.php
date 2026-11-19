<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use SineMacula\ApiToolkit\Http\Resources\PolymorphicResource;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Reports\SalesSummaryResource;
use Tests\Fixtures\Reports\SalesSummaryRow;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture controller rendering the bodies an action documents with
 * #[ResponseSchema] rather than through a resource contract.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParityShapeController
{
    /**
     * List one record of each kind, unpaginated.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    #[ResponseSchema([UserResource::class, OrganizationResource::class, PostResource::class], collection: true)]
    public function feed(): AnonymousResourceCollection
    {
        return PolymorphicResource::collection(collect([User::query()->firstOrFail(), Organization::query()->firstOrFail(), Post::query()->firstOrFail()]));
    }

    /**
     * List the users a page at a time.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    #[ResponseSchema([UserResource::class, OrganizationResource::class, PostResource::class], collection: true)]
    public function pagedFeed(): AnonymousResourceCollection
    {
        return PolymorphicResource::collection(User::query()->orderBy('id')->paginate(2));
    }

    /**
     * List the monthly sales a page at a time.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    #[ResponseSchema(SalesSummaryResource::class, collection: true)]
    public function sales(): AnonymousResourceCollection
    {
        $rows = [new SalesSummaryRow('2026-06', 120, '15000.00'), new SalesSummaryRow('2026-07', 98, '12250.00')];

        return SalesSummaryResource::collection(new LengthAwarePaginator($rows, 2, 15, 1, ['path' => LengthAwarePaginator::resolveCurrentPath()]));
    }

    /**
     * Sign in, setting the refresh token cookie.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[ResponseHeader('Set-Cookie', 'The refresh token.', ['type' => 'string', 'pattern' => '^refresh_token='])]
    #[ResponseSchema([SignedInShape::class, PendingSignInShape::class])]
    public function signIn(): JsonResponse
    {
        return new JsonResponse(['data' => ['status' => 'signed_in', 'token' => 'access']], headers: ['Set-Cookie' => 'refresh_token=refresh; HttpOnly']);
    }
}
