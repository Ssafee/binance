<?php
declare(strict_types=1);

/**
 * Shared API bootstrap: error reporting + JSON errors for AJAX.
 */
if (defined('BINANCE_BOOTSTRAP')) {
    return;
}
define('BINANCE_BOOTSTRAP', true);

$envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
$appDebug = true; // default ON so 500s show a message
if (is_readable($envFile)) {
    $envRaw = (string) file_get_contents($envFile);
    if (preg_match('/^\s*APP_DEBUG\s*=\s*(.+)$/mi', $envRaw, $m)) {
        $val = strtolower(trim($m[1], " \t\r\n\"'"));
        $appDebug = in_array($val, ['1', 'true', 'yes', 'on'], true);
    }
}
define('APP_DEBUG', $appDebug);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');

function apiJsonFlags(): int
{
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return $flags;
}

/**
 * @param array<string, mixed> $extra
 */
function apiEmitError(int $status, string $message, array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $payload = array_merge([
        'ok' => false,
        'error' => $message,
        'debug' => APP_DEBUG,
        'php' => PHP_VERSION,
    ], $extra);
    echo json_encode($payload, apiJsonFlags()) ?: '{"ok":false,"error":"encode fail"}';
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    apiEmitError(500, $e->getMessage(), APP_DEBUG ? [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'type' => get_class($e),
    ] : []);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    // Avoid double output if something already printed JSON
    if (headers_sent()) {
        return;
    }
    apiEmitError(500, $err['message'], APP_DEBUG ? [
        'file' => $err['file'],
        'line' => $err['line'],
        'type' => 'Fatal',
    ] : []);
});
