<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

use Carbon\Carbon;

final class FunctionOverrides
{
    private static bool $forceMissingConfigPath = false;
    private static bool $throwOnJsonDecode      = false;

    /** @var array<int, int> */
    private static array $connectionAbortedSequence = [1];
    private static int $sleepCalls                  = 0;
    private static int $flushCalls                  = 0;
    private static int $obFlushCalls                = 0;
    private static ?Carbon $baseNow                 = null;
    private static int $nowTick                     = 0;

    /** @var array<int, string>|null */
    private static ?array $forcedClassUses          = null;
    private static bool $interceptTraitCallUserFunc = false;
    private static mixed $traitCallUserFuncReturn   = null;

    public static function reset(): void
    {
        self::$forceMissingConfigPath     = false;
        self::$throwOnJsonDecode          = false;
        self::$connectionAbortedSequence  = [1];
        self::$sleepCalls                 = 0;
        self::$flushCalls                 = 0;
        self::$obFlushCalls               = 0;
        self::$baseNow                    = Carbon::create(2025, 1, 1, 0, 0, 0, 'UTC');
        self::$nowTick                    = 0;
        self::$forcedClassUses            = null;
        self::$interceptTraitCallUserFunc = false;
        self::$traitCallUserFuncReturn    = null;
    }

    public static function forceMissingConfigPath(bool $value): void
    {
        self::$forceMissingConfigPath = $value;
    }

    public static function shouldPretendMissingConfigPath(): bool
    {
        return self::$forceMissingConfigPath;
    }

    public static function throwOnJsonDecode(bool $value): void
    {
        self::$throwOnJsonDecode = $value;
    }

    public static function shouldThrowOnJsonDecode(): bool
    {
        return self::$throwOnJsonDecode;
    }

    /**
     * @param  array<int, int>  $sequence
     */
    public static function setConnectionAbortedSequence(array $sequence): void
    {
        self::$connectionAbortedSequence = $sequence;
    }

    public static function nextConnectionAborted(): int
    {
        if (self::$connectionAbortedSequence === []) {
            return 1;
        }

        return (int) array_shift(self::$connectionAbortedSequence);
    }

    public static function noteSleep(): void
    {
        self::$sleepCalls++;
    }

    public static function sleepCalls(): int
    {
        return self::$sleepCalls;
    }

    public static function noteFlush(): void
    {
        self::$flushCalls++;
    }

    public static function flushCalls(): int
    {
        return self::$flushCalls;
    }

    public static function noteObFlush(): void
    {
        self::$obFlushCalls++;
    }

    public static function obFlushCalls(): int
    {
        return self::$obFlushCalls;
    }

    public static function nextNow(): Carbon
    {
        self::$baseNow ??= Carbon::create(2025, 1, 1, 0, 0, 0, 'UTC');

        $now = self::$baseNow->copy()->addSeconds(self::$nowTick * 30);

        self::$nowTick++;

        return $now;
    }

    /**
     * @param  array<int, string>|null  $uses
     */
    public static function forceClassUses(?array $uses): void
    {
        self::$forcedClassUses = $uses;
    }

    /**
     * @return array<int, string>|null
     */
    public static function forcedClassUses(): ?array
    {
        return self::$forcedClassUses;
    }

    public static function interceptTraitCallUserFunc(bool $intercept, mixed $return = null): void
    {
        self::$interceptTraitCallUserFunc = $intercept;
        self::$traitCallUserFuncReturn    = $return;
    }

    public static function shouldInterceptTraitCallUserFunc(): bool
    {
        return self::$interceptTraitCallUserFunc;
    }

    public static function traitCallUserFuncReturn(): mixed
    {
        return self::$traitCallUserFuncReturn;
    }
}
