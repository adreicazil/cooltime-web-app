<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

requirePost();
$user = firebaseUserFromToken(authorizationToken());
try {
    revokeOtpSession($user['uid']);
    jsonResponse(['ok' => true]);
} catch (Throwable $error) {
    error_log('CoolTime OTP session revocation failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'The secure session could not be revoked.'], 503);
}
