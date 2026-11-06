<?php

/**
 * Combined test runner — runs every suite under tests/ in one command.
 *
 * Usage:   php tests/run_all.php
 *
 * It loads the shared helpers, then the CSRF suite (tests/csrf_check.php) and
 * the mailer suite (tests/mailer_check.php). Each suite exposes a run*Suite()
 * function, so including the files only defines them — the suites are run
 * once each here (no double execution of the CSRF source inventory scan).
 *
 * Exits 0 only if every assertion across both suites passed; otherwise 1.
 */

// Mark that we are orchestrating the suites, so the per-file standalone blocks
// (which would call run*Suite() and exit on their own) are skipped on include.
define('RUNNER_MODE', true);

require_once __DIR__ . '/test_helpers.php';
require_once __DIR__ . '/csrf_check.php';
require_once __DIR__ . '/mailer_check.php';

sectionHeader('CSRF Protection Suite');
$csrfFailed = runCsrfSuite(false);
$csrfPassed = $GLOBALS['last_suite_passed'] ?? 0;

sectionHeader('Mailer Suite');
$mailerFailed = runMailerSuite();
$mailerPassed = $GLOBALS['last_suite_passed'] ?? 0;

$totalPassed = $csrfPassed + $mailerPassed;
$totalFailed = $csrfFailed + $mailerFailed;

echo "\n========================================\n";
echo "Combined Test Results\n";
echo "========================================\n";
echo sprintf("CSRF Protection Suite: %d passed, %d failed\n", $csrfPassed, $csrfFailed);
echo sprintf("Mailer Suite:          %d passed, %d failed\n", $mailerPassed, $mailerFailed);
echo "----------------------------------------\n";
echo sprintf("Grand total: %d passed, %d failed across 2 suites.\n", $totalPassed, $totalFailed);

exit($totalFailed > 0 ? 1 : 0);
