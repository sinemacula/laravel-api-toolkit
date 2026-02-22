<?php

declare(strict_types = 1);

namespace Tests\Unit;

use SineMacula\ApiToolkit\Repositories\RepositoryResolver;
use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use Tests\Fixtures\Repositories\DummyRepository;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class RepositoryResolverAndHasRepositoriesTest extends TestCase
{
    public function testRepositoryResolverCanRegisterResolveAndFlushRepositories(): void
    {
        RepositoryResolver::register('dynamic', DummyRepository::class);

        static::assertTrue(RepositoryResolver::has('dynamic'));

        $first  = RepositoryResolver::get('dynamic');
        $second = RepositoryResolver::get('dynamic');

        static::assertInstanceOf(DummyRepository::class, $first);
        static::assertSame($first, $second);

        RepositoryResolver::flush();

        $third = RepositoryResolver::get('dynamic');

        static::assertNotSame($first, $third);
    }

    public function testRepositoryResolverThrowsWhenRepositoryMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        RepositoryResolver::get('missing');
    }

    public function testHasRepositoriesResolvesConfiguredRepositoryAndCachesInstance(): void
    {
        $host = new class {
            use HasRepositories;

            public function get(string $name): RepositoryInterface
            {
                return $this->resolveRepository($name);
            }
        };

        $first  = $host->dummy();
        $second = $host->dummy();

        static::assertInstanceOf(DummyRepository::class, $first);
        static::assertSame($first, $second);

        static::assertSame($first, $host->get('dummy'));
    }

    public function testHasRepositoriesFallsBackToParentCallWhenAvailable(): void
    {
        $host = new class extends ParentCallHost {
            use HasRepositories;
        };

        static::assertSame('parent:method', $host->method('a'));
    }

    public function testHasRepositoriesCanDelegateToTraitCallPathWhenClassUsesOverrideIsProvided(): void
    {
        FunctionOverrides::forceClassUses([HasRepositories::class]);
        FunctionOverrides::interceptTraitCallUserFunc(true, 'delegated');

        $host = new class {
            use HasRepositories;
        };

        static::assertSame('delegated', $host->unknown());
    }

    public function testHasRepositoriesThrowsWhenNoRepositoryParentOrTraitHandlerExists(): void
    {
        FunctionOverrides::forceClassUses([]);

        $host = new class {
            use HasRepositories;
        };

        $this->expectException(\BadMethodCallException::class);

        $host->unknown();
    }
}

class ParentCallHost
{
    public function __call($method, $arguments): mixed
    {
        return 'parent:' . $method;
    }
}
