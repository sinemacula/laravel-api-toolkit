<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Services\StubActor;

/**
 * Tests for the ServiceConcern interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class ServiceConcernTest extends TestCase
{
    /**
     * Test that an implementer receives a ServiceContext and next closure
     * and returns the (possibly transformed) value.
     *
     * @return void
     */
    public function testHandleReceivesContextAndNext(): void
    {
        $nextCalled = false;

        $context = ServiceContext::for(new StubActor);

        $concern = new class implements ServiceConcern {
            /**
             * Handle the concern, wrapping the next step result.
             *
             * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
             * @param  \Closure(): mixed  $next
             * @return mixed
             */
            #[\Override]
            public function handle(ServiceContext $context, \Closure $next): mixed
            {
                return ['wrapped' => $next()];
            }
        };

        $result = $concern->handle($context, function () use (&$nextCalled): string {
            $nextCalled = true;

            return 'inner';
        });

        self::assertTrue($nextCalled);
        self::assertSame(['wrapped' => 'inner'], $result);
    }

    /**
     * Test that the first parameter of handle() is ServiceContext, not Service.
     *
     * @return void
     */
    public function testContractDoesNotReferenceService(): void
    {
        $reflection = new \ReflectionClass(ServiceConcern::class);

        self::assertTrue($reflection->hasMethod('handle'));

        $method     = $reflection->getMethod('handle');
        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);

        $contextParam = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $contextParam);
        self::assertSame(ServiceContext::class, $contextParam->getName());

        $nextParam = $parameters[1]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $nextParam);
        self::assertSame(\Closure::class, $nextParam->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('mixed', $returnType->getName());
    }
}
