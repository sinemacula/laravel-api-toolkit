<?php

declare(strict_types = 1);

namespace Tests\Unit\Query;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Query\PageSize;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the PageSize value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PageSize::class)]
final class PageSizeTest extends TestCase
{
    /**
     * Set up the test environment with a ceiling of 100 and a default of 50.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.parser.max_limit', 100);
        Config::set('api-toolkit.parser.defaults.limit', 50);
    }

    /**
     * Provide the classes that declare no ceiling of their own.
     *
     * @return iterable<string, array{class-string|null}>
     */
    public static function undeclaredProvider(): iterable
    {
        yield 'no resource' => [null];
        yield 'an inheriting resource' => [UserResource::class];
        yield 'a class that is not a resource' => [User::class];
    }

    /**
     * Test that a class declaring no ceiling of its own takes the configured
     * pair.
     *
     * @param  class-string|null  $class
     * @return void
     */
    #[DataProvider('undeclaredProvider')]
    public function testAClassDeclaringNoCeilingTakesTheConfiguredPair(?string $class): void
    {
        self::assertEquals(new PageSize(100, 50), PageSize::for($class));
    }

    /**
     * Test that a declared ceiling below the configured one replaces it and
     * lowers the default to it.
     *
     * @return void
     */
    public function testALowerDeclaredCeilingLowersTheCeilingAndTheDefault(): void
    {
        self::assertEquals(new PageSize(25, 25), PageSize::for($this->declaring(25)));
    }

    /**
     * Test that a declared ceiling of one is accepted.
     *
     * @return void
     */
    public function testADeclaredCeilingOfOneIsAccepted(): void
    {
        self::assertEquals(new PageSize(1, 1), PageSize::for($this->declaring(1)));
    }

    /**
     * Test that a declared ceiling above the configured one cannot raise it.
     *
     * @return void
     */
    public function testAHigherDeclaredCeilingKeepsTheConfiguredOne(): void
    {
        self::assertEquals(new PageSize(100, 50), PageSize::for($this->declaring(250)));
    }

    /**
     * Test that a declared ceiling above the configured default keeps that
     * default.
     *
     * @return void
     */
    public function testADeclaredCeilingAboveTheDefaultKeepsTheDefault(): void
    {
        self::assertEquals(new PageSize(75, 50), PageSize::for($this->declaring(75)));
    }

    /**
     * Test that a declared ceiling applies while the configured one is
     * disabled.
     *
     * @return void
     */
    public function testADeclaredCeilingAppliesUnderADisabledConfiguredCeiling(): void
    {
        Config::set('api-toolkit.parser.max_limit', 0);

        self::assertEquals(new PageSize(25, 25), PageSize::for($this->declaring(25)));
    }

    /**
     * Test that with no ceiling anywhere the bounds carry none and the
     * configured default.
     *
     * @return void
     */
    public function testNoCeilingAnywhereKeepsTheConfiguredDefault(): void
    {
        Config::set('api-toolkit.parser.max_limit', 0);

        self::assertEquals(new PageSize(0, 50), PageSize::for(UserResource::class));
    }

    /**
     * Provide the declared ceilings that serve no page.
     *
     * @return iterable<string, array{int}>
     */
    public static function unusableCeilingProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * Test that a declared ceiling below one is a logic error naming the
     * resource.
     *
     * @param  int  $declared
     * @return void
     */
    #[DataProvider('unusableCeilingProvider')]
    public function testADeclaredCeilingBelowOneIsALogicError(int $declared): void
    {
        $resource = $this->declaring($declared);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('%s::maxLimit() must return null or an integer of at least 1; [%d] given.', $resource, $declared));

        PageSize::for($resource);
    }

    /**
     * Test that an omitted page size resolves to the default.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnOmittedPageSizeResolvesToTheDefault(): void
    {
        self::assertSame(20, (new PageSize(25, 20))->resolve(null));
    }

    /**
     * Test that a requested page size up to the ceiling resolves as asked.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testARequestedPageSizeAtTheCeilingResolvesAsAsked(): void
    {
        self::assertSame(25, (new PageSize(25, 20))->resolve(25));
    }

    /**
     * Test that any requested page size resolves as asked without a ceiling.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnyRequestedPageSizeResolvesWithoutACeiling(): void
    {
        self::assertSame(5000, (new PageSize(0, 20))->resolve(5000));
    }

    /**
     * Test that a requested page size above the ceiling is refused, naming the
     * ceiling and the size asked for.
     *
     * @return void
     */
    public function testARequestedPageSizeAboveTheCeilingIsRefused(): void
    {
        try {
            (new PageSize(25, 20))->resolve(26);

            self::fail('Expected the page size to be refused.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame(['parameter' => 'limit', 'pointer' => '', 'reason' => 'max_limit', 'limit' => 25, 'actual' => 26], $exception->getCustomMeta());
        }
    }

    /**
     * Test that bounds differ exactly when their ceiling or default does.
     *
     * @return void
     */
    public function testBoundsDifferWhenTheirCeilingOrDefaultDoes(): void
    {
        $bounds = new PageSize(25, 20);

        self::assertFalse($bounds->differsFrom(new PageSize(25, 20)));
        self::assertTrue($bounds->differsFrom(new PageSize(30, 20)));
        self::assertTrue($bounds->differsFrom(new PageSize(25, 10)));
    }

    /**
     * Name a resource declaring the given page size ceiling.
     *
     * @param  int  $ceiling
     * @return class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>
     */
    private function declaring(int $ceiling): string
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'declaring';

            /** @var int The ceiling the resource declares */
            public static int $ceiling = 0;

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [];
            }

            /**
             * Return the largest page size this resource serves.
             *
             * @return int
             */
            #[\Override]
            public static function maxLimit(): int
            {
                return self::$ceiling;
            }
        };

        $resource::$ceiling = $ceiling;

        return $resource::class;
    }
}
