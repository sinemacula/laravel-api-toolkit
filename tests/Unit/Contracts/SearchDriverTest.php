<?php

declare(strict_types = 1);

namespace Tests\Unit\Contracts;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Tests for the SearchDriver interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class SearchDriverTest extends TestCase
{
    /**
     * Test that the contract declares the three capabilities a driver has to
     * answer for, and nothing that would tie it to one engine.
     *
     * @return void
     */
    public function testDeclaresTheDriverCapabilities(): void
    {
        $reflection = new \ReflectionClass(SearchDriver::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame(
            ['supportedStrategies', 'canVerifyIndexBacking', 'apply'],
            array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $reflection->getMethods()),
        );
    }

    /**
     * Test that the strategy declaration is a parameterless method returning an
     * array, so a driver states what it implements without being asked about a
     * particular connection.
     *
     * @return void
     */
    public function testSupportedStrategiesReturnsAnArray(): void
    {
        $method = (new \ReflectionClass(SearchDriver::class))->getMethod('supportedStrategies');

        self::assertCount(0, $method->getParameters());
        self::assertSame('array', $this->returnTypeName($method));
    }

    /**
     * Test that index verification is asked per strategy and per connection, so
     * a driver serving two connections answers for each separately.
     *
     * @return void
     */
    public function testCanVerifyIndexBackingTakesAStrategyAndAConnection(): void
    {
        $method     = (new \ReflectionClass(SearchDriver::class))->getMethod('canVerifyIndexBacking');
        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('strategy', $parameters[0]->getName());
        self::assertSame(SearchStrategy::class, $this->parameterTypeName($parameters[0]));
        self::assertSame('connection', $parameters[1]->getName());
        self::assertSame(Connection::class, $this->parameterTypeName($parameters[1]));
        self::assertSame('bool', $this->returnTypeName($method));
    }

    /**
     * Test that the predicate is applied for a set of columns, a parsed term,
     * and a strategy, so a driver never parses client input itself.
     *
     * @return void
     */
    public function testApplyTakesTheColumnsTermAndStrategy(): void
    {
        $method     = (new \ReflectionClass(SearchDriver::class))->getMethod('apply');
        $parameters = $method->getParameters();

        self::assertCount(4, $parameters);
        self::assertSame(Builder::class, $this->parameterTypeName($parameters[0]));
        self::assertSame('array', $this->parameterTypeName($parameters[1]));
        self::assertSame(SearchTerm::class, $this->parameterTypeName($parameters[2]));
        self::assertSame(SearchStrategy::class, $this->parameterTypeName($parameters[3]));
        self::assertSame('void', $this->returnTypeName($method));
    }

    /**
     * Return the name of a parameter's declared type.
     *
     * @param  \ReflectionParameter  $parameter
     * @return string
     */
    private function parameterTypeName(\ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);

        return $type->getName();
    }

    /**
     * Return the name of a method's declared return type.
     *
     * @param  \ReflectionMethod  $method
     * @return string
     */
    private function returnTypeName(\ReflectionMethod $method): string
    {
        $type = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);

        return $type->getName();
    }
}
