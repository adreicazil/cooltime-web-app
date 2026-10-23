<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requirePost();

function accountEndpointRead(array $account, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/users/' . rawurlencode($id);
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if (str_contains($http_response_header[0] ?? '', ' 404 ')) return null;
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) {
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
        throw new RuntimeException('Firestore profile read failed (HTTP ' . ($status[1] ?? '0') . ').');
    }
    $document = json_decode($response, true);
    if (!is_array($document) || !isset($document['fields'])) throw new RuntimeException('Firestore profile response was invalid.');
    $fields = [];
    foreach ($document['fields'] as $key => $value) foreach (['stringValue', 'booleanValue'] as $type) if (isset($value[$type])) { $fields[$key] = $value[$type]; break; }
    return $fields;
}

function accountEndpointRequest(string $url, array $payload, string $token = ''): array
{
    $headers = "Content-Type: application/json\r\n";
    if ($token !== '') $headers .= 'Authorization: Bearer ' . $token . "\r\n";
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => json_encode($payload, JSON_THROW_ON_ERROR), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    $body = is_string($response) ? json_decode($response, true) : null;
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status' => (int) ($match[1] ?? 0), 'body' => is_array($body) ? $body : []];
}

function accountEndpointPatch(array $account, string $uid, array $fields): void
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:commit';
    $name = 'projects/' . $account['project_id'] . '/databases/(default)/documents/users/' . $uid;
    $result = accountEndpointRequest($url, ['writes' => [[
        'update' => ['name' => $name, 'fields' => $fields], 'updateMask' => ['fieldPaths' => array_keys($fields)],
    ]]], firebaseAdminAccessToken());
    if ($result['status'] < 200 || $result['status'] >= 300) throw new RuntimeException('Firestore profile update failed (HTTP ' . $result['status'] . ').');
}

function accountEndpointLog(array $account, string $actorId, string $action, string $targetId): void
{
    $id = 'account_' . $action . '_' . bin2hex(random_bytes(6));
    firestorePatchDocument($account, 'activity_logs', $id, [
        'type' => ['stringValue' => 'account_' . $action], 'adminId' => ['stringValue' => $actorId],
        'targetUserId' => ['stringValue' => $targetId], 'createdAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
    ]);
}

try {
    $data = requestData();
    $actor = firebaseUserFromToken(authorizationToken());
    requireOtpSession($actor);
    $account = firebaseServiceAccount();
    $actorProfile = accountEndpointRead($account, $actor['uid']);
    if (strtolower(trim((string) ($actorProfile['status'] ?? 'active'))) !== 'active'
        || normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') {
        jsonResponse(['ok' => false, 'message' => 'Super administrator access is required.'], 403);
    }

    $action = (string) ($data['action'] ?? '');
    $uid = trim((string) ($data['uid'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $role = strtolower(trim((string) ($data['role'] ?? 'customer')));
    $status = strtolower(trim((string) ($data['status'] ?? 'active')));
    if (!in_array($role, ['customer', 'staff', 'admin', 'super_admin'], true) || !in_array($status, ['active', 'disabled'], true)) jsonResponse(['ok' => false, 'message' => 'Role or status is invalid.'], 422);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['ok' => false, 'message' => 'Name and valid email are required.'], 422);

    if ($action === 'create') {
        $password = (string) ($data['password'] ?? '');
        if (strlen($password) < 6) jsonResponse(['ok' => false, 'message' => 'Password must be at least 6 characters.'], 422);
        $apiKey = environment('FIREBASE_API_KEY');
        $created = accountEndpointRequest('https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . rawurlencode($apiKey), ['email' => $email, 'password' => $password, 'returnSecureToken' => false]);
        $uid = (string) ($created['body']['localId'] ?? '');
        if ($created['status'] < 200 || $created['status'] >= 300 || $uid === '') jsonResponse(['ok' => false, 'message' => $created['body']['error']['message'] ?? 'Firebase account creation failed.'], 422);
    } elseif (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $uid) || !accountEndpointRead($account, $uid)) {
        jsonResponse(['ok' => false, 'message' => 'A valid existing account is required.'], 404);
    }

    if ($action === 'update' || $action === 'create') {
        $authPayload = ['localId' => $uid, 'email' => $email, 'displayName' => $name, 'disableUser' => $status !== 'active'];
        if ((string) ($data['password'] ?? '') !== '') {
            if (strlen((string) $data['password']) < 6) jsonResponse(['ok' => false, 'message' => 'Password must be at least 6 characters.'], 422);
            $authPayload['password'] = (string) $data['password'];
        }
        $authResult = accountEndpointRequest('https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/accounts:update', $authPayload, firebaseAdminAccessToken());
        if ($authResult['status'] < 200 || $authResult['status'] >= 300) throw new RuntimeException('Firebase Auth account update failed.');
        $profileFields = [
            'uid' => ['stringValue' => $uid], 'name' => ['stringValue' => $name], 'username' => ['stringValue' => $name],
            'email' => ['stringValue' => $email], 'role' => ['stringValue' => $role], 'status' => ['stringValue' => $status],
            'updatedAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
        ];
        if ($action === 'create') $profileFields['createdAt'] = ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')];
        accountEndpointPatch($account, $uid, $profileFields);
        accountEndpointLog($account, $actor['uid'], $action, $uid);
        $accountAction = $action === 'create' ? 'created' : 'updated';
        $statusLabel = $status === 'active' ? 'active' : 'disabled';
        $emailSent = trySendConfiguredEmail(
            $email,
            'CoolTime account ' . $accountAction,
            '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Your CoolTime account has been ' . $accountAction . ' by an administrator.</p><p>Account role: ' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '<br>Account status: ' . $statusLabel . '</p>',
            "Hello {$name}, your CoolTime account has been {$accountAction} by an administrator. Account role: {$role}. Account status: {$statusLabel}."
        );
        jsonResponse(['ok' => true, 'success' => true, 'uid' => $uid, 'emailSent' => $emailSent, 'message' => 'Admin account created successfully']);
    }
    if ($action === 'delete') {
        if ($uid === $actor['uid']) jsonResponse(['ok' => false, 'message' => 'You cannot delete your own account.'], 409);
        accountEndpointLog($account, $actor['uid'], $action, $uid);
        $deleted = accountEndpointRequest('https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/accounts:delete', ['localId' => $uid], firebaseAdminAccessToken());
        if ($deleted['status'] < 200 || $deleted['status'] >= 300) throw new RuntimeException('Firebase Auth deletion failed.');
        $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/users/' . rawurlencode($uid);
        $context = stream_context_create(['http' => ['method' => 'DELETE', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 15, 'ignore_errors' => true]]);
        @file_get_contents($url, false, $context);
        jsonResponse(['ok' => true]);
    }
    jsonResponse(['ok' => false, 'message' => 'Unsupported account action.'], 400);
} catch (Throwable $error) {
    error_log('Account administration failed: ' . $error->getMessage());
    $message = preg_match('/Firestore .*HTTP 403/', $error->getMessage())
        ? 'The server account was denied access to Firestore. Check its project IAM permissions.'
        : 'Account change could not be completed.';
    jsonResponse(['ok' => false, 'message' => $message], 500);
}
