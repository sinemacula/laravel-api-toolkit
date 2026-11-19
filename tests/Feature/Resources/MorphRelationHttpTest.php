<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RelationWindow;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\NoteRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\BoundedPostResource;
use Tests\Fixtures\Resources\BoundedUserResource;
use Tests\Fixtures\Resources\NoteResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\TestCase;

/**
 * Feature tests for polymorphic relations read through the repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnProjectionApplier::class)]
#[CoversClass(EagerLoadPlanner::class)]
#[CoversClass(RelationLimit::class)]
#[CoversClass(RelationWindow::class)]
final class MorphRelationHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up the users and notes routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [BoundedPostResource::class, BoundedUserResource::class, NoteResource::class, TagResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(BoundedUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, BoundedUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/notes', function (NoteRepository $repository): ApiResourceCollection {

            $notes = $repository->usingResource(NoteResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($notes, NoteResource::class);
        });
    }

    /**
     * Test that a morph-many is bounded per parent in one windowed statement
     * per path, never reaching notes of another type sharing the parent's key.
     *
     * @return void
     */
    public function testMorphManyIsBoundedPerParentAndKeepsToItsOwnType(): void
    {
        $noteables = [];

        foreach (['Author' => 6, 'Reader' => 2] as $name => $count) {
            $user = User::create(['name' => $name, 'email' => strtolower($name) . '@example.com']);
            $post = Post::query()->forceCreate(['id' => $user->id, 'user_id' => $user->id, 'title' => $name . ' post', 'body' => 'Body']);

            $noteables[] = [$user, $name, $count];
            $noteables[] = [$post, $name . ' post', $count];
        }

        for ($index = 1; $index <= 6; $index++) {
            foreach ($noteables as [$noteable, $label, $count]) {
                if ($index > $count) {
                    continue;
                }

                $this->attachNote($noteable, $label . ' note ' . $index);
            }
        }

        $statements = $this->recordStatements();

        $response = $this->getJson('/users?fields[bounded_users]=name,notes,posts&fields[bounded_posts]=title,notes&fields[notes]=title');

        $response->assertOk();
        $response->assertJsonPath('data.0.notes.*.title', ['Author note 1', 'Author note 2', 'Author note 3']);
        $response->assertJsonPath('data.0._truncated', ['notes']);
        $response->assertJsonPath('data.0.posts.0.notes.*.title', ['Author post note 1', 'Author post note 2', 'Author post note 3']);
        $response->assertJsonPath('data.0.posts.0._truncated', ['notes']);
        $response->assertJsonPath('data.1.notes.*.title', ['Reader note 1', 'Reader note 2']);
        $response->assertJsonMissingPath('data.1._truncated');
        $response->assertJsonPath('data.1.posts.0.notes.*.title', ['Reader post note 1', 'Reader post note 2']);
        $response->assertJsonMissingPath('data.1.posts.0._truncated');

        $noteStatements = self::selectsFrom($statements(), 'notes');

        self::assertCount(2, $noteStatements);

        foreach ($noteStatements as $sql) {
            self::assertStringContainsString('laravel_row', $sql);
            self::assertStringContainsString('noteable_type', $sql);
        }
    }

    /**
     * Test that a narrowed notes read keeps the morph keys its parent needs and
     * loads the parents in one statement per type, however many notes.
     *
     * @return void
     */
    public function testMorphToKeepsItsKeysWhenNarrowedAndLoadsOncePerTargetType(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'author@example.com']);

        foreach ([5, 40] as $total) {
            for ($index = Note::query()->count(); $index < $total; $index++) {
                $this->attachNote($this->createNoteable($author, $index), 'Note');
            }

            $statements = $this->recordStatements();

            $response = $this->getJson('/notes?fields[notes]=subject');
            $executed = $statements();

            $response->assertOk();
            $response->assertJsonCount($total, 'data');

            foreach (self::rows($response->json('data')) as $row) {
                $note = Note::query()->whereKey($row['id'])->firstOrFail();

                self::assertSame(sprintf('Note on %s %d', class_basename((string) $note->noteable_type), $note->noteable_id), $row['subject']);
            }

            [$notes] = array_values(array_filter(self::selectsFrom($executed, 'notes'), static fn (string $sql): bool => !str_contains($sql, 'aggregate')));

            self::assertStringNotContainsString('*', $notes);
            self::assertStringContainsString('noteable_type', $notes);
            self::assertStringContainsString('noteable_id', $notes);
            self::assertCount(1, self::selectsFrom($executed, 'posts'));
            self::assertCount(1, self::selectsFrom($executed, 'users'));
        }
    }

    /**
     * Test that a morph-to-many is bounded per parent in one windowed statement
     * that keeps its type predicate.
     *
     * @return void
     */
    public function testMorphToManyIsBoundedPerParent(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'author@example.com']);

        $full   = $this->attachNote($author, 'Full');
        $single = $this->attachNote($author, 'Single');

        [$red, $green, $blue] = array_map(static fn (string $name): int => Tag::create(['name' => $name])->id, ['red', 'green', 'blue']);

        $full->labels()->attach([$blue, $red, $green]);
        $single->labels()->attach([$green]);

        DB::table('labelables')->insert(['tag_id' => $red, 'labelable_type' => Post::class, 'labelable_id' => $single->id]);

        $statements = $this->recordStatements();

        $response = $this->getJson('/notes?fields[notes]=title,labels&fields[tags]=name');

        $response->assertOk();
        $response->assertJsonPath('data.0.labels.*.name', ['red', 'green']);
        $response->assertJsonPath('data.0._truncated', ['labels']);
        $response->assertJsonPath('data.1.labels.*.name', ['green']);
        $response->assertJsonMissingPath('data.1._truncated');

        $labelStatements = self::selectsFrom($statements(), 'tags');

        self::assertCount(1, $labelStatements);
        self::assertStringContainsString('laravel_row', $labelStatements[0]);
        self::assertStringContainsString('labelable_type', $labelStatements[0]);
    }

    /**
     * Attach a new note with the given title to the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $noteable
     * @param  string  $title
     * @return \Tests\Fixtures\Models\Note
     */
    private function attachNote(Model $noteable, string $title): Note
    {
        $note        = new Note;
        $note->title = $title;

        $note->noteable()->associate($noteable)->save();

        return $note;
    }

    /**
     * Create the model the note at the given index attaches to, alternating
     * posts and users.
     *
     * @param  \Tests\Fixtures\Models\User  $author
     * @param  int  $index
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function createNoteable(User $author, int $index): Model
    {
        if ($index % 2 === 0) {
            return Post::create(['user_id' => $author->id, 'title' => 'Post ' . $index, 'body' => 'Body']);
        }

        return User::create(['name' => 'User ' . $index, 'email' => 'user' . $index . '@example.com']);
    }

    /**
     * Return the select statements that read from the given table.
     *
     * @param  array<int, string>  $statements
     * @param  string  $table
     * @return array<int, string>
     */
    private static function selectsFrom(array $statements, string $table): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from ' . $table . ' ')));
    }

    /**
     * Assert the decoded value is a list of records and return it.
     *
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertContainsOnlyArray($value);

        /** @var array<int, array<string, mixed>> */
        return $value;
    }

    /**
     * Record the SQL of every statement run from here on, with identifier
     * quotes removed.
     *
     * @return \Closure(): array<int, string>
     */
    private function recordStatements(): \Closure
    {
        $statements = [];

        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = str_replace(['`', '"'], '', $query->sql);
        });

        return static function () use (&$statements): array {
            return $statements;
        };
    }
}
