<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
requirePost();

function runningBillDecodeValue(array $value): mixed
{
    foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) {
        if (array_key_exists($type, $value)) {
            return in_array($type, ['integerValue', 'doubleValue'], true) ? (float) $value[$type] : $value[$type];
        }
    }
    if (isset($value['arrayValue']['values'])) {
        return array_map('runningBillDecodeValue', $value['arrayValue']['values']);
    }
    if (isset($value['mapValue']['fields'])) {
        return runningBillDecodeFields($value['mapValue']['fields']);
    }
    return null;
}

function runningBillDecodeFields(array $fields): array
{
    $decoded = [];
    foreach ($fields as $name => $value) $decoded[$name] = runningBillDecodeValue($value);
    return $decoded;
}

function runningBillRead(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (str_contains($http_response_header[0] ?? '', ' 404 ')) return null;
    if (!is_string($response)) throw new RuntimeException('Unable to read running bill data.');
    $document = json_decode($response, true);
    if (!is_array($document) || !isset($document['fields'])) throw new RuntimeException('Firestore returned invalid bill data.');
    return runningBillDecodeFields($document['fields']);
}

function runningBillPatch(array $account, string $id, array $fields): void
{
    $name = 'projects/' . $account['project_id'] . '/databases/(default)/documents/invoices/' . $id;
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents:commit';
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json",
        'content' => json_encode(['writes' => [[
            'update' => ['name' => $name, 'fields' => $fields],
            'updateMask' => ['fieldPaths' => array_keys($fields)],
        ]]], JSON_THROW_ON_ERROR),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) {
        throw new RuntimeException('Firestore could not save the running bill.');
    }
}

try {
    $input = requestData();
    $invoiceId = trim((string) ($input['invoiceId'] ?? ''));
    $serviceId = trim((string) ($input['serviceId'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $invoiceId)
        || !preg_match('/^[A-Za-z0-9_-]{1,150}$/', $serviceId)) {
        jsonResponse(['ok' => false, 'message' => 'A valid invoice and service are required.'], 400);
    }

    $user = firebaseUserFromToken(authorizationToken());
    requireOtpSession($user);
    $account = firebaseServiceAccount();
    $invoice = runningBillRead($account, 'invoices', $invoiceId);
    if (!$invoice || ($invoice['customerId'] ?? '') !== $user['uid']) {
        jsonResponse(['ok' => false, 'message' => 'Invoice not found.'], 404);
    }
    if (in_array(strtolower((string) ($invoice['status'] ?? 'pending')), ['paid', 'cancelled'], true)) {
        jsonResponse(['ok' => false, 'message' => 'This invoice is closed and cannot accept more services.'], 409);
    }
    $sessionId = (string) ($invoice['latestPaymentSessionId'] ?? '');
    if ($sessionId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $sessionId)) {
        $checkoutPayment = runningBillRead($account, 'payments', 'paymongo_' . $sessionId);
        if (strtolower((string) ($checkoutPayment['status'] ?? '')) === 'pending') {
            jsonResponse(['ok' => false, 'message' => 'This bill is in checkout. Finish or cancel that payment before changing its services.'], 409);
        }
    }

    $service = runningBillRead($account, 'services', $serviceId);
    $price = (float) ($service['price'] ?? 0);
    if (!$service || $price <= 0) jsonResponse(['ok' => false, 'message' => 'This service is unavailable.'], 404);

    $lines = is_array($invoice['serviceDetails'] ?? null) ? $invoice['serviceDetails'] : [];
    $found = false;
    foreach ($lines as &$line) {
        if (($line['id'] ?? '') !== $serviceId) continue;
        $line['quantity'] = (int) ($line['quantity'] ?? 1) + 1;
        $line['lineTotal'] = round((float) ($line['price'] ?? $price) * $line['quantity'], 2);
        $found = true;
        break;
    }
    unset($line);
    if (!$found) {
        $lines[] = ['id' => $serviceId, 'name' => (string) ($service['name'] ?? $serviceId), 'price' => $price, 'quantity' => 1, 'lineTotal' => $price];
    }
    $total = round(array_reduce($lines, static fn (float $sum, array $line): float => $sum + (float) ($line['lineTotal'] ?? 0), 0.0), 2);
    $encodedLines = array_map(static fn (array $line): array => ['mapValue' => ['fields' => [
        'id' => ['stringValue' => (string) $line['id']],
        'name' => ['stringValue' => (string) $line['name']],
        'price' => ['doubleValue' => (float) $line['price']],
        'quantity' => ['integerValue' => (string) (int) $line['quantity']],
        'lineTotal' => ['doubleValue' => (float) $line['lineTotal']],
    ]]], $lines);
    $serviceIds = array_values(array_unique(array_map(static fn (array $line): string => (string) $line['id'], $lines)));
    runningBillPatch($account, $invoiceId, [
        'serviceDetails' => ['arrayValue' => ['values' => $encodedLines]],
        'servicesAdded' => ['arrayValue' => ['values' => array_map(static fn (string $id): array => ['stringValue' => $id], $serviceIds)]],
        'runningTotal' => ['doubleValue' => $total],
        'totalAmount' => ['doubleValue' => $total],
        'finalAmount' => ['doubleValue' => $total],
        'updatedAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
    ]);
    jsonResponse(['ok' => true, 'runningTotal' => $total]);
} catch (Throwable $error) {
    error_log('Running bill update failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'The running bill could not be updated. Please try again.'], 500);
}
