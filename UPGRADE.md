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

Configuration is declared at the class level via property overrides:

    class MyService extends Service
    {
        protected bool $useTransaction = false;

        protected bool $useLock = true;

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

### Default behavior

A service subclass with no property overrides behaves identically to the previous default:

- **Transactions:** enabled (`$useTransaction = true`)
- **Locking:** disabled (`$useLock = false`)
