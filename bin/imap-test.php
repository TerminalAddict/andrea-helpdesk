<?php
/**
 * CLI IMAP connectivity / folder-list test.
 * Reads JSON params from stdin, writes a JSON result to stdout.
 * Invoked by ImapAccountController via proc_open() so that DNS resolution
 * and network access run in the CLI context (same as the cron poller).
 *
 * Input JSON fields:
 *   host, port, encryption (ssl|tls|none), username, password
 *   mode: "test" (default) — verify credentials
 *         "list"           — list all folders
 */
declare(strict_types=1);

function imap_test_fail(string $msg): never
{
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit(1);
}

$input = json_decode(file_get_contents('php://stdin'), true);
if (!$input) {
    imap_test_fail('No input received');
}

$host       = $input['host']       ?? '';
$port       = (int)($input['port'] ?? 993);
$encryption = strtolower($input['encryption'] ?? 'ssl');
$username   = $input['username']   ?? '';
$password   = $input['password']   ?? '';
$mode       = $input['mode']       ?? 'test';

// ── Connect ──────────────────────────────────────────────────────────────────

$context = stream_context_create([
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);

$scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
$fp     = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
if (!$fp) {
    imap_test_fail("Cannot connect to {$host}:{$port} — " . ($errstr ?: "error {$errno}"));
}
stream_set_timeout($fp, 10);

// Read IMAP greeting
$banner = fgets($fp, 4096);
if (!$banner || stripos($banner, '* OK') === false) {
    fclose($fp);
    imap_test_fail('No IMAP greeting received — is this an IMAP server on this port?');
}

// STARTTLS upgrade
if ($encryption === 'tls') {
    fwrite($fp, "T1 STARTTLS\r\n");
    while (!feof($fp)) {
        $line = fgets($fp, 4096);
        if (!$line) break;
        if (str_starts_with($line, 'T1 OK')) break;
        if (str_starts_with($line, 'T1 NO') || str_starts_with($line, 'T1 BAD')) {
            fclose($fp);
            imap_test_fail('STARTTLS rejected by server: ' . trim($line));
        }
    }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($fp);
        imap_test_fail('TLS negotiation failed');
    }
}

// ── Login ─────────────────────────────────────────────────────────────────────

$q = fn(string $s) => '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $s) . '"';
fwrite($fp, 'T2 LOGIN ' . $q($username) . ' ' . $q($password) . "\r\n");

$loggedIn = false;
while (!feof($fp)) {
    $line = fgets($fp, 4096);
    if (!$line) break;
    if (str_starts_with($line, 'T2 OK'))  { $loggedIn = true; break; }
    if (str_starts_with($line, 'T2 NO') || str_starts_with($line, 'T2 BAD')) {
        fclose($fp);
        imap_test_fail('Login failed — check username and password.');
    }
}
if (!$loggedIn) {
    fclose($fp);
    imap_test_fail('No login response received from server');
}

// ── Mode: list folders ────────────────────────────────────────────────────────

if ($mode === 'list') {
    fwrite($fp, "T3 LIST \"\" \"*\"\r\n");
    $folders = [];
    while (!feof($fp)) {
        $line = fgets($fp, 4096);
        if (!$line) break;
        if (str_starts_with($line, 'T3 OK') || str_starts_with($line, 'T3 NO') || str_starts_with($line, 'T3 BAD')) break;
        // * LIST (\Flags) "/" "Folder Name"  OR  * LIST (\Flags) NIL FolderAtom
        if (preg_match('/^\* LIST \([^)]*\) (?:NIL|"[^"]*") (.+)$/i', rtrim($line), $m)) {
            $name = trim($m[1], "\" \t");
            if ($name !== '') $folders[] = $name;
        }
    }
    fwrite($fp, "T4 LOGOUT\r\n");
    fclose($fp);
    sort($folders);
    echo json_encode(['ok' => true, 'folders' => $folders]);
    exit(0);
}

// ── Mode: test (default) ──────────────────────────────────────────────────────

fwrite($fp, "T3 LOGOUT\r\n");
fclose($fp);
echo json_encode(['ok' => true, 'msg' => 'Connection successful — credentials accepted.']);
