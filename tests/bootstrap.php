<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/bootstrap.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Bootstrap for the test suite.
 *
 * The scanners are free of OJS dependencies and run anywhere. When the plugin is
 * installed in plugins/generic/blindReviewGuard of an OJS 3.5 installation, the
 * application around it is bootstrapped too, so that the plugin classes are
 * compiled against the real PKP classes they extend: a signature that does not
 * match this PKP version is a fatal error, and that is exactly what the suite
 * must catch before a release does. Under PKP's PHPUnit configuration the
 * application is already loaded.
 */

if (!class_exists('\PKP\plugins\GenericPlugin')) {
    $ojsRoot = dirname(__DIR__, 4);
    if (is_file($ojsRoot . '/lib/pkp/includes/bootstrap.php')) {
        chdir($ojsRoot);
        define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
        require_once $ojsRoot . '/lib/pkp/includes/bootstrap.php';
    }
}

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
require_once __DIR__ . '/PoFile.php';
