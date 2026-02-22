<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit {
    use Tests\Fixtures\Support\FunctionOverrides;

    function function_exists(string $function): bool
    {
        if ($function === 'config_path' && FunctionOverrides::shouldPretendMissingConfigPath()) {
            return false;
        }

        return \function_exists($function);
    }

    function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed
    {
        if (FunctionOverrides::shouldThrowOnJsonDecode()) {
            throw new \RuntimeException('Forced decode failure for tests.');
        }

        return \json_decode($json, $associative, $depth, $flags);
    }
}

namespace SineMacula\ApiToolkit\Http\Routing {
    use Tests\Fixtures\Support\FunctionOverrides;

    function connection_aborted(): int
    {
        return FunctionOverrides::nextConnectionAborted();
    }

    function now(): \Carbon\Carbon
    {
        return FunctionOverrides::nextNow();
    }

    function sleep(int $seconds): int
    {
        FunctionOverrides::noteSleep();

        return 0;
    }

    function flush(): void
    {
        FunctionOverrides::noteFlush();
    }

    function ob_flush(): bool
    {
        FunctionOverrides::noteObFlush();

        return true;
    }
}

namespace SineMacula\ApiToolkit\Http\Controllers {
    use Tests\Fixtures\Support\FunctionOverrides;

    function flush(): void
    {
        FunctionOverrides::noteFlush();
    }

    function ob_flush(): bool
    {
        FunctionOverrides::noteObFlush();

        return true;
    }
}

namespace SineMacula\ApiToolkit\Repositories\Traits {
    use Tests\Fixtures\Support\FunctionOverrides;

    function class_uses(object|string $object, bool $autoload = true): array
    {
        return FunctionOverrides::forcedClassUses() ?? \class_uses($object, $autoload);
    }

    function call_user_func_array(callable $callback, array $args): mixed
    {
        if (FunctionOverrides::shouldInterceptTraitCallUserFunc()) {
            return FunctionOverrides::traitCallUserFuncReturn();
        }

        return $callback(...$args);
    }
}
