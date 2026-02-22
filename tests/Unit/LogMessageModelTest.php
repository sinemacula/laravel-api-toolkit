<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use SineMacula\ApiToolkit\Models\LogMessage;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class LogMessageModelTest extends TestCase
{
    public function testPrunableQueryTargetsRowsOlderThanConfiguredRetentionWindow(): void
    {
        config()->set('logging.channels.database.days', 7);

        LogMessage::query()->create([
            'level'      => 'INFO',
            'message'    => 'old',
            'context'    => json_encode(['a' => 1]),
            'created_at' => CarbonImmutable::now()->subDays(10),
        ]);

        LogMessage::query()->create([
            'level'      => 'INFO',
            'message'    => 'new',
            'context'    => json_encode(['a' => 1]),
            'created_at' => CarbonImmutable::now(),
        ]);

        $messages = (new LogMessage)->prunable()->pluck('message')->all();

        static::assertSame(['old'], $messages);
    }
}
