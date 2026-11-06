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
 * buildResetEmailHtml HTML-escapes $name via htmlspecialchars before placing it
 * into the email (security fix); $resetUrl is interpolated raw because it is
 * system-generated (EMAIL_BASE_URL + a hex token), so it is safe and left as-is.
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

    // ── buildResetEmailHtml — name is HTML-escaped (security) ────────────
    // The function now htmlspecialchars()s the name before placing it into
    // <strong>{$nameEsc}</strong>, so a script payload must NOT survive as a
    // live tag — the < and > are escaped to &lt; and &gt;.
    $payload = '<script>alert(1)</script>';
    $htmlXss = buildResetEmailHtml($payload, 'https://example.com/reset', '60');
    check(
        !str_contains($htmlXss, $payload),
        'buildResetEmailHtml HTML-escapes the name — script payload does not appear as a live tag'
    );
    check(
        str_contains($htmlXss, '&lt;script&gt;'),
        'buildResetEmailHtml escapes the script payload to HTML entities'
    );
    // NOTE: the reset URL is interpolated raw into href="{$resetUrl}" and the
    // "copy/paste" line; it is system-generated (EMAIL_BASE_URL + hex token) so
    // it is safe and left unescaped.

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
