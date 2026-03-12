# Upgrade Guide

## From 1.x to 2.x

### Removed: Mutable service configuration methods

The following methods have been removed from the `Service` base class:

- `useTransaction()`
- `dontUseTransaction()`
- `useLock()`
- `dontUseLock()`

**Before (1.x):**

Callers configured service behavior at runtime using fluent methods:

    $service = new MyService($payload);
    $service->dontUseTransaction();
    $service->useLock();
    $service->run();

**After (2.x):**

Configuration is declared at the class level via method overrides:

    class MyService extends Service
    {
        protected function shouldUseTransaction(): bool
        {
            return false;
        }

        protected function shouldUseLock(): bool
        {
            return true;
        }

        protected function handle(): bool
        {
            // ...
        }

        protected function getLockId(): string
        {
            return 'my-service-lock-id';
        }
    }

The caller no longer needs to configure the service externally:

    $service = new MyService($payload);
    $service->run();

### Immutable configuration properties

The `$useTransaction` and `$useLock` properties on the `Service`
base class are now `protected readonly bool`. They are initialized
during construction via the `shouldUseTransaction()` and
`shouldUseLock()` methods. This prevents any post-construction
mutation of service configuration.

Subclasses that previously redeclared these properties with
different default values must now override the corresponding
method instead. Code that attempts to reassign these properties
after construction will produce a PHP error.

**Before (property override -- this no longer works):**

    class MyService extends Service
    {
        protected bool $useTransaction = false;

        protected function handle(): bool
        {
            // ...
        }
    }

**After (method override):**

    class MyService extends Service
    {
        protected function shouldUseTransaction(): bool
        {
            return false;
        }

        protected function handle(): bool
        {
            // ...
        }
    }

### Lock key generation via LockKeyProvider contract

The `Lockable` trait no longer declares
`abstract generateLockKey()`. The `Service` class now implements
`LockKeyProvider` with a `getLockKey()` method that contains the
same logic.

**Impact on Service subclasses:** Subclasses that previously
overrode `generateLockKey()` must now override `getLockKey()`
instead. The method visibility changes from `protected` to
`public`.

**Before:**

    class MyService extends Service
    {
        protected function generateLockKey(): string
        {
            return sha1('custom-key');
        }
    }

**After:**

    class MyService extends Service
    {
        public function getLockKey(): string
        {
            return sha1('custom-key');
        }
    }

**Impact on standalone Lockable consumers:** Classes using
`Lockable` without extending `Service` should implement
`LockKeyProvider` and provide a `getLockKey()` method instead of
overriding `generateLockKey()`.

**Before:**

    class MyJob
    {
        use Lockable;

        protected function generateLockKey(): string
        {
            return sha1('job-lock');
        }
    }

**After:**

    use SineMacula\ApiToolkit\Contracts\LockKeyProvider;

    class MyJob implements LockKeyProvider
    {
        use Lockable;

        public function getLockKey(): string
        {
            return sha1('job-lock');
        }
    }

### Removed: ServiceLockException

The `ServiceLockException` class has been removed. It was never
thrown by the framework -- lock acquisition failures use
`TooManyRequestsException`.

Any code that catches `ServiceLockException` should be updated
to catch `TooManyRequestsException` instead (or removed if the
catch block was unreachable).

### Default behavior

A service subclass with no method overrides behaves identically to the previous default:

- **Transactions:** enabled (`shouldUseTransaction()` returns `true`)
- **Locking:** disabled (`shouldUseLock()` returns `false`)
