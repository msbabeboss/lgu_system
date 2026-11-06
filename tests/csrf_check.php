<?php

require_once __DIR__ . '/test_helpers.php';

/**
 * Reset the request superglobals used by the CSRF helpers between assertions.
 */
function resetCsrfRequest(array $session = [], array $post = [], array $server = []): void
{
    $_SESSION = $session;
    $_POST = $post;
    $_SERVER = $server;
}

function collectPhpFiles(string $root): array
{
    $excluded = ['.git', '.qodo', '.agents', '.vscode', 'vendor', 'uploads', 'tests'];
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1);
        $segments = preg_split('~[\\\\/]~', $relative);
        if (array_intersect($excluded, $segments)) {
            continue;
        }

        $files[$relative] = file_get_contents($file->getPathname());
    }

    ksort($files);
    return $files;
}

function inspectPhpFile(string $source): array
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    preg_match_all('~<form\b([^>]*)>(.*?)</form\s*>~is', $code, $forms, PREG_SET_ORDER);

    $postForms = [];
    foreach ($forms as $form) {
        if (!preg_match('~\bmethod\s*=\s*["\']?post\b~i', $form[1])) {
            continue;
        }

        $postForms[] = (bool) preg_match(
            '~csrfField\s*\(|name\s*=\s*["\']csrf_token["\']|csrf_token~i',
            $form[2]
        );
    }

    $hasServerPost = (bool) preg_match(
        '~\$_POST\b|REQUEST_METHOD.{0,80}(?:===|==)\s*["\']POST["\']~is',
        $code
    );
    $hasClientPost = (bool) preg_match('~method\s*:\s*["\']POST["\']~i', $code);
    $hasGuard = (bool) preg_match('~verifyCsrf(?:OrAbort|Token)\s*\(~', $code);
    $hasDynamicToken = (bool) preg_match('~csrfMeta\s*\(|csrf-token|csrf_token~i', $code);

    return [
        'has_server_post' => $hasServerPost,
        'has_client_post' => $hasClientPost,
        'has_guard' => $hasGuard,
        'post_forms' => count($postForms),
        'forms_with_token' => count(array_filter($postForms)),
        'forms_missing_token' => count(array_filter($postForms, static fn (bool $hasToken): bool => !$hasToken)),
        'has_dynamic_token' => $hasDynamicToken,
    ];
}

/**
 * Run the CSRF behavior checks and the static source inventory.
 *
 * @param bool $writeReport When true, regenerate _csrf_reference/COVERAGE.md.
 * @return int Number of failed assertions in this suite.
 */
function runCsrfSuite(bool $writeReport = false): int
{
    // ── Behavior checks ───────────────────────────────────────────────────
    resetCsrfRequest();
    $token = generateCsrfToken();
    check((bool) preg_match('/\A[a-f0-9]{64}\z/', $token), 'Token is 64 lowercase hexadecimal characters');
    check(generateCsrfToken() === $token, 'Token remains stable during the same session');

    resetCsrfRequest(['csrf_token' => $token], ['csrf_token' => $token]);
    check(verifyCsrfToken(), 'Matching form token is accepted');

    resetCsrfRequest(['csrf_token' => $token]);
    check(!verifyCsrfToken(), 'Missing token is rejected');

    resetCsrfRequest(['csrf_token' => $token], ['csrf_token' => str_repeat('0', 64)]);
    check(!verifyCsrfToken(), 'Incorrect form token is rejected');

    resetCsrfRequest(['csrf_token' => $token], ['csrf_token' => ['malformed']]);
    check(!verifyCsrfToken(), 'Array-valued form token is rejected without a type error');

    resetCsrfRequest(['csrf_token' => 'malformed'], ['csrf_token' => 'malformed']);
    check(!verifyCsrfToken(), 'Malformed session token is rejected');

    resetCsrfRequest(['csrf_token' => $token], [], ['HTTP_X_CSRF_TOKEN' => $token]);
    check(verifyCsrfToken(), 'Matching X-CSRF-Token header is accepted when no form token is sent');

    resetCsrfRequest(
        ['csrf_token' => $token],
        ['csrf_token' => str_repeat('0', 64)],
        ['HTTP_X_CSRF_TOKEN' => $token]
    );
    check(!verifyCsrfToken(), 'Incorrect form token is not overridden by a valid header');

    resetCsrfRequest(['csrf_token' => ['malformed']]);
    $replacementToken = generateCsrfToken();
    check(
        (bool) preg_match('/\A[a-f0-9]{64}\z/', $replacementToken),
        'Malformed session token is replaced with a valid token'
    );

    resetCsrfRequest(['csrf_token' => $token]);
    $field = csrfField();
    check(
        str_contains($field, 'type="hidden"')
            && str_contains($field, 'name="csrf_token"')
            && str_contains($field, htmlspecialchars($token, ENT_QUOTES, 'UTF-8')),
        'csrfField() renders the session token in an escaped hidden input'
    );

    // ── Static source inventory (review each route; this does not send requests) ──
    $root = dirname(__DIR__);
    $inventory = [];
    foreach (collectPhpFiles($root) as $path => $source) {
        if ($path === 'helpers\\functions.php' || $path === 'helpers/functions.php') {
            continue;
        }

        $inspection = inspectPhpFile($source);
        if ($inspection['has_server_post'] || $inspection['has_client_post'] || $inspection['post_forms'] > 0) {
            $inventory[$path] = $inspection;
        }
    }

    $lines = [
        '# CSRF static source inventory',
        '',
        'Generated by `php tests/csrf_check.php --write-report`. This is a source scan, not proof that each action is protected; review routes and run the staging procedure in [CSRF_DOCUMENTATION.md](./CSRF_DOCUMENTATION.md).',
        '',
        '| PHP file | Server POST code | Client POST call | CSRF guard reference | Literal POST forms |',
        '|---|---|---|---|---|',
    ];

    foreach ($inventory as $path => $inspection) {
        $server = $inspection['has_server_post'] ? 'yes' : 'no';
        $client = $inspection['has_client_post'] ? 'yes' : 'no';
        $guard = $inspection['has_guard'] ? 'helper found' : 'not found';
        $forms = $inspection['post_forms'] === 0
            ? 'none found'
            : sprintf(
                '%d/%d inline token refs',
                $inspection['forms_with_token'],
                $inspection['post_forms']
            );

        if ($inspection['post_forms'] > 0 && $inspection['forms_missing_token'] > 0 && $inspection['has_dynamic_token']) {
            $forms .= '; dynamic token ref also found (manual check)';
        }

        $markdownPath = str_replace('\\', '/', $path);
        $lines[] = sprintf('| `%s` | %s | %s | %s | %s |', $markdownPath, $server, $client, $guard, $forms);
    }

    $lines[] = '';
    $lines[] = '## Items to review';
    $lines[] = '';
    $lines[] = '- `ajax/pusher_auth.php` reads POST fields without the CSRF helper. It is an authenticated Pusher channel-authorization endpoint, not a data-changing action; separately verify its login requirement and restriction to the current user channel. Add CSRF validation if its behavior is changed to mutate application data or policy requires every POST to carry a token.';
    $lines[] = '- Files with a client POST but no server POST handler are callers/templates; follow the request to its endpoint and verify that the caller sends a token and the endpoint validates it.';
    $lines[] = '- A helper reference anywhere in a file does not establish that every action branch is guarded. Check each handler before its first side effect.';
    $lines[] = '';
    $lines[] = 'The scan excludes `vendor/`, `uploads/`, helper library definitions, and the test script itself.';
    $report = implode("\n", $lines) . "\n";

    echo "\nStatic source inventory (review each route; this does not send requests):\n";
    foreach ($inventory as $path => $inspection) {
        printf(
            "- %s — server POST: %s; client POST: %s; guard: %s; POST forms: %d/%d token refs\n",
            $path,
            $inspection['has_server_post'] ? 'yes' : 'no',
            $inspection['has_client_post'] ? 'yes' : 'no',
            $inspection['has_guard'] ? 'found' : 'not found',
            $inspection['forms_with_token'],
            $inspection['post_forms']
        );
    }

    if ($writeReport) {
        $reportPath = $root . '/_csrf_reference/COVERAGE.md';
        if (file_put_contents($reportPath, $report) === false) {
            fwrite(STDERR, "Could not write {$reportPath}\n");
            exit(1);
        }
        echo "\nUpdated _csrf_reference/COVERAGE.md\n";
    }

    echo sprintf("POST-related PHP files inventoried: %d.\n", count($inventory));

    return finishSuite('CSRF Protection');
}

// ── Standalone execution ──────────────────────────────────────────────────
if (!defined('RUNNER_MODE')) {
    sectionHeader('CSRF Protection Suite');
    $writeReport = in_array('--write-report', $argv ?? [], true);
    $failed = runCsrfSuite($writeReport);
    exit($failed > 0 ? 1 : 0);
}
