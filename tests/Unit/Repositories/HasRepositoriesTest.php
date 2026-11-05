<?php

namespace Tests\Unit\Repositories;

use BadMethodCallException;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SineMacula\ApiToolkit\Repositories\RepositoryResolver;
use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use SineMacula\Repositories\Exceptions\RepositoryException;
use Tests\Fixtures\Repositories\AliasedAccessorConsumer;
use Tests\Fixtures\Repositories\MagicChildConsumer;
use Tests\Fixtures\Repositories\NonModelRepository;
use Tests\Fixtures\Repositories\OverridingAccessorConsumer;
use Tests\Fixtures\Repositories\PreferredAccessorConsumer;
use Tests\Fixtures\Repositories\RecordRepository;
use Tests\Fixtures\Repositories\RepositoryConsumer;

/**
 * Repository accessor tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(HasRepositories::class)]
#[CoversClass(RepositoryResolver::class)]
class HasRepositoriesTest extends TestCase
{
    /**
     * Bind a fresh application with an empty repository map.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        $app->instance('config', new ConfigRepository());

        RepositoryResolver::register('records', RecordRepository::class);
        RepositoryResolver::register('nonModels', NonModelRepository::class);
        RepositoryResolver::register('unbound', RepositoryInterface::class);
    }

    /**
     * Drop the cached repositories and the application.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        RepositoryResolver::flush();
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * A registered accessor returns its repository, resolved once.
     *
     * @return void
     */
    #[Test]
    public function registeredAccessorReturnsTheSameRepository(): void
    {
        $consumer = new RepositoryConsumer();

        $repository = $consumer->records();

        static::assertInstanceOf(RecordRepository::class, $repository);
        static::assertSame($repository, $consumer->records());
    }

    /**
     * A non-accessor call is forwarded to the parent magic method.
     *
     * @return void
     */
    #[Test]
    public function nonAccessorCallIsForwardedToTheParent(): void
    {
        $consumer = new MagicChildConsumer();

        static::assertSame('parent:other(value)', $consumer->other('value'));
        static::assertInstanceOf(RecordRepository::class, $consumer->records());
    }

    /**
     * A repository whose model is not an Eloquent model fails with a
     * repository exception.
     *
     * @return void
     */
    #[Test]
    public function nonModelRepositoryThrowsRepositoryException(): void
    {
        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessage('Class stdClass must be an instance of Illuminate\Database\Eloquent\Model');

        (new RepositoryConsumer())->nonModels();
    }

    /**
     * A repository the container cannot build fails with a binding resolution
     * exception.
     *
     * @return void
     */
    #[Test]
    public function unbuildableRepositoryThrowsBindingResolutionException(): void
    {
        $this->expectException(BindingResolutionException::class);

        (new RepositoryConsumer())->unbound();
    }

    /**
     * Resolving an unregistered repository fails with a runtime exception.
     *
     * @return void
     */
    #[Test]
    public function unregisteredRepositoryThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Repository 'missing' not found in registry.");

        (new RepositoryConsumer())->repository('missing');
    }

    /**
     * An unknown method that is not a repository accessor is rejected.
     *
     * @return void
     */
    #[Test]
    public function unknownMethodThrowsBadMethodCallException(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Method missing does not exist.');

        (new RepositoryConsumer())->missing(); // @phpstan-ignore method.notFound
    }

    /**
     * An aliased accessor hands unknown calls to the trait that owns the
     * class magic call handler.
     *
     * @return void
     */
    #[Test]
    public function aliasedAccessorForwardsToTheOtherTrait(): void
    {
        $consumer = new AliasedAccessorConsumer();

        static::assertInstanceOf(RecordRepository::class, $consumer->repositoryCall('records', []));
        static::assertSame('trait:missing(value)', $consumer->repositoryCall('missing', ['value']));
    }

    /**
     * An unknown method is rejected when the accessor is preferred over
     * another trait magic call handler.
     *
     * @return void
     */
    #[Test]
    public function preferredAccessorRejectsUnknownMethod(): void
    {
        $consumer = new PreferredAccessorConsumer();

        static::assertInstanceOf(RecordRepository::class, $consumer->records());

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Method missing does not exist.');

        $consumer->missing(); // @phpstan-ignore method.notFound
    }

    /**
     * An aliased accessor hands unknown calls to the class magic call
     * handler.
     *
     * @return void
     */
    #[Test]
    public function aliasedAccessorForwardsToTheClassHandler(): void
    {
        static::assertSame('class:missing(value)', (new OverridingAccessorConsumer())->repositoryCall('missing', ['value']));
    }
}
