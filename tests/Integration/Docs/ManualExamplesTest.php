<?php

declare(strict_types = 1);

namespace Tests\Integration\Docs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\ManualUser;
use Tests\Fixtures\Repositories\ManualUserRepository;
use Tests\Fixtures\Resources\ManualCommentResource;
use Tests\Fixtures\Resources\ManualOrganizationResource;
use Tests\Fixtures\Resources\ManualUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Integration tests running the query, filter and pagination examples of the
 * shipped API manual, and the commands the README and manual name, against the
 * toolkit.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EnvelopeBuilder::class)]
final class ManualExamplesTest extends TestCase
{
    use RegistersApiExceptionHandler, ValidatesOpenApiDocuments;

    /** @var int The records behind the manual's pagination examples */
    private const int RECORDS = 275;

    /**
     * Serve the manual's users endpoint over a resource declaring every field
     * its examples name.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [ManualUserResource::class, ManualOrganizationResource::class, ManualCommentResource::class, PostResource::class]);
        Config::set('api-toolkit.resources.resource_map', [ManualUser::class => ManualUserResource::class]);
        Config::set('api-toolkit.repositories.allow_random_order', true);

        if (DB::connection()->getDriverName() === 'sqlite') {
            Config::set('api-toolkit.search.unverified_connections', [DB::getDefaultConnection()]);
        }

        Route::middleware(ParseApiQuery::class)->get('/users', static fn (ManualUserRepository $users): ApiResourceCollection => new ApiResourceCollection(
            $users->withApiCriteria()->paginate(),
            ManualUserResource::class,
        ));
    }

    /**
     * Provide every filter example of the manual's Filtering section.
     *
     * @return iterable<string, array{string}>
     */
    public static function filterExamples(): iterable
    {
        foreach (self::fencedBlocks(self::section(self::manual('20-advanced-querying.md'), 'Filtering'), 'json') as $block) {

            $filter = json_encode(json_decode($block, false, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);

            yield $filter => [$filter];
        }
    }

    /**
     * Test that every filter example of the manual is answered.
     *
     * @param  string  $filter
     * @return void
     */
    #[DataProvider('filterExamples')]
    public function testEveryFilterExampleIsAnswered(string $filter): void
    {
        $this->getJson('/users?' . http_build_query(['filters' => $filter]))->assertOk();
    }

    /**
     * Provide every query string the manual shows a client.
     *
     * @return iterable<string, array{string}>
     */
    public static function queryExamples(): iterable
    {
        foreach (glob(self::path('resources/api-docs/*.md')) ?: [] as $file) {

            $manual = (string) file_get_contents($file);

            $lines = [...self::fencedBlocks($manual, 'text'), ...(preg_match_all('/`(\?[^`]+)`/', $manual, $matches) > 0 ? $matches[1] : [])];

            foreach (array_unique($lines) as $line) {

                if (!str_starts_with($line, '?')) {
                    continue;
                }

                yield basename($file) . ' ' . $line => [$line];
            }
        }
    }

    /**
     * Test that every query string of the manual is answered.
     *
     * @param  string  $query
     * @return void
     */
    #[DataProvider('queryExamples')]
    public function testEveryQueryExampleIsAnswered(string $query): void
    {
        parse_str(substr($query, 1), $parameters);

        $this->getJson('/users?' . http_build_query($parameters))->assertOk();
    }

    /**
     * Provide every response example of the manual's Pagination section.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function paginationExamples(): iterable
    {
        foreach (self::fencedBlocks(self::manual('30-pagination.md'), 'json') as $block) {

            /** @var array<string, mixed> $example */
            $example = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            $self    = data_get($example, 'links.self');

            assert(is_string($self));

            yield $self => [$example];
        }
    }

    /**
     * Test that the response a pagination example shows is the one its own self
     * link is answered with, envelope and first record alike.
     *
     * @param  array<string, mixed>  $example
     * @return void
     */
    #[DataProvider('paginationExamples')]
    public function testEveryPaginationExampleIsTheResponseItsSelfLinkReturns(array $example): void
    {
        $this->seedRecords();

        $self = data_get($example, 'links.self');

        self::assertIsString($self);

        $response = $this->getJson($self)->assertOk();

        $response->assertJsonCount(25, 'data');
        $response->assertJsonPath('data.0', data_get($example, 'data.0'));
        $response->assertJsonPath('links', $example['links']);
        $response->assertJsonPath('meta', $example['meta']);
    }

    /**
     * Test that every pagination example validates against the exported
     * envelope components for its strategy.
     *
     * @param  array<string, mixed>  $example
     * @return void
     */
    #[DataProvider('paginationExamples')]
    public function testEveryPaginationExampleMatchesTheExportedEnvelope(array $example): void
    {
        $cursor   = !array_key_exists('total', (array) $example['meta']);
        $document = ['components' => ['schemas' => (new EnvelopeBuilder)->buildSchemas()]];
        $schemas  = $cursor
            ? ['meta' => EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME, 'links' => EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME]
            : ['meta' => EnvelopeBuilder::PAGINATION_META_SCHEMA_NAME, 'links' => EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME];

        foreach ($schemas as $member => $schema) {

            $result = $this->validateAgainstExported($document, '/components/schemas/' . $schema, $example[$member]);

            self::assertTrue($result->isValid(), sprintf('The example %s does not match %s: %s', $member, $schema, $this->formatErrors($result)));
        }
    }

    /**
     * Provide every Artisan command the README and the manual name.
     *
     * @return iterable<string, array{string}>
     */
    public static function documentedCommands(): iterable
    {
        $documents = [self::path('README.md'), ...glob(self::path('resources/api-docs/*.md')) ?: []];

        foreach ($documents as $document) {

            preg_match_all('/\bapi-toolkit:[a-z][a-z:-]*[a-z]/', (string) file_get_contents($document), $matches);

            foreach (array_unique($matches[0]) as $command) {
                yield basename($document) . ' ' . $command => [$command];
            }
        }
    }

    /**
     * Test that every command the documentation names is registered.
     *
     * @param  string  $command
     * @return void
     */
    #[DataProvider('documentedCommands')]
    public function testEveryDocumentedCommandIsRegistered(string $command): void
    {
        self::assertArrayHasKey($command, Artisan::all());
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->unsignedInteger('age')->nullable();
            $table->softDeletes();
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->boolean('flagged')->default(false);
        });
    }

    /**
     * Seed the records the pagination examples page through, each shaped as the
     * examples show.
     *
     * @return void
     */
    private function seedRecords(): void
    {
        $rows = array_map(static fn (int $key): array => [
            'name'       => 'John Smith',
            'first_name' => 'John',
            'last_name'  => 'Smith',
            'email'      => 'john.smith.' . $key . '@example.com',
            'created_at' => '2024-06-19 23:11:52',
            'updated_at' => '2024-06-19 23:11:52',
        ], range(1, self::RECORDS));

        DB::table('users')->insert($rows);
    }

    /**
     * Return the body of the given level-two section.
     *
     * @param  string  $markdown
     * @param  string  $heading
     * @return string
     */
    private static function section(string $markdown, string $heading): string
    {
        $start = strpos($markdown, "\n## " . $heading . "\n");

        assert($start !== false);

        $end = strpos($markdown, "\n## ", $start + 1);

        return substr($markdown, $start, $end === false ? null : $end - $start);
    }

    /**
     * Return the contents of every fenced block in the given language.
     *
     * @param  string  $markdown
     * @param  string  $language
     * @return list<string>
     */
    private static function fencedBlocks(string $markdown, string $language): array
    {
        preg_match_all('/^```' . $language . '\n(.*?)^```$/ms', $markdown, $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * Return the given manual section's Markdown.
     *
     * @param  string  $file
     * @return string
     */
    private static function manual(string $file): string
    {
        return (string) file_get_contents(self::path('resources/api-docs/' . $file));
    }

    /**
     * Return the absolute path of the given repository file.
     *
     * @param  string  $file
     * @return string
     */
    private static function path(string $file): string
    {
        return dirname(__DIR__, 3) . '/' . $file;
    }
}
