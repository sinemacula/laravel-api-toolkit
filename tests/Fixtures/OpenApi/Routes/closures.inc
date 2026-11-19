<?php

declare(strict_types = 1);

/**
 * An application route file declaring a closure route, loaded through the
 * router as a routes/*.php file is.
 *
 * Named .inc so nothing that loads every PHP file beneath the test root, as
 * exception discovery does, registers its route.
 */

use Illuminate\Support\Facades\Route;

Route::get('status', static fn (): array => ['status' => 'ok']);
