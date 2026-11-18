<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

use Illuminate\Support\Facades\DB;

/**
 * Bulk-inserts users of one organization with their posts and tags.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UserGraphSeeder
{
    /** @var int The rows written per insert statement */
    private const int CHUNK = 500;

    /**
     * Seed users of one organization, each with the given posts, and every post
     * with each of the given number of shared tags.
     *
     * @param  int  $users
     * @param  int  $postsPerUser
     * @param  int  $tagsPerPost
     * @return void
     */
    public static function seed(int $users, int $postsPerUser = 0, int $tagsPerPost = 0): void
    {
        $now = now()->toDateTimeString();

        DB::table('organizations')->insert(['id' => 1, 'name' => 'Acme', 'slug' => 'acme', 'created_at' => $now, 'updated_at' => $now]);

        self::insert('users', array_map(static fn (int $id): array => [
            'id'              => $id,
            'organization_id' => 1,
            'name'            => 'User ' . $id,
            'email'           => 'user' . $id . '@example.com',
            'created_at'      => $now,
            'updated_at'      => $now,
        ], self::ids($users)));

        self::insert('posts', array_map(static fn (int $id): array => [
            'id'         => $id,
            'user_id'    => intdiv($id - 1, max(1, $postsPerUser)) + 1,
            'title'      => 'Post ' . $id,
            'body'       => 'Body of post ' . $id,
            'published'  => $id % 2 === 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::ids($users * $postsPerUser)));

        self::insert('tags', array_map(static fn (int $id): array => [
            'id'         => $id,
            'name'       => 'Tag ' . $id,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::ids($tagsPerPost)));

        $pivot = [];

        foreach (self::ids($users * $postsPerUser) as $post) {
            foreach (self::ids($tagsPerPost) as $tag) {
                $pivot[] = ['post_id' => $post, 'tag_id' => $tag];
            }
        }

        self::insert('post_tag', $pivot);
    }

    /**
     * Return the keys 1 to the given count.
     *
     * @param  int  $count
     * @return list<int>
     */
    private static function ids(int $count): array
    {
        return $count < 1 ? [] : range(1, $count);
    }

    /**
     * Insert the rows in chunks.
     *
     * @param  string  $table
     * @param  list<array<string, mixed>>  $rows
     * @return void
     */
    private static function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
