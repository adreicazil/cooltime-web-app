<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

requirePost();
$data = requestData();
$code = (string) ($data['code'] ?? '');
if (!preg_match('/^\d{6}$/', $code)) {
    jsonResponse(['ok' => false, 'message' => 'Enter the 6-digit verification code.'], 400);
}

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
    try {
        createOtpSession($user['uid'], $now + OTP_SESSION_TTL_SECONDS);
    } catch (Throwable $error) {
        error_log('CoolTime verified OTP session could not be saved: ' . $error->getMessage());
        jsonResponse(['ok' => false, 'message' => 'Verification succeeded, but your secure session could not be started. Please try again.'], 503);
    }
    recordOtpAudit($user['uid'], $user['email'], (string) $record['code_hash'], 'login', (int) $record['expires_at'], true);
    return ['delete' => true, 'response' => ['ok' => true]];
});

jsonResponse($response, $response['ok'] ? 200 : 400);
