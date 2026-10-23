<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

requirePost();
$email = strtolower(trim((string) (requestData()['email'] ?? '')));
$genericResponse = ['ok' => true, 'message' => 'If an account matches that email address, a verification code has been sent.'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse($genericResponse);
}

try {
    $user = firebaseAdminLookupUserByEmail($email);
    if (!$user || empty($user['localId']) || empty($user['email'])) {
        jsonResponse($genericResponse);
    }

    $now = time();
    $response = withOtpRecord('password_reset', (string) $user['localId'], function (array $record) use ($user, $now): array {
        $windowStartedAt = (int) ($record['window_started_at'] ?? $now);
        $requestCount = (int) ($record['request_count'] ?? 0);
        if ($now - $windowStartedAt >= OTP_REQUEST_WINDOW_SECONDS) {
            $windowStartedAt = $now;
            $requestCount = 0;
        }
        $lastSentAt = (int) ($record['last_sent_at'] ?? 0);
        if ($now - $lastSentAt < OTP_RESEND_COOLDOWN_SECONDS) {
            return ['record' => $record, 'response' => ['ok' => false, 'message' => 'Please wait before requesting another code.']];
        }
        if ($requestCount >= OTP_MAX_REQUESTS_PER_WINDOW) {
            return ['record' => $record, 'response' => ['ok' => false, 'message' => 'Too many code requests. Please try again later.']];
        }

        $code = (string) random_int(100000, 999999);
        try {
            sendOtpEmail((string) $user['email'], $code, 'password_reset');
        } catch (Throwable $error) {
            error_log('CoolTime password-reset email failed: ' . $error->getMessage());
            return ['record' => $record, 'response' => ['ok' => false, 'message' => 'We could not send a verification email. Please try again later.']];
        }

        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        recordOtpAudit((string) $user['localId'], (string) $user['email'], $codeHash, 'password_reset', $now + OTP_TTL_SECONDS);
        return [
            'record' => [
                'uid' => (string) $user['localId'],
                'email' => strtolower((string) $user['email']),
                'code_hash' => $codeHash,
                'expires_at' => $now + OTP_TTL_SECONDS,
                'attempts' => 0,
                'last_sent_at' => $now,
                'window_started_at' => $windowStartedAt,
                'request_count' => $requestCount + 1,
            ],
            'response' => ['ok' => true, 'message' => 'A verification code has been sent to your registered email address.'],
        ];
    });
    jsonResponse($response, $response['ok'] ? 200 : 429);
} catch (Throwable $error) {
    error_log('CoolTime password-reset request failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'We could not process your request right now. Please try again later.'], 503);
}
