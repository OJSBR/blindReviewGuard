<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/bootstrap.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Autoloading for the test suite.
 *
 * The classes under test are deliberately free of OJS dependencies, so the suite
 * runs without bootstrapping the application, without a database and without the
 * PKP development dependencies - which the OJS release tarball does not ship.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'APP\\plugins\\generic\\blindReviewGuard\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/' . $relative . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/FixtureFactory.php';
