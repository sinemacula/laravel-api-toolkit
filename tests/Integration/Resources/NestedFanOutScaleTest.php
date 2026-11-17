<?php

declare(strict_types = 1);

namespace Tests\Integration\Resources;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RelationWindow;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\UserGraphSeeder;
use Tests\TestCase;

/**
 * Pins the cost of a page whose two embedded to-many levels are each filled to
 * the default relation limit.
 *
 * The test runs in a process of its own, so its peak is measured against no
 * earlier test's memory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EagerLoadPlanner::class)]
#[CoversClass(RelationWindow::class)]
#[RunTestsInSeparateProcesses]
final class NestedFanOutScaleTest extends TestCase
{
    use RecordsStatements;

    /** @var int The users on the page */
    private const int USERS = 8;

    /** @var int The default relation limit, reached by the posts of each user and the tags of each post */
    private const int PER_PARENT = 25;

    /** @var int The most memory the read and render may add, over twice what they use */
    private const int PEAK_CEILING = 40 * 1024 * 1024;

    /**
     * Test that the page reads in one statement per level plus the count, and
     * stays under the memory ceiling.
     *
     * @return void
     */
    public function testFullFanOutReadsInOneStatementPerLevelWithinAMemoryCeiling(): void
    {
        UserGraphSeeder::seed(self::USERS, self::PER_PARENT, self::PER_PARENT);

        // Warm the column metadata, so only the page's own statements remain
        $this->readPage();

        $json = '';

        memory_reset_peak_usage();

        $baseline   = memory_get_usage();
        $statements = $this->recordStatements(function () use (&$json): void {
            $json = $this->readPage();
        });
        $peak = memory_get_peak_usage() - $baseline;

        self::assertStatementCount(5, $statements);
        self::assertLessThan(self::PEAK_CEILING, $peak);

        /** @var list<array{posts: list<array{tags: list<array<string, mixed>>}>}> $users */
        $users = json_decode($json, true);

        self::assertCount(self::USERS, $users);

        foreach ($users as $user) {

            self::assertCount(self::PER_PARENT, $user['posts']);

            foreach ($user['posts'] as $post) {
                self::assertCount(self::PER_PARENT, $post['tags']);
            }
        }
    }

    /**
     * Read and encode the page embedding the organization, posts and tags.
     *
     * @return string
     */
    private function readPage(): string
    {
        assert($this->app !== null);

        ApiQuery::parse(Request::create('/users', 'GET', [
            'limit'  => '100',
            'fields' => ['users' => 'name,organization,posts', 'posts' => 'title,tags', 'tags' => 'name'],
        ]));

        $users = $this->app->make(UserRepository::class)->usingResource(UserResource::class)->withApiCriteria()->paginate();

        return (string) json_encode((new ApiResourceCollection($users, UserResource::class))->resolve());
    }
}
