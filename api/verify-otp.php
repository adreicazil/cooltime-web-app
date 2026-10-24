<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    error_log('CoolTime OTP verification fatal error: ' . $error['message']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode(['ok' => false, 'success' => false, 'message' => 'The verification service is temporarily unavailable. Please try again later.']);
});

try {
    require_once __DIR__ . '/config.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $response = ['ok' => false, 'message' => 'Method not allowed.'];
        $status = 405;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        $code = is_array($data) ? (string) ($data['code'] ?? '') : '';
        if (!preg_match('/^\d{6}$/', $code)) {
            $response = ['ok' => false, 'message' => 'Enter the 6-digit verification code.'];
            $status = 400;
        } else {
            $user = firebaseUserFromToken(authorizationToken());
            $now = time();
            $response = withOtpRecord('login', $user['uid'], function (array $record) use ($code, $now, $user): array {
                if (empty($record['code_hash']) || $now > (int) ($record['expires_at'] ?? 0)) {
                    return ['delete' => true, 'response' => ['ok' => false, 'message' => 'This code has expired. Please request a new one.']];
                }
                $attempts = (int) ($record['attempts'] ?? 0);
                if ($attempts >= OTP_MAX_ATTEMPTS) {
                    return ['delete' => true, 'response' => ['ok' => false, 'message' => 'Too many incorrect attempts. Please request a new code.']];
                }
                if (!password_verify($code, $record['code_hash'])) {
                    $record['attempts'] = $attempts + 1;
                    return ['record' => $record, 'response' => ['ok' => false, 'message' => 'Incorrect verification code.']];
                }

                createOtpSession($user['uid'], $now + OTP_SESSION_TTL_SECONDS);
                recordOtpAudit($user['uid'], $user['email'], (string) $record['code_hash'], 'login', (int) $record['expires_at'], true);
                return ['delete' => true, 'response' => ['ok' => true]];
            });
            $status = !empty($response['ok']) ? 200 : 400;
        }
    }
} catch (Throwable $error) {
    error_log('CoolTime OTP verification failed: ' . $error->getMessage());
    $response = ['ok' => false, 'message' => 'The verification service is temporarily unavailable. Please try again later.'];
    $status = 503;
}

http_response_code($status);
if (ob_get_level() > 0) {
    ob_clean();
}
echo json_encode($response);
exit;
