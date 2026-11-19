<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

ini_set('display_errors', '0');

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Vary: Accept');

const CONTACT_SENT = 'Το αίτημά σας στάλθηκε. Θα επικοινωνήσουμε μαζί σας σύντομα.';
const CONTACT_UNAVAILABLE = 'Η φόρμα είναι προσωρινά μη διαθέσιμη. Δοκιμάστε ξανά ή επικοινωνήστε μαζί μας τηλεφωνικά.';
const CONTACT_EVENTS = ['Γάμος', 'Βάπτιση', 'Ιδιωτική εκδήλωση', 'Εταιρική εκδήλωση'];

function respond(int $status, string $message, array $errors = []): never
{
    http_response_code($status);
    $ok = $status >= 200 && $status < 300;

    if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=UTF-8');
        $payload = ['ok' => $ok, 'message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    $title = $ok ? 'Το αίτημα στάλθηκε' : 'Αίτημα επικοινωνίας';
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $items = '';
    foreach ($errors as $error) {
        $items .= '<li>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $errorList = $items === '' ? '' : '<ul>' . $items . '</ul>';

    echo <<<HTML
    <!doctype html>
    <html lang="el">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{$title} | Patsilias Events</title>
        <style>
            body { margin: 0; background: #f4f1eb; color: #182333; font: 1rem/1.6 system-ui, sans-serif; }
            main { max-width: 40rem; margin: 10vh auto 0; padding: 2rem 1.25rem; }
            a { color: inherit; text-underline-offset: .2em; }
        </style>
    </head>
    <body>
        <main>
            <h1>{$title}</h1>
            <p>{$safeMessage}</p>
            {$errorList}
            <a href="./#contact">Επιστροφή στην επικοινωνία</a>
        </main>
    </body>
    </html>
    HTML;
    exit;
}

function formValue(string $field, string $label, int $maxLength, array &$errors, bool $required = false): string
{
    $raw = $_POST[$field] ?? '';
    if (!is_string($raw) || strlen($raw) > $maxLength * 4 || preg_match('//u', $raw) !== 1) {
        $errors[$field] = "Ελέγξτε το πεδίο «{$label}».";
        return '';
    }

    $value = trim($raw, " \t\n\r");
    $forbiddenControls = $field === 'message' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    if (preg_match($forbiddenControls, $value) !== 0 || preg_match_all('/./us', $value) > $maxLength) {
        $errors[$field] = "Ελέγξτε το πεδίο «{$label}» (έως {$maxLength} χαρακτήρες).";
        return '';
    }
    if ($required && ($value === '' || preg_match('/^\s+$/u', $value) === 1)) {
        $errors[$field] = "Συμπληρώστε το πεδίο «{$label}».";
    }
    return $value;
}

function loadConfig(string $privateDirectory): array
{
    $path = $privateDirectory . '/contact-config.php';
    if (!is_readable($path)) {
        throw new RuntimeException('Missing contact configuration');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid contact configuration');
    }
    foreach (['smtp_host', 'smtp_encryption', 'smtp_username', 'smtp_password', 'from_email', 'from_name', 'to_email', 'rate_limit_secret'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
            throw new RuntimeException('Incomplete contact configuration');
        }
    }
    $port = $config['smtp_port'] ?? null;
    if (
        filter_var($config['smtp_host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || !is_int($port) || $port < 1 || $port > 65535
        || !in_array($config['smtp_encryption'], ['starttls', 'smtps'], true)
        || filter_var($config['from_email'], FILTER_VALIDATE_EMAIL) === false
        || filter_var($config['to_email'], FILTER_VALIDATE_EMAIL) === false
        || strlen($config['rate_limit_secret']) < 32
    ) {
        throw new RuntimeException('Invalid contact configuration');
    }
    return $config;
}

/**
 * Reserve one SMTP attempt, or return the number of seconds until a slot is available.
 * Keep at most 30 timestamped, pseudonymous records in the private folder.
 */
function reserveAttempt(string $privateDirectory, string $secret): int
{
    $directory = realpath($privateDirectory);
    if ($directory === false) {
        throw new RuntimeException('Missing throttle directory');
    }

    $lock = @fopen($directory . '/contact-rate.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Cannot open throttle lock');
    }

    $temporaryPath = null;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock throttle state');
        }
        $now = time();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $packedIp = @inet_pton($ip);
        $sender = hash_hmac('sha256', $packedIp === false ? 'unknown' : $packedIp, $secret);
        $statePath = $directory . '/contact-rate.json';
        $records = [];
        if (is_file($statePath)) {
            $state = @file_get_contents($statePath, false, null, 0, 8193);
            if ($state === false || strlen($state) > 8192) {
                throw new RuntimeException('Cannot read throttle state');
            }
            $records = json_decode($state, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($records)) {
                throw new RuntimeException('Invalid throttle state');
            }
        }

        $recent = [];
        $senderAttempts = [];
        foreach ($records as $record) {
            if (!is_array($record) || !is_int($record['at'] ?? null) || !is_string($record['sender'] ?? null)) {
                throw new RuntimeException('Invalid throttle record');
            }
            if ($record['at'] <= $now - 3600) {
                continue;
            }
            $recent[] = $record;
            if ($record['sender'] === $sender && $record['at'] > $now - 900) {
                $senderAttempts[] = $record['at'];
            }
        }
        if (count($recent) >= 30) {
            return max(1, $recent[0]['at'] + 3600 - $now);
        }
        if (count($senderAttempts) >= 4) {
            return max(1, $senderAttempts[0] + 900 - $now);
        }

        $recent[] = ['at' => $now, 'sender' => $sender];
        $payload = json_encode($recent, JSON_THROW_ON_ERROR);
        $temporaryPath = @tempnam($directory, '.contact-rate-');
        if (
            $temporaryPath === false
            || dirname($temporaryPath) !== $directory
            || @file_put_contents($temporaryPath, $payload) !== strlen($payload)
            || !@rename($temporaryPath, $statePath)
        ) {
            throw new RuntimeException('Cannot update throttle state');
        }
        $temporaryPath = null;
        return 0;
    } finally {
        if (is_string($temporaryPath)) {
            @unlink($temporaryPath);
        }
        fclose($lock);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'Χρησιμοποιήστε τη φόρμα επικοινωνίας για να στείλετε αίτημα.');
}
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    respond(403, 'Η υποβολή πρέπει να γίνει από τη σελίδα επικοινωνίας.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    respond(413, 'Το αίτημα είναι πολύ μεγάλο. Μειώστε το μήνυμά σας και δοκιμάστε ξανά.');
}
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
if ($contentType !== 'application/x-www-form-urlencoded') {
    respond(415, 'Χρησιμοποιήστε τη φόρμα επικοινωνίας για να στείλετε αίτημα.');
}
if (($_POST['website'] ?? '') !== '') {
    respond(200, CONTACT_SENT);
}

$errors = [];
$name = formValue('name', 'Όνομα', 120, $errors, true);
$phone = formValue('phone', 'Τηλέφωνο', 40, $errors, true);
$email = formValue('email', 'Email', 254, $errors);
$date = formValue('date', 'Ημερομηνία', 10, $errors);
$event = formValue('event', 'Τύπος εκδήλωσης', 40, $errors, true);
$venue = formValue('venue', 'Χώρος / Περιοχή', 180, $errors);
$message = formValue('message', 'Μήνυμα', 3000, $errors, true);
$equipment = formValue('equipment', 'Εξοπλισμός', 1000, $errors);

if ($phone !== '') {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (preg_match('/^\+?[0-9() .-]+$/D', $phone) !== 1 || strlen($digits) < 7 || strlen($digits) > 17) {
        $errors['phone'] = 'Ελέγξτε τον αριθμό τηλεφώνου.';
    }
}
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Ελέγξτε τη διεύθυνση email.';
}
if ($date !== '' && (
    preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1
    || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
)) {
    $errors['date'] = 'Επιλέξτε έγκυρη ημερομηνία.';
}
if ($event !== '' && !in_array($event, CONTACT_EVENTS, true)) {
    $errors['event'] = 'Επιλέξτε έναν τύπο εκδήλωσης από τη λίστα.';
}
if ($errors !== []) {
    respond(422, 'Ελέγξτε τα στοιχεία της φόρμας και δοκιμάστε ξανά.', $errors);
}

$privateDirectory = dirname(__DIR__) . '/patsilias-private';
try {
    $config = loadConfig($privateDirectory);
    foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
        $path = $privateDirectory . '/phpmailer/src/' . $file;
        if (!is_readable($path)) {
            throw new RuntimeException('Missing SMTP library');
        }
        require_once $path;
    }
    $retryAfter = reserveAttempt($privateDirectory, $config['rate_limit_secret']);
} catch (Throwable) {
    error_log('Patsilias Contact: setup or rate limit state unavailable.');
    respond(503, CONTACT_UNAVAILABLE);
}

if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    respond(429, 'Έχουν υποβληθεί αρκετά αιτήματα. Δοκιμάστε αργότερα ή καλέστε μας.');
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['smtp_host'];
    $mail->Port = $config['smtp_port'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp_username'];
    $mail->Password = $config['smtp_password'];
    $mail->SMTPSecure = $config['smtp_encryption'] === 'smtps'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 10;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_BASE64;
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($config['to_email']);
    if ($email !== '') {
        $mail->addReplyTo($email, $name);
    }
    $mail->Subject = 'Νέο αίτημα εκδήλωσης | Patsilias Events';
    $mail->Body = implode("\n", [
        "Όνομα: {$name}",
        "Τηλέφωνο: {$phone}",
        'Email: ' . ($email !== '' ? $email : '—'),
        'Ημερομηνία: ' . ($date !== '' ? $date : '—'),
        "Τύπος εκδήλωσης: {$event}",
        'Χώρος / Περιοχή: ' . ($venue !== '' ? $venue : '—'),
        'Επιλεγμένος εξοπλισμός: ' . ($equipment !== '' ? $equipment : '—'),
        '',
        'Μήνυμα:',
        $message,
    ]);
    if (!$mail->send()) {
        throw new RuntimeException('SMTP did not accept message');
    }
} catch (Throwable) {
    error_log('Patsilias Contact: SMTP attempt failed.');
    respond(502, CONTACT_UNAVAILABLE);
}

respond(200, CONTACT_SENT);
