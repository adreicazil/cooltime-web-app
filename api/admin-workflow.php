<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-engine.php';
requirePost();

function adminWorkflowRead(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $response = secureHttpRequest($url, 'GET', ['Authorization: Bearer ' . firebaseAdminAccessToken()], null, 15);
    if ($response['status'] === 404) return null;
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $snippet = substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500);
        error_log(sprintf('Firestore document read failed (collection=%s, id=%s, HTTP=%d, cURL=%s, response=%s).',
            $collection, $id, $response['status'], $response['error'] ?: 'none', $snippet ?: '[empty]'));
        throw new RuntimeException('Firestore read failed (HTTP ' . $response['status'] . ').');
    }
    $document = json_decode($response['body'], true);
    if (!is_array($document)) {
        $snippet = substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500);
        error_log(sprintf('Firestore response was invalid (collection=%s, id=%s, HTTP=%d, cURL=%s, JSON=%s).',
            $collection, $id, $response['status'], $response['error'] ?: 'none', $snippet ?: '[empty]'));
        throw new RuntimeException('Firestore response was invalid (HTTP ' . $response['status'] . ').');
    }
    // Firestore allows a document with no fields. Treat that as an empty
    // record instead of turning an otherwise successful read into HTTP 500.
    if (!isset($document['fields'])) {
        if (isset($document['name'])) return [];
        $snippet = substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500);
        error_log(sprintf('Firestore response had no document fields or name (collection=%s, id=%s, HTTP=%d, JSON=%s).',
            $collection, $id, $response['status'], $snippet ?: '[empty]'));
        throw new RuntimeException('Firestore response was invalid (HTTP ' . $response['status'] . ').');
    }
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
        $response = secureHttpRequest($url, 'GET', ['Authorization: Bearer ' . firebaseAdminAccessToken()], null, 20);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $snippet = substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500);
            error_log(sprintf('Firestore collection read failed (collection=%s, HTTP=%d, cURL=%s, response=%s).',
                $collection, $response['status'], $response['error'] ?: 'none', $snippet ?: '[empty]'));
            throw new RuntimeException('Unable to read admin collection ' . $collection . ' (HTTP ' . $response['status'] . ').');
        }
        $result = json_decode($response['body'], true);
        if (!is_array($result)) {
            error_log(sprintf('Firestore collection response was invalid (collection=%s, HTTP=%d, response=%s).',
                $collection, $response['status'], substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500) ?: '[empty]'));
            throw new RuntimeException('Firestore returned invalid collection data.');
        }
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
    $response = secureHttpRequest($url, 'POST', ["Authorization: Bearer " . firebaseAdminAccessToken(), 'Content-Type: application/json'],
        json_encode(['writes' => $formatted], JSON_THROW_ON_ERROR), 15);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $snippet = substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500);
        error_log(sprintf('Firestore commit failed (HTTP=%d, cURL=%s, response=%s).',
            $response['status'], $response['error'] ?: 'none', $snippet ?: '[empty]'));
        throw new RuntimeException('Firestore update failed (HTTP ' . $response['status'] . ').');
    }
}

function adminWorkflowAuditWrite(array $actor, array $profile, string $action, string $referenceId, string $details, string $createdAt): array
{
    $actorName = trim((string) ($profile['name'] ?? $profile['username'] ?? $actor['email'] ?? 'Administrator'));
    return ['collection' => 'audit_logs', 'id' => 'audit_' . bin2hex(random_bytes(12)), 'fields' => [
        'actorEmail' => ['stringValue' => (string) ($actor['email'] ?? '')],
        'actorName' => ['stringValue' => $actorName],
        'actorRole' => ['stringValue' => normalizeAccountRole($profile['role'] ?? '')],
        'action' => ['stringValue' => $action],
        'referenceId' => ['stringValue' => substr($referenceId, 0, 200)],
        'details' => ['stringValue' => substr($details, 0, 4000)],
        'createdAt' => ['timestampValue' => $createdAt],
    ]];
}

function adminWorkflowPosTarget(array $account, array $input): array
{
    $collection = (string) ($input['transactionCollection'] ?? 'invoices');
    if (!in_array($collection, ['invoices', 'payments', 'pos_transactions'], true)) jsonResponse(['ok' => false, 'message' => 'Invalid POS transaction source.'], 422);
    $id = trim((string) ($input['transactionId'] ?? $input['invoiceId'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $id)) jsonResponse(['ok' => false, 'message' => 'A valid POS transaction is required.'], 422);
    $record = adminWorkflowRead($account, $collection, $id);
    $reference = (string) ($record['referenceNumber'] ?? $record['invoiceNumber'] ?? $record['referenceId'] ?? '');
    $source = strtolower((string) ($record['source'] ?? ''));
    if (!$record || ($collection !== 'pos_transactions' && !in_array($source, ['pos', 'walk_in_pos'], true)
        && !preg_match('/^(?:OTC|PMO)-/', $reference))) {
        jsonResponse(['ok' => false, 'message' => 'POS transaction was not found.'], 404);
    }
    $invoiceId = $collection === 'invoices' ? $id : (string) ($record['invoiceId'] ?? '');
    $invoice = $invoiceId !== '' && $collection !== 'invoices' ? adminWorkflowRead($account, 'invoices', $invoiceId) : ($collection === 'invoices' ? $record : null);
    return [$collection, $id, $record, $invoice, $invoiceId, $reference ?: $id];
}

function adminWorkflowDelete(array $account, string $collection, string $id): void
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:commit';
    $path = 'projects/' . $account['project_id'] . '/databases/(default)/documents/' . $collection . '/' . $id;
    $response = secureHttpRequest($url, 'POST', ["Authorization: Bearer " . firebaseAdminAccessToken(), 'Content-Type: application/json'],
        json_encode(['writes' => [['delete' => $path]]], JSON_THROW_ON_ERROR), 15);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $snippet = substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500);
        error_log(sprintf('Firestore delete failed (HTTP=%d, cURL=%s, response=%s).',
            $response['status'], $response['error'] ?: 'none', $snippet ?: '[empty]'));
        throw new RuntimeException('Firestore delete failed (HTTP ' . $response['status'] . ').');
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

function adminWorkflowPaymongoStatus(string $sessionId): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $sessionId)) return 'expired';
    $secret = environment('PAYMONGO_SECRET_KEY', false);
    if ($secret === '' || !str_starts_with($secret, 'sk_')) throw new RuntimeException('PayMongo secret key is not configured.');
    $response = secureHttpRequest('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId), 'GET',
        ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json'], null, 20);
    if ($response['status'] === 404) return 'expired';
    if ($response['status'] < 200 || $response['status'] >= 300) {
        error_log(sprintf('PayMongo checkout verification failed (session=%s, HTTP=%d, cURL=%s, response=%s).',
            $sessionId, $response['status'], $response['error'] ?: 'none', substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500)));
        throw new RuntimeException('PayMongo could not verify the checkout session.');
    }
    $session = json_decode($response['body'], true)['data']['attributes'] ?? [];
    foreach (($session['payments'] ?? []) as $payment) {
        if (strtolower((string) ($payment['attributes']['status'] ?? '')) === 'paid') return 'paid';
    }
    return in_array(strtolower((string) ($session['status'] ?? '')), ['expired', 'closed', 'cancelled', 'canceled'], true) ? 'expired' : 'unpaid';
}

function adminWorkflowSettlePaymongoInvoice(array $account, array $booking, array $invoice, string $bookingId, string $invoiceId, string $sessionId): void
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $fields = [
        'status' => ['stringValue' => 'paid'], 'paymentStatus' => ['stringValue' => 'paid'],
        'balanceDue' => ['doubleValue' => 0], 'balance_due' => ['doubleValue' => 0], 'latestPaymentSessionId' => ['nullValue' => null],
        'paidAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
    ];
    $writes = [
        ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => $fields],
        ['collection' => 'payments', 'id' => 'paymongo_' . $sessionId, 'fields' => ['status' => ['stringValue' => 'paid'], 'paidAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now]]],
        ['collection' => 'bookings', 'id' => $bookingId, 'fields' => ['paymentStatus' => ['stringValue' => 'paid'], 'updatedAt' => ['timestampValue' => $now]]],
    ];
    adminWorkflowCommit($account, $writes);
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
    if ($action === 'verify_booking_checkout') {
        $bookingId = trim((string) ($input['bookingId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)) jsonResponse(['ok' => false, 'message' => 'A valid booking is required.'], 422);
        $booking = adminWorkflowRead($account, 'bookings', $bookingId);
        if (!$booking) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
        $invoiceId = 'web_' . $bookingId;
        $invoice = adminWorkflowRead($account, 'invoices', $invoiceId);
        if (!$invoice) jsonResponse(['ok' => true, 'checkoutStatus' => 'none']);
        if (strtolower((string) ($invoice['status'] ?? '')) === 'paid') jsonResponse(['ok' => true, 'checkoutStatus' => 'paid']);
        $sessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
        if ($sessionId === '') jsonResponse(['ok' => true, 'checkoutStatus' => 'none']);
        $status = adminWorkflowPaymongoStatus($sessionId);
        if ($status === 'paid') {
            adminWorkflowSettlePaymongoInvoice($account, $booking, $invoice, $bookingId, $invoiceId, $sessionId);
        }
        jsonResponse(['ok' => true, 'checkoutStatus' => $status]);
    }
    if ($action === 'verify_session') {
        jsonResponse(['ok' => true, 'role' => normalizeAccountRole($actorProfile['role'] ?? ''), 'profile' => [
            'name' => (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? ''),
            'email' => (string) $actor['email'],
        ]]);
    }
    if ($action === 'load_data') {
        $collections = ['bookings', 'users', 'services', 'invoices', 'payments', 'pos_transactions', 'feedback', 'inquiries', 'vehicles', 'branches', 'reports', 'system_settings', 'service_history', 'schedule_slots', 'staff', 'branch', 'notifications', 'activity_logs', 'paymongo_intents'];
        $data = [];
        foreach ($collections as $collection) $data[$collection] = adminWorkflowList($account, $collection);
        jsonResponse(['ok' => true, 'data' => $data, 'actor' => ['uid' => $actor['uid'], 'email' => $actor['email']]]);
    }
    if ($action === 'record_pos_transaction') {
        [$collection, $id, $record, $invoice, $invoiceId, $reference] = adminWorkflowPosTarget($account, $input);
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'admin' && normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') {
            jsonResponse(['ok' => false, 'message' => 'Administrator access is required.'], 403);
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $amount = (float) ($record['totalAmount'] ?? $record['finalAmount'] ?? $record['runningTotal'] ?? 0);
        adminWorkflowCommit($account, [adminWorkflowAuditWrite($actor, $actorProfile, 'Walk-in POS Recorded', $reference,
            'Walk-in POS transaction recorded; amount PHP ' . number_format($amount, 2) . '.', $now)]);
        jsonResponse(['ok' => true]);
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
        $statusMessage = $status === 'active'
            ? 'Your CoolTime customer account has been activated.'
            : 'Your CoolTime customer account has been disabled. Contact support if you need help accessing your account.';
        notifyCustomerRecipient(
            $account,
            'account_status_' . $status . '_' . bin2hex(random_bytes(6)),
            $customerId,
            (string) ($customer['email'] ?? ''),
            'account_status_updated',
            $statusMessage,
            ['customerId' => $customerId, 'customerName' => (string) ($customer['name'] ?? $customer['username'] ?? 'Customer')],
            'CoolTime account status updated'
        );
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
    if ($action === 'request_void_pos_transaction') {
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'admin') jsonResponse(['ok' => false, 'message' => 'Regular administrator access is required to request a void.'], 403);
        [$collection, $id, $record, $invoice, $invoiceId, $reference] = adminWorkflowPosTarget($account, $input);
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 1000) jsonResponse(['ok' => false, 'message' => 'A void reason is required (maximum 1000 characters).'], 422);
        if (strtolower((string) ($record['status'] ?? '')) !== 'paid') jsonResponse(['ok' => false, 'message' => 'Only paid transactions can be submitted for void approval.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $actorName = (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? $actor['email']);
        $fields = [
            'status' => ['stringValue' => 'void_requested'], 'voidRequestStatus' => ['stringValue' => 'pending'],
            'voidRequestedBy' => ['stringValue' => $actor['uid']], 'voidRequestedByName' => ['stringValue' => $actorName],
            'voidRequestedByEmail' => ['stringValue' => $actor['email']], 'voidRequestReason' => ['stringValue' => substr($reason, 0, 1000)],
            'voidRequestedAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
        ];
        $writes = [['collection' => $collection, 'id' => $id, 'fields' => $fields]];
        if ($invoice && $collection !== 'invoices') $writes[] = ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => $fields];
        $writes[] = adminWorkflowAuditWrite($actor, $actorProfile, 'POS Void Requested', $reference, $reason, $now);
        adminWorkflowCommit($account, $writes);
        jsonResponse(['ok' => true, 'message' => 'Void request sent to a Super Admin.']);
    }
    if ($action === 'approve_void_pos_transaction' || $action === 'reject_void_pos_transaction') {
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') jsonResponse(['ok' => false, 'message' => 'Super administrator access is required.'], 403);
        [$collection, $id, $record, $invoice, $invoiceId, $reference] = adminWorkflowPosTarget($account, $input);
        if (strtolower((string) ($record['status'] ?? '')) !== 'void_requested') jsonResponse(['ok' => false, 'message' => 'This transaction has no pending void request.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $actorName = (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? $actor['email']);
        $approved = $action === 'approve_void_pos_transaction';
        $fields = $approved ? [
            'status' => ['stringValue' => 'voided'], 'voidRequestStatus' => ['stringValue' => 'approved'],
            'voidedAt' => ['timestampValue' => $now], 'voidedBy' => ['stringValue' => $actor['uid']],
            'voidedByEmail' => ['stringValue' => $actor['email']], 'updatedAt' => ['timestampValue' => $now],
        ] : [
            'status' => ['stringValue' => 'paid'], 'voidRequestStatus' => ['stringValue' => 'rejected'],
            'voidRejectedAt' => ['timestampValue' => $now], 'voidRejectedBy' => ['stringValue' => $actor['uid']],
            'voidRejectedByName' => ['stringValue' => $actorName], 'updatedAt' => ['timestampValue' => $now],
        ];
        $writes = [['collection' => $collection, 'id' => $id, 'fields' => $fields]];
        if ($invoice && $collection !== 'invoices') $writes[] = ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => $fields];
        if ($approved) {
            $bookingId = (string) ($invoice['bookingId'] ?? $record['bookingId'] ?? '');
            if ($bookingId !== '' && adminWorkflowRead($account, 'bookings', $bookingId)) {
                $writes[] = ['collection' => 'bookings', 'id' => $bookingId, 'fields' => ['status' => ['stringValue' => 'voided'], 'updatedAt' => ['timestampValue' => $now]]];
            }
        }
        $auditAction = $approved ? 'POS Void Approved' : 'POS Void Rejected';
        $writes[] = adminWorkflowAuditWrite($actor, $actorProfile, $auditAction, $reference, (string) ($record['voidRequestReason'] ?? ''), $now);
        adminWorkflowCommit($account, $writes);
        jsonResponse(['ok' => true, 'message' => $approved ? 'POS void approved.' : 'POS void request rejected.']);
    }
    if ($action === 'flag_pos_transaction') {
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') jsonResponse(['ok' => false, 'message' => 'Super administrator access is required.'], 403);
        [$collection, $id, $record, $invoice, $invoiceId, $reference] = adminWorkflowPosTarget($account, $input);
        $isFlagged = filter_var($input['isFlagged'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 1000) jsonResponse(['ok' => false, 'message' => 'A flag reason is required (maximum 1000 characters).'], 422);
        if ((bool) ($record['isFlagged'] ?? false) === $isFlagged) jsonResponse(['ok' => false, 'message' => 'The POS transaction flag has already changed. Refresh and try again.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $actorName = (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? $actor['email']);
        $fields = ['isFlagged' => ['booleanValue' => $isFlagged], 'flagReason' => ['stringValue' => $isFlagged ? substr($reason, 0, 1000) : ''],
            'flaggedBy' => ['stringValue' => $isFlagged ? $actor['uid'] : ''], 'flaggedByName' => ['stringValue' => $isFlagged ? $actorName : ''],
            'flaggedAt' => ['timestampValue' => $now], 'lastAuditAt' => ['timestampValue' => $now],
            'lastAuditBy' => ['stringValue' => $actor['uid']], 'lastAuditByName' => ['stringValue' => $actorName], 'updatedAt' => ['timestampValue' => $now]];
        $writes = [['collection' => $collection, 'id' => $id, 'fields' => $fields]];
        if ($invoice && $collection !== 'invoices') $writes[] = ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => $fields];
        $writes[] = adminWorkflowAuditWrite($actor, $actorProfile, $isFlagged ? 'POS Transaction Flagged' : 'POS Transaction Unflagged', $reference, $reason, $now);
        adminWorkflowCommit($account, $writes);
        jsonResponse(['ok' => true, 'isFlagged' => $isFlagged, 'message' => $isFlagged ? 'POS transaction flagged.' : 'POS transaction flag removed.']);
    }
    if ($action === 'void_pos_transaction') {
        if (normalizeAccountRole($actorProfile['role'] ?? '') !== 'super_admin') jsonResponse(['ok' => false, 'message' => 'Super administrator access is required.'], 403);
        [$collection, $id, $record, $invoice, $invoiceId, $reference] = adminWorkflowPosTarget($account, $input);
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 1000) jsonResponse(['ok' => false, 'message' => 'A void reason is required (maximum 1000 characters).'], 422);
        if (strtolower((string) ($record['status'] ?? '')) !== 'paid') jsonResponse(['ok' => false, 'message' => 'Only paid POS transactions can be voided.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $actorName = (string) ($actorProfile['name'] ?? $actorProfile['username'] ?? $actor['email']);
        $fields = ['status' => ['stringValue' => 'voided'], 'voidedAt' => ['timestampValue' => $now],
            'voidedBy' => ['stringValue' => $actor['uid']], 'voidedByEmail' => ['stringValue' => $actor['email']],
            'voidReason' => ['stringValue' => substr($reason, 0, 1000)], 'lastAuditAt' => ['timestampValue' => $now],
            'lastAuditBy' => ['stringValue' => $actor['uid']], 'lastAuditByName' => ['stringValue' => $actorName], 'updatedAt' => ['timestampValue' => $now]];
        $writes = [['collection' => $collection, 'id' => $id, 'fields' => $fields]];
        $linkedInvoice = $invoice;
        if ($invoiceId !== '' && $collection !== 'invoices') {
            $linkedInvoice ??= adminWorkflowRead($account, 'invoices', $invoiceId);
            if ($linkedInvoice) $writes[] = ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => $fields];
        }
        $bookingId = (string) ($linkedInvoice['bookingId'] ?? $record['bookingId'] ?? '');
        if ($bookingId !== '' && adminWorkflowRead($account, 'bookings', $bookingId)) {
            $writes[] = ['collection' => 'bookings', 'id' => $bookingId, 'fields' => ['status' => ['stringValue' => 'voided'], 'updatedAt' => ['timestampValue' => $now]]];
        }
        $writes[] = adminWorkflowAuditWrite($actor, $actorProfile, 'POS Transaction Voided', $reference, $reason, $now);
        adminWorkflowCommit($account, $writes);
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
        $writes[] = adminWorkflowAuditWrite($actor, $actorProfile, 'Booking Cancelled', (string) ($booking['referenceNumber'] ?? $bookingId), $reason, $now);
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
            adminWorkflowAuditWrite($actor, $actorProfile, 'Booking Confirmed', (string) ($booking['referenceNumber'] ?? $bookingId), 'Booking approved and confirmed.', $now),
        ]);
        $booking['id'] = $bookingId;
        $booking['status'] = 'confirmed';
        try {
            $details = notificationBookingDetails($account, $booking);
        } catch (Throwable $error) {
            error_log('Booking approval saved, but extended notification details could not be loaded: ' . $error->getMessage());
            $details = [
                'bookingId' => $bookingId, 'customerId' => (string) ($booking['customerId'] ?? ''),
                'customerName' => (string) ($booking['customerName'] ?? 'Customer'),
                'customerEmail' => (string) ($booking['customerEmail'] ?? ''),
                'serviceName' => (string) ($booking['serviceName'] ?? $booking['serviceId'] ?? 'Service'),
                'branchName' => normalizeBranchLabel((string) ($booking['branchName'] ?? $booking['branch'] ?? $booking['branchId'] ?? ''), 'Branch'),
                'scheduledDate' => (string) ($booking['date'] ?? $booking['appointmentDate'] ?? ''),
                'scheduledTime' => (string) ($booking['timeSlot'] ?? $booking['preferredTimeSlot'] ?? ''),
            ];
        }
        $details['bookingStatus'] = 'confirmed';
        $details['message'] = 'Booking confirmed: your service appointment has been approved.';
        try {
            $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? '')) ?? [];
        } catch (Throwable $error) {
            error_log('Booking approval saved, but the customer profile could not be reloaded for email: ' . $error->getMessage());
            $customer = [];
        }
        $customerEmail = (string) ($customer['email'] ?? $booking['customerEmail'] ?? '');
        $emailSent = notifyCustomerRecipient($account, 'booking_confirmed_' . $bookingId, (string) ($booking['customerId'] ?? ''), $customerEmail, 'booking_confirmed', $details['message'], $details, 'CoolTime booking confirmed');
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Booking approved and confirmation email sent.' : 'Booking approved. Email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    if ($action === 'reschedule_booking') {
        $bookingId = trim((string) ($input['bookingId'] ?? ''));
        $requestedBranch = normalizeBranchId((string) ($input['branchId'] ?? $input['branch'] ?? ''));
        $date = trim((string) ($input['date'] ?? ''));
        $timeSlot = strtoupper(trim((string) ($input['timeSlot'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)
            || !in_array($requestedBranch, ['cabuyao', 'santa_rosa'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !preg_match('/^\d{1,2}:\d{2}\s?(AM|PM)\s*-\s*\d{1,2}:\d{2}\s?(AM|PM)$/', $timeSlot)) {
            jsonResponse(['ok' => false, 'message' => 'Booking and a valid new date and time are required.'], 422);
        }
        $timezone = new DateTimeZone('Asia/Manila');
        $timeParts = preg_split('/\s*-\s*/', $timeSlot) ?: [];
        $startTime = preg_replace('/^0(?=\d:)/', '', trim((string) ($timeParts[0] ?? ''))) ?: trim((string) ($timeParts[0] ?? ''));
        $endTime = preg_replace('/^0(?=\d:)/', '', trim((string) ($timeParts[1] ?? ''))) ?: trim((string) ($timeParts[1] ?? ''));
        $slotDateTime = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $date . ' ' . $startTime, $timezone);
        $slotEndDateTime = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $date . ' ' . $endTime, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$slotDateTime || !$slotEndDateTime || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $slotDateTime->format('Y-m-d g:i A') !== $date . ' ' . $startTime
            || $slotEndDateTime->format('Y-m-d g:i A') !== $date . ' ' . $endTime
            || $slotEndDateTime <= $slotDateTime
            || $slotDateTime <= new DateTimeImmutable('now', $timezone)) {
            jsonResponse(['ok' => false, 'message' => 'The new appointment date and time must be valid and in the future.'], 422);
        }
        $booking = adminWorkflowRead($account, 'bookings', $bookingId);
        if (!$booking) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
        if (strtolower((string) ($booking['status'] ?? '')) !== 'confirmed') {
            jsonResponse(['ok' => false, 'message' => 'Only confirmed bookings can update their appointment slot.'], 409);
        }
        $oldBranchId = (string) ($booking['branchId'] ?? $booking['branch'] ?? '');
        $branchId = $requestedBranch;
        $branchName = normalizeBranchLabel((string) ($input['branch'] ?? $requestedBranch));
        $branchAliases = match ($branchId) {
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
            $existingBranch = normalizeBranchId((string) ($existing['branchId'] ?? $existing['branch'] ?? ''));
            $sameBranch = $branchId === 'santa_rosa'
                ? $existingBranch === 'santa_rosa'
                : $existingBranch === 'cabuyao';
            if (!$sameBranch
                || (string) ($existing['date'] ?? '') !== $date
                || strcasecmp((string) ($existing['timeSlot'] ?? ''), $timeSlot) !== 0) continue;
            if (!in_array(strtolower((string) ($existing['status'] ?? '')), ['cancelled', 'canceled', 'completed', 'expired', 'archived', 'payment overdue'], true)) $occupied++;
        }
        if ($occupied >= (int) $branch['capacity']) jsonResponse(['ok' => false, 'message' => 'That branch and time slot is full. Choose another appointment slot.'], 409);

        $oldDate = (string) ($booking['date'] ?? $booking['appointmentDate'] ?? '');
        $oldTime = (string) ($booking['timeSlot'] ?? $booking['preferredTimeSlot'] ?? '');
        $oldBranchLabel = normalizeBranchLabel($oldBranchId, 'Branch');
        $branchChanged = normalizeBranchId($oldBranchId) !== $branchId;
        $dateChanged = $oldDate !== $date;
        $reference = (string) ($booking['referenceNumber'] ?? $booking['referenceId'] ?? '');
        if ($branchChanged || $dateChanged) {
            preg_match('/^(PMO|OTC)-/i', $reference, $paymentSourceMatch);
            $paymentMethod = strtolower((string) ($booking['paymentMethod'] ?? ''));
            $bookingSource = strtolower((string) ($booking['source'] ?? $booking['bookingType'] ?? ''));
            $fallbackSource = (!empty($booking['walkIn']) || str_contains($bookingSource, 'walk') || $paymentMethod === 'cash') ? 'OTC' : 'PMO';
            $paymentSource = strtoupper((string) ($paymentSourceMatch[1] ?? $fallbackSource));
            $reference = cooltimeReferenceNumber($paymentSource, $branchName, $date);
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $bookingFields = [
            'branch' => ['stringValue' => $branchName], 'branchId' => ['stringValue' => $branchId],
            'date' => ['stringValue' => $date], 'appointmentDate' => ['stringValue' => $date],
            'timeSlot' => ['stringValue' => $timeSlot], 'updatedAt' => ['timestampValue' => $now],
            'rescheduledAt' => ['timestampValue' => $now], 'rescheduledBy' => ['stringValue' => $actor['uid']],
        ];
        if ($reference !== '') {
            $bookingFields['referenceNumber'] = ['stringValue' => $reference];
            $bookingFields['referenceId'] = ['stringValue' => $reference];
        }
        adminWorkflowCommit($account, [
            ['collection' => 'bookings', 'id' => $bookingId, 'fields' => $bookingFields],
            ['collection' => 'activity_logs', 'id' => 'rescheduled_' . bin2hex(random_bytes(8)), 'fields' => [
                'type' => ['stringValue' => 'booking_rescheduled'], 'bookingId' => ['stringValue' => $bookingId],
                'previousBranch' => ['stringValue' => $oldBranchLabel], 'previousDate' => ['stringValue' => $oldDate], 'previousTime' => ['stringValue' => $oldTime],
                'adminId' => ['stringValue' => $actor['uid']], 'createdAt' => ['timestampValue' => $now],
            ]],
            adminWorkflowAuditWrite($actor, $actorProfile, 'Booking Slot Updated', $reference ?: $bookingId,
                "Appointment changed from {$oldBranchLabel} {$oldDate} {$oldTime} to {$branchName} {$date} {$timeSlot}.", $now),
        ]);
        $booking['id'] = $bookingId;
        $booking['branchId'] = $branchId;
        $booking['branch'] = $branchName;
        $booking['date'] = $date;
        $booking['appointmentDate'] = $date;
        $booking['timeSlot'] = $timeSlot;
        $booking['referenceNumber'] = $reference;
        $booking['referenceId'] = $reference;
        $details = notificationBookingDetails($account, $booking);
        $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? '')) ?? [];
        $emailSent = notifyRescheduleSuccess($account, (string) ($booking['customerId'] ?? ''), (string) ($customer['email'] ?? ''), $details);
        jsonResponse(['ok' => true, 'message' => $emailSent ? 'Booking slot updated and customer notified by email.' : 'Booking slot updated. In-app notification was saved; email delivery was skipped or unavailable.', 'emailSent' => $emailSent]);
    }
    if ($action === 'reject_reschedule_request') {
        $notificationId = trim((string) ($input['notificationId'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        if (!preg_match('/^admin_reschedule_request_[A-Za-z0-9_-]{1,150}$/', $notificationId) || $reason === '') {
            jsonResponse(['ok' => false, 'message' => 'A reschedule request and rejection reason are required.'], 422);
        }
        $request = adminWorkflowRead($account, 'notifications', $notificationId);
        if (!$request || strtolower((string) ($request['type'] ?? '')) !== 'reschedule_request') jsonResponse(['ok' => false, 'message' => 'Reschedule request was not found.'], 404);
        if (strtolower((string) ($request['decision'] ?? '')) !== '') jsonResponse(['ok' => false, 'message' => 'This request has already been handled.'], 409);
        $bookingId = (string) ($request['bookingId'] ?? '');
        $booking = $bookingId !== '' ? adminWorkflowRead($account, 'bookings', $bookingId) : null;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $writes = [[ 'collection' => 'notifications', 'id' => $notificationId, 'fields' => [
            'decision' => ['stringValue' => 'rejected'], 'decisionReason' => ['stringValue' => substr($reason, 0, 1000)],
            'respondedBy' => ['stringValue' => $actor['uid']], 'respondedByEmail' => ['stringValue' => $actor['email']],
            'respondedAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
        ]]];
        $reference = (string) (($booking['referenceNumber'] ?? '') ?: ($bookingId ?: $notificationId));
        $writes[] = adminWorkflowAuditWrite($actor, $actorProfile, 'Reschedule Rejected', $reference, $reason, $now);
        adminWorkflowCommit($account, $writes);
        jsonResponse(['ok' => true, 'message' => 'Reschedule request rejected and recorded.']);
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
        if (strtolower((string) ($booking['status'] ?? '')) !== 'confirmed') {
            jsonResponse(['ok' => false, 'message' => 'Only confirmed bookings can be completed.'], 409);
        }
        $scheduledDate = (string) ($booking['date'] ?? $booking['appointmentDate'] ?? '');
        $scheduledSlot = (string) ($booking['timeSlot'] ?? $booking['preferredTimeSlot'] ?? '');
        $scheduledEnd = trim((string) (preg_split('/\s*-\s*/', $scheduledSlot)[1] ?? $scheduledSlot));
        $scheduledEnd = preg_replace('/^0(?=\d:)/', '', $scheduledEnd) ?: $scheduledEnd;
        $scheduledEndAt = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $scheduledDate . ' ' . $scheduledEnd, new DateTimeZone('Asia/Manila'));
        if ($scheduledEndAt && $scheduledEndAt > new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))
            && ($input['earlyCompletionConfirmed'] ?? false) !== true) {
            jsonResponse(['ok' => false, 'message' => 'This appointment schedule has not passed yet. Confirm early service completion?'], 409);
        }
        $invoiceId = 'web_' . $bookingId;
        $invoice = adminWorkflowRead($account, 'invoices', $invoiceId);
        if (!$invoice) jsonResponse(['ok' => false, 'message' => 'The booking has no invoice to complete.'], 404);
        $sessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
        $checkoutPaid = strtolower((string) ($invoice['status'] ?? '')) === 'paid';
        $cashSettlementAmount = 0.0;
        if (!$checkoutPaid && $sessionId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $sessionId)) {
            $checkoutPayment = adminWorkflowRead($account, 'payments', 'paymongo_' . $sessionId);
            if (in_array(strtolower((string) ($checkoutPayment['status'] ?? '')), ['pending', 'paid'], true)) {
                $liveCheckoutStatus = adminWorkflowPaymongoStatus($sessionId);
                if ($liveCheckoutStatus === 'paid') {
                    adminWorkflowSettlePaymongoInvoice($account, $booking, $invoice, $bookingId, $invoiceId, $sessionId);
                    $checkoutPaid = true;
                    $invoice['status'] = 'paid';
                } elseif (($input['cashSettlePendingCheckout'] ?? false) === true) {
                    $secret = environment('PAYMONGO_SECRET_KEY', false);
                    $expire = httpJsonRequest('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId) . '/expire',
                        ['data' => ['attributes' => (object) []]], ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json']);
                    $afterExpireStatus = ($expire['status'] >= 200 && $expire['status'] < 300) ? 'expired' : adminWorkflowPaymongoStatus($sessionId);
                    if (!in_array($afterExpireStatus, ['paid', 'expired'], true)) {
                        jsonResponse(['ok' => false, 'message' => 'PayMongo could not safely close the unpaid checkout. Please retry shortly.'], 409);
                    }
                    if ($afterExpireStatus === 'paid' || adminWorkflowPaymongoStatus($sessionId) === 'paid') {
                        adminWorkflowSettlePaymongoInvoice($account, $booking, $invoice, $bookingId, $invoiceId, $sessionId);
                        $checkoutPaid = true;
                        $invoice['status'] = 'paid';
                    } else {
                        $now = gmdate('Y-m-d\TH:i:s\Z');
                        adminWorkflowCommit($account, [
                            ['collection' => 'payments', 'id' => 'paymongo_' . $sessionId, 'fields' => ['status' => ['stringValue' => 'expired'], 'expiredAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now]]],
                            ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => ['latestPaymentSessionId' => ['nullValue' => null], 'updatedAt' => ['timestampValue' => $now]]],
                        ]);
                        $invoice['latestPaymentSessionId'] = '';
                    }
                } else {
                    jsonResponse(['ok' => false, 'message' => 'This checkout is still unpaid. Verify the session and choose a counter/cash settlement to continue.'], 409);
                }
            }
        }
        $total = (float) ($invoice['runningTotal'] ?? $invoice['totalAmount'] ?? $invoice['finalAmount'] ?? 0);
        if (($input['cashSettlePendingCheckout'] ?? false) === true && !$checkoutPaid) {
            $cashSettlementAmount = max(0, $total - (float) ($invoice['downpaymentAmount'] ?? 0));
            $downpayment = 0;
            $method = 'cash';
        }
        if ($checkoutPaid) {
            $downpayment = 0;
            $cashSettlementAmount = 0;
        }
        if ($downpayment > $total) jsonResponse(['ok' => false, 'message' => 'Downpayment cannot exceed the invoice total.'], 422);
        if ($downpayment > 0 && strtolower((string) ($invoice['status'] ?? '')) === 'paid') jsonResponse(['ok' => false, 'message' => 'This invoice is already paid; do not record another downpayment.'], 409);
        if ($downpayment > 0 && (float) ($invoice['downpaymentAmount'] ?? 0) > 0) jsonResponse(['ok' => false, 'message' => 'A downpayment is already recorded for this invoice.'], 409);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $invoiceDownpayment = ($checkoutPaid || $cashSettlementAmount > 0) ? (float) ($invoice['downpaymentAmount'] ?? 0) : $downpayment;
        $balance = ($checkoutPaid || $cashSettlementAmount > 0) ? 0 : max(0, $total - $downpayment);
        $bookingBranchId = normalizeBranchId((string) ($booking['branchId'] ?? $booking['branch'] ?? ''));
        $bookingBranchLabel = normalizeBranchLabel((string) ($booking['branch'] ?? $booking['branchName'] ?? $bookingBranchId), 'Branch');
        $historyId = 'booking_' . $bookingId;
        $writes = [
            ['collection' => 'bookings', 'id' => $bookingId, 'fields' => [
                'status' => ['stringValue' => 'completed'], 'serviceCompletedAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
                'paymentStatus' => ['stringValue' => ($checkoutPaid || $cashSettlementAmount > 0 || $balance <= 0) ? 'paid' : 'pending'],
                'branchId' => ['stringValue' => $bookingBranchId], 'branch' => ['stringValue' => $bookingBranchLabel],
            ]],
            ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => [
                'serviceStatus' => ['stringValue' => 'completed'], 'downpaymentAmount' => ['doubleValue' => $invoiceDownpayment],
                'serviceCompletedAt' => ['timestampValue' => $now], 'status' => ['stringValue' => $balance <= 0 ? 'paid' : (string) ($invoice['status'] ?? 'pending')],
                'paymentStatus' => ['stringValue' => $balance <= 0 ? 'paid' : 'pending'], 'balanceDue' => ['doubleValue' => $balance], 'balance_due' => ['doubleValue' => $balance],
                'branchId' => ['stringValue' => $bookingBranchId], 'branch' => ['stringValue' => $bookingBranchLabel],
                'updatedAt' => ['timestampValue' => $now],
            ]],
            ['collection' => 'service_history', 'id' => $historyId, 'fields' => [
                'bookingId' => ['stringValue' => $bookingId], 'customerId' => ['stringValue' => (string) ($booking['customerId'] ?? '')],
                'branchId' => ['stringValue' => $bookingBranchId], 'branch' => ['stringValue' => $bookingBranchLabel], 'serviceId' => ['stringValue' => (string) ($booking['serviceId'] ?? '')],
                'totalAmount' => ['doubleValue' => $total], 'downpaymentAmount' => ['doubleValue' => $invoiceDownpayment],
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
                'branchId' => ['stringValue' => $bookingBranchId], 'branch' => ['stringValue' => $bookingBranchLabel], 'amount' => ['integerValue' => (string) (int) round($downpayment * 100)],
                'amountUnit' => ['stringValue' => 'centavos'], 'currency' => ['stringValue' => 'PHP'], 'method' => ['stringValue' => $method],
                'status' => ['stringValue' => 'paid'], 'referenceNumber' => ['stringValue' => (string)($invoice['referenceNumber'] ?? $invoice['invoiceNumber'] ?? $invoiceId)],
                'providerReference' => ['stringValue' => $paymentId], 'paidAt' => ['timestampValue' => $now],
            ]];
            $writes[1]['fields']['downpaymentPaymentId'] = ['stringValue' => $paymentId];
        }
        if ($cashSettlementAmount > 0) {
            $paymentId = 'counter_cash_' . $bookingId;
            $writes[] = ['collection' => 'payments', 'id' => $paymentId, 'fields' => [
                'invoiceId' => ['stringValue' => $invoiceId], 'bookingId' => ['stringValue' => $bookingId],
                'customerId' => ['stringValue' => (string) ($booking['customerId'] ?? '')], 'branchId' => ['stringValue' => $bookingBranchId],
                'branch' => ['stringValue' => $bookingBranchLabel], 'amount' => ['integerValue' => (string) (int) round($cashSettlementAmount * 100)],
                'amountUnit' => ['stringValue' => 'centavos'], 'currency' => ['stringValue' => 'PHP'], 'method' => ['stringValue' => 'cash'],
                'status' => ['stringValue' => 'paid'], 'referenceNumber' => ['stringValue' => (string) ($invoice['referenceNumber'] ?? $invoice['invoiceNumber'] ?? $invoiceId)],
                'providerReference' => ['stringValue' => $paymentId], 'paidAt' => ['timestampValue' => $now],
            ]];
        }
        $settlementDetails = 'Service completed; downpayment PHP ' . number_format($invoiceDownpayment, 2)
            . ($cashSettlementAmount > 0 ? ', counter cash settlement PHP ' . number_format($cashSettlementAmount, 2) : '')
            . ', remaining balance PHP ' . number_format($balance, 2) . '.';
        $writes[] = adminWorkflowAuditWrite($actor, $actorProfile, 'Booking Service Completed', (string) ($booking['referenceNumber'] ?? $bookingId), $settlementDetails, $now);
        adminWorkflowCommit($account, $writes);
        $customer = adminWorkflowRead($account, 'users', (string) ($booking['customerId'] ?? ''));
        $booking['id'] = $bookingId;
        $booking['status'] = 'completed';
        $details = notificationBookingDetails($account, $booking);
        $details['bookingStatus'] = 'completed';
        $details['paymentBalance'] = $balance;
        $details['paymentStatus'] = $balance <= 0 ? 'paid' : (string) ($invoice['status'] ?? 'pending');
        $details['message'] = 'Service completed: your appointment is complete. Remaining balance: PHP ' . number_format($balance, 2) . '.';
        $customerEmail = (string) ($customer['email'] ?? $booking['customerEmail'] ?? '');
        notifyCustomerRecipient($account, 'service_completed_' . $bookingId, (string) ($booking['customerId'] ?? ''), $customerEmail, 'service_completed', $details['message'], $details, 'CoolTime service completed — invoice ' . $invoiceId);
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
            adminWorkflowAuditWrite($actor, $actorProfile, ucfirst($decision) . ' Customer Request', $ticket, $message, $now),
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
