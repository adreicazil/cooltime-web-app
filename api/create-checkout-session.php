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

function paymongoResolvePreviousCheckout(string $secret, string $sessionId): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $sessionId)) return ['status' => 'missing'];
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
    if ($result['status'] === 404) return ['status' => 'expired'];
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new RuntimeException('Unable to verify the previous PayMongo checkout. Please retry shortly.');
    }
    $attributes = $result['body']['data']['attributes'] ?? [];
    $paymentStatus = strtolower((string) ($attributes['payment_status'] ?? ''));
    if ($paymentStatus === 'paid') return ['status' => 'paid'];
    foreach (($attributes['payments'] ?? []) as $payment) {
        if (strtolower((string) ($payment['attributes']['status'] ?? '')) === 'paid') return ['status' => 'paid'];
    }
    $status = strtolower((string) ($attributes['status'] ?? ''));
    if ($status === 'active' && $paymentStatus !== 'paid') {
        return ['status' => 'active', 'checkoutUrl' => (string) ($attributes['checkout_url'] ?? '')];
    }
    return ['status' => 'expired'];
}

function paymongoExpireCheckout(string $secret, string $sessionId): void
{
    $result = httpJsonRequest(
        'https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId) . '/expire',
        ['data' => ['attributes' => (object) []]],
        ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json']
    );
    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log('PayMongo could not expire refreshed checkout session ' . $sessionId . '; HTTP ' . $result['status']);
    }
}

try {
    $data = requestData();
    $invoiceId = trim((string) ($data['invoiceId'] ?? $data['billId'] ?? ''));
    $requestedBookingId = trim((string) ($data['bookingId'] ?? ''));
    $forceRefresh = filter_var($data['forceRefresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $invoiceId)) jsonResponse(['ok' => false, 'message' => 'A valid invoice is required.'], 400);
    $account = firebaseServiceAccount();
    $invoice = firestoreReadDocument($account, 'invoices', $invoiceId);
    if (!$invoice) {
        jsonResponse(['ok' => false, 'message' => 'Invoice not found.'], 404);
    }
    $isGuestWalkIn = strtolower((string)($invoice['source'] ?? '')) === 'walk_in_pos' && (bool)($invoice['is_guest'] ?? false);
    $user = null;
    $isAdmin = false;
    if (!$isGuestWalkIn) {
        $user = firebaseUserFromToken(authorizationToken());
        requireOtpSession($user);
        $profile = firestoreReadDocument($account, 'users', $user['uid']);
        $isAdmin = in_array(strtolower((string) ($profile['role'] ?? '')), ['admin', 'super_admin'], true);
        if (($invoice['customerId'] ?? '') !== $user['uid'] && !$isAdmin) jsonResponse(['ok' => false, 'message' => 'Invoice not found.'], 404);
    }
    $bookingId = (string) ($invoice['bookingId'] ?? '');
    if ($bookingId === '' && str_starts_with($invoiceId, 'web_')) $bookingId = substr($invoiceId, 4);
    if ($requestedBookingId !== '' && $bookingId !== '' && !hash_equals($bookingId, $requestedBookingId)) {
        jsonResponse(['ok' => false, 'message' => 'Invoice and booking do not match.'], 404);
    }
    $booking = null;
    if ($bookingId !== '' && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)) $booking = firestoreReadDocument($account, 'bookings', $bookingId);
    if ($bookingId !== '' && (!$booking || !in_array(strtolower(str_replace(' ', '_', (string) ($booking['status'] ?? ''))), ['completed', 'payment_overdue'], true))) {
        jsonResponse(['ok' => false, 'message' => 'Payment is only available after the service has been marked completed.'], 403);
    }
    if (strtolower((string) ($invoice['status'] ?? 'open')) === 'paid') {
        $now = gmdate('Y-m-d\\TH:i:s\\Z');
        firestorePatchDocument($account, 'invoices', $invoiceId, ['paymentStatus' => ['stringValue' => 'paid'], 'balanceDue' => ['doubleValue' => 0], 'balance_due' => ['doubleValue' => 0], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null], 'latestPaymentSessionId' => ['nullValue' => null], 'updatedAt' => ['timestampValue' => $now]]);
        if ($bookingId !== '' && $booking) firestorePatchDocument($account, 'bookings', $bookingId, ['paymentStatus' => ['stringValue' => 'paid'], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null], 'latestPaymentSessionId' => ['nullValue' => null], 'updatedAt' => ['timestampValue' => $now]]);
        jsonResponse(['ok' => true, 'alreadyPaid' => true, 'invoiceId' => $invoiceId]);
    }
    $secret = environment('PAYMONGO_SECRET_KEY', false);
    if ($secret === '' || (!str_starts_with($secret, 'sk_test_') && !str_starts_with($secret, 'sk_live_'))) {
        jsonResponse(['ok' => false, 'message' => 'Online payments are not configured on this server.'], 503);
    }
    $existingSession = (string) ($invoice['checkoutSessionId'] ?? $invoice['latestPaymentSessionId'] ?? $booking['checkoutSessionId'] ?? $booking['latestPaymentSessionId'] ?? '');
    if ($existingSession !== '' && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $existingSession)) {
        $previousCheckout = paymongoResolvePreviousCheckout($secret, $existingSession);
        if ($previousCheckout['status'] === 'paid') {
            $now = gmdate('Y-m-d\\TH:i:s\\Z');
            $paidFields = ['status' => ['stringValue' => 'paid'], 'paymentStatus' => ['stringValue' => 'paid'], 'balanceDue' => ['doubleValue' => 0], 'balance_due' => ['doubleValue' => 0], 'latestPaymentSessionId' => ['nullValue' => null], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null], 'paidAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now]];
            firestorePatchDocument($account, 'invoices', $invoiceId, $paidFields);
            firestorePatchDocument($account, 'payments', 'paymongo_' . $existingSession, ['status' => ['stringValue' => 'paid'], 'paidAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now]]);
            if ($bookingId !== '' && $booking) firestorePatchDocument($account, 'bookings', $bookingId, ['paymentStatus' => ['stringValue' => 'paid'], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null], 'latestPaymentSessionId' => ['nullValue' => null], 'updatedAt' => ['timestampValue' => $now]]);
            jsonResponse(['ok' => true, 'alreadyPaid' => true, 'invoiceId' => $invoiceId]);
        }
        if ($previousCheckout['status'] === 'active' && !$forceRefresh) {
            $activeUrl = $previousCheckout['checkoutUrl'] ?: (string) ($invoice['checkoutUrl'] ?? $booking['checkoutUrl'] ?? '');
            if ($activeUrl !== '') {
                $activeFields = [
                    'checkoutSessionId' => ['stringValue' => $existingSession],
                    'checkoutUrl' => ['stringValue' => $activeUrl],
                    'latestPaymentSessionId' => ['stringValue' => $existingSession],
                ];
                firestorePatchDocument($account, 'invoices', $invoiceId, $activeFields);
                if ($bookingId !== '' && $booking) firestorePatchDocument($account, 'bookings', $bookingId, $activeFields);
                jsonResponse(['ok' => true, 'checkoutUrl' => $activeUrl, 'sessionId' => $existingSession, 'reused' => true]);
            }
        }
        if ($previousCheckout['status'] === 'active' && $forceRefresh) {
            paymongoExpireCheckout($secret, $existingSession);
        }
        if ($previousCheckout['status'] === 'expired') {
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
    if ($amountPesos <= 0) jsonResponse(['ok' => false, 'message' => 'Invoice amount must be greater than zero.'], 400);
    $amountCentavos = max(100, (int) round($amountPesos * 100));
    $branchId = normalizeBranchId((string) ($invoice['branchId'] ?? $invoice['branch'] ?? $booking['branchId'] ?? $booking['branch'] ?? ''));
    if ($branchId === '') jsonResponse(['ok' => false, 'message' => 'Invoice branch is invalid.'], 422);
    $branchLabel = normalizeBranchLabel((string) ($invoice['branch'] ?? $invoice['branchName'] ?? $booking['branch'] ?? $booking['branchName'] ?? $branchId));
    $appointmentDate = (string)($invoice['date'] ?? $invoice['appointmentDate'] ?? $booking['date'] ?? $booking['appointmentDate'] ?? '');
    if ($appointmentDate === '') $appointmentDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $expectedDateCode = preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate) ? str_replace('-', '', $appointmentDate) : '';
    $referenceNumber = (string)($invoice['referenceNumber'] ?? $invoice['invoiceNumber'] ?? '');
    if ($expectedDateCode === '' || !preg_match('/^PMO-[A-Z]{3}-' . preg_quote($expectedDateCode, '/') . '-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/', $referenceNumber)) {
        $referenceNumber = cooltimeReferenceNumber('PMO', $branchLabel, $appointmentDate);
        $referenceFields = ['referenceNumber' => ['stringValue' => $referenceNumber], 'invoiceNumber' => ['stringValue' => $referenceNumber],
            'branchId' => ['stringValue' => $branchId], 'branch' => ['stringValue' => $branchLabel]];
        firestorePatchDocument($account, 'invoices', $invoiceId, $referenceFields);
        if ($bookingId !== '' && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)) {
            firestorePatchDocument($account, 'bookings', $bookingId, $referenceFields);
        }
    }
    $returnOrigin = paymongoReturnOrigin();
    $returnNonce = bin2hex(random_bytes(16));
    $cancelSignature = hash_hmac('sha256', $invoiceId . '|' . $returnNonce, $secret);
    $successUrl = $returnOrigin . '/api/payment-return.php?status=success&invoice=' . rawurlencode($invoiceId) . '&nonce=' . rawurlencode($returnNonce) . '&signature=' . rawurlencode($cancelSignature);
    $cancelUrl = $returnOrigin . '/api/payment-return.php?status=cancelled&invoice=' . rawurlencode($invoiceId) . '&nonce=' . rawurlencode($returnNonce) . '&signature=' . rawurlencode($cancelSignature);
    $result = httpJsonRequest('https://api.paymongo.com/v1/checkout_sessions', ['data' => ['attributes' => [
        'line_items' => [['currency' => 'PHP', 'amount' => $amountCentavos, 'name' => 'CoolTime invoice ' . $referenceNumber, 'quantity' => 1]],
        'payment_method_types' => ['qrph', 'gcash', 'paymaya'],
        'description' => 'CoolTime service invoice ' . $referenceNumber,
        'reference_number' => $referenceNumber,
        'metadata' => ['invoiceId' => $invoiceId, 'referenceNumber' => $referenceNumber],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'send_email_receipt' => false,
    ]]], ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json']);
    $session = $result['body']['data'] ?? null;
    $checkoutUrl = $session['attributes']['checkout_url'] ?? '';
    $sessionStatus = strtolower((string) ($session['attributes']['status'] ?? ''));
    if ($result['status'] < 200 || $result['status'] >= 300 || empty($session['id']) || $sessionStatus !== 'active'
        || !is_string($checkoutUrl) || !filter_var($checkoutUrl, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($checkoutUrl, PHP_URL_SCHEME)) !== 'https') {
        error_log('PayMongo checkout creation failed validation; HTTP ' . $result['status'] . '; response ' . substr(json_encode($result['body']), 0, 1500));
        $paymongoErrors = $result['body']['errors'] ?? [];
        $paymongoMessage = '';
        if (is_array($paymongoErrors) && isset($paymongoErrors[0]) && is_array($paymongoErrors[0])) {
            $paymongoMessage = (string) ($paymongoErrors[0]['detail'] ?? $paymongoErrors[0]['message'] ?? '');
        }
        if ($paymongoMessage === '' && $result['status'] >= 200 && $result['status'] < 300) {
            $paymongoMessage = 'PayMongo did not return an active checkout session.';
        }
        writeSystemLog('API Failure', 'PayMongo checkout creation returned HTTP ' . $result['status'] . ': '
            . ($paymongoMessage !== '' ? $paymongoMessage : ($result['error'] ?: 'Invalid checkout response.')),
            '/api/create-checkout-session.php', (int) ($result['status'] ?: 502), (string) ($user['email'] ?? ''));
        jsonResponse(['ok' => false, 'message' => $paymongoMessage !== '' ? substr($paymongoMessage, 0, 1000) : 'Payment checkout is temporarily unavailable.'], 502);
    }
    $paymentId = 'paymongo_' . preg_replace('/[^A-Za-z0-9_-]/', '', $session['id']);
    firestorePatchDocument($account, 'payments', $paymentId, [
        'invoiceId' => ['stringValue' => $invoiceId],
        'invoiceReferenceNumber' => ['stringValue' => $referenceNumber],
        'customerId' => ['stringValue' => (string) ($invoice['customerId'] ?? ($user['uid'] ?? ''))],
        'branchId' => ['stringValue' => $branchId],
        'branch' => ['stringValue' => $branchLabel],
        'amount' => ['integerValue' => (string) $amountCentavos],
        'currency' => ['stringValue' => 'PHP'],
        'method' => ['stringValue' => 'paymongo_checkout'],
        'status' => ['stringValue' => 'pending'],
        'referenceNumber' => ['stringValue' => $referenceNumber],
        'providerReference' => ['stringValue' => (string) $session['id']],
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
        'branchId' => ['stringValue' => $branchId], 'branch' => ['stringValue' => $branchLabel],
        'checkoutSessionId' => ['stringValue' => (string) $session['id']],
        'checkoutUrl' => ['stringValue' => $checkoutUrl],
        'latestPaymentSessionId' => ['stringValue' => (string) $session['id']],
        'latestCancelNonce' => ['stringValue' => $returnNonce],
        'checkoutStartedAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z')],
    ]);
    if ($bookingId !== '' && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)) {
        firestorePatchDocument($account, 'bookings', $bookingId, [
            'checkoutSessionId' => ['stringValue' => (string) $session['id']],
            'checkoutUrl' => ['stringValue' => $checkoutUrl],
            'latestPaymentSessionId' => ['stringValue' => (string) $session['id']],
        ]);
    }
    jsonResponse(['ok' => true, 'checkoutUrl' => $checkoutUrl, 'sessionId' => (string) $session['id']]);
} catch (Throwable $error) {
    error_log('Checkout session error: ' . $error->getMessage());
    writeSystemLog('API Failure', 'Checkout session error: ' . $error->getMessage(), '/api/create-checkout-session.php', 500);
    jsonResponse(['ok' => false, 'message' => 'Unable to start payment checkout.'], 500);
}
