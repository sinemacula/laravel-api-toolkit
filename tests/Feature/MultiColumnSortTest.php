<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for multi-column ordering through the kernel.
 *
 * A comma-separated `order` applies each column in turn: the first column is
 * the primary sort and every subsequent column breaks ties. Two rows sharing a
 * name are ordered by name ascending, and the descending id secondary sort
 * decides which of the tied rows appears first.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(OrderApplier::class)]
final class MultiColumnSortTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        // Two rows share the name Mia so the secondary id sort decides their
        // order; Zed trails both alphabetically.
        User::create(['name' => 'Mia', 'email' => 'mia-first@example.com']);
        User::create(['name' => 'Mia', 'email' => 'mia-second@example.com']);
        User::create(['name' => 'Zed', 'email' => 'zed@example.com']);
    }

    /**
     * Test that a comma-separated order applies the primary column with the
     * secondary column breaking ties.
     *
     * @return void
     */
    public function testSecondaryColumnBreaksTheTie(): void
    {
        $response = $this->getJson('/users?order=name:asc,id:desc');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');

        // Name ascending puts the two Mia rows first; id descending then places
        // the later-created Mia (higher id) before the earlier one.
        $response->assertJsonPath('data.0.name', 'Mia');
        $response->assertJsonPath('data.1.name', 'Mia');
        $response->assertJsonPath('data.2.name', 'Zed');

        $response->assertJsonPath('data.0.email', 'mia-second@example.com');
        $response->assertJsonPath('data.1.email', 'mia-first@example.com');

        self::assertGreaterThan($response->json('data.1.id'), $response->json('data.0.id'));
    }
}
