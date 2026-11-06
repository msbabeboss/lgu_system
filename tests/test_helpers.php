<?php

/**
 * Shared test bootstrap + helpers for the LGU System test suites.
 *
 * Loaded by tests/csrf_check.php, tests/mailer_check.php, and tests/run_all.php.
 * Provides:
 *   - $failures (array) and $passed (int) globals
 *   - check(bool $cond, string $msg)   — prints [PASS]/[FAIL], tracks results
 *   - sectionHeader(string $name)      — prints a suite banner
 *   - finishSuite(string $name): int    — prints a per-suite summary, returns
 *                                         the failure count, and resets counters
 *
 * Bootstrap: requires helpers/functions.php (the same bootstrap the original
 * csrf_check.php used). The mailer suite additionally needs BASE_PATH, APP_NAME,
 * MAIL_*, EMAIL_BASE_URL and the Composer autoloader; those are defined by
 * config/config.php, which is loaded here when BASE_PATH is not yet defined.
 */

require_once __DIR__ . '/../helpers/functions.php';

// The mailer constants + Composer autoloader live in config/config.php.
// helpers/functions.php alone does not define BASE_PATH, so load the config
// loader (which also pulls in the autoloader) when it has not been loaded yet.
//
// config/config.php begins with a blank line before its opening <?php tag, and
// it calls session ini-settings + session_start(). Under the CLI SAPI that
// leading output would mark headers as already sent and trigger warnings on
// the session calls. We capture any stray output in a buffer (so headers stay
// unsent through the session bootstrap) and then discard it, keeping the test
// output clean. Production config is not modified.
if (!defined('BASE_PATH')) {
    ob_start();
    require_once __DIR__ . '/../config/config.php';
    ob_end_clean();
}

$failures = [];
$passed = 0;

/**
 * Assert a condition, printing [PASS]/[FAIL] and tracking the result.
 */
function check(bool $condition, string $message): void
{
    global $failures, $passed;

    if ($condition) {
        $passed++;
        echo "[PASS] {$message}\n";
        return;
    }

    $failures[] = $message;
    echo "[FAIL] {$message}\n";
}

/**
 * Print a visible suite banner.
 */
function sectionHeader(string $name): void
{
    echo "\n========================================\n";
    echo "{$name}\n";
    echo "========================================\n";
}

/**
 * Print a per-suite summary, expose the per-suite counts via globals for a
 * combined runner, reset the counters so the next suite starts clean, and
 * return the number of failures in this suite.
 */
function finishSuite(string $name): int
{
    global $failures, $passed;

    $suitePassed = $passed;
    $suiteFailed = count($failures);

    echo sprintf("\n--- %s: %d passed, %d failed ---\n", $name, $suitePassed, $suiteFailed);

    // Expose per-suite counts so a combined runner (run_all.php) can total them.
    $GLOBALS['last_suite_passed'] = $suitePassed;
    $GLOBALS['last_suite_failed'] = $suiteFailed;

    $failures = [];
    $passed = 0;

    return $suiteFailed;
}
