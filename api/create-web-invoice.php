<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
requirePost();

function invoiceReadFields(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (str_contains($http_response_header[0] ?? '', ' 404 ')) return null;
    if (!is_string($response)) throw new RuntimeException('Unable to read booking data.');
    $document = json_decode($response, true);
    if (!is_array($document) || !isset($document['fields'])) throw new RuntimeException('Unable to read Firestore data.');
    $fields = [];
    foreach ($document['fields'] as $key => $value) {
        foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue'] as $type) {
            if (array_key_exists($type, $value)) { $fields[$key] = $value[$type]; break; }
        }
    }
    return $fields;
}

try {
    $input = requestData();
    $bookingId = trim((string) ($input['bookingId'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $bookingId)) jsonResponse(['ok' => false, 'message' => 'A valid booking is required.'], 400);
    $user = firebaseUserFromToken(authorizationToken());
    requireOtpSession($user);
    $account = firebaseServiceAccount();
    $booking = invoiceReadFields($account, 'bookings', $bookingId);
    if (!$booking || ($booking['customerId'] ?? '') !== $user['uid']) jsonResponse(['ok' => false, 'message' => 'Booking not found.'], 404);

    // Deterministic IDs make retries safe when a booking was created but the browser
    // lost its response before receiving the invoice ID.
    $invoiceId = 'web_' . $bookingId;
    $existing = invoiceReadFields($account, 'invoices', $invoiceId);
    if ($existing) jsonResponse(['ok' => true, 'invoiceId' => $invoiceId]);

    $serviceId = (string) ($booking['serviceId'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,150}$/', $serviceId)) jsonResponse(['ok' => false, 'message' => 'Booking service is invalid.'], 422);
    $service = invoiceReadFields($account, 'services', $serviceId);
    if (!$service || !isset($service['price']) || (float) $service['price'] <= 0) {
        jsonResponse(['ok' => false, 'message' => 'Service pricing is not configured.'], 503);
    }
    $branch = strtolower((string) ($booking['branchId'] ?? ''));
    $branchId = match ($branch) {
        'cabuyao', 'cabuyao_main', 'cabuyao main' => 'cabuyao',
        'santarosa', 'santa_rosa', 'santa-rosa' => 'santa_rosa',
        default => '',
    };
    if ($branchId === '') jsonResponse(['ok' => false, 'message' => 'Booking branch is invalid.'], 422);
    $price = (float) $service['price'];
    $serviceName = (string) ($service['name'] ?? $serviceId);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    firestorePatchDocument($account, 'invoices', $invoiceId, [
        'invoiceId' => ['stringValue' => $invoiceId],
        'bookingId' => ['stringValue' => $bookingId],
        'customerId' => ['stringValue' => $user['uid']],
        'branchId' => ['stringValue' => $branchId],
        'source' => ['stringValue' => 'web'],
        'servicesAdded' => ['arrayValue' => ['values' => [['stringValue' => $serviceId]]]],
        'serviceDetails' => ['arrayValue' => ['values' => [['mapValue' => ['fields' => [
            'id' => ['stringValue' => $serviceId], 'name' => ['stringValue' => $serviceName],
            'price' => ['doubleValue' => $price], 'quantity' => ['integerValue' => '1'], 'lineTotal' => ['doubleValue' => $price],
        ]]]]]],
        'runningTotal' => ['doubleValue' => $price],
        'finalAmount' => ['doubleValue' => $price],
        'totalAmount' => ['doubleValue' => $price],
        'downpaymentAmount' => ['doubleValue' => 0],
        'serviceStatus' => ['stringValue' => 'pending'],
        'status' => ['stringValue' => 'pending'],
        'createdAt' => ['timestampValue' => $now],
        'updatedAt' => ['timestampValue' => $now],
    ]);
    jsonResponse(['ok' => true, 'invoiceId' => $invoiceId]);
} catch (Throwable $error) {
    error_log('Web invoice creation failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'Booking was saved, but its invoice could not be created. Please contact the branch.'], 500);
}
