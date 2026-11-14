<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$STATE_DIR = __DIR__ . '/maintenance-data';
$STATE_FILE = $STATE_DIR . '/state.json';
$LOCK_FILE = $STATE_DIR . '/state.lock';
$ACCOUNT_FILE = __DIR__ . '/account-data/users.json';

function maintenanceJson(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function maintenanceSessionIsRoot(string $accountFile): bool
{
    $sessionKey = $_SESSION['account_username'] ?? '';
    if (!is_string($sessionKey) || $sessionKey === '' || !is_file($accountFile)) {
        return false;
    }

    $raw = @file_get_contents($accountFile);
    $db = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($db) || !isset($db['users']) || !is_array($db['users'])) {
        return false;
    }

    foreach ($db['users'] as $user) {
        if (
            is_array($user) &&
            isset($user['key']) &&
            is_string($user['key']) &&
            hash_equals($user['key'], $sessionKey)
        ) {
            return ($user['role'] ?? '') === 'root' && empty($user['banned']);
        }
    }
    return false;
}

function maintenanceStartSession(): void
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

function maintenanceSameOrigin(): bool
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

function readMaintenanceState(string $stateFile): ?array
{
    if (!is_file($stateFile)) {
        return ['enabled' => false, 'updated_at' => null];
    }

    $raw = @file_get_contents($stateFile);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state) || !isset($state['enabled']) || !is_bool($state['enabled'])) {
        return null;
    }
    return $state;
}

maintenanceStartSession();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $state = readMaintenanceState($STATE_FILE);
    if ($state === null) {
        maintenanceJson(500, ['ok' => false, 'error' => 'Stan konserwacji jest nieczytelny.']);
    }
    maintenanceJson(200, ['ok' => true, 'enabled' => $state['enabled']]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    maintenanceJson(405, ['ok' => false, 'error' => 'Dozwolone metody: GET i POST.']);
}

if (!maintenanceSameOrigin()) {
    maintenanceJson(403, ['ok' => false, 'error' => 'Żądanie pochodzi z innej witryny.']);
}
if (!maintenanceSessionIsRoot($ACCOUNT_FILE)) {
    maintenanceJson(403, ['ok' => false, 'error' => 'Tylko konto ROOT może zmieniać tryb konserwacji.']);
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') !== 0) {
    maintenanceJson(415, ['ok' => false, 'error' => 'Wymagane jest application/json.']);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body) || !isset($body['enabled']) || !is_bool($body['enabled'])) {
    maintenanceJson(400, ['ok' => false, 'error' => 'Pole enabled musi mieć wartość true lub false.']);
}

if (!is_dir($STATE_DIR) && !@mkdir($STATE_DIR, 0755, true) && !is_dir($STATE_DIR)) {
    maintenanceJson(500, ['ok' => false, 'error' => 'Nie można utworzyć katalogu stanu konserwacji.']);
}

$lock = @fopen($LOCK_FILE, 'c');
if (!$lock) {
    maintenanceJson(500, ['ok' => false, 'error' => 'Nie można otworzyć blokady stanu konserwacji.']);
}
if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    maintenanceJson(500, ['ok' => false, 'error' => 'Nie można zablokować stanu konserwacji.']);
}

$state = [
    'enabled' => $body['enabled'],
    'updated_at' => gmdate('c')
];
$encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$tmpFile = $STATE_FILE . '.tmp';
$saved = is_string($encoded) && @file_put_contents($tmpFile, $encoded, LOCK_EX) !== false;
if ($saved) {
    $saved = @rename($tmpFile, $STATE_FILE);
}
if (!$saved) {
    @unlink($tmpFile);
    flock($lock, LOCK_UN);
    fclose($lock);
    maintenanceJson(500, ['ok' => false, 'error' => 'Nie udało się zapisać stanu konserwacji.']);
}

flock($lock, LOCK_UN);
fclose($lock);
maintenanceJson(200, ['ok' => true, 'enabled' => $state['enabled']]);
