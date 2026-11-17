<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// The driver and client obtain their loggers through TYPO3's LogManager. Without a bootstrapped
// TYPO3 there is no log configuration; an empty one gives loggers without writers, so nothing is
// written to disk during the tests.
$GLOBALS['TYPO3_CONF_VARS']['LOG'] = [];
