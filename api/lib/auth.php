<?php
declare(strict_types=1);

/**
 * Simple password gate for the ladder / simulation pages.
 * Password lives in .env as LADDER_PASSWORD (plain) or
 * LADDER_PASSWORD_HASH (output of password_hash(), preferred).
 */

require_once __DIR__ . '/env.php';

function ladderSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    session_name('binance_ladder');
    // SameSite=Strict so the session cookie is never sent on cross-site
    // requests — that alone blocks CSRF against the buy/sell endpoints.
    @session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
    ]);
    @session_start();
}

function ladderPasswordHash(): string
{
    return trim((string) env('LADDER_PASSWORD_HASH', ''));
}

function ladderPasswordPlain(): string
{
    return (string) env('LADDER_PASSWORD', '');
}

function ladderPasswordConfigured(): bool
{
    return ladderPasswordHash() !== '' || ladderPasswordPlain() !== '';
}

function ladderVerifyPassword(string $candidate): bool
{
    if ($candidate === '') {
        return false;
    }

    $hash = ladderPasswordHash();
    if ($hash !== '') {
        return password_verify($candidate, $hash);
    }

    $plain = ladderPasswordPlain();
    if ($plain === '') {
        return false;
    }

    return hash_equals($plain, $candidate);
}

function ladderSessionTtl(): int
{
    return max(300, (int) env('LADDER_SESSION_TTL', '86400'));
}

function ladderIsAuthed(): bool
{
    ladderSessionStart();
    if (empty($_SESSION['ladder_auth'])) {
        return false;
    }

    $at = (int) ($_SESSION['ladder_auth_at'] ?? 0);
    if ($at > 0 && (time() - $at) > ladderSessionTtl()) {
        ladderLogout();
        return false;
    }

    return true;
}

function ladderLogin(string $password): bool
{
    ladderSessionStart();
    if (!ladderVerifyPassword($password)) {
        return false;
    }

    $_SESSION['ladder_auth'] = true;
    $_SESSION['ladder_auth_at'] = time();
    return true;
}

function ladderLogout(): void
{
    ladderSessionStart();
    unset($_SESSION['ladder_auth'], $_SESSION['ladder_auth_at']);
}

/** Guard for JSON endpoints — exits with 401 when not signed in. */
function ladderRequireApiAuth(): void
{
    if (ladderIsAuthed()) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode([
        'ok' => false,
        'error' => ladderPasswordConfigured()
            ? 'Not signed in.'
            : 'Set LADDER_PASSWORD in .env first.',
        'authRequired' => true,
        'passwordConfigured' => ladderPasswordConfigured(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
