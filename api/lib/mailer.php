<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Send cron notification email via SMTP (PHPMailer).
 * Returns true on success, false if disabled / failed (never throws to cron).
 *
 * @param array<string, mixed> $payload Summary from cron run
 */
function sendCronEmail(array $payload): bool
{
    $enabled = strtolower(trim((string) env('CRON_EMAIL_ENABLED', '1')));
    if (in_array($enabled, ['0', 'false', 'no', 'off', ''], true)) {
        return false;
    }

    // always | actions | errors
    $mode = strtolower(trim((string) env('CRON_EMAIL_MODE', 'always')));
    $ok = !empty($payload['ok']);
    $actions = (int) ($payload['actions'] ?? 0);
    $hasError = !$ok || !empty($payload['error']);

    if ($mode === 'errors' && !$hasError) {
        return false;
    }
    if ($mode === 'actions' && !$hasError && $actions <= 0) {
        return false;
    }

    $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_readable($autoload)) {
        return false;
    }
    require_once $autoload;

    $host = trim((string) env('SMTP_HOST', 'mail.liveasoft.com'));
    $user = trim((string) env('SMTP_USER', 'info@liveasoft.com'));
    $pass = (string) env('SMTP_PASS', '');
    $port = (int) env('SMTP_PORT', '465');
    $from = trim((string) env('SMTP_FROM', $user));
    $fromName = trim((string) env('SMTP_FROM_NAME', 'Binance Watch Cron'));
    $to = trim((string) env('CRON_EMAIL_TO', 'mzaryabuddin@gmail.com'));
    $ccRaw = trim((string) env('CRON_EMAIL_CC', 'safee.hussainy@yahoo.com,malik.zaryab@liveasoft.com'));

    if ($host === '' || $user === '' || $pass === '' || $to === '') {
        return false;
    }

    $skipped = !empty($payload['skipped']);
    $ticks = (int) ($payload['ticks'] ?? 0);
    $items = (int) ($payload['items'] ?? 0);
    $serverAuto = !empty($payload['serverAuto']);
    $error = (string) ($payload['error'] ?? '');

    $status = $hasError ? 'ERROR' : ($actions > 0 ? 'TRADE' : ($skipped ? 'SKIPPED' : 'OK'));
    $subject = sprintf('[Binance Watch] Cron %s — %d action(s), %d tick(s)', $status, $actions, $ticks);

    $logLines = [];
    foreach (array_slice($payload['log'] ?? [], 0, 20) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sym = isset($row['symbol']) ? (string) $row['symbol'] . ' · ' : '';
        $logLines[] = '- ' . $sym . (string) ($row['msg'] ?? json_encode($row));
    }
    $logBlock = $logLines !== [] ? implode("\n", $logLines) : '- (no log rows)';

    $body = "Binance Watch — cron run summary\n"
        . "================================\n"
        . 'Time: ' . date('Y-m-d H:i:s T') . "\n"
        . 'Status: ' . $status . "\n"
        . 'OK: ' . ($ok ? 'yes' : 'no') . "\n"
        . 'Server auto: ' . ($serverAuto ? 'ON' : 'OFF') . "\n"
        . 'Skipped: ' . ($skipped ? 'yes' : 'no') . "\n"
        . 'Actions: ' . $actions . "\n"
        . 'Ticks: ' . $ticks . "\n"
        . 'Watch items: ' . $items . "\n"
        . ($error !== '' ? "Error: {$error}\n" : '')
        . "\nLog:\n{$logBlock}\n";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $port;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);

        foreach (preg_split('/[\s,;]+/', $ccRaw) ?: [] as $cc) {
            $cc = trim($cc);
            if ($cc !== '' && strcasecmp($cc, $to) !== 0) {
                $mail->addCC($cc);
            }
        }

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        return true;
    } catch (MailException $e) {
        // Never break the cron because mail failed
        return false;
    } catch (Throwable $e) {
        return false;
    }
}
