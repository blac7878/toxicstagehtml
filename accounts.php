<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$SETUP_PLACEHOLDER = 'REPLACE_WITH_A_LONG_RANDOM_SECRET_BEFORE_FIRST_ROOT';
$setupKeyFromEnvironment = getenv('ACCOUNT_SETUP_KEY');
$ACCOUNT_SETUP_KEY = is_string($setupKeyFromEnvironment) && $setupKeyFromEnvironment !== ''
    ? $setupKeyFromEnvironment
    : $SETUP_PLACEHOLDER;
$DATA_DIR = __DIR__ . '/account-data';
$DATA_FILE = $DATA_DIR . '/users.json';
$LOCK_FILE = $DATA_DIR . '/users.lock';

function jsonOut(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ensureDataDir(): bool {
    global $DATA_DIR, $DATA_FILE;
    if (!is_dir($DATA_DIR) && !@mkdir($DATA_DIR, 0755, true) && !is_dir($DATA_DIR)) return false;
    if (!is_file($DATA_FILE)) {
        $initial = json_encode(['users' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($initial) || @file_put_contents($DATA_FILE, $initial, LOCK_EX) === false) return false;
    }
    return true;
}
function readDb(): ?array {
    global $DATA_FILE;
    $raw = @file_get_contents($DATA_FILE);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !isset($decoded['users']) || !is_array($decoded['users'])) return null;
    foreach ($decoded['users'] as $user) {
        if (!is_array($user)
            || !isset($user['key'], $user['username'], $user['password_hash'])
            || !is_string($user['key'])
            || !is_string($user['username'])
            || !is_string($user['password_hash'])
            || (isset($user['role']) && !is_string($user['role']))) {
            return null;
        }
    }
    return $decoded;
}
function writeDb(array $db): bool {
    global $DATA_FILE;
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) return false;
    $tmp = $DATA_FILE . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $DATA_FILE)) { @unlink($tmp); return false; }
    return true;
}
function withLockedDb(callable $fn): array {
    global $LOCK_FILE;
    $lock = @fopen($LOCK_FILE, 'c');
    if (!$lock) return ['status' => 500, 'data' => ['ok' => false, 'error' => 'Nie można otworzyć blokady bazy kont.']];
    if (!flock($lock, LOCK_EX)) { fclose($lock); return ['status' => 500, 'data' => ['ok' => false, 'error' => 'Nie można zablokować bazy kont.']]; }
    $db = readDb();
    if ($db === null) {
        flock($lock, LOCK_UN); fclose($lock);
        return ['status' => 500, 'data' => ['ok' => false, 'error' => 'Baza kont jest nieczytelna lub ma nieprawidłowy format. Nie zapisano żadnych zmian.']];
    }
    $result = $fn($db);
    if (!is_array($result)) $result = ['status' => 500, 'data' => ['ok' => false, 'error' => 'Nieprawidłowy wynik operacji.']];
    if (!empty($result['write']) && !writeDb($db)) $result = ['status' => 500, 'data' => ['ok' => false, 'error' => 'Nie udało się zapisać bazy kont. Sprawdź uprawnienia account-data.']];
    flock($lock, LOCK_UN); fclose($lock);
    return $result;
}
function userKey(string $username): string { return strtolower($username); }
function findUserIndex(array $db, string $key): int {
    foreach ($db['users'] as $i => $user) if (isset($user['key']) && hash_equals((string)$user['key'], $key)) return (int)$i;
    return -1;
}
function hasAdmin(array $db): bool {
    // ROOT jest również kontem uprzywilejowanym, więc nie pokazujemy konfiguracji pierwszego konta.
    foreach ($db['users'] as $user) if (in_array(($user['role'] ?? 'user'), ['admin', 'root'], true) && empty($user['banned'])) return true;
    return false;
}
function normalizeSingleRoot(array &$db): bool {
    // Migracja starych instalacji: pierwszy aktywny admin staje się ROOT-em.
    // Jeśli dane zawierają kilka kont ROOT, zachowujemy pierwsze i obniżamy pozostałe do admina.
    $changed = false;
    $rootIndex = -1;
    foreach ($db['users'] as $i => &$user) {
        if (($user['role'] ?? 'user') !== 'root') continue;
        if ($rootIndex < 0) {
            $rootIndex = (int)$i;
            if (!empty($user['banned'])) { $user['banned'] = false; $changed = true; }
        } else {
            $user['role'] = 'admin';
            $changed = true;
        }
    }
    unset($user);
    if ($rootIndex < 0) {
        foreach ($db['users'] as $i => &$user) {
            if (($user['role'] ?? 'user') === 'admin' && empty($user['banned'])) {
                $user['role'] = 'root';
                $rootIndex = (int)$i;
                $changed = true;
                break;
            }
        }
        unset($user);
    }
    return $changed;
}
function publicUser(array $user): array {
    return ['username' => (string)($user['username'] ?? 'Gracz'), 'role' => (string)($user['role'] ?? 'user'), 'banned' => !empty($user['banned']), 'created_at' => $user['created_at'] ?? null, 'last_login' => $user['last_login'] ?? null];
}
function currentUserFromDb(array $db): ?array {
    $key = isset($_SESSION['account_username']) ? (string)$_SESSION['account_username'] : '';
    if ($key === '') return null;
    $idx = findUserIndex($db, $key);
    if ($idx < 0) return null;
    $user = $db['users'][$idx];
    if (!empty($user['banned'])) return null;
    return $user;
}
function requireAdmin(array $db): array {
    $user = currentUserFromDb($db);
    if (!$user || !in_array(($user['role'] ?? 'user'), ['admin', 'root'], true)) jsonOut(403, ['ok' => false, 'error' => 'Wymagane jest zalogowane konto administratora.']);
    return $user;
}
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function requestHasAllowedOrigin(): bool {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return true;
    $originParts = parse_url($origin);
    $hostParts = parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!is_array($originParts) || !is_array($hostParts) || empty($originParts['scheme']) || empty($originParts['host']) || empty($hostParts['host'])) return false;
    if (isset($originParts['user']) || isset($originParts['pass']) || isset($originParts['query']) || isset($originParts['fragment'])) return false;
    $requestScheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $originScheme = strtolower((string)$originParts['scheme']);
    $originPort = (int)($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));
    $requestPort = (int)($hostParts['port'] ?? ($requestScheme === 'https' ? 443 : 80));
    return $originScheme === $requestScheme
        && strtolower((string)$originParts['host']) === strtolower((string)$hostParts['host'])
        && $originPort === $requestPort
        && (!isset($originParts['path']) || $originParts['path'] === '' || $originParts['path'] === '/');
}

if (!ensureDataDir()) jsonOut(500, ['ok' => false, 'error' => 'Nie można utworzyć katalogu account-data.']);
startSecureSession();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST' && !requestHasAllowedOrigin()) jsonOut(403, ['ok' => false, 'error' => 'Żądanie pochodzi z innej witryny. Odśwież stronę i spróbuj ponownie.']);
// Bezpieczna migracja: ROOT jest dokładnie jeden. Na istniejącej instalacji pierwszy aktywny admin zostanie ROOT-em.
$rootMigration = withLockedDb(function (&$db) {
    $changed = normalizeSingleRoot($db);
    return ['status' => 200, 'write' => $changed, 'data' => ['ok' => true]];
});
if (($rootMigration['status'] ?? 500) !== 200) jsonOut((int)$rootMigration['status'], $rootMigration['data'] ?? ['ok' => false, 'error' => 'Nie udało się przygotować roli ROOT.']);
$action = (string)($_GET['action'] ?? '');

if ($method === 'GET') {
    $db = readDb();
    if ($db === null) jsonOut(500, ['ok' => false, 'error' => 'Baza kont jest nieczytelna lub ma nieprawidłowy format. Nie zapisano żadnych zmian.']);
    if ($action === 'status') {
        jsonOut(200, ['ok' => true, 'needs_admin' => !hasAdmin($db), 'setup_configured' => ($ACCOUNT_SETUP_KEY !== '' && $ACCOUNT_SETUP_KEY !== $SETUP_PLACEHOLDER && strlen($ACCOUNT_SETUP_KEY) >= 24)]);
    }
    if ($action === 'me') {
        $user = currentUserFromDb($db);
        if (!$user && isset($_SESSION['account_username'])) { $_SESSION = []; session_regenerate_id(true); }
        jsonOut(200, ['ok' => true, 'user' => $user ? publicUser($user) : null]);
    }
    if ($action === 'users') {
        requireAdmin($db);
        $users = array_map('publicUser', $db['users']);
        usort($users, static fn($a, $b) => strcmp(strtolower($a['username']), strtolower($b['username'])));
        jsonOut(200, ['ok' => true, 'users' => $users]);
    }
    header('Allow: GET, POST');
    jsonOut(400, ['ok' => false, 'error' => 'Nieznana operacja.']);
}
if ($method !== 'POST') { header('Allow: GET, POST'); jsonOut(405, ['ok' => false, 'error' => 'Dozwolone metody: GET i POST.']); }
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') !== 0) jsonOut(415, ['ok' => false, 'error' => 'Wymagane jest application/json.']);
$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) jsonOut(400, ['ok' => false, 'error' => 'Nieprawidłowe dane JSON.']);
$action = (string)($body['action'] ?? $action);

if ($action === 'register') {
    global $ACCOUNT_SETUP_KEY, $SETUP_PLACEHOLDER;
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $setupKey = (string)($body['setup_key'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) jsonOut(400, ['ok' => false, 'error' => 'Login musi mieć 3–20 znaków: litery, cyfry lub _.']);
    if (strlen($password) < 10 || strlen($password) > 128) jsonOut(400, ['ok' => false, 'error' => 'Hasło musi mieć od 10 do 128 bajtów.']);
    $key = userKey($username);
    $result = withLockedDb(function (&$db) use ($username, $password, $setupKey, $key, $ACCOUNT_SETUP_KEY, $SETUP_PLACEHOLDER) {
        if (findUserIndex($db, $key) >= 0) return ['status' => 409, 'data' => ['ok' => false, 'error' => 'Takie konto już istnieje.']];
        $needsAdmin = !hasAdmin($db);
        $configured = $ACCOUNT_SETUP_KEY !== '' && $ACCOUNT_SETUP_KEY !== $SETUP_PLACEHOLDER && strlen($ACCOUNT_SETUP_KEY) >= 24;
        $role = 'user';
        if ($needsAdmin && $setupKey !== '' && $configured && hash_equals($ACCOUNT_SETUP_KEY, $setupKey)) $role = 'root';
        $db['users'][] = ['key' => $key, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'banned' => false, 'created_at' => gmdate('c'), 'last_login' => gmdate('c')];
        return ['status' => 200, 'write' => true, 'data' => ['ok' => true, 'user' => ['username' => $username, 'role' => $role, 'banned' => false, 'created_at' => gmdate('c'), 'last_login' => gmdate('c')]]];
    });
    if (($result['status'] ?? 500) !== 200) jsonOut((int)$result['status'], $result['data']);
    session_regenerate_id(true); $_SESSION['account_username'] = $key;
    jsonOut(200, $result['data']);
}

if ($action === 'login') {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $key = userKey($username); $db = readDb();
    if ($db === null) jsonOut(500, ['ok' => false, 'error' => 'Baza kont jest nieczytelna lub ma nieprawidłowy format. Nie zapisano żadnych zmian.']);
    $idx = findUserIndex($db, $key);
    if ($idx < 0 || !password_verify($password, (string)($db['users'][$idx]['password_hash'] ?? ''))) jsonOut(401, ['ok' => false, 'error' => 'Nieprawidłowy login lub hasło.']);
    if (!empty($db['users'][$idx]['banned'])) jsonOut(403, ['ok' => false, 'error' => 'To konto jest zablokowane.']);
    $res = withLockedDb(function (&$locked) use ($key) {
        $i = findUserIndex($locked, $key);
        if ($i < 0 || !empty($locked['users'][$i]['banned'])) return ['status' => 403, 'data' => ['ok' => false, 'error' => 'Konto jest zablokowane lub nie istnieje.']];
        $locked['users'][$i]['last_login'] = gmdate('c');
        return ['status' => 200, 'write' => true, 'data' => ['ok' => true, 'user' => publicUser($locked['users'][$i])]];
    });
    if (($res['status'] ?? 500) !== 200) jsonOut((int)$res['status'], $res['data']);
    session_regenerate_id(true); $_SESSION['account_username'] = $key;
    jsonOut(200, $res['data']);
}
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $params = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite'] ?? 'Lax']); }
    session_destroy(); jsonOut(200, ['ok' => true]);
}

// Działania administracyjne są weryfikowane po stronie serwera.
// ROOT może zarządzać rolami i innymi administratorami. Admin zarządza tylko kontami graczy.
$result = withLockedDb(function (&$db) use ($action, $body) {
    $actor = currentUserFromDb($db);
    $actorRole = (string)($actor['role'] ?? 'user');
    if (!$actor || !in_array($actorRole, ['admin', 'root'], true)) {
        return ['status' => 403, 'data' => ['ok' => false, 'error' => 'Wymagane jest konto administratora.']];
    }
    $isRoot = $actorRole === 'root';
    $username = trim((string)($body['username'] ?? ''));
    $key = userKey($username);
    $idx = findUserIndex($db, $key);
    if ($idx < 0) return ['status' => 404, 'data' => ['ok' => false, 'error' => 'Nie znaleziono konta.']];
    $target = $db['users'][$idx];
    $targetRole = (string)($target['role'] ?? 'user');

    // ROOT jest chroniony: żadna operacja administracyjna nie może go zbanować, usunąć ani zmienić jego roli.
    if ($targetRole === 'root') {
        return ['status' => 403, 'data' => ['ok' => false, 'error' => 'Konto ROOT (właściciel gry) jest chronione.']];
    }
    // Administrator nie może zarządzać kontami innych administratorów ani nadawać/odbierać ról.
    if (!$isRoot && $targetRole === 'admin') {
        return ['status' => 403, 'data' => ['ok' => false, 'error' => 'Tylko ROOT może zarządzać kontami administratorów.']];
    }

    if ($action === 'admin_role') {
        if (!$isRoot) return ['status' => 403, 'data' => ['ok' => false, 'error' => 'Tylko ROOT może zmieniać role kont.']];
        $role = (string)($body['role'] ?? 'user');
        if (!in_array($role, ['user', 'admin'], true)) return ['status' => 400, 'data' => ['ok' => false, 'error' => 'Nieprawidłowa rola. ROOT może nadawać role admin lub user.']];
        $db['users'][$idx]['role'] = $role;
        return ['status' => 200, 'write' => true, 'data' => ['ok' => true]];
    }
    if ($action === 'admin_ban') {
        $banned = !empty($body['banned']);
        if ($key === (string)($actor['key'] ?? '')) return ['status' => 409, 'data' => ['ok' => false, 'error' => 'Nie możesz zablokować własnego konta.']];
        $db['users'][$idx]['banned'] = $banned;
        return ['status' => 200, 'write' => true, 'data' => ['ok' => true]];
    }
    if ($action === 'admin_delete') {
        if ($key === (string)($actor['key'] ?? '')) return ['status' => 409, 'data' => ['ok' => false, 'error' => 'Nie możesz usunąć własnego konta z tej sesji.']];
        array_splice($db['users'], $idx, 1);
        return ['status' => 200, 'write' => true, 'data' => ['ok' => true]];
    }
    return ['status' => 400, 'data' => ['ok' => false, 'error' => 'Nieznana operacja administracyjna.']];
});
jsonOut((int)($result['status'] ?? 500), $result['data'] ?? ['ok' => false, 'error' => 'Błąd serwera.']);
