<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
requirePost();

function firestoreReadDocument(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = $http_response_header[0] ?? '';
    if (str_contains($status, ' 404 ')) return null;
    if (!is_string($response)) throw new RuntimeException('Unable to read invoice.');
    $document = json_decode($response, true);
    if (!is_array($document) || !isset($document['fields'])) throw new RuntimeException('Unable to read invoice.');
    $values = [];
    foreach ($document['fields'] as $key => $value) {
        foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) {
            if (array_key_exists($type, $value)) { $values[$key] = $value[$type]; break; }
        }
    }
    return $values;
}

function firestoreCheckoutMark(array $account, string $invoiceId, array $fields): void
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:commit';
    $name = 'projects/' . $account['project_id'] . '/databases/(default)/documents/invoices/' . $invoiceId;
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json",
        'content' => json_encode(['writes' => [['update' => ['name' => $name, 'fields' => $fields], 'updateMask' => ['fieldPaths' => array_keys($fields)]]]], JSON_THROW_ON_ERROR),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) throw new RuntimeException('Unable to link checkout with invoice.');
}

function paymongoResolvePreviousCheckout(string $secret, string $sessionId): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $sessionId)) return 'unknown';
    $headers = ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json'];
    $request = static function (string $url, string $method = 'GET') use ($headers): array {
        $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => 20, 'ignore_errors' => true];
        if ($method === 'POST') {
            $options['header'] .= "\r\nContent-Type: application/json";
            $options['content'] = json_encode(['data' => ['attributes' => (object) []]], JSON_THROW_ON_ERROR);
        }
        $context = stream_context_create(['http' => $options]);
        $response = @file_get_contents($url, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $matches);
        return ['status' => (int) ($matches[1] ?? 0), 'body' => is_string($response) ? (json_decode($response, true) ?? []) : []];
    };
    $sessionUrl = 'https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId);
    $result = $request($sessionUrl);
    if ($result['status'] === 404) return 'expired';
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new RuntimeException('Unable to verify the previous PayMongo checkout. Please retry shortly.');
    }
    $attributes = $result['body']['data']['attributes'] ?? [];
    foreach (($attributes['payments'] ?? []) as $payment) {
        if (strtolower((string) ($payment['attributes']['status'] ?? '')) === 'paid') return 'paid';
    }
    $status = strtolower((string) ($attributes['status'] ?? ''));
    if (in_array($status, ['expired', 'closed', 'cancelled', 'canceled'], true)) return 'expired';
    if ($status !== 'active') return 'expired';

    $expire = $request($sessionUrl . '/expire', 'POST');
    if ($expire['status'] >= 200 && $expire['status'] < 300) return 'expired';
    // A concurrent successful payment takes precedence over an expire attempt.
    $verify = $request($sessionUrl);
    if ($verify['status'] >= 200 && $verify['status'] < 300) {
        $verifyAttributes = $verify['body']['data']['attributes'] ?? [];
        foreach (($verifyAttributes['payments'] ?? []) as $payment) {
            if (strtolower((string) ($payment['attributes']['status'] ?? '')) === 'paid') return 'paid';
        }
        if (in_array(strtolower((string) ($verifyAttributes['status'] ?? '')), ['expired', 'closed', 'cancelled', 'canceled'], true)) return 'expired';
    }
    throw new RuntimeException('The previous checkout is still active and could not be expired. Please retry shortly.');
}

try {
    $data = requestData();
    $invoiceId = trim((string) ($data['invoiceId'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $invoiceId)) jsonResponse(['ok' => false, 'message' => 'A valid invoice is required.'], 400);
    $user = firebaseUserFromToken(authorizationToken());
    requireOtpSession($user);
    $account = firebaseServiceAccount();
    $invoice = firestoreReadDocument($account, 'invoices', $invoiceId);
    $profile = firestoreReadDocument($account, 'users', $user['uid']);
    $isAdmin = in_array(strtolower((string) ($profile['role'] ?? '')), ['admin', 'super_admin'], true);
    if (!$invoice || (($invoice['customerId'] ?? '') !== $user['uid'] && !$isAdmin)) {
        jsonResponse(['ok' => false, 'message' => 'Invoice not found.'], 404);
    }
    if (strtolower((string) ($invoice['status'] ?? 'open')) === 'paid') jsonResponse(['ok' => false, 'message' => 'This invoice is already paid.'], 409);
    $secret = environment('PAYMONGO_SECRET_KEY', false);
    if ($secret === '' || (!str_starts_with($secret, 'sk_test_') && !str_starts_with($secret, 'sk_live_'))) {
        jsonResponse(['ok' => false, 'message' => 'Online payments are not configured on this server.'], 503);
    }
    $existingSession = (string) ($invoice['latestPaymentSessionId'] ?? '');
    if ($existingSession !== '' && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $existingSession)) {
        $previousStatus = paymongoResolvePreviousCheckout($secret, $existingSession);
        if ($previousStatus === 'paid') jsonResponse(['ok' => false, 'message' => 'This invoice has already been paid. Refresh your bills to see the updated status.'], 409);
        if ($previousStatus === 'expired') {
            firestorePatchDocument($account, 'payments', 'paymongo_' . $existingSession, [
                'status' => ['stringValue' => 'expired'],
                'expiredAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z')],
            ]);
        }
    }
    $amountPesos = (float) ($invoice['finalAmount'] ?? $invoice['runningTotal'] ?? 0);
    if (strtolower((string) ($invoice['serviceStatus'] ?? '')) === 'completed') {
        $amountPesos = max(0, $amountPesos - (float) ($invoice['downpaymentAmount'] ?? 0));
    }
    $amountCentavos = (int) round($amountPesos * 100);
    if ($amountCentavos < 100) jsonResponse(['ok' => false, 'message' => 'Invoice amount must be at least ₱1.00.'], 400);
    $returnUrl = environment('PAYMONGO_RETURN_URL', false);
    if (filter_var($returnUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($returnUrl, 'https://')) {
        jsonResponse(['ok' => false, 'message' => 'Online payment return URL is not configured.'], 503);
    }
    $returnParts = parse_url($returnUrl);
    $returnOrigin = ($returnParts['scheme'] ?? 'https') . '://' . ($returnParts['host'] ?? '');
    if (!empty($returnParts['port'])) $returnOrigin .= ':' . $returnParts['port'];
    $returnPath = (string) ($returnParts['path'] ?? '');
    $successBase = preg_match('/\.html?$/i', $returnPath) ? $returnUrl : $returnOrigin . '/customer-dashboard.html';
    $returnNonce = bin2hex(random_bytes(16));
    $cancelSignature = hash_hmac('sha256', $invoiceId . '|' . $returnNonce, $secret);
    $successUrl = $successBase . '?status=success&invoice=' . rawurlencode($invoiceId);
    $cancelUrl = $returnOrigin . '/api/payment-return.php?status=cancelled&invoice=' . rawurlencode($invoiceId) . '&nonce=' . rawurlencode($returnNonce) . '&signature=' . rawurlencode($cancelSignature);
    $result = httpJsonRequest('https://api.paymongo.com/v1/checkout_sessions', ['data' => ['attributes' => [
        'line_items' => [['currency' => 'PHP', 'amount' => $amountCentavos, 'name' => 'CoolTime invoice ' . $invoiceId, 'quantity' => 1]],
        'payment_method_types' => ['card', 'gcash', 'grab_pay', 'paymaya', 'qrph'],
        'description' => 'CoolTime service invoice ' . $invoiceId,
        'reference_number' => $invoiceId,
        'metadata' => ['invoiceId' => $invoiceId],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'send_email_receipt' => false,
    ]]], ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json']);
    $session = $result['body']['data'] ?? null;
    $checkoutUrl = $session['attributes']['checkout_url'] ?? '';
    if ($result['status'] < 200 || $result['status'] >= 300 || empty($session['id']) || $checkoutUrl === '') {
        error_log('PayMongo checkout creation failed with HTTP ' . $result['status']);
        jsonResponse(['ok' => false, 'message' => 'Payment checkout is temporarily unavailable.'], 502);
    }
    $paymentId = 'paymongo_' . preg_replace('/[^A-Za-z0-9_-]/', '', $session['id']);
    firestorePatchDocument($account, 'payments', $paymentId, [
        'invoiceId' => ['stringValue' => $invoiceId],
        'customerId' => ['stringValue' => (string) ($invoice['customerId'] ?? $user['uid'])],
        'branchId' => ['stringValue' => (string) ($invoice['branchId'] ?? '')],
        'amount' => ['integerValue' => (string) $amountCentavos],
        'currency' => ['stringValue' => 'PHP'],
        'method' => ['stringValue' => 'paymongo_checkout'],
        'status' => ['stringValue' => 'pending'],
        'referenceNumber' => ['stringValue' => (string) $session['id']],
        'createdAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
    ]);
    $paymentIntentId = (string) ($session['attributes']['payment_intent']['id'] ?? $session['attributes']['payment_intent_id'] ?? '');
    if ($paymentIntentId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $paymentIntentId)) {
        firestorePatchDocument($account, 'paymongo_intents', $paymentIntentId, [
            'invoiceId' => ['stringValue' => $invoiceId],
            'checkoutSessionId' => ['stringValue' => (string) $session['id']],
            'paymentDocumentId' => ['stringValue' => $paymentId],
        ]);
    }
    firestoreCheckoutMark($account, $invoiceId, [
        'latestPaymentSessionId' => ['stringValue' => (string) $session['id']],
        'latestCancelNonce' => ['stringValue' => $returnNonce],
        'checkoutStartedAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z')],
    ]);
    jsonResponse(['ok' => true, 'checkoutUrl' => $checkoutUrl, 'sessionId' => (string) $session['id']]);
} catch (Throwable $error) {
    error_log('Checkout session error: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'Unable to start payment checkout.'], 500);
}
