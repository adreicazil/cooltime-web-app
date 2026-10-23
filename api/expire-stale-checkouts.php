<?php
declare(strict_types=1);

// Call from a trusted scheduler every 5–10 minutes. This closes Checkout
// Sessions whose customer never returned through PayMongo's cancel URL.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-engine.php';

function staleJson(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') staleJson(405, ['ok' => false, 'message' => 'Method not allowed.']);
$configuredToken = environment('PAYMONGO_SWEEP_TOKEN', false);
$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($configuredToken === '' || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)
    || !hash_equals($configuredToken, trim($matches[1]))) {
    staleJson(401, ['ok' => false, 'message' => 'Unauthorized.']);
}

try {
    $secret = environment('PAYMONGO_SECRET_KEY', false);
    if ($secret === '' || !str_starts_with($secret, 'sk_')) staleJson(503, ['ok' => false, 'message' => 'PayMongo is not configured.']);
    $minutes = max(10, min(1440, (int) (environment('PAYMONGO_CHECKOUT_TIMEOUT_MINUTES', false) ?: 30)));
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $minutes * 60);
    $account = firebaseServiceAccount();
    $queryUrl = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:runQuery';
    $query = ['structuredQuery' => ['from' => [['collectionId' => 'invoices']], 'where' => ['fieldFilter' => [
        'field' => ['fieldPath' => 'checkoutStartedAt'], 'op' => 'LESS_THAN_OR_EQUAL', 'value' => ['timestampValue' => $cutoff],
    ]], 'limit' => 1000]];
    $result = httpJsonRequest($queryUrl, $query, ['Authorization: Bearer ' . firebaseAdminAccessToken()]);
    if ($result['status'] !== 200) throw new RuntimeException('Unable to find stale checkout sessions.');
    $processed = 0;
    $failed = 0;
    foreach ($result['body'] as $row) {
        $doc = $row['document'] ?? null;
        if (!is_array($doc) || empty($doc['name']) || !isset($doc['fields'])) continue;
        $parts = explode('/', (string) $doc['name']);
        $invoiceId = (string) end($parts);
        $invoice = [];
        foreach ($doc['fields'] as $key => $field) {
            foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) {
                if (array_key_exists($type, $field)) { $invoice[$key] = $field[$type]; break; }
            }
        }
        $sessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
        if (!in_array(strtolower((string) ($invoice['status'] ?? '')), ['pending', 'payment overdue'], true)
            || !preg_match('/^[A-Za-z0-9_-]+$/', $sessionId)) continue;
        $paymentDocId = 'paymongo_' . $sessionId;
        $paymentDocUrl = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/payments/' . rawurlencode($paymentDocId);
        // Read the payment marker before expiring to avoid touching completed sessions.
        $paymentContext = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 15, 'ignore_errors' => true]]);
        $paymentRaw = @file_get_contents($paymentDocUrl, false, $paymentContext);
        if (!is_string($paymentRaw)) continue;
        $paymentPayload = json_decode($paymentRaw, true);
        $paymentFields = $paymentPayload['fields'] ?? [];
        $paymentStatus = (string) ($paymentFields['status']['stringValue'] ?? '');
        if ($paymentStatus !== 'pending') continue;

        $expired = httpJsonRequest('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId) . '/expire', ['data' => ['attributes' => (object) []]], ['Authorization: Basic ' . base64_encode($secret . ':'), 'Accept: application/json']);
        if ($expired['status'] < 200 || $expired['status'] >= 300) { $failed++; continue; }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        firestorePatchDocument($account, 'invoices', $invoiceId, [
            'status' => ['stringValue' => 'payment overdue'], 'paymentFailureReason' => ['stringValue' => 'Checkout session expired after no payment confirmation.'], 'updatedAt' => ['timestampValue' => $now],
        ]);
        firestorePatchDocument($account, 'payments', $paymentDocId, ['status' => ['stringValue' => 'abandoned'], 'updatedAt' => ['timestampValue' => $now], 'failureReason' => ['stringValue' => 'Checkout session expired after no payment confirmation.']]);
        firestorePatchDocument($account, 'activity_logs', 'checkout_expired_' . bin2hex(random_bytes(8)), [
            'type' => ['stringValue' => 'payment_checkout_expired'], 'invoiceId' => ['stringValue' => $invoiceId], 'createdAt' => ['timestampValue' => $now],
        ]);
        if (!empty($invoice['bookingId']) && preg_match('/^[A-Za-z0-9_-]{1,150}$/', (string) $invoice['bookingId'])) {
            firestorePatchDocument($account, 'bookings', (string) $invoice['bookingId'], ['status' => ['stringValue' => 'payment overdue'], 'updatedAt' => ['timestampValue' => $now]]);
        }
        $customerId = (string) ($invoice['customerId'] ?? '');
        if ($customerId !== '' && !empty($invoice['bookingId'])) {
            $customer = notificationReadDocument($account, 'users', $customerId);
            $bookingId = (string) $invoice['bookingId'];
            $booking = notificationReadDocument($account, 'bookings', $bookingId);
            if (filter_var((string) ($customer['email'] ?? ''), FILTER_VALIDATE_EMAIL) && $booking) {
                $booking['id'] = $bookingId;
                $details = notificationBookingDetails($account, $booking);
                $amount = (float) ($invoice['finalAmount'] ?? $invoice['runningTotal'] ?? 0);
                if (strtolower((string) ($invoice['serviceStatus'] ?? '')) === 'completed') $amount = max(0, $amount - (float) ($invoice['downpaymentAmount'] ?? 0));
                $details['invoiceId'] = $invoiceId;
                $details['paymentBalance'] = $amount;
                notifyPaymentOverdue($account, $customerId, (string) $customer['email'], $details);
            }
        }
        $processed++;
    }
    staleJson(200, ['ok' => true, 'expired' => $processed, 'failed' => $failed]);
} catch (Throwable $error) {
    error_log('Stale checkout sweep failed: ' . $error->getMessage());
    staleJson(500, ['ok' => false, 'message' => 'Checkout expiry sweep failed.']);
}
