<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/BgaFrameworkStubs.php';
require_once __DIR__ . '/../modules/php/constants.inc.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

spl_autoload_register(static function (string $class): void {
    // TODO (SETUP.md step 7): replace YourGameName with your project's real PHP namespace
    // segment -- verify the exact casing Studio's scaffold generated with
    // `grep -rn "^namespace" modules/php/` before assuming it matches your lowercase slug.
    $prefix = 'Bga\\Games\\YourGameName\\';

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
