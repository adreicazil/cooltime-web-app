<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

requirePost();
$data = requestData();
$email = strtolower(trim((string) ($data['email'] ?? '')));
$resetToken = (string) ($data['resetToken'] ?? '');
$newPassword = (string) ($data['newPassword'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($resetToken) !== 64 || strlen($newPassword) < 6) {
    jsonResponse(['ok' => false, 'message' => 'Your reset session is invalid or the new password is too short.'], 400);
}

try {
    $user = firebaseAdminLookupUserByEmail($email);
    if (!$user || empty($user['localId'])) {
        jsonResponse(['ok' => false, 'message' => 'Your reset session is invalid or has expired.'], 400);
    }
    $now = time();
    $response = withOtpRecord('password_reset', (string) $user['localId'], function (array $record) use ($email, $resetToken, $newPassword, $now): array {
        if (strtolower((string) ($record['email'] ?? '')) !== $email || empty($record['reset_token_hash']) || $now > (int) ($record['expires_at'] ?? 0) || !password_verify($resetToken, $record['reset_token_hash'])) {
            return ['delete' => true, 'response' => ['ok' => false, 'message' => 'Your reset session is invalid or has expired.']];
        }
        try {
            firebaseAdminUpdatePassword((string) $record['uid'], $newPassword);
        } catch (Throwable $error) {
            error_log('CoolTime Firebase password update failed: ' . $error->getMessage());
            return ['record' => $record, 'response' => ['ok' => false, 'message' => 'We could not update your password. Please try again later.']];
        }
        return ['delete' => true, 'response' => ['ok' => true, 'message' => 'Your password has been reset successfully.']];
    });
    jsonResponse($response, $response['ok'] ? 200 : 400);
} catch (Throwable $error) {
    error_log('CoolTime password-reset completion failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'We could not update your password right now. Please try again later.'], 503);
}
