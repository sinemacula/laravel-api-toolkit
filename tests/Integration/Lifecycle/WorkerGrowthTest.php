<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchPlan;
use SineMacula\ApiToolkit\Services\Input\Payload;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\RunsOctaneWorker;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\TestCase;

/**
 * Tests that the toolkit's process-wide memos do not grow with the queries a
 * long-lived worker serves.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
#[CoversClass(OctaneFlushListener::class)]
#[Group('octane')]
final class WorkerGrowthTest extends TestCase
{
    use RunsOctaneWorker;

    /** @var array<int, string> Memos keyed by client input, which every flush must empty */
    private const array FLUSHED = [
        EagerLoadPlanner::class . '::$aggregateCache',
        EagerLoadPlanner::class . '::$eagerLoadCache',
        FieldResolver::class . '::$resolvedCache',
        IndexProof::class . '::$cache',
    ];

    /** @var array<int, string> Static arrays keyed by code alone, which live for the worker */
    private const array CODE_KEYED = [
        ApiResource::class . '::$default',
        ApiResource::class . '::$fixed',
        FieldColumnMapper::class . '::$cache',
        Payload::class . '::$bodyKeys',
        Payload::class . '::$sensitiveClasses',
        Payload::class . '::$summaryProperties',
        ResourceTypeIndex::class . '::$classes',
        ResourceTypeIndex::class . '::$source',
        ResourceTypeIndex::class . '::$types',
        SchemaCompiler::class . '::$cache',
        SearchPlan::class . '::$cache',
        ValueResolver::class . '::$castAccessorCache',
        ValueResolver::class . '::$relatedModelCache',
    ];

    /** @var array<int, string> Stacks each finishing console command pops */
    private const array COMMAND_SCOPED = [
        RuntimeContext::class . '::$commands',
    ];

    /**
     * Leave the rows a test writes committed, as one engine updates its
     * full-text index only when a transaction commits.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that every static array in the toolkit is emptied by a flush, keyed
     * by code alone or popped as each console command finishes.
     *
     * @return void
     */
    public function testEveryStaticArrayIsFlushedOrKeyedByCode(): void
    {
        $expected = [...self::FLUSHED, ...self::CODE_KEYED, ...self::COMMAND_SCOPED];

        sort($expected);

        self::assertSame($expected, $this->staticArrays());
    }

    /**
     * Test that a flush empties every memo keyed by client input.
     *
     * @return void
     */
    public function testFlushEmptiesEveryClientKeyedMemo(): void
    {
        foreach (self::FLUSHED as $memo) {
            $this->memo($memo)->setValue(null, ['probe' => ['probe']]);
        }

        $this->app->make(CacheManager::class)->flush(); // @phpstan-ignore method.nonObject

        foreach (self::FLUSHED as $memo) {
            self::assertSame([], $this->memo($memo)->getValue(), $memo);
        }
    }

    /**
     * Test that the memos stop growing while a worker serves ever-new fields,
     * filters, searches and orders.
     *
     * @return void
     */
    public function testMemosPlateauAcrossVariedRequests(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/growth/users', static function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchableFilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchableFilterableUserResource::class);
        });

        Config::set('api-toolkit.search.unverified_connections', ['testing']);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [SearchableFilterableUserResource::class]);

        $this->beforeApplicationDestroyed(static function (): void {
            DB::table('users')->delete();
        });

        // No n-gram of this name holds a full-text stopword
        $humphrey = User::create(['name' => 'Humphrey', 'email' => 'humphrey@growth.test']);

        User::create(['name' => 'Bob', 'email' => 'bob@growth.test', 'status' => 'inactive']);

        $this->serveCycles($humphrey->id, 0, 20);

        $sizes = $this->memoSizes();

        $this->serveCycles($humphrey->id, 20, 200);

        self::assertSame($sizes, $this->memoSizes());
    }

    /**
     * Serve one varied list request per cycle in the given range, each
     * rendering the matching user.
     *
     * @param  int  $matchingId
     * @param  int  $from
     * @param  int  $to
     * @return void
     */
    private function serveCycles(int $matchingId, int $from, int $to): void
    {
        $requests = [];

        for ($cycle = $from; $cycle < $to; $cycle++) {
            $fields = (new Randomizer(new Mt19937($cycle)))->shuffleArray(['id', 'name', 'email', 'status']);

            $requests[] = Request::create('/growth/users?' . http_build_query([
                'fields'  => ['searchable_filterable_users' => implode(',', array_slice($fields, 0, $cycle % 4 + 1))],
                'filters' => json_encode(['id' => ['$le' => $matchingId + $cycle], 'name' => ['$in' => ['Humphrey', "name-{$cycle}"]], 'status' => ['$eq' => 'active']]),
                'search'  => substr('humphrey', $cycle % 4, $cycle % 3 + 3),
                'order'   => $cycle % 2 === 0 ? 'id:asc' : 'id:desc',
                'limit'   => $cycle % 50 + 1,
            ]));
        }

        $responses = $this->serveThroughOctane(...$requests)->responses;
        $rendered  = array_map(static fn (Response $response): array => [$response->getStatusCode(), substr_count((string) $response->getContent(), '"_type"')], $responses);

        self::assertSame(array_fill(0, $to - $from, [200, 1]), $rendered);
    }

    /**
     * Read the size of every static array, counting nested entries.
     *
     * @return array<string, int>
     */
    private function memoSizes(): array
    {
        $sizes = [];

        foreach ($this->staticArrays() as $memo) {
            $value = $this->memo($memo)->getValue();

            assert(is_array($value));

            $sizes[$memo] = count($value, COUNT_RECURSIVE);
        }

        return $sizes;
    }

    /**
     * Find every static array property the toolkit declares.
     *
     * @return array<int, string>
     */
    private function staticArrays(): array
    {
        $source = dirname(__DIR__, 3) . '/src';
        $memos  = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            assert($file instanceof \SplFileInfo);

            $class = 'SineMacula\ApiToolkit\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($source) + 1, -4));

            assert(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class));

            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                $type = $property->getType();

                if ($property->getDeclaringClass()->getName() !== $class || !$type instanceof \ReflectionNamedType || $type->getName() !== 'array') {
                    continue;
                }

                $memos[] = $class . '::$' . $property->getName();
            }
        }

        sort($memos);

        return $memos;
    }

    /**
     * Reflect the static property the given memo names.
     *
     * @param  string  $memo
     * @return \ReflectionProperty
     */
    private function memo(string $memo): \ReflectionProperty
    {
        [$class, $property] = explode('::$', $memo);

        assert(class_exists($class) || trait_exists($class));

        return new \ReflectionProperty($class, $property);
    }
}
