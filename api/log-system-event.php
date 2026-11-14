<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requirePost();

try {
    $input = requestData();
    if (($input['event'] ?? '') !== 'Login Failed') {
        jsonResponse(['ok' => false, 'message' => 'Unsupported event.'], 422);
    }
    $now = time();
    $window = $_SESSION['system_login_log_window'] ?? ['startedAt' => $now, 'count' => 0];
    if ($now - (int) ($window['startedAt'] ?? 0) >= 60) $window = ['startedAt' => $now, 'count' => 0];
    if ((int) ($window['count'] ?? 0) >= 10) jsonResponse(['ok' => true]);
    $window['count'] = (int) ($window['count'] ?? 0) + 1;
    $_SESSION['system_login_log_window'] = $window;

    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? substr($email, 0, 254) : 'unknown email';
    $allowedReasons = [
        'auth/invalid-credential' => 'Invalid email or password',
        'auth/user-not-found' => 'Invalid email or password',
        'auth/wrong-password' => 'Invalid email or password',
        'auth/too-many-requests' => 'Too many authentication attempts',
        'auth/network-request-failed' => 'Authentication network failure',
    ];
    $code = (string) ($input['errorCode'] ?? '');
    $reason = $allowedReasons[$code] ?? 'Authentication failed';
    writeSystemLog('Login Failed', $email . ' - ' . $reason, '/login.html', 401, $email);
    jsonResponse(['ok' => true]);
} catch (Throwable $error) {
    error_log('Login failure log request failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'Login event could not be recorded.'], 500);
}
