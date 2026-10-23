<?php
declare(strict_types=1);

// Keep PHP diagnostics out of the response so clients always receive JSON.
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('max_execution_time', '30');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

// PHP errors such as uncaught engine-level fatals bypass a normal try/catch.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    error_log('CoolTime OTP endpoint fatal error: ' . $error['message']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
    }
    if (!defined('COOLTIME_OTP_RESPONSE_SENT')) {
        if (ob_get_level() > 0) ob_clean();
        echo json_encode(['ok' => false, 'message' => 'The verification service is temporarily unavailable. Please try again later.']);
    }
});

ob_start();

try {
    require_once __DIR__ . '/config.php';

    requirePost();
    $user = firebaseUserFromToken(authorizationToken());
    $now = time();
// Revoke any prior verified session where Firebase permits it. This also clears
// the local PHP-session copy and does not prevent issuing a fresh OTP if Firebase
// service-account credentials are unavailable.
    revokeOtpSession($user['uid']);

    $response = withOtpRecord('login', $user['uid'], function (array $record) use ($user, $now): array {
    $windowStartedAt = (int) ($record['window_started_at'] ?? $now);
    $requestCount = (int) ($record['request_count'] ?? 0);
    if ($now - $windowStartedAt >= OTP_REQUEST_WINDOW_SECONDS) {
        $windowStartedAt = $now;
        $requestCount = 0;
    }
    $lastSentAt = (int) ($record['last_sent_at'] ?? 0);
    if ($now - $lastSentAt < OTP_RESEND_COOLDOWN_SECONDS) {
        jsonResponse(['ok' => false, 'message' => 'Please wait before requesting another code.'], 429);
    }
    if ($requestCount >= OTP_MAX_REQUESTS_PER_WINDOW) {
        jsonResponse(['ok' => false, 'message' => 'Too many code requests. Please try again later.'], 429);
    }

    $code = (string) random_int(100000, 999999);
    try {
        sendOtpEmail($user['email'], $code, 'login');
    } catch (Throwable $error) {
        error_log('CoolTime OTP email failed: ' . $error->getMessage());
        jsonResponse(['ok' => false, 'message' => 'We could not send a verification email. Please try again later.'], 503);
    }

    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    recordOtpAudit($user['uid'], $user['email'], $codeHash, 'login', $now + OTP_TTL_SECONDS);
    return [
        'record' => [
            'code_hash' => $codeHash,
            'expires_at' => $now + OTP_TTL_SECONDS,
            'attempts' => 0,
            'last_sent_at' => $now,
            'window_started_at' => $windowStartedAt,
            'request_count' => $requestCount + 1,
        ],
        'response' => [
            'ok' => true,
            'success' => true,
            'message' => 'OTP sent successfully',
            'email' => maskedEmail($user['email']),
            'expiresIn' => OTP_TTL_SECONDS,
        ],
    ];
    });

    jsonResponse($response);
} catch (Throwable $error) {
    error_log('CoolTime OTP endpoint failed: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
    }
    if (ob_get_level() > 0) ob_clean();
    define('COOLTIME_OTP_RESPONSE_SENT', true);
    echo json_encode(['ok' => false, 'message' => 'The verification service is temporarily unavailable. Please try again later.']);
    exit;
}
