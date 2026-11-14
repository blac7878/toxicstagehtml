<?php
declare(strict_types=1);

$stateFile = __DIR__ . '/maintenance-data/state.json';
$accountFile = __DIR__ . '/account-data/users.json';
$rawState = is_file($stateFile) ? @file_get_contents($stateFile) : null;
$maintenanceEnabled = false;
$stateValid = !is_file($stateFile);

if (is_file($stateFile) && is_string($rawState)) {
    $state = json_decode($rawState, true);
    if (is_array($state) && isset($state['enabled']) && is_bool($state['enabled'])) {
        $maintenanceEnabled = $state['enabled'];
        $stateValid = true;
    }
}

$canBypass = false;
if (session_status() !== PHP_SESSION_ACTIVE) {
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

$sessionKey = $_SESSION['account_username'] ?? '';
if (is_string($sessionKey) && $sessionKey !== '' && is_file($accountFile)) {
    $rawAccounts = @file_get_contents($accountFile);
    $accounts = is_string($rawAccounts) ? json_decode($rawAccounts, true) : null;
    if (is_array($accounts) && isset($accounts['users']) && is_array($accounts['users'])) {
        foreach ($accounts['users'] as $user) {
            if (
                is_array($user) &&
                isset($user['key']) &&
                is_string($user['key']) &&
                hash_equals($user['key'], $sessionKey)
            ) {
                $canBypass = in_array(($user['role'] ?? ''), ['admin', 'root'], true) && empty($user['banned']);
                break;
            }
        }
    }
}

if (!$canBypass && (!$stateValid || $maintenanceEnabled)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Retry-After: 300');
    readfile(__DIR__ . '/503.html');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
