<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Query\ApiQuerySnapshot;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;

/**
 * Fixture job that reads users through the API criteria and renders each one,
 * under the snapshot it carries.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryReplayingJob implements ShouldQueue
{
    /** @var mixed The users the last run rendered */
    public static mixed $rendered = null;

    /**
     * Create a new query replaying job instance.
     *
     * @param  \SineMacula\ApiToolkit\Query\ApiQuerySnapshot|null  $query
     * @return void
     */
    public function __construct(

        /** The query to replay, or null to read under none */
        public readonly ?ApiQuerySnapshot $query = null,
    ) {}

    /**
     * Read and render the users the query selects.
     *
     * @param  \Tests\Fixtures\Repositories\UserRepository  $users
     * @return void
     */
    public function handle(UserRepository $users): void
    {
        $read = static fn (): array => $users->usingResource(UserResource::class)
            ->withApiCriteria()
            ->lazyById()
            ->map(static fn (Model $user): array => (new UserResource($user))->resolve())
            ->all();

        self::$rendered = $this->query === null ? $read() : ApiQuery::using($this->query, $read);
    }
}
