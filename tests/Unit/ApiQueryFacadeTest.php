<?php

declare(strict_types = 1);

namespace Tests\Unit;

use SineMacula\ApiToolkit\Facades\ApiQuery;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ApiQueryFacadeTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    public function testFacadeAccessorComesFromConfiguration(): void
    {
        config()->set('api-toolkit.parser.alias', 'custom.alias');

        $accessor = $this->invokeNonPublic(ApiQuery::class, 'getFacadeAccessor');

        static::assertSame('custom.alias', $accessor);
    }
}
