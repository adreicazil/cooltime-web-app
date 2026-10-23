<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-engine.php';

function returnPage(string $status, string $invoiceId): never
{
    $query = http_build_query(['payment' => $status, 'invoice' => $invoiceId]);
    header('Location: ../customer-dashboard.html?' . $query, true, 303);
    exit;
}

function returnReadDocument(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if (str_contains($http_response_header[0] ?? '', ' 404 ')) return null;
    if (!is_string($response)) throw new RuntimeException('Payment return invoice lookup failed.');
    $document = json_decode($response, true);
    if (!isset($document['fields'])) throw new RuntimeException('Payment return invoice response was invalid.');
    $fields = [];
    foreach ($document['fields'] as $name => $value) foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) if (isset($value[$type])) { $fields[$name] = $value[$type]; break; }
    return $fields;
}

function returnCommit(array $account, array $writes): void
{
    $formatted = [];
    foreach ($writes as $write) {
        $formatted[] = ['update' => ['name' => 'projects/' . $account['project_id'] . '/databases/(default)/documents/' . $write['collection'] . '/' . $write['id'], 'fields' => $write['fields']], 'updateMask' => ['fieldPaths' => array_keys($write['fields'])]];
    }
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:commit';
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json", 'content' => json_encode(['writes' => $formatted], JSON_THROW_ON_ERROR), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) throw new RuntimeException('Payment return status could not be recorded.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit('Method not allowed.'); }
$invoiceId = trim((string) ($_GET['invoice'] ?? ''));
$nonce = trim((string) ($_GET['nonce'] ?? ''));
$signature = trim((string) ($_GET['signature'] ?? ''));
$secret = environment('PAYMONGO_SECRET_KEY', false);
if (($_GET['status'] ?? '') !== 'cancelled' || !preg_match('/^[A-Za-z0-9_-]{1,150}$/', $invoiceId)
    || !preg_match('/^[a-f0-9]{32}$/', $nonce) || $secret === ''
    || !hash_equals(hash_hmac('sha256', $invoiceId . '|' . $nonce, $secret), $signature)) {
    http_response_code(403); exit('Invalid payment return.');
}

try {
    $account = firebaseServiceAccount();
    $invoice = returnReadDocument($account, 'invoices', $invoiceId);
    if (!$invoice) { http_response_code(404); exit('Invoice not found.'); }
    if (!hash_equals((string) ($invoice['latestCancelNonce'] ?? ''), $nonce)) returnPage('superseded', $invoiceId);
    if (strtolower((string) ($invoice['status'] ?? '')) === 'paid') returnPage('success', $invoiceId);
    if (strtolower((string) ($invoice['status'] ?? '')) === 'cancelled') returnPage('cancelled', $invoiceId);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $attemptId = 'abandoned_' . bin2hex(random_bytes(8));
    $amount = (float) ($invoice['finalAmount'] ?? $invoice['runningTotal'] ?? 0);
    if (strtolower((string) ($invoice['serviceStatus'] ?? '')) === 'completed') $amount = max(0, $amount - (float) ($invoice['downpaymentAmount'] ?? 0));
    $writes = [
        ['collection' => 'invoices', 'id' => $invoiceId, 'fields' => ['status' => ['stringValue' => 'payment overdue'], 'paymentFailureReason' => ['stringValue' => 'Customer returned from checkout without confirmed payment.'], 'updatedAt' => ['timestampValue' => $now]]],
        ['collection' => 'payments', 'id' => $attemptId, 'fields' => [
            'invoiceId' => ['stringValue' => $invoiceId], 'customerId' => ['stringValue' => (string) ($invoice['customerId'] ?? '')],
            'branchId' => ['stringValue' => (string) ($invoice['branchId'] ?? '')], 'amount' => ['integerValue' => (string) (int) round($amount * 100)],
            'amountUnit' => ['stringValue' => 'centavos'], 'currency' => ['stringValue' => 'PHP'], 'method' => ['stringValue' => 'paymongo_checkout'],
            'status' => ['stringValue' => 'abandoned'], 'failureReason' => ['stringValue' => 'Customer returned from checkout without confirmed payment.'],
            'referenceNumber' => ['stringValue' => $attemptId], 'createdAt' => ['timestampValue' => $now],
        ]],
        ['collection' => 'activity_logs', 'id' => 'payment_return_' . bin2hex(random_bytes(8)), 'fields' => [
            'type' => ['stringValue' => 'payment_checkout_abandoned'], 'invoiceId' => ['stringValue' => $invoiceId], 'createdAt' => ['timestampValue' => $now],
        ]],
    ];
    $sessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
    if ($sessionId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $sessionId)) {
        $writes[] = ['collection' => 'payments', 'id' => 'paymongo_' . $sessionId, 'fields' => [
            'status' => ['stringValue' => 'abandoned'], 'updatedAt' => ['timestampValue' => $now],
        ]];
    }
    if (!empty($invoice['bookingId']) && preg_match('/^[A-Za-z0-9_-]{1,150}$/', (string) $invoice['bookingId'])) {
        $writes[] = ['collection' => 'bookings', 'id' => (string) $invoice['bookingId'], 'fields' => ['status' => ['stringValue' => 'payment overdue'], 'updatedAt' => ['timestampValue' => $now]]];
    }
    returnCommit($account, $writes);
    $customer = !empty($invoice['customerId']) ? returnReadDocument($account, 'users', (string) $invoice['customerId']) : null;
    if (!empty($customer['email']) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) && !empty($invoice['bookingId'])) {
        $booking = returnReadDocument($account, 'bookings', (string) $invoice['bookingId']);
        if ($booking) {
            $booking['id'] = (string) $invoice['bookingId'];
            $details = notificationBookingDetails($account, $booking);
            $details['invoiceId'] = $invoiceId;
            $details['paymentBalance'] = $amount;
            notifyPaymentOverdue($account, (string) ($invoice['customerId'] ?? ''), (string) $customer['email'], $details);
        }
    }
    returnPage('overdue', $invoiceId);
} catch (Throwable $error) {
    error_log('Payment return handler failed: ' . $error->getMessage());
    returnPage('unavailable', $invoiceId);
}
