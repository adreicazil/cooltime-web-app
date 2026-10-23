<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-engine.php';
requirePost();

function adminWorkflowRead(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if (str_contains($http_response_header[0] ?? '', ' 404 ')) return null;
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) {
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
        throw new RuntimeException('Firestore read failed (HTTP ' . ($status[1] ?? '0') . ').');
    }
    $document = json_decode($response, true);
    if (!isset($document['fields'])) throw new RuntimeException('Firestore response was invalid.');
    $fields = [];
    foreach ($document['fields'] as $name => $value) {
        foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) {
            if (array_key_exists($type, $value)) { $fields[$name] = $value[$type]; break; }
        }
    }
    return $fields;
}

function adminWorkflowDecodeValue(array $value): mixed
{
    foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue', 'referenceValue'] as $type) {
        if (!array_key_exists($type, $value)) continue;
        return match ($type) {
            'integerValue' => (int) $value[$type],
            'doubleValue' => (float) $value[$type],
            'booleanValue' => (bool) $value[$type],
            default => $value[$type],
        };
    }
    if (array_key_exists('nullValue', $value)) return null;
    if (isset($value['arrayValue']['values'])) return array_map('adminWorkflowDecodeValue', $value['arrayValue']['values']);
    if (isset($value['mapValue']['fields'])) return array_map('adminWorkflowDecodeValue', $value['mapValue']['fields']);
    return null;
}

function adminWorkflowList(array $account, string $collection): array
{
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $collection)) throw new InvalidArgumentException('Invalid collection name.');
    $rows = [];
    $pageToken = '';
    do {
        $query = ['pageSize' => 1000];
        if ($pageToken !== '') $query['pageToken'] = $pageToken;
        $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
            . '/databases/(default)/documents/' . rawurlencode($collection) . '?' . http_build_query($query);
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(),
            'timeout' => 20, 'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (!is_string($response) || !preg_match('/\s2\d\d\s/', $statusLine)) {
            preg_match('/\s(\d{3})\s/', $statusLine, $status);
            throw new RuntimeException('Unable to read admin collection ' . $collection . ' (HTTP ' . ($status[1] ?? '0') . ').');
        }
        $result = json_decode($response, true);
        if (!is_array($result)) throw new RuntimeException('Firestore returned invalid collection data.');
        foreach (($result['documents'] ?? []) as $document) {
            $path = explode('/', (string) ($document['name'] ?? ''));
            $id = (string) end($path);
            $row = ['id' => $id];
            foreach (($document['fields'] ?? []) as $field => $value) $row[$field] = adminWorkflowDecodeValue($value);
            $rows[] = $row;
        }
        $pageToken = (string) ($result['nextPageToken'] ?? '');
    } while ($pageToken !== '');
    return $rows;
}

function adminWorkflowCommit(array $account, array $writes): void
{
    $formatted = [];
    foreach ($writes as $write) {
        $name = 'projects/' . $account['project_id'] . '/databases/(default)/documents/' . $write['collection'] . '/' . $write['id'];
        $formatted[] = ['update' => ['name' => $name, 'fields' => $write['fields']], 'updateMask' => ['fieldPaths' => array_keys($write['fields'])]];
    }
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:commit';
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json", 'content' => json_encode(['writes' => $formatted], JSON_THROW_ON_ERROR), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) {
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
        throw new RuntimeException('Firestore update failed (HTTP ' . ($status[1] ?? '0') . ').');
    }
}

function adminWorkflowDelete(array $account, string $collection, string $id): void
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:commit';
    $path = 'projects/' . $account['project_id'] . '/databases/(default)/documents/' . $collection . '/' . $id;
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json",
        'content' => json_encode(['writes' => [['delete' => $path]]], JSON_THROW_ON_ERROR), 'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) {
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
        throw new RuntimeException('Firestore delete failed (HTTP ' . ($status[1] ?? '0') . ').');
    }
}

function adminWorkflowExpireCheckout(string $sessionId): void
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $sessionId)) throw new InvalidArgumentException('Checkout session reference is invalid.');
    $secret = environment('PAYMONGO_SECRET_KEY', false);
    if ($secret === '' || !str_starts_with($secret, 'sk_')) throw new RuntimeException('PayMongo secret key is not configured; the checkout cannot be expired.');
    $headers = ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json'];
    $url = 'https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId);
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $statusLine)) throw new RuntimeException('Unable to verify PayMongo checkout session before cancellation.');
    $session = json_decode($response, true)['data'] ?? [];
    $attributes = $session['attributes'] ?? [];
    foreach (($attributes['payments'] ?? []) as $payment) {
        if (strtolower((string) ($payment['attributes']['status'] ?? '')) === 'paid') {
            throw new RuntimeException('This checkout has a successful payment. Refresh payment status before cancelling the booking.');
        }
    }
    if (strtolower((string) ($attributes['status'] ?? '')) === 'expired') return;
    $expired = httpJsonRequest($url . '/expire', ['data' => ['attributes' => (object) []]], $headers);
    if ($expired['status'] < 200 || $expired['status'] >= 300) throw new RuntimeException('PayMongo could not expire the active checkout session.');
}

function adminWorkflowActor(): array
{
    $user = firebaseUserFromToken(authorizationToken());
    requireOtpSession($user);
    $account = firebaseServiceAccount();
    $profile = adminWorkflowRead($account, 'users', $user['uid']);
    if (strtolower(trim((string) ($profile['status'] ?? 'active'))) !== 'active'
        || !in_array(normalizeAccountRole($profile['role'] ?? ''), ['admin', 'super_admin'], true)) {
        jsonResponse(['ok' => false, 'message' => 'Administrator access is required.'], 403);
    }
    return [$user, $account, $profile];
}

$action = 'unknown';
try {
    $input = requestData();
    $action = (string) ($input['action'] ?? 'unknown');
    [$actor, $account, $actorProfile] = adminWorkflowActor();
    if ($action === 'verify_session') {
        jsonResponse(['ok' => true, 'role' => normalizeAccountRole($actorProfile['role'] ?? ''), 'profile' => [
            'name' => (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? ''),
            'email' => (string) $actor['email'],
        ]]);
    }
    if ($action === 'load_data') {
        $collections = ['bookings', 'users', 'services', 'invoices', 'payments', 'feedback', 'inquiries', 'vehicles', 'branches', 'reports', 'system_settings', 'service_history', 'schedule_slots', 'staff', 'branch', 'notifications', 'activity_logs', 'paymongo_intents'];
        $data = [];
        foreach ($collections as $collection) $data[$collection] = adminWorkflowList($account, $collection);
        jsonResponse(['ok' => true, 'data' => $data, 'actor' => ['uid' => $actor['uid'], 'email' => $actor['email']]]);
    }
    if ($action === 'list_manageable_accounts') {
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') {
            jsonResponse(['ok' => false, 'message' => 'Super administrator access is required.'], 403);
        }
        $accounts = array_values(array_filter(adminWorkflowList($account, 'users'), static function (array $row): bool {
            return in_array(normalizeAccountRole($row['role'] ?? ''), ['admin', 'staff'], true);
        }));
        jsonResponse(['ok' => true, 'accounts' => $accounts]);
    }
    if ($action === 'send_email') {
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        if (!$email || $subject === '' || $message === '') jsonResponse(['ok' => false, 'message' => 'Recipient, subject, and message are required.'], 422);
        $emailSent = trySendConfiguredEmail($email, $subject, nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')), $message);
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Email sent.' : 'Email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    if ($action === 'save_service') {
        $serviceId = trim((string) ($input['serviceId'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $serviceId) || $name === '' || $name === '0' || $price === false || $price <= 0) {
            jsonResponse(['ok' => false, 'message' => 'Enter a valid service ID, name, and positive price.'], 422);
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $fields = [
            'serviceId' => ['stringValue' => $serviceId], 'name' => ['stringValue' => substr($name, 0, 160)],
            'price' => ['doubleValue' => (float) $price], 'duration' => ['stringValue' => substr(trim((string) ($input['duration'] ?? '')), 0, 80)],
            'description' => ['stringValue' => substr(trim((string) ($input['description'] ?? '')), 0, 2000)],
            'status' => ['stringValue' => 'active'], 'updatedAt' => ['timestampValue' => $now],
        ];
        if (!adminWorkflowRead($account, 'services', $serviceId)) $fields['createdAt'] = ['timestampValue' => $now];
        adminWorkflowCommit($account, [['collection' => 'services', 'id' => $serviceId, 'fields' => $fields]]);
        jsonResponse(['ok' => true, 'message' => 'Service saved.']);
    }
    if ($action === 'delete_service') {
        $serviceId = trim((string) ($input['serviceId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $serviceId)) jsonResponse(['ok' => false, 'message' => 'A valid service ID is required.'], 422);
        if (!adminWorkflowRead($account, 'services', $serviceId)) jsonResponse(['ok' => false, 'message' => 'Service was not found.'], 404);
        adminWorkflowDelete($account, 'services', $serviceId);
        jsonResponse(['ok' => true, 'message' => 'Service removed.']);
    }
    if ($action === 'set_customer_status') {
        $customerId = trim((string) ($input['customerId'] ?? ''));
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $customerId) || !in_array($status, ['active', 'disabled'], true)) {
            jsonResponse(['ok' => false, 'message' => 'A valid customer and status are required.'], 422);
        }
        $customer = adminWorkflowRead($account, 'users', $customerId);
        if (!$customer || normalizeAccountRole($customer['role'] ?? '') !== 'customer') jsonResponse(['ok' => false, 'message' => 'Customer record was not found.'], 404);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        adminWorkflowCommit($account, [
            ['collection' => 'users', 'id' => $customerId, 'fields' => ['status' => ['stringValue' => $status], 'updatedAt' => ['timestampValue' => $now]]],
            ['collection' => 'activity_logs', 'id' => 'customer_status_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'customer_status_updated'], 'targetUserId' => ['stringValue' => $customerId],
                'status' => ['stringValue' => $status], 'adminId' => ['stringValue' => $actor['uid']], 'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        jsonResponse(['ok' => true, 'message' => 'Customer status updated.']);
    }
    if ($action === 'import_customer_records') {
        $records = $input['records'] ?? null;
        if (!is_array($records) || count($records) > 200) jsonResponse(['ok' => false, 'message' => 'Import batches must contain between 1 and 200 customer records.'], 422);
        $existingUsers = adminWorkflowList($account, 'users');
        $customersById = [];
        $customersByEmail = [];
        foreach ($existingUsers as $existingUser) {
            if (normalizeAccountRole($existingUser['role'] ?? '') !== 'customer') continue;
            $customersById[(string) $existingUser['id']] = $existingUser;
            $emailKey = strtolower(trim((string) ($existingUser['email'] ?? '')));
            if ($emailKey !== '') $customersByEmail[$emailKey][] = $existingUser;
        }

        $writes = [];
        $seenIds = [];
        $imported = 0;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $recordId = trim((string) ($record['id'] ?? ''));
            $email = strtolower(trim((string) ($record['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $customer = null;
            if ($recordId !== '') {
                $candidate = $customersById[$recordId] ?? null;
                if (!$candidate || strtolower(trim((string) ($candidate['email'] ?? ''))) !== $email) continue;
                $customer = $candidate;
            } else {
                $emailMatches = $customersByEmail[$email] ?? [];
                if (count($emailMatches) !== 1) continue;
                $customer = $emailMatches[0];
            }
            $customerId = (string) $customer['id'];
            if (isset($seenIds[$customerId])) continue;
            $fields = [];
            $name = trim((string) ($record['name'] ?? ''));
            if ($name !== '') {
                $name = substr($name, 0, 160);
                $fields['name'] = ['stringValue' => $name];
                $fields['username'] = ['stringValue' => $name];
            }
            $phone = trim((string) ($record['phone'] ?? ''));
            if ($phone !== '') {
                $phone = substr($phone, 0, 40);
                $fields['phone'] = ['stringValue' => $phone];
                $fields['contactNumber'] = ['stringValue' => $phone];
            }
            $status = strtolower(trim((string) ($record['status'] ?? '')));
            if (in_array($status, ['active', 'disabled'], true)) $fields['status'] = ['stringValue' => $status];
            if (!$fields) continue;
            $fields['updatedAt'] = ['timestampValue' => $now];
            $writes[] = ['collection' => 'users', 'id' => $customerId, 'fields' => $fields];
            $writes[] = ['collection' => 'activity_logs', 'id' => 'customer_import_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'customer_record_imported'], 'targetUserId' => ['stringValue' => $customerId],
                'adminId' => ['stringValue' => $actor['uid']], 'createdAt' => ['timestampValue' => $now],
            ]];
            $seenIds[$customerId] = true;
            $imported++;
        }
        if ($writes) adminWorkflowCommit($account, $writes);
        jsonResponse(['ok' => true, 'imported' => $imported, 'skipped' => count($records) - $imported, 'message' => 'Customer records imported.']);
    }
    if ($action === 'save_report') {
        $reportId = trim((string) ($input['reportId'] ?? ''));
        $type = trim((string) ($input['type'] ?? ''));
        $period = trim((string) ($input['period'] ?? ''));
        $start = trim((string) ($input['start'] ?? ''));
        $end = trim((string) ($input['end'] ?? ''));
        $format = trim((string) ($input['format'] ?? ''));
        $rowCount = filter_var($input['rowCount'] ?? null, FILTER_VALIDATE_INT);
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $reportId) || !in_array($type, ['bookings', 'inquiries', 'feedback', 'sales'], true)
            || !in_array($period, ['daily', 'weekly', 'monthly'], true) || !in_array($format, ['excel', 'csv', 'chart', 'pdf'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
            || $start > $end || $rowCount === false || $rowCount < 0) {
            jsonResponse(['ok' => false, 'message' => 'Report parameters are invalid.'], 422);
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        adminWorkflowCommit($account, [
            ['collection' => 'reports', 'id' => $reportId, 'fields' => [
                'reportId' => ['stringValue' => $reportId], 'type' => ['stringValue' => $type], 'period' => ['stringValue' => $period],
                'start' => ['stringValue' => $start], 'end' => ['stringValue' => $end], 'format' => ['stringValue' => $format],
                'rowCount' => ['integerValue' => (string) $rowCount], 'generatedBy' => ['stringValue' => $actor['uid']], 'createdAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'activity_logs', 'id' => 'report_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'report_generated'], 'reportId' => ['stringValue' => $reportId],
                'adminId' => ['stringValue' => $actor['uid']], 'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        jsonResponse(['ok' => true, 'message' => 'Report saved to history.']);
    }
    if ($action === 'save_setting') {
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') jsonResponse(['ok' => false, 'message' => 'Super administrator access is required.'], 403);
        $category = trim((string) ($input['category'] ?? ''));
        $key = trim((string) ($input['key'] ?? ''));
        $value = trim((string) ($input['value'] ?? ''));
        if (!in_array($category, ['General', 'Security', 'Notifications', 'Backup'], true)
            || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $key) || $value === '' || strlen($value) > 1000) {
            jsonResponse(['ok' => false, 'message' => 'Setting category, key, or value is invalid.'], 422);
        }
        $settingId = strtolower($category) . '_' . $key;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        adminWorkflowCommit($account, [
            ['collection' => 'system_settings', 'id' => $settingId, 'fields' => [
                'category' => ['stringValue' => $category], 'key' => ['stringValue' => $key], 'value' => ['stringValue' => $value],
                'updatedBy' => ['stringValue' => $actor['uid']], 'updatedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'activity_logs', 'id' => 'setting_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'system_setting_updated'], 'adminId' => ['stringValue' => $actor['uid']],
                'category' => ['stringValue' => $category], 'key' => ['stringValue' => $key], 'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        jsonResponse(['ok' => true, 'message' => 'Setting saved.']);
    }
    if ($action === 'flag_pos_transaction') {
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') jsonResponse(['ok' => false, 'message' => 'Super administrator access is required.'], 403);
        $invoiceId = trim((string) ($input['invoiceId'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $invoiceId) || $reason === '' || strlen($reason) > 1000) {
            jsonResponse(['ok' => false, 'message' => 'A valid POS transaction and flag reason are required.'], 422);
        }
        $invoice = adminWorkflowRead($account, 'invoices', $invoiceId);
        if (!$invoice || strtolower((string) ($invoice['source'] ?? '')) !== 'pos') jsonResponse(['ok' => false, 'message' => 'POS transaction was not found.'], 404);
        $isFlagged = filter_var($input['isFlagged'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ((bool) ($invoice['isFlagged'] ?? false) === $isFlagged) jsonResponse(['ok' => false, 'message' => 'The POS transaction flag has already changed. Refresh and try again.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $actorName = (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? $actor['email']);
        $eventType = $isFlagged ? 'pos_transaction_flagged' : 'pos_transaction_unflagged';
        $fields = [
            'isFlagged' => ['booleanValue' => $isFlagged], 'flagReason' => ['stringValue' => $isFlagged ? substr($reason, 0, 1000) : ''],
            'lastAuditAt' => ['timestampValue' => $now], 'lastAuditBy' => ['stringValue' => $actor['uid']],
            'lastAuditByName' => ['stringValue' => $actorName], 'updatedAt' => ['timestampValue' => $now],
        ];
        if ($isFlagged) $fields['flaggedAt'] = ['timestampValue' => $now];
        adminWorkflowCommit($account, [
            ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => $fields],
            ['collection' => 'activity_logs', 'id' => 'pos_flag_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => $eventType], 'invoiceId' => ['stringValue' => $invoiceId],
                'adminId' => ['stringValue' => $actor['uid']], 'adminEmail' => ['stringValue' => $actor['email']],
                'reason' => ['stringValue' => substr($reason, 0, 1000)], 'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        jsonResponse(['ok' => true, 'isFlagged' => $isFlagged, 'message' => $isFlagged ? 'POS transaction flagged.' : 'POS transaction flag removed.']);
    }
    if ($action === 'void_pos_transaction') {
        $invoiceId = trim((string) ($input['invoiceId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $invoiceId)) jsonResponse(['ok' => false, 'message' => 'A valid POS transaction is required.'], 422);
        $invoice = adminWorkflowRead($account, 'invoices', $invoiceId);
        if (!$invoice || strtolower((string) ($invoice['source'] ?? '')) !== 'pos') jsonResponse(['ok' => false, 'message' => 'POS transaction was not found.'], 404);
        if (strtolower((string) ($invoice['status'] ?? '')) !== 'paid') jsonResponse(['ok' => false, 'message' => 'Only paid POS transactions can be voided.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $actorName = (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? $actor['email']);
        adminWorkflowCommit($account, [
            ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => [
                'status' => ['stringValue' => 'voided'], 'voidedAt' => ['timestampValue' => $now],
                'voidedBy' => ['stringValue' => $actor['uid']], 'voidedByEmail' => ['stringValue' => $actor['email']],
                'lastAuditAt' => ['timestampValue' => $now], 'lastAuditBy' => ['stringValue' => $actor['uid']],
                'lastAuditByName' => ['stringValue' => $actorName],
                'updatedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'activity_logs', 'id' => 'pos_void_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'pos_transaction_voided'], 'invoiceId' => ['stringValue' => $invoiceId],
                'adminId' => ['stringValue' => $actor['uid']], 'adminEmail' => ['stringValue' => $actor['email']],
                'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        jsonResponse(['ok' => true, 'message' => 'POS transaction voided.']);
    }
    if ($action === 'cancel_booking') {
        $bookingId = trim((string) ($input['bookingId'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId) || $reason === '') jsonResponse(['ok' => false, 'message' => 'Booking and cancellation reason are required.'], 422);
        $booking = adminWorkflowRead($account, 'bookings', $bookingId);
        if (!$booking) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
        if (strtolower((string) ($booking['status'] ?? '')) === 'confirmed') jsonResponse(['ok' => false, 'message' => 'Confirmed bookings cannot be cancelled.'], 409);
        if (strtolower((string) ($booking['status'] ?? '')) === 'paid') jsonResponse(['ok' => false, 'message' => 'This booking is marked paid. Verify its invoice before cancelling it.'], 409);
        if (strtolower((string) ($booking['status'] ?? '')) === 'cancelled') jsonResponse(['ok' => true, 'alreadyCancelled' => true, 'message' => 'Booking was already cancelled.']);
        $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? ''));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $logId = 'cancel_' . bin2hex(random_bytes(8));
        $writes = [
            ['collection' => 'bookings', 'id' => $bookingId, 'fields' => [
                'status' => ['stringValue' => 'cancelled'], 'cancelledAt' => ['timestampValue' => $now],
                'cancellationReason' => ['stringValue' => substr($reason, 0, 1000)],
                'cancelledBy' => ['stringValue' => $actor['uid']], 'cancelledByEmail' => ['stringValue' => $actor['email']],
                'updatedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'activity_logs', 'id' => $logId, 'fields' => [
                'type' => ['stringValue' => 'booking_cancelled'], 'bookingId' => ['stringValue' => $bookingId],
                'adminId' => ['stringValue' => $actor['uid']], 'adminEmail' => ['stringValue' => $actor['email']],
                'reason' => ['stringValue' => substr($reason, 0, 1000)], 'createdAt' => ['timestampValue' => $now],
            ]],
        ];
        $invoiceId = 'web_' . $bookingId;
        $invoice = adminWorkflowRead($account, 'invoices', $invoiceId);
        if ($invoice && strtolower((string) ($invoice['status'] ?? '')) === 'paid') {
            jsonResponse(['ok' => false, 'message' => 'This invoice is already paid. Resolve the payment before cancelling the booking.'], 409);
        }
        $checkoutSessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
        $checkoutPaymentId = $checkoutSessionId !== '' ? 'paymongo_' . $checkoutSessionId : '';
        $checkoutPayment = $checkoutPaymentId !== '' ? adminWorkflowRead($account, 'payments', $checkoutPaymentId) : null;
        if ($checkoutPayment && strtolower((string) ($checkoutPayment['status'] ?? '')) === 'paid') {
            jsonResponse(['ok' => false, 'message' => 'This checkout has a recorded successful payment. Refresh its invoice before cancelling the booking.'], 409);
        }
        if ($checkoutSessionId !== '' && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $checkoutSessionId)) {
            adminWorkflowExpireCheckout($checkoutSessionId);
            if ($checkoutPayment) {
                $writes[] = ['collection' => 'payments', 'id' => $checkoutPaymentId, 'fields' => [
                    'status' => ['stringValue' => 'abandoned'], 'failureReason' => ['stringValue' => 'Checkout expired by administrator when cancelling the booking.'],
                    'updatedAt' => ['timestampValue' => $now],
                ]];
            }
        }
        if ($invoice && !in_array(strtolower((string) ($invoice['status'] ?? '')), ['paid', 'cancelled'], true)) {
            $writes[] = ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => [
                'status' => ['stringValue' => 'cancelled'], 'updatedAt' => ['timestampValue' => $now],
                'balance_due' => ['doubleValue' => 0], 'balanceDue' => ['doubleValue' => 0],
            ]];
        }
        adminWorkflowCommit($account, $writes);
        $booking['id'] = $bookingId;
        $details = notificationBookingDetails($account, $booking);
        $details['paymentBalance'] = 0;
        $details['bookingStatus'] = 'cancelled';
        $details['paymentStatus'] = $invoice ? 'cancelled' : 'unbilled';
        $details['message'] = 'Appointment cancellation: Your service appointment has been successfully cancelled!';
        $emailSent = notifyBookingCancelled($account, $booking, $customer ?? [], $details);
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Booking cancelled, checkout closed, and confirmation email sent.' : 'Booking cancelled and its slot released. Checkout closed if active; email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    if ($action === 'approve_booking') {
        $bookingId = trim((string) ($input['bookingId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)) jsonResponse(['ok' => false, 'message' => 'A valid booking is required.'], 422);
        $booking = adminWorkflowRead($account, 'bookings', $bookingId);
        if (!$booking) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
        if (strtolower((string) ($booking['status'] ?? '')) === 'confirmed') jsonResponse(['ok' => true, 'alreadyApproved' => true, 'message' => 'Booking was already approved.']);
        if (strtolower((string) ($booking['status'] ?? '')) !== 'pending') jsonResponse(['ok' => false, 'message' => 'Only pending bookings can be approved.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        adminWorkflowCommit($account, [
            ['collection' => 'bookings', 'id' => $bookingId, 'fields' => [
                'status' => ['stringValue' => 'confirmed'], 'confirmedAt' => ['timestampValue' => $now],
                'confirmedBy' => ['stringValue' => $actor['uid']], 'updatedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'activity_logs', 'id' => 'approved_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'booking_approved'], 'bookingId' => ['stringValue' => $bookingId],
                'adminId' => ['stringValue' => $actor['uid']], 'adminEmail' => ['stringValue' => $actor['email']], 'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? ''));
        $customerEmail = (string) ($customer['email'] ?? $booking['customerEmail'] ?? '');
        $service = adminWorkflowRead($account, 'services', (string) ($booking['serviceId'] ?? ''));
        $details = ' for ' . (string) ($service['name'] ?? $booking['serviceId'] ?? 'your service') . ' on ' . (string) ($booking['date'] ?? '') . ' at ' . (string) ($booking['timeSlot'] ?? '');
        $emailSent = filter_var($customerEmail, FILTER_VALIDATE_EMAIL)
            ? trySendConfiguredEmail($customerEmail, 'CoolTime booking confirmed',
                '<p>Your booking ' . htmlspecialchars($bookingId, ENT_QUOTES, 'UTF-8') . htmlspecialchars($details, ENT_QUOTES, 'UTF-8') . ' is confirmed.</p>',
                'Your booking ' . $bookingId . $details . ' is confirmed.')
            : false;
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Booking approved and confirmation email sent.' : 'Booking approved. Email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    if ($action === 'reschedule_booking') {
        $bookingId = trim((string) ($input['bookingId'] ?? ''));
        $date = trim((string) ($input['date'] ?? ''));
        $timeSlot = strtoupper(trim((string) ($input['timeSlot'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !preg_match('/^\d{1,2}:\d{2}\s?(AM|PM)$/', $timeSlot)) {
            jsonResponse(['ok' => false, 'message' => 'Booking and a valid new date and time are required.'], 422);
        }
        $timezone = new DateTimeZone('Asia/Manila');
        $slotDateTime = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $date . ' ' . $timeSlot, $timezone);
        $normalizedTime = preg_replace('/^0(?=\d:)/', '', $timeSlot) ?: $timeSlot;
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$slotDateTime || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $slotDateTime->format('Y-m-d g:i A') !== $date . ' ' . $normalizedTime
            || $slotDateTime <= new DateTimeImmutable('now', $timezone)) {
            jsonResponse(['ok' => false, 'message' => 'The new appointment date and time must be valid and in the future.'], 422);
        }
        $booking = adminWorkflowRead($account, 'bookings', $bookingId);
        if (!$booking) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
        if (!in_array(strtolower((string) ($booking['status'] ?? '')), ['pending', 'confirmed'], true)) {
            jsonResponse(['ok' => false, 'message' => 'Only pending or confirmed bookings can be rescheduled.'], 409);
        }
        $branchId = (string) ($booking['branchId'] ?? '');
        $branchAliases = match (strtolower(str_replace([' ', '-'], '_', $branchId))) {
            'santarosa', 'santa_rosa' => ['santarosa', 'santa_rosa'],
            'cabuyao_main', 'cabuyao' => ['cabuyao_main', 'cabuyao'],
            default => [$branchId],
        };
        $branch = null;
        foreach ($branchAliases as $alias) {
            $branch = adminWorkflowRead($account, 'branches', $alias) ?? adminWorkflowRead($account, 'branch', $alias);
            if ($branch) break;
        }
        if (!$branch || (int) ($branch['capacity'] ?? 0) < 1) jsonResponse(['ok' => false, 'message' => 'Branch capacity is not configured.'], 503);
        $occupied = 0;
        foreach (adminWorkflowList($account, 'bookings') as $existing) {
            if (($existing['id'] ?? '') === $bookingId) continue;
            $existingBranch = strtolower(str_replace([' ', '-'], '_', (string) ($existing['branchId'] ?? '')));
            $targetBranch = strtolower(str_replace([' ', '-'], '_', $branchId));
            $sameBranch = $existingBranch === $targetBranch
                || (in_array($existingBranch, ['santarosa', 'santa_rosa'], true) && in_array($targetBranch, ['santarosa', 'santa_rosa'], true))
                || (in_array($existingBranch, ['cabuyao_main', 'cabuyao'], true) && in_array($targetBranch, ['cabuyao_main', 'cabuyao'], true));
            if (!$sameBranch
                || (string) ($existing['date'] ?? '') !== $date
                || strcasecmp((string) ($existing['timeSlot'] ?? ''), $timeSlot) !== 0) continue;
            if (!in_array(strtolower((string) ($existing['status'] ?? '')), ['cancelled', 'canceled', 'completed'], true)) $occupied++;
        }
        if ($occupied >= (int) $branch['capacity']) jsonResponse(['ok' => false, 'message' => 'That branch and time slot is full. Choose another appointment slot.'], 409);

        $oldDate = (string) ($booking['date'] ?? '');
        $oldTime = (string) ($booking['timeSlot'] ?? '');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        adminWorkflowCommit($account, [
            ['collection' => 'bookings', 'id' => $bookingId, 'fields' => [
                'date' => ['stringValue' => $date], 'timeSlot' => ['stringValue' => $timeSlot], 'updatedAt' => ['timestampValue' => $now],
                'rescheduledAt' => ['timestampValue' => $now], 'rescheduledBy' => ['stringValue' => $actor['uid']],
            ]],
            ['collection' => 'activity_logs', 'id' => 'rescheduled_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'booking_rescheduled'], 'bookingId' => ['stringValue' => $bookingId],
                'previousDate' => ['stringValue' => $oldDate], 'previousTime' => ['stringValue' => $oldTime],
                'adminId' => ['stringValue' => $actor['uid']], 'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        $booking['id'] = $bookingId;
        $booking['date'] = $date;
        $booking['timeSlot'] = $timeSlot;
        $details = notificationBookingDetails($account, $booking);
        $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? '')) ?? [];
        $emailSent = notifyRescheduleSuccess($account, (string) ($booking['customerId'] ?? ''), (string) ($customer['email'] ?? ''), $details);
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Booking slot updated and customer notified by email.' : 'Booking slot updated. In-app notification was saved; email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    if ($action === 'reject_cancellation') {
        $bookingId = trim((string) ($input['bookingId'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId) || $reason === '') jsonResponse(['ok' => false, 'message' => 'Booking and reply reason are required.'], 422);
        $booking = adminWorkflowRead($account, 'bookings', $bookingId);
        if (!$booking) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
        $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? ''));
        $customerEmail = (string) ($customer['email'] ?? $booking['customerEmail'] ?? '');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        adminWorkflowCommit($account, [[
            'collection' => 'activity_logs', 'id' => 'cancel_request_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'cancellation_request_rejected'], 'bookingId' => ['stringValue' => $bookingId],
                'adminId' => ['stringValue' => $actor['uid']], 'adminEmail' => ['stringValue' => $actor['email']],
                'reason' => ['stringValue' => substr($reason, 0, 1000)], 'createdAt' => ['timestampValue' => $now],
            ],
        ]]);
        $emailSent = filter_var($customerEmail, FILTER_VALIDATE_EMAIL)
            ? trySendConfiguredEmail($customerEmail, 'CoolTime cancellation request update',
                '<p>Your cancellation request for booking ' . htmlspecialchars($bookingId, ENT_QUOTES, 'UTF-8') . ' could not be approved.</p><p>' . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . '</p>',
                "Your cancellation request for booking {$bookingId} could not be approved. {$reason}")
            : false;
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Cancellation request rejected and reply emailed.' : 'Cancellation request rejected. Email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    if ($action === 'complete_booking') {
        $bookingId = trim((string) ($input['bookingId'] ?? ''));
        $downpayment = (float) ($input['downpaymentAmount'] ?? -1);
        $method = strtolower(trim((string) ($input['paymentMethod'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId) || !is_finite($downpayment) || $downpayment < 0
            || ($downpayment > 0 && !in_array($method, ['cash', 'card', 'e-wallet'], true))) {
            jsonResponse(['ok' => false, 'message' => 'Booking completion details are invalid.'], 422);
        }
        $booking = adminWorkflowRead($account, 'bookings', $bookingId);
        if (!$booking) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
        if (!in_array(strtolower((string) ($booking['status'] ?? '')), ['confirmed', 'in_progress'], true)) {
            jsonResponse(['ok' => false, 'message' => 'Only confirmed or in-progress bookings can be completed.'], 409);
        }
        $invoiceId = 'web_' . $bookingId;
        $invoice = adminWorkflowRead($account, 'invoices', $invoiceId);
        if (!$invoice) jsonResponse(['ok' => false, 'message' => 'The booking has no invoice to complete.'], 404);
        $sessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
        if ($sessionId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $sessionId)) {
            $checkoutPayment = adminWorkflowRead($account, 'payments', 'paymongo_' . $sessionId);
            if (in_array(strtolower((string) ($checkoutPayment['status'] ?? '')), ['pending', 'paid'], true)) {
                jsonResponse(['ok' => false, 'message' => 'Resolve the active checkout before completing service and recording a downpayment.'], 409);
            }
        }
        $total = (float) ($invoice['runningTotal'] ?? $invoice['totalAmount'] ?? $invoice['finalAmount'] ?? 0);
        if ($downpayment > $total) jsonResponse(['ok' => false, 'message' => 'Downpayment cannot exceed the invoice total.'], 422);
        if ($downpayment > 0 && strtolower((string) ($invoice['status'] ?? '')) === 'paid') jsonResponse(['ok' => false, 'message' => 'This invoice is already paid; do not record another downpayment.'], 409);
        if ((float) ($invoice['downpaymentAmount'] ?? 0) > 0) jsonResponse(['ok' => false, 'message' => 'A downpayment is already recorded for this invoice.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $balance = max(0, $total - $downpayment);
        $historyId = 'booking_' . $bookingId;
        $writes = [
            ['collection' => 'bookings', 'id' => $bookingId, 'fields' => [
                'status' => ['stringValue' => 'completed'], 'serviceCompletedAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => [
                'serviceStatus' => ['stringValue' => 'completed'], 'downpaymentAmount' => ['doubleValue' => $downpayment],
                'serviceCompletedAt' => ['timestampValue' => $now], 'status' => ['stringValue' => $balance <= 0 ? 'paid' : (string) ($invoice['status'] ?? 'pending')],
                'updatedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'service_history', 'id' => $historyId, 'fields' => [
                'bookingId' => ['stringValue' => $bookingId], 'customerId' => ['stringValue' => (string) ($booking['customerId'] ?? '')],
                'branchId' => ['stringValue' => (string) ($booking['branchId'] ?? '')], 'serviceId' => ['stringValue' => (string) ($booking['serviceId'] ?? '')],
                'totalAmount' => ['doubleValue' => $total], 'downpaymentAmount' => ['doubleValue' => $downpayment],
                'completedBy' => ['stringValue' => $actor['uid']], 'completedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'activity_logs', 'id' => 'completed_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'booking_completed'], 'bookingId' => ['stringValue' => $bookingId],
                'adminId' => ['stringValue' => $actor['uid']], 'adminEmail' => ['stringValue' => $actor['email']],
                'downpaymentAmount' => ['doubleValue' => $downpayment], 'createdAt' => ['timestampValue' => $now],
            ]],
        ];
        if ($downpayment > 0) {
            $paymentId = 'downpayment_' . $bookingId;
            $writes[] = ['collection' => 'payments', 'id' => $paymentId, 'fields' => [
                'invoiceId' => ['stringValue' => $invoiceId], 'customerId' => ['stringValue' => (string) ($booking['customerId'] ?? '')],
                'branchId' => ['stringValue' => (string) ($booking['branchId'] ?? '')], 'amount' => ['integerValue' => (string) (int) round($downpayment * 100)],
                'amountUnit' => ['stringValue' => 'centavos'], 'currency' => ['stringValue' => 'PHP'], 'method' => ['stringValue' => $method],
                'status' => ['stringValue' => 'paid'], 'referenceNumber' => ['stringValue' => $paymentId], 'paidAt' => ['timestampValue' => $now],
            ]];
            $writes[1]['fields']['downpaymentPaymentId'] = ['stringValue' => $paymentId];
        }
        adminWorkflowCommit($account, $writes);
        $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? ''));
        if (!empty($customer['email']) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            try { trySendConfiguredEmail($customer['email'], 'CoolTime service completed — invoice ' . $invoiceId,
                '<p>Your service is complete. Invoice total: ₱' . number_format($total, 2) . '.</p><p>Downpayment credited: ₱' . number_format($downpayment, 2) . '. Remaining balance: ₱' . number_format($balance, 2) . '.</p>',
                'Service completed. Total: PHP ' . number_format($total, 2) . '; downpayment credited: PHP ' . number_format($downpayment, 2) . '; balance: PHP ' . number_format($balance, 2) . '.'); }
            catch (Throwable $mailError) { error_log('Service completion email failed: ' . $mailError->getMessage()); }
        }
        jsonResponse(['ok' => true, 'invoiceId' => $invoiceId, 'runningTotal' => $total, 'downpaymentAmount' => $downpayment, 'balanceDue' => $balance]);
    }
    if ($action === 'submission_decision') {
        $collection = (string) ($input['collection'] ?? '');
        $ticket = trim((string) ($input['ticketNumber'] ?? ''));
        $decision = strtolower(trim((string) ($input['decision'] ?? '')));
        $message = trim((string) ($input['message'] ?? ''));
        if (!in_array($collection, ['feedback', 'inquiries'], true) || !preg_match('/^[A-Za-z0-9_-]{1,150}$/', $ticket)
            || !in_array($decision, ['approved', 'rejected', 'responded'], true) || $message === '') {
            jsonResponse(['ok' => false, 'message' => 'A valid ticket, decision, and response are required.'], 422);
        }
        $submission = adminWorkflowRead($account, $collection, $ticket);
        if (!$submission) jsonResponse(['ok' => false, 'message' => 'Submission was not found.'], 404);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        adminWorkflowCommit($account, [
            ['collection' => $collection, 'id' => $ticket, 'fields' => [
                'status' => ['stringValue' => $decision], 'adminResponse' => ['stringValue' => substr($message, 0, 5000)],
                'respondedBy' => ['stringValue' => $actor['uid']], 'respondedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'activity_logs', 'id' => 'submission_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'submission_' . $decision], 'ticketNumber' => ['stringValue' => $ticket],
                'adminId' => ['stringValue' => $actor['uid']], 'adminEmail' => ['stringValue' => $actor['email']], 'createdAt' => ['timestampValue' => $now],
            ]],
        ]);
        $emailSent = filter_var((string) ($submission['email'] ?? ''), FILTER_VALIDATE_EMAIL)
            ? trySendConfiguredEmail((string) $submission['email'], "CoolTime update for {$ticket}", nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')), $message)
            : false;
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Decision saved and email sent.' : 'Decision saved. Email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    jsonResponse(['ok' => false, 'message' => 'Unsupported admin action.'], 400);
} catch (Throwable $error) {
    error_log(sprintf(
        'Admin workflow action failed (action=%s, exception=%s): %s',
        preg_replace('/[^A-Za-z0-9_-]/', '', $action) ?: 'unknown',
        get_class($error),
        $error->getMessage()
    ));
    $permissionFailure = str_contains(strtolower($error->getMessage()), 'permission-denied')
        || str_contains(strtolower($error->getMessage()), 'permission denied')
        || str_contains(strtolower($error->getMessage()), 'http 403');
    jsonResponse(['ok' => false, 'message' => $permissionFailure
        ? 'The server account was denied access to Firestore. Check its project IAM permissions.'
        : 'The requested action could not be completed.'], 500);
}
