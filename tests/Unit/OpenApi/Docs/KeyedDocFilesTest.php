<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind;
use SineMacula\ApiToolkit\OpenApi\Docs\KeyedDocFiles;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;
use Tests\Concerns\WritesDocsDirectories;
use Tests\TestCase;

/**
 * Tests for the keyed description files.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(KeyedDocFiles::class)]
final class KeyedDocFilesTest extends TestCase
{
    use WritesDocsDirectories;

    /**
     * Test that a shared file is matched to its key in every audience.
     *
     * @return void
     */
    public function testSharedFileMatchesItsKey(): void
    {
        $this->useDocsDirectory(['tags/User.md' => "\nAccounts.\n", 'operations/User.md' => 'Not a tag.']);

        $keys = ['public' => ['Article', 'User'], 'internal' => ['User']];

        self::assertSame(['User' => 'Accounts.'], (new KeyedDocFiles)->resolve(DocKind::TAG, $keys, 'public'));
        self::assertSame(['User' => 'Accounts.'], (new KeyedDocFiles)->resolve(DocKind::TAG, $keys, 'internal'));
    }

    /**
     * Test that an audience's file replaces the shared one for that audience
     * only, an empty one blanking it.
     *
     * @return void
     */
    public function testAudienceFileReplacesTheSharedOne(): void
    {
        $this->useDocsDirectory([
            'tags/User.md'                    => 'Accounts.',
            'tags/Article.md'                 => 'Articles.',
            'audiences/internal/tags/User.md' => 'Staff accounts.',
            'audiences/partner/tags/User.md'  => '',
        ]);

        $files = new KeyedDocFiles;
        $keys  = ['public' => ['User', 'Article'], 'internal' => ['User'], 'partner' => ['User']];

        self::assertSame(['User' => 'Accounts.', 'Article' => 'Articles.'], $files->resolve(DocKind::TAG, $keys, 'public'));
        self::assertSame(['User' => 'Staff accounts.'], $files->resolve(DocKind::TAG, $keys, 'internal'));
        self::assertSame(['User' => ''], $files->resolve(DocKind::TAG, $keys, 'partner'));
    }

    /**
     * Test that a present but empty file resolves to an empty string.
     *
     * @return void
     */
    public function testEmptyFileIsKept(): void
    {
        $this->useDocsDirectory(['tags/User.md' => "  \n"]);

        self::assertSame(['User' => ''], (new KeyedDocFiles)->resolve(DocKind::TAG, ['public' => ['User']], 'public'));
    }

    /**
     * Test that a shared file matching no key in any audience fails.
     *
     * @return void
     */
    public function testSharedOrphanFails(): void
    {
        $dir = $this->useDocsDirectory([
            'operations/users.show.md' => 'Shows a user.',
            'operations/users.zap.md'  => 'Zaps a user.',
        ]);

        $this->expectOrphan(sprintf('The operation description [%s/operations/users.zap.md] matches no operation in any audience.', $dir));

        (new KeyedDocFiles)->resolve(DocKind::OPERATION, ['public' => ['users.show'], 'internal' => ['users.store']], 'public');
    }

    /**
     * Test that an audience's file matching none of its keys fails, even when
     * another audience has the key.
     *
     * @return void
     */
    public function testAudienceOrphanFails(): void
    {
        $dir = $this->useDocsDirectory(['audiences/public/tags/Staff.md' => 'Staff only.']);

        $this->expectOrphan(sprintf('The tag description [%s/audiences/public/tags/Staff.md] matches no tag in the [public] audience.', $dir));

        (new KeyedDocFiles)->resolve(DocKind::TAG, ['internal' => ['Staff'], 'public' => ['User']], 'internal');
    }

    /**
     * Test that a file under an audience outside the export fails, while a
     * manual file or an empty directory there is ignored.
     *
     * @return void
     */
    public function testFileUnderAnUnknownAudienceFails(): void
    {
        $dir = $this->useDocsDirectory([
            'audiences/empty/tags/'        => '',
            'audiences/manual/10.md'       => 'Manual.',
            'audiences/staff/tags/User.md' => 'Staff.',
        ]);

        $this->expectOrphan(sprintf('The tag description [%s/audiences/staff/tags/User.md] belongs to the [staff] audience, which is not configured.', $dir));

        (new KeyedDocFiles)->resolve(DocKind::TAG, ['public' => ['User']], 'public');
    }

    /**
     * Test that shared files are audited before any audience's files.
     *
     * @return void
     */
    public function testSharedOrphansAreReportedFirst(): void
    {
        $dir = $this->useDocsDirectory([
            'audiences/internal/tags/Ghost.md' => 'Ghost.',
            'tags/Ghost.md'                    => 'Ghost.',
        ]);

        $this->expectOrphan(sprintf('The tag description [%s/tags/Ghost.md] matches no tag in any audience.', $dir));

        (new KeyedDocFiles)->resolve(DocKind::TAG, ['internal' => []], 'internal');
    }

    /**
     * Test that the audiences' files are audited in the given audience order.
     *
     * @return void
     */
    public function testAudienceOrphansAreReportedInAudienceOrder(): void
    {
        $dir = $this->useDocsDirectory([
            'audiences/internal/tags/Ghost.md' => 'Ghost.',
            'audiences/public/tags/Ghost.md'   => 'Ghost.',
        ]);

        $this->expectOrphan(sprintf('The tag description [%s/audiences/public/tags/Ghost.md] matches no tag in the [public] audience.', $dir));

        (new KeyedDocFiles)->resolve(DocKind::TAG, ['public' => [], 'internal' => []], 'internal');
    }

    /**
     * Test that every reserved byte is percent-encoded and a space is kept.
     *
     * @return void
     */
    public function testReservedBytesAreEncodedAndSpacesKept(): void
    {
        $keys = [
            'Admin/Users'      => 'Admin%2FUsers',
            'Admin\Users'      => 'Admin%5CUsers',
            'User Profiles'    => 'User Profiles',
            '.hidden'          => '%2Ehidden',
            'a.b'              => 'a.b',
            '100%'             => '100%25',
            'a:b*c?d"e<f>g|h'  => 'a%3Ab%2Ac%3Fd%22e%3Cf%3Eg%7Ch',
            "tab\tnul\0us\x1F" => 'tab%09nul%00us%1F',
            "del\x7F"          => 'del%7F',
        ];

        $files = [];

        foreach ($keys as $key => $name) {
            $files['tags/' . $name . '.md'] = 'Described ' . $name . '.';
        }

        $this->useDocsDirectory($files);

        $expected = [];

        foreach ($keys as $key => $name) {
            $expected[$key] = 'Described ' . $name . '.';
        }

        self::assertSame($expected, (new KeyedDocFiles)->resolve(DocKind::TAG, ['public' => array_keys($keys)], 'public'));
    }

    /**
     * Test that a file differing from its key only in case is an orphan.
     *
     * @return void
     */
    public function testFileDifferingFromItsKeyOnlyInCaseIsAnOrphan(): void
    {
        $dir = $this->useDocsDirectory(['tags/user.md' => 'Accounts.']);

        $this->expectOrphan(sprintf('The tag description [%s/tags/user.md] matches no tag in any audience.', $dir));

        (new KeyedDocFiles)->resolve(DocKind::TAG, ['public' => ['User']], 'public');
    }

    /**
     * Test that only the visible files directly within a kind's directory
     * count.
     *
     * @return void
     */
    public function testOnlyTopLevelFilesCount(): void
    {
        $this->useDocsDirectory([
            'tags/nested/User.md'              => 'Nested.',
            'tags/User.txt'                    => 'Text.',
            'tags/._User.md'                   => 'Resource fork.',
            'audiences/.retired/tags/Staff.md' => 'Retired.',
        ]);

        self::assertSame([], (new KeyedDocFiles)->resolve(DocKind::TAG, ['public' => ['User']], 'public'));
    }

    /**
     * Test that every file of a kind is listed, shared first, then each
     * audience by name.
     *
     * @return void
     */
    public function testFilesListsSharedThenEachAudience(): void
    {
        $dir = $this->useDocsDirectory([
            'tags/b.md'                    => 'B.',
            'tags/a.md'                    => 'A.',
            'audiences/public/tags/x.md'   => 'X.',
            'audiences/internal/tags/y.md' => 'Y.',
            'audiences/internal/10.md'     => 'Manual.',
            'operations/z.md'              => 'Z.',
        ]);

        config()->set('api-toolkit.openapi.docs_path', $dir . '/');

        self::assertSame(
            [$dir . '/tags/a.md', $dir . '/tags/b.md', $dir . '/audiences/internal/tags/y.md', $dir . '/audiences/public/tags/x.md'],
            (new KeyedDocFiles)->files(DocKind::TAG),
        );
    }

    /**
     * Test that a numeric audience name resolves its own files.
     *
     * @return void
     */
    public function testNumericAudienceNameResolvesItsOwnFiles(): void
    {
        $this->useDocsDirectory(['tags/User.md' => 'Accounts.', 'audiences/2024/tags/User.md' => 'Accounts, as of 2024.']);

        $keys = ['public' => ['User'], '2024' => ['User']];

        self::assertSame(['User' => 'Accounts, as of 2024.'], (new KeyedDocFiles)->resolve(DocKind::TAG, $keys, '2024'));
    }

    /**
     * Test that orphans fail the resolution of an audience that has no keys.
     *
     * @return void
     */
    public function testOrphansFailAnAudienceWithoutKeys(): void
    {
        $dir = $this->useDocsDirectory(['tags/Ghost.md' => 'Ghost.']);

        $this->expectOrphan(sprintf('The tag description [%s/tags/Ghost.md] matches no tag in any audience.', $dir));

        (new KeyedDocFiles)->resolve(DocKind::TAG, ['public' => [], 'internal' => ['User']], 'public');
    }

    /**
     * Expect an orphaned documentation failure with exactly the message.
     *
     * @param  string  $message
     * @return void
     */
    private function expectOrphan(string $message): void
    {
        $this->expectException(OrphanedDocumentationException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, '/') . '$/');
    }
}
