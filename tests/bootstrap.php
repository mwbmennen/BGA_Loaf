<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/BgaFrameworkStubs.php';

$constants = __DIR__ . '/../modules/php/constants.inc.php';
if (file_exists($constants)) {
    require_once $constants;
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Bga\\Games\\loaf\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/../modules/php/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

// TODO (SETUP.md step 7): if your tests need a test double for your real Game class (a
// "FakeGame" that overrides framework-facing methods with call-tracking), require it here,
// the way this line's predecessor did in the project this template was extracted from.
