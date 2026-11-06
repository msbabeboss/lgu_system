<?php

require_once __DIR__ . '/test_helpers.php';
require_once __DIR__ . '/../helpers/mailer.php';

/**
 * Mailer test suite — fully OFFLINE.
 *
 * This suite NEVER performs a real SMTP send and never calls sendMail() (it
 * would hit the network via PHPMailer). It only verifies behavior that can be
 * confirmed by reading helpers/mailer.php:
 *
 *   - buildResetEmailHtml(): a pure function returning HTML that contains the
 *     recipient name, the reset URL, the expiry minutes, and "Password Reset".
 *   - The send* guards: every send* function early-returns on an empty toEmail
 *     or empty adminEmails, so calling them with empty input must return without
 *     throwing — and without reaching sendMail() (no network call).
 *
 * buildResetEmailHtml interpolates $name and $resetUrl RAW (no htmlspecialchars),
 * so a script payload in the name appears UNESCAPED in the output. That is the
 * function's real behavior; per instructions we assert what the code actually
 * does and document the raw interpolation rather than asserting it is escaped.
 * Production code is not modified here.
 *
 * @return int Number of failed assertions in this suite.
 */
function runMailerSuite(): int
{
    // ── buildResetEmailHtml — pure HTML builder ───────────────────────────
    $resetUrl = 'https://example.com/reset?token=abc123def456';
    $html = buildResetEmailHtml('Juan Dela Cruz', $resetUrl, '30');

    check(is_string($html), 'buildResetEmailHtml returns a string');
    check(str_contains($html, 'Juan Dela Cruz'), 'buildResetEmailHtml contains the recipient name');
    check(str_contains($html, $resetUrl), 'buildResetEmailHtml contains the reset URL');
    check(str_contains($html, '30 minutes'), 'buildResetEmailHtml contains the expiry minutes');
    check(str_contains($html, 'Password Reset'), "buildResetEmailHtml contains 'Password Reset'");

    // ── buildResetEmailHtml — name is interpolated RAW (current behavior) ──
    // The function does NOT htmlspecialchars() the name; it is placed directly
    // into <strong>{$name}</strong>. So a script payload survives unescaped.
    // We assert this real behavior (do not modify production code).
    $payload = '<script>alert(1)</script>';
    $htmlXss = buildResetEmailHtml($payload, 'https://example.com/reset', '60');
    check(
        str_contains($htmlXss, $payload),
        'buildResetEmailHtml interpolates the name raw — script payload appears unescaped (current behavior)'
    );
    // NOTE: the reset URL is likewise interpolated raw into href="{$resetUrl}"
    // and the "copy/paste" line; documented here, not asserted as escaped.

    // ── send* guards — empty input must early-return without sending ──────
    // Each function's first line guards on empty toEmail / empty adminEmails
    // and returns BEFORE reaching sendMail(), so these calls never touch SMTP.
    $guardCases = [
        'sendCertificateIssuedEmail with empty toEmail early-returns without throwing' =>
            static fn () => sendCertificateIssuedEmail('', 'Admin', 'Resident Name', 'Barangay Clearance', 'Test', 'Brgy 1'),
        'sendResidentAddedEmail with empty toEmail early-returns without throwing' =>
            static fn () => sendResidentAddedEmail('', 'Admin', 'Resident Name', 'Brgy 1', 'Male', '1990-01-01', 34, 'Single', 'Farmer'),
        'sendBlotterFiledEmail with empty adminEmails early-returns without throwing' =>
            static fn () => sendBlotterFiledEmail([], 'BLT-1-2024-0001', 'Title', 'Theft', 'Low', 'Location', '2024-01-01', 'Brgy 1', 'Admin', ''),
        'sendNoticeBroadcastEmails with empty adminEmails early-returns without throwing' =>
            static fn () => sendNoticeBroadcastEmails([], 'Notice Title', 'Notice content', 'Admin'),
    ];

    foreach ($guardCases as $message => $callable) {
        $threw = false;
        try {
            $callable();
        } catch (Throwable $e) {
            $threw = true;
        }
        check(!$threw, $message);
    }

    return finishSuite('Mailer');
}

// ── Standalone execution ──────────────────────────────────────────────────
if (!defined('RUNNER_MODE')) {
    sectionHeader('Mailer Suite');
    $failed = runMailerSuite();
    exit($failed > 0 ? 1 : 0);
}
