<?php

declare(strict_types = 1);

namespace Tests\Concerns;

trait InteractsWithNonPublicMembers
{
    protected function invokeNonPublic(object|string $target, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass($target);
        $methodRef  = $reflection->getMethod($method);

        $methodRef->setAccessible(true);

        return $methodRef->invokeArgs(is_object($target) ? $target : null, $arguments);
    }

    protected function setNonPublicProperty(object|string $target, string $property, mixed $value): void
    {
        $reflection  = new \ReflectionClass($target);
        $propertyRef = $reflection->getProperty($property);

        $propertyRef->setAccessible(true);
        $propertyRef->setValue(is_object($target) ? $target : null, $value);
    }

    protected function getNonPublicProperty(object|string $target, string $property): mixed
    {
        $reflection  = new \ReflectionClass($target);
        $propertyRef = $reflection->getProperty($property);

        $propertyRef->setAccessible(true);

        return $propertyRef->getValue(is_object($target) ? $target : null);
    }
}
