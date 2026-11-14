<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$storeDir = __DIR__ . '/changelog-data';
$storeFile = $storeDir . '/changelog.json';
$storeLockFile = $storeDir . '/changelog.lock';
$accountFile = __DIR__ . '/account-data/users.json';

$defaultText = "Witaj w Nerf Game!\n\n- Naprawiono ekran GAME OVER\n- Ulepszono system multiplayer\n- Dodano poprawki rozgrywki";

function sendJson(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (
        !empty($_SERVER['HTTPS']) &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off'
    ) || (
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    );

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

function requestHasAllowedOrigin(): bool
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }

    $originParts = parse_url($origin);
    $hostParts = parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''));

    if (
        !is_array($originParts) ||
        !is_array($hostParts) ||
        empty($originParts['scheme']) ||
        empty($originParts['host']) ||
        empty($hostParts['host']) ||
        isset($originParts['user']) ||
        isset($originParts['pass']) ||
        isset($originParts['query']) ||
        isset($originParts['fragment'])
    ) {
        return false;
    }

    $requestScheme = (
        !empty($_SERVER['HTTPS']) &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off'
    ) || (
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    ) ? 'https' : 'http';
    $originScheme = strtolower((string)$originParts['scheme']);
    $originPort = (int)($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));
    $requestPort = (int)($hostParts['port'] ?? ($requestScheme === 'https' ? 443 : 80));

    return $originScheme === $requestScheme
        && strtolower((string)$originParts['host']) === strtolower((string)$hostParts['host'])
        && $originPort === $requestPort
        && (!isset($originParts['path']) || $originParts['path'] === '' || $originParts['path'] === '/');
}

/**
 * Weryfikuje rzeczywistą rolę konta po stronie serwera.
 * Nie ufa roli przesłanej przez przeglądarkę.
 */
function currentSessionCanEditChangelog(string $accountFile): bool
{
    $sessionKey = $_SESSION['account_username'] ?? '';

    if (!is_string($sessionKey) || $sessionKey === '') {
        return false;
    }

    if (!is_file($accountFile)) {
        return false;
    }

    $raw = @file_get_contents($accountFile);
    $db = is_string($raw) ? json_decode($raw, true) : null;

    if (
        !is_array($db) ||
        !isset($db['users']) ||
        !is_array($db['users'])
    ) {
        return false;
    }

    foreach ($db['users'] as $user) {
        if (
            !is_array($user) ||
            !isset($user['key']) ||
            !is_string($user['key'])
        ) {
            continue;
        }

        if (hash_equals($user['key'], $sessionKey)) {
            $role = $user['role'] ?? 'user';

            return in_array($role, ['admin', 'root'], true)
                && empty($user['banned']);
        }
    }

    return false;
}

startSecureSession();

$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

/* Odczyt changeloga jest dostępny dla wszystkich graczy. */
if ($method === 'GET') {
    global $storeFile, $defaultText;

    if (is_file($storeFile)) {
        $raw = @file_get_contents($storeFile);
        $saved = is_string($raw) ? json_decode($raw, true) : null;

        if (
            is_array($saved) &&
            isset($saved['text']) &&
            is_string($saved['text'])
        ) {
            sendJson(200, [
                'ok' => true,
                'text' => $saved['text'],
                'updated_at' => $saved['updated_at'] ?? null
            ]);
        }

        sendJson(500, [
            'ok' => false,
            'error' => 'Zapisany changelog jest nieczytelny lub ma nieprawidłowy format.'
        ]);
    }

    sendJson(200, [
        'ok' => true,
        'text' => $defaultText,
        'updated_at' => null
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');

    sendJson(405, [
        'ok' => false,
        'error' => 'Dozwolone metody: GET i POST.'
    ]);
}

if (!requestHasAllowedOrigin()) {
    sendJson(403, [
        'ok' => false,
        'error' => 'Żądanie pochodzi z innej witryny. Odśwież stronę i spróbuj ponownie.'
    ]);
}

/* Tylko zalogowany ADMIN lub ROOT może zapisywać zmiany. */
if (!currentSessionCanEditChangelog($accountFile)) {
    sendJson(403, [
        'ok' => false,
        'error' => 'Zapis changeloga wymaga zalogowanego konta ADMIN lub ROOT.'
    ]);
}

$contentType = strtolower(
    (string)($_SERVER['CONTENT_TYPE'] ?? '')
);

if (strpos($contentType, 'application/json') !== 0) {
    sendJson(415, [
        'ok' => false,
        'error' => 'Wymagane jest application/json.'
    ]);
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($data)) {
    sendJson(400, [
        'ok' => false,
        'error' => 'Nieprawidłowe dane JSON.'
    ]);
}

$text = isset($data['text']) && is_string($data['text'])
    ? str_replace(["\r\n", "\r"], "\n", $data['text'])
    : '';

if (trim($text) === '') {
    sendJson(400, [
        'ok' => false,
        'error' => 'Changelog nie może być pusty.'
    ]);
}

if (strlen($text) > 10000) {
    sendJson(413, [
        'ok' => false,
        'error' => 'Changelog może mieć maksymalnie 10000 bajtów.'
    ]);
}

/* Tworzy katalog danych, jeśli jeszcze nie istnieje. */
if (
    !is_dir($storeDir) &&
    !@mkdir($storeDir, 0755, true) &&
    !is_dir($storeDir)
) {
    sendJson(500, [
        'ok' => false,
        'error' => 'Nie można utworzyć katalogu changelog-data.'
    ]);
}

$lock = @fopen($storeLockFile, 'c');
if (!$lock) {
    sendJson(500, [
        'ok' => false,
        'error' => 'Nie można otworzyć blokady zapisu changeloga.'
    ]);
}
if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    sendJson(500, [
        'ok' => false,
        'error' => 'Nie można zablokować zapisu changeloga.'
    ]);
}

$updatedAt = gmdate('c');

$payload = json_encode(
    [
        'text' => $text,
        'updated_at' => $updatedAt
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if (!is_string($payload)) {
    flock($lock, LOCK_UN);
    fclose($lock);
    sendJson(500, [
        'ok' => false,
        'error' => 'Nie można zakodować changelogu.'
    ]);
}

$tmpFile = $storeFile . '.tmp';

if (
    @file_put_contents($tmpFile, $payload, LOCK_EX) === false ||
    !@rename($tmpFile, $storeFile)
) {
    @unlink($tmpFile);
    flock($lock, LOCK_UN);
    fclose($lock);

    sendJson(500, [
        'ok' => false,
        'error' => 'Brak uprawnień zapisu do changelog-data.'
    ]);
}

flock($lock, LOCK_UN);
fclose($lock);

sendJson(200, [
    'ok' => true,
    'text' => $text,
    'updated_at' => $updatedAt
]);