<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-engine.php';

function webhookResponse(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

function verifyPayMongoSignature(string $rawBody, string $header, string $secret): bool
{
    $parts = [];
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) $parts[$pair[0]] = $pair[1];
    }
    $timestamp = $parts['t'] ?? '';
    if ($timestamp === '' || !ctype_digit($timestamp)) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    $testSignature = $parts['te'] ?? '';
    $liveSignature = $parts['li'] ?? '';
    return ($testSignature !== '' && hash_equals($expected, $testSignature))
        || ($liveSignature !== '' && hash_equals($expected, $liveSignature));
}

function webhookReadDocument(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (str_contains($http_response_header[0] ?? '', ' 404 ')) return null;
    if (!is_string($response)) throw new RuntimeException('Firestore read failed.');
    $document = json_decode($response, true);
    if (!is_array($document) || !isset($document['fields'])) throw new RuntimeException('Firestore returned an invalid document.');
    $fields = [];
    foreach ($document['fields'] as $key => $value) {
        foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) {
            if (array_key_exists($type, $value)) { $fields[$key] = $value[$type]; break; }
        }
    }
    return $fields;
}

function webhookFirestoreCommit(array $account, array $updates): void
{
    $writes = [];
    foreach ($updates as $collection => $documents) {
        foreach ($documents as $id => $fields) {
            $path = 'projects/' . $account['project_id'] . '/databases/(default)/documents/' . $collection . '/' . $id;
            $writes[] = [
                'update' => ['name' => $path, 'fields' => $fields],
                'updateMask' => ['fieldPaths' => array_keys($fields)],
            ];
        }
    }
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/databases/(default)/documents:commit';
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json",
        'content' => json_encode(['writes' => $writes], JSON_THROW_ON_ERROR),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('Firestore payment update failed.');
    }
}

function timestampValue(mixed $value, mixed $fallback): string
{
    foreach ([$value, $fallback, time()] as $candidate) {
        if (is_numeric($candidate)) return gmdate('Y-m-d\TH:i:s\Z', (int) $candidate);
        if (is_string($candidate) && $candidate !== '' && strtotime($candidate) !== false) {
            return gmdate('Y-m-d\TH:i:s\Z', strtotime($candidate));
        }
    }
    return gmdate('Y-m-d\TH:i:s\Z');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') webhookResponse(['ok' => false, 'message' => 'Method not allowed.'], 405);
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$webhookSecret = environment('PAYMONGO_WEBHOOK_SECRET', false);
if ($webhookSecret === '') webhookResponse(['ok' => false, 'message' => 'Webhook is not configured.'], 503);
if (!is_string($rawBody) || strlen($rawBody) > 1048576 || $signature === ''
    || !verifyPayMongoSignature($rawBody, $signature, $webhookSecret)) {
    webhookResponse(['ok' => false, 'message' => 'Invalid webhook signature.'], 401);
}

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    $outer = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $legacyAttributes = is_array($outer['attributes'] ?? null) ? $outer['attributes'] : [];
    $eventType = (string) ($outer['type'] ?? $legacyAttributes['type'] ?? $payload['type'] ?? '');
    $failureEvent = in_array($eventType, ['payment.failed', 'checkout_session.payment.failed', 'checkout_session.expired'], true);
    if (!in_array($eventType, ['payment.paid', 'checkout_session.payment.paid'], true) && !$failureEvent) {
        webhookResponse(['ok' => true, 'ignored' => true]);
    }

    $resource = is_array($outer['data'] ?? null) ? $outer['data'] : (is_array($legacyAttributes['data'] ?? null) ? $legacyAttributes['data'] : []);
    $resourceAttributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
    if (!$failureEvent && $eventType === 'payment.paid' && strtolower((string) ($resourceAttributes['status'] ?? 'paid')) !== 'paid') {
        webhookResponse(['ok' => true, 'ignored' => true]);
    }

    $account = firebaseServiceAccount();
    $sessionId = str_starts_with($eventType, 'checkout_session.') ? (string) ($resource['id'] ?? '') : '';
    $reference = (string) ($resourceAttributes['reference_number'] ?? $resourceAttributes['external_reference_number'] ?? '');
    $paymentIntentId = (string) ($resourceAttributes['payment_intent_id'] ?? $resourceAttributes['payment_intent']['id'] ?? '');
    $paymentRecord = null;
    $intentLink = null;

    if ($sessionId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $sessionId)) {
        $paymentDocId = 'paymongo_' . $sessionId;
        $paymentRecord = webhookReadDocument($account, 'payments', $paymentDocId);
    } elseif ($paymentIntentId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $paymentIntentId)) {
        $intentLink = webhookReadDocument($account, 'paymongo_intents', $paymentIntentId);
        if ($intentLink) {
            $reference = (string) ($intentLink['invoiceId'] ?? $reference);
            $sessionId = (string) ($intentLink['checkoutSessionId'] ?? '');
            $paymentRecordId = (string) ($intentLink['paymentDocumentId'] ?? '');
            if ($paymentRecordId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $paymentRecordId)) {
                $paymentRecord = webhookReadDocument($account, 'payments', $paymentRecordId);
            }
        }
    }

    if ($reference === '' && isset($resourceAttributes['metadata']['invoiceId'])) {
        $reference = (string) $resourceAttributes['metadata']['invoiceId'];
    }
    if ($reference === '' && preg_match('/^CoolTime service invoice ([A-Za-z0-9_-]+)$/', (string) ($resourceAttributes['description'] ?? ''), $matches)) {
        $reference = $matches[1];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $reference)) {
        webhookResponse(['ok' => false, 'message' => 'Payment reference is missing.'], 422);
    }

    $invoice = webhookReadDocument($account, 'invoices', $reference);
    if (!$invoice) webhookResponse(['ok' => false, 'message' => 'Invoice not found.'], 404);
    if ($paymentRecord && ($paymentRecord['invoiceId'] ?? '') !== $reference) {
        webhookResponse(['ok' => false, 'message' => 'Payment does not match invoice.'], 409);
    }

    if ($failureEvent) {
        $bookingId = (string) ($invoice['bookingId'] ?? '');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $attemptId = 'failed_' . preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId ?: (string) ($resource['id'] ?? bin2hex(random_bytes(5))));
        $failureReason = (string) ($resourceAttributes['failure_code'] ?? $resourceAttributes['failure_message'] ?? $resourceAttributes['reason'] ?? $eventType);
        $failureFields = [
            'invoiceId' => ['stringValue' => $reference], 'customerId' => ['stringValue' => (string) ($invoice['customerId'] ?? '')],
            'branchId' => ['stringValue' => (string) ($invoice['branchId'] ?? '')],
            'amount' => ['integerValue' => (string) ($paymentRecord['amount'] ?? 0)], 'amountUnit' => ['stringValue' => 'centavos'],
            'method' => ['stringValue' => 'paymongo_checkout'], 'status' => ['stringValue' => 'failed'],
            'failureReason' => ['stringValue' => substr($failureReason, 0, 500)], 'eventType' => ['stringValue' => $eventType],
            'referenceNumber' => ['stringValue' => $sessionId ?: (string) ($resource['id'] ?? $reference)],
            'createdAt' => ['timestampValue' => $now],
        ];
        $alreadyPaid = strtolower((string) ($invoice['status'] ?? '')) === 'paid';
        $updates = ['payments' => [$attemptId => $failureFields]];
        $invoiceSessionId = (string) ($invoice['latestPaymentSessionId'] ?? $sessionId);
        if (!$alreadyPaid && $invoiceSessionId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $invoiceSessionId)) {
            $updates['payments']['paymongo_' . $invoiceSessionId] = ['status' => ['stringValue' => 'failed'], 'updatedAt' => ['timestampValue' => $now]];
        }
        if (!$alreadyPaid) {
            $updates['invoices'] = [$reference => ['status' => ['stringValue' => 'payment overdue'], 'paymentFailureReason' => ['stringValue' => substr($failureReason, 0, 500)], 'updatedAt' => ['timestampValue' => $now]]];
        }
        if (!$alreadyPaid && $bookingId !== '' && preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)) {
            $updates['bookings'] = [$bookingId => ['status' => ['stringValue' => 'payment overdue'], 'updatedAt' => ['timestampValue' => $now]]];
        }
        webhookFirestoreCommit($account, $updates);

        if ($alreadyPaid) webhookResponse(['ok' => true, 'ignored' => true, 'reason' => 'Invoice already paid.']);

        $customer = !empty($invoice['customerId']) ? webhookReadDocument($account, 'users', (string) $invoice['customerId']) : null;
        if (!empty($customer['email']) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) && $bookingId !== '') {
            $booking = webhookReadDocument($account, 'bookings', $bookingId);
            if ($booking) {
                $booking['id'] = $bookingId;
                $details = notificationBookingDetails($account, $booking);
                $amount = (float) ($invoice['finalAmount'] ?? $invoice['runningTotal'] ?? 0);
                if (strtolower((string) ($invoice['serviceStatus'] ?? '')) === 'completed') $amount = max(0, $amount - (float) ($invoice['downpaymentAmount'] ?? 0));
                $details['invoiceId'] = $reference;
                $details['paymentBalance'] = $amount;
                notifyPaymentOverdue($account, (string) ($invoice['customerId'] ?? ''), (string) $customer['email'], $details);
            }
        }
        webhookResponse(['ok' => true, 'paymentOverdue' => true]);
    }

    $payments = is_array($resourceAttributes['payments'] ?? null) ? $resourceAttributes['payments'] : [];
    $payment = $resource;
    foreach ($payments as $candidate) {
        $candidateAttributes = is_array($candidate['attributes'] ?? null) ? $candidate['attributes'] : [];
        if (strtolower((string) ($candidateAttributes['status'] ?? '')) === 'paid') {
            $payment = $candidate;
            break;
        }
    }
    $paymentAttributes = is_array($payment['attributes'] ?? null) ? $payment['attributes'] : $resourceAttributes;
    if (strtolower((string) ($paymentAttributes['status'] ?? 'paid')) !== 'paid') {
        webhookResponse(['ok' => true, 'ignored' => true]);
    }

    $invoiceAmount = (float) ($invoice['finalAmount'] ?? $invoice['totalAmount'] ?? $invoice['runningTotal'] ?? 0);
    if (strtolower((string) ($invoice['serviceStatus'] ?? '')) === 'completed') {
        $invoiceAmount = max(0, $invoiceAmount - (float) ($invoice['downpaymentAmount'] ?? 0));
    }
    $expectedCentavos = (int) round($invoiceAmount * 100);
    if ($expectedCentavos < 1) webhookResponse(['ok' => false, 'message' => 'Invoice amount is invalid.'], 422);
    if ($paymentRecord && (int) ($paymentRecord['amount'] ?? 0) !== $expectedCentavos) {
        webhookResponse(['ok' => false, 'message' => 'Invoice amount does not match checkout.'], 409);
    }
    if (!$paymentRecord) {
        $settledAmount = (int) ($paymentAttributes['net_amount'] ?? $paymentAttributes['amount'] ?? 0);
        if ($settledAmount !== $expectedCentavos) webhookResponse(['ok' => false, 'message' => 'Paid amount does not match invoice.'], 409);
    }

    $paymongoPaymentId = (string) ($payment['id'] ?? $resource['id'] ?? '');
    $paymentMethod = (string) (
        $paymentAttributes['source']['type']
        ?? $paymentAttributes['payment_method_type']
        ?? $paymentAttributes['payment_method_details']['type']
        ?? 'paymongo_checkout'
    );
    $paidAt = timestampValue($paymentAttributes['paid_at'] ?? null, $outer['created_at'] ?? $payload['created_at'] ?? null);
    $paymentDocId = $paymentRecord
        ? (string) ($intentLink['paymentDocumentId'] ?? ('paymongo_' . ($sessionId ?: $resource['id'])))
        : 'paymongo_' . ($paymongoPaymentId !== '' ? $paymongoPaymentId : $resource['id']);
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $paymentDocId)) throw new RuntimeException('Invalid payment document reference.');

    $paymentFields = [
        'invoiceId' => ['stringValue' => $reference],
        'customerId' => ['stringValue' => (string) ($invoice['customerId'] ?? '')],
        'branchId' => ['stringValue' => (string) ($invoice['branchId'] ?? '')],
        // Sales analytics uses the invoice amount in centavos; keep any fee-inclusive
        // processor amount separately so service revenue is not overstated.
        'amount' => ['integerValue' => (string) $expectedCentavos],
        'amountUnit' => ['stringValue' => 'centavos'],
        'currency' => ['stringValue' => (string) ($paymentAttributes['currency'] ?? 'PHP')],
        'method' => ['stringValue' => $paymentMethod],
        'status' => ['stringValue' => 'paid'],
        'referenceNumber' => ['stringValue' => $paymongoPaymentId !== '' ? $paymongoPaymentId : ($sessionId ?: $reference)],
        'paidAt' => ['timestampValue' => $paidAt],
        'updatedAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
    ];
    if ($sessionId !== '') $paymentFields['checkoutSessionId'] = ['stringValue' => $sessionId];
    if ($paymentIntentId !== '') $paymentFields['paymentIntentId'] = ['stringValue' => $paymentIntentId];
    if (isset($paymentAttributes['amount'])) $paymentFields['chargedAmount'] = ['integerValue' => (string) $paymentAttributes['amount']];
    if (isset($paymentAttributes['net_amount'])) $paymentFields['netAmount'] = ['integerValue' => (string) $paymentAttributes['net_amount']];

    $paymentUpdates = [$paymentDocId => $paymentFields];
    $linkedSessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
    $linkedPaymentId = $linkedSessionId !== '' ? 'paymongo_' . $linkedSessionId : '';
    if ($linkedPaymentId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $linkedSessionId) && $linkedPaymentId !== $paymentDocId) {
        $paymentUpdates[$linkedPaymentId] = [
            'status' => ['stringValue' => 'paid'],
            'referenceNumber' => ['stringValue' => $paymongoPaymentId !== '' ? $paymongoPaymentId : $reference],
            'paidAt' => ['timestampValue' => $paidAt],
            'updatedAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z')],
        ];
    }
    webhookFirestoreCommit($account, [
        'invoices' => [$reference => [
            'status' => ['stringValue' => 'paid'],
            'paymentId' => ['stringValue' => $paymentDocId],
            'paymentReference' => ['stringValue' => $paymongoPaymentId],
            'paymentMethod' => ['stringValue' => $paymentMethod],
            'paidAt' => ['timestampValue' => $paidAt],
            'updatedAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
        ]],
        'payments' => $paymentUpdates,
    ]);

    webhookResponse(['ok' => true]);
} catch (Throwable $error) {
    error_log('PayMongo webhook processing failed: ' . $error->getMessage());
    webhookResponse(['ok' => false, 'message' => 'Webhook could not be processed.'], 500);
}
