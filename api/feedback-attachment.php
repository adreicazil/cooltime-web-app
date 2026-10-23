<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function attachmentResponse(string $message, int $status): never
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $message]); exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') attachmentResponse('Method not allowed.', 405);
    $user = firebaseUserFromToken(authorizationToken());
    requireOtpSession($user);
    $account = firebaseServiceAccount();
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/users/' . rawurlencode($user['uid']);
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    $profile = is_string($response) ? json_decode($response, true) : null;
    $role = $profile['fields']['role']['stringValue'] ?? '';
    if (!in_array($role, ['admin', 'super_admin'], true)) attachmentResponse('Administrator access is required.', 403);
    $ticket = trim((string) ($_GET['ticket'] ?? ''));
    if (!preg_match('/^CT-\d{8}-[A-F0-9]{12}$/', $ticket)) attachmentResponse('Invalid ticket.', 400);
    $storageRoot = environment('FEEDBACK_STORAGE_DIR', false);
    if ($storageRoot === '') $storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cooltime-feedback';
    $matches = glob(rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'feedback' . DIRECTORY_SEPARATOR . $ticket . '.*') ?: [];
    if (!$matches) attachmentResponse('Attachment not found.', 404);
    $path = $matches[0]; $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($extension) { 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', default => 'application/octet-stream' };
    header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . basename($path) . '"'); header('Cache-Control: private, no-store');
    readfile($path);
} catch (Throwable $error) {
    error_log('Feedback attachment read failed: ' . $error->getMessage());
    attachmentResponse('Attachment could not be read.', 500);
}
