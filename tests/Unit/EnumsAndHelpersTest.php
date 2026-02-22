<?php

declare(strict_types = 1);

namespace Tests\Unit;

use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use Tests\Fixtures\Enums\PureState;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class EnumsAndHelpersTest extends TestCase
{
    public function testCacheKeysResolveWithConfiguredPrefixAndReplacements(): void
    {
        config()->set('api-toolkit.cache.prefix', 'custom');

        $key = CacheKeys::MODEL_RELATIONS->resolveKey(['User', 'posts']);

        static::assertSame('custom:model-relations:User:posts', $key);
        static::assertSame('custom:model-resources:%s', CacheKeys::MODEL_RESOURCES->resolveKey());
    }

    public function testBackedEnumsExposeCodeUsingProvidesCodeTrait(): void
    {
        static::assertSame(10100, ErrorCode::BAD_REQUEST->getCode());
        static::assertSame(404, HttpStatus::NOT_FOUND->getCode());
    }

    public function testPureEnumHelperIsCaseInsensitiveAndRejectsNonStrings(): void
    {
        static::assertSame(PureState::ENABLED, PureState::tryFrom('enabled'));
        static::assertSame(PureState::DISABLED, PureState::tryFrom('DISABLED'));
        static::assertNull(PureState::tryFrom('unknown'));
        static::assertNull(PureState::tryFrom(123));
    }

    public function testFieldOrderingStrategyEnumValuesAreStable(): void
    {
        static::assertSame('default', FieldOrderingStrategy::DEFAULT->value);
        static::assertSame('by_requested_fields', FieldOrderingStrategy::BY_REQUESTED_FIELDS->value);
    }
}
