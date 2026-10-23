<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

requirePost();
$data = requestData();
$email = strtolower(trim((string) ($data['email'] ?? '')));
$code = (string) ($data['code'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
    jsonResponse(['ok' => false, 'message' => 'Enter the 6-digit verification code.'], 400);
}

try {
    $user = firebaseAdminLookupUserByEmail($email);
    if (!$user || empty($user['localId'])) {
        jsonResponse(['ok' => false, 'message' => 'The verification code is invalid or has expired.'], 400);
    }
    $now = time();
    $response = withOtpRecord('password_reset', (string) $user['localId'], function (array $record) use ($code, $email, $now, $user): array {
        if (strtolower((string) ($record['email'] ?? '')) !== $email || empty($record['code_hash']) || $now > (int) ($record['expires_at'] ?? 0)) {
            return ['delete' => true, 'response' => ['ok' => false, 'message' => 'The verification code is invalid or has expired.']];
        }
        $attempts = (int) ($record['attempts'] ?? 0);
        if ($attempts >= OTP_MAX_ATTEMPTS) {
            return ['delete' => true, 'response' => ['ok' => false, 'message' => 'Too many incorrect attempts. Please request a new code.']];
        }
        if (!password_verify($code, $record['code_hash'])) {
            $record['attempts'] = $attempts + 1;
            return ['record' => $record, 'response' => ['ok' => false, 'message' => 'The verification code is incorrect.']];
        }

        recordOtpAudit((string) $user['localId'], $email, (string) $record['code_hash'], 'password_reset', (int) $record['expires_at'], true);
        $resetToken = bin2hex(random_bytes(32));
        unset($record['code_hash']);
        $record['reset_token_hash'] = password_hash($resetToken, PASSWORD_DEFAULT);
        $record['reset_authorized_at'] = $now;
        return ['record' => $record, 'response' => ['ok' => true, 'resetToken' => $resetToken]];
    });
    jsonResponse($response, $response['ok'] ? 200 : 400);
} catch (Throwable $error) {
    error_log('CoolTime password-reset verification failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'We could not verify that code right now. Please try again later.'], 503);
}
