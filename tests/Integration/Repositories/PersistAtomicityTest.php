<?php

declare(strict_types = 1);

namespace Tests\Integration\Repositories;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use Tests\Fixtures\Models\Project;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ProjectRepository;
use Tests\TestCase;

/**
 * Integration tests proving persist() writes all or nothing against foreign
 * keys.
 *
 * Each case catches the failure inside the test's own transaction and reads on,
 * so on PostgreSQL it also proves that transaction is left usable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AttributeSetter::class)]
final class PersistAtomicityTest extends TestCase
{
    /** @var \Tests\Fixtures\Repositories\ProjectRepository */
    private ProjectRepository $repository;

    /** @var \Tests\Fixtures\Models\User The user every project belongs to */
    private User $owner;

    /** @var \Tests\Fixtures\Models\Tag The tag every project starts with */
    private Tag $tag;

    /**
     * Set up the repository, the owner and the tag.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(ProjectRepository::class); // @phpstan-ignore method.nonObject
        $this->owner      = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->tag        = Tag::create(['name' => 'php']);
    }

    /**
     * Test that a sync to a missing key rolls back the update and the detach
     * before it.
     *
     * @return void
     */
    public function testAFailedSyncRollsBackTheUpdate(): void
    {
        $project = Project::create(['user_id' => $this->owner->id, 'name' => 'Apollo']);

        $project->tags()->attach($this->tag->id);

        $this->assertThrows(fn (): bool => $this->repository->persist($project, ['name' => 'Gemini', 'tags' => [$this->tag->id + 1]]), QueryException::class);

        self::assertSame('Apollo', $project->fresh()?->name);
        self::assertSame(['php'], $project->tags()->pluck('name')->all());
    }

    /**
     * Test that a sync to a missing key rolls back the insert.
     *
     * @return void
     */
    public function testAFailedSyncRollsBackTheCreate(): void
    {
        $attributes = ['user' => $this->owner->id, 'name' => 'Apollo', 'tags' => [$this->tag->id + 1]];

        $this->assertThrows(fn (): bool => $this->repository->persist(new Project, $attributes), QueryException::class);

        self::assertSame(0, Project::query()->count());
        self::assertSame(0, DB::table('project_tag')->count());
    }

    /**
     * Test that an association to a missing key fails at save and writes
     * nothing.
     *
     * @return void
     */
    public function testAnAssociationToAMissingKeyFailsAtSave(): void
    {
        $attributes = ['user' => $this->owner->id + 1, 'name' => 'Apollo', 'tags' => [$this->tag->id]];

        $this->assertThrows(fn (): bool => $this->repository->persist(new Project, $attributes), QueryException::class);

        self::assertSame(0, Project::query()->count());
        self::assertSame(0, DB::table('project_tag')->count());
    }
}
