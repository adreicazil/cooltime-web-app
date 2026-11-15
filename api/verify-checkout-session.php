<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
requirePost();

function checkoutRead(array $account, string $collection, string $id): ?array
{
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $collection) || !preg_match('/^[A-Za-z0-9_-]{1,150}$/', $id)) return null;
    $response = secureHttpRequest('https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id), 'GET', ['Authorization: Bearer ' . firebaseAdminAccessToken()], null, 15);
    if ($response['status'] === 404) return null;
    if ($response['status'] < 200 || $response['status'] >= 300) throw new RuntimeException('Unable to read payment record.');
    $doc = json_decode($response['body'], true);
    if (!is_array($doc)) throw new RuntimeException('Payment record response was invalid.');
    $result = [];
    foreach (($doc['fields'] ?? []) as $key => $value) {
        foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) {
            if (array_key_exists($type, $value)) { $result[$key] = $value[$type]; break; }
        }
    }
    return $result;
}

try {
    $input = requestData();
    $invoiceId = trim((string) ($input['invoiceId'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $invoiceId)) jsonResponse(['ok' => false, 'message' => 'A valid invoice is required.'], 422);
    $user = firebaseUserFromToken(authorizationToken());
    requireOtpSession($user);
    $account = firebaseServiceAccount();
    $invoice = checkoutRead($account, 'invoices', $invoiceId);
    if (!$invoice || (string) ($invoice['customerId'] ?? '') !== (string) $user['uid']) jsonResponse(['ok' => false, 'message' => 'Invoice not found.'], 404);
    $sessionId = (string) ($invoice['checkoutSessionId'] ?? $invoice['latestPaymentSessionId'] ?? '');
    if ($sessionId === '' || !preg_match('/^[A-Za-z0-9_-]{1,150}$/', $sessionId)) jsonResponse(['ok' => true, 'checkoutStatus' => 'none']);
    $secret = environment('PAYMONGO_SECRET_KEY', false);
    if ($secret === '' || !str_starts_with($secret, 'sk_')) throw new RuntimeException('PayMongo is not configured.');
    $headers = ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json'];
    $response = secureHttpRequest('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId), 'GET', $headers, null, 20);
    $attributes = [];
    if ($response['status'] === 404) {
        $status = 'expired';
    } elseif ($response['status'] < 200 || $response['status'] >= 300) {
        error_log(sprintf('Customer PayMongo verification failed (session=%s, HTTP=%d, cURL=%s, response=%s).', $sessionId, $response['status'], $response['error'] ?: 'none', substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500)));
        writeSystemLog('API Failure', sprintf('PayMongo checkout verification failed: HTTP %d; %s', $response['status'],
            $response['error'] ?: substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500)),
            '/api/verify-checkout-session.php', (int) $response['status'], (string) ($user['email'] ?? ''));
        throw new RuntimeException('PayMongo could not verify this checkout.');
    } else {
        $attributes = json_decode($response['body'], true)['data']['attributes'] ?? [];
        $status = strtolower((string) ($attributes['status'] ?? '')) === 'active' ? 'active' : 'expired';
        $paymentStatus = strtolower((string) ($attributes['payment_status'] ?? ''));
        if ($paymentStatus === 'paid') $status = 'paid';
        foreach (($attributes['payments'] ?? []) as $payment) {
            if (strtolower((string) ($payment['attributes']['status'] ?? '')) === 'paid') { $status = 'paid'; break; }
        }
    }
    $now = gmdate('Y-m-d\TH:i:s\Z');
    if ($status === 'paid') {
        firestorePatchDocument($account, 'invoices', $invoiceId, [
            'status' => ['stringValue' => 'paid'], 'paymentStatus' => ['stringValue' => 'paid'],
            'balanceDue' => ['doubleValue' => 0], 'balance_due' => ['doubleValue' => 0],
            'latestPaymentSessionId' => ['nullValue' => null], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null],
            'paidAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
        ]);
        firestorePatchDocument($account, 'payments', 'paymongo_' . $sessionId, [
            'status' => ['stringValue' => 'paid'], 'paidAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
        ]);
        $bookingId = (string) ($invoice['bookingId'] ?? '');
        if ($bookingId !== '') firestorePatchDocument($account, 'bookings', $bookingId, ['paymentStatus' => ['stringValue' => 'paid'], 'latestPaymentSessionId' => ['nullValue' => null], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null], 'updatedAt' => ['timestampValue' => $now]]);
    } else {
        if ($status === 'expired') {
            firestorePatchDocument($account, 'payments', 'paymongo_' . $sessionId, [
                'status' => ['stringValue' => 'expired'], 'expiredAt' => ['timestampValue' => $now], 'updatedAt' => ['timestampValue' => $now],
            ]);
            firestorePatchDocument($account, 'invoices', $invoiceId, [
                'latestPaymentSessionId' => ['nullValue' => null], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null], 'updatedAt' => ['timestampValue' => $now],
            ]);
            $bookingId = (string) ($invoice['bookingId'] ?? '');
            if ($bookingId !== '') firestorePatchDocument($account, 'bookings', $bookingId, ['latestPaymentSessionId' => ['nullValue' => null], 'checkoutSessionId' => ['nullValue' => null], 'checkoutUrl' => ['nullValue' => null], 'updatedAt' => ['timestampValue' => $now]]);
        }
    }
    jsonResponse(['ok' => true, 'checkoutStatus' => $status, 'alreadyPaid' => $status === 'paid']);
} catch (Throwable $error) {
    error_log('Customer PayMongo status verification failed: ' . $error->getMessage());
    try {
        writeSystemLog('API Failure', 'Checkout status verification error: ' . $error->getMessage(), '/api/verify-checkout-session.php', 502);
    } catch (Throwable $loggingError) {
        error_log('Could not write checkout verification system log: ' . $loggingError->getMessage());
    }
    jsonResponse(['ok' => false, 'message' => 'Payment service is temporarily busy. Please try again shortly.'], 502);
}
