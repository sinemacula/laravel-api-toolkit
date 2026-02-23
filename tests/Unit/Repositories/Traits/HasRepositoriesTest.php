<?php

namespace Tests\Unit\Repositories\Traits;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\RepositoryResolver;
use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Support\HasRepositoriesTestParent;
use Tests\TestCase;

/**
 * Tests for the HasRepositories trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(HasRepositories::class)]
class HasRepositoriesTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        RepositoryResolver::flush();

        parent::tearDown();
    }

    /**
     * Test that __call resolves a registered repository via magic method.
     *
     * @return void
     */
    public function testCallResolvesRegisteredRepositoryViaMagicMethod(): void
    {
        RepositoryResolver::register('users', UserRepository::class);

        $consumer = $this->createConsumerWithParent();

        /** @phpstan-ignore method.notFound */
        $result = $consumer->users();

        static::assertInstanceOf(RepositoryInterface::class, $result);
        static::assertInstanceOf(UserRepository::class, $result);
    }

    /**
     * Test that __call throws BadMethodCallException for an unknown method.
     *
     * The trait's __call has a recursive path when class_uses
     * returns the trait itself. Using a parent class that throws
     * BadMethodCallException avoids this recursion.
     *
     * @return void
     */
    public function testCallThrowsBadMethodCallExceptionForUnknownMethod(): void
    {
        $consumer = $this->createConsumerWithParent();

        $this->expectException(\BadMethodCallException::class);

        // @phpstan-ignore method.notFound
        $consumer->nonexistent();
    }

    /**
     * Test that resolveRepository caches the resolved instance.
     *
     * @return void
     */
    public function testResolveRepositoryCachesResolvedInstance(): void
    {
        RepositoryResolver::register('users', UserRepository::class);

        $consumer = $this->createConsumerWithParent();

        /** @phpstan-ignore method.notFound */
        $first = $consumer->users();
        /** @phpstan-ignore method.notFound */
        $second = $consumer->users();

        static::assertSame($first, $second);
    }

    /**
     * Create a consumer with a parent class that provides a fallback __call.
     *
     * @return object
     */
    private function createConsumerWithParent(): object
    {
        return new class extends HasRepositoriesTestParent {
            use HasRepositories;
        };
    }
}
