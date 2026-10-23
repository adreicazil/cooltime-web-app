<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
requirePost();

function availabilityFirestoreQuery(array $account, string $branchId): array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents:runQuery';
    $payload = [
        'structuredQuery' => [
            'select' => ['fields' => [
                ['fieldPath' => 'branchId'], ['fieldPath' => 'date'],
                ['fieldPath' => 'timeSlot'], ['fieldPath' => 'status'],
            ]],
            'from' => [['collectionId' => 'bookings']],
            'where' => ['fieldFilter' => [
                'field' => ['fieldPath' => 'branchId'],
                'op' => 'EQUAL',
                'value' => ['stringValue' => $branchId],
            ]],
        ],
    ];
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json",
        'content' => json_encode($payload, JSON_THROW_ON_ERROR),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('Firestore availability query failed.');
    }
    $rows = json_decode($response, true);
    if (!is_array($rows)) throw new RuntimeException('Firestore availability response was invalid.');
    return $rows;
}

function availabilityDateKey(array $value): string
{
    if (isset($value['stringValue'])) {
        $date = (string) $value['stringValue'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
        try { return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d'); }
        catch (Throwable) { return ''; }
    }
    if (isset($value['timestampValue'])) {
        try { return (new DateTimeImmutable((string) $value['timestampValue']))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d'); }
        catch (Throwable) { return ''; }
    }
    return '';
}

try {
    $data = requestData();
    $branch = strtolower(trim((string) ($data['branchId'] ?? '')));
    $date = trim((string) ($data['date'] ?? ''));
    if (!in_array($branch, ['cabuyao', 'santarosa'], true)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonResponse(['ok' => false, 'message' => 'A valid branch and appointment date are required.'], 400);
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        jsonResponse(['ok' => false, 'message' => 'The appointment date is invalid.'], 400);
    }
    $authorization = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'] as $headerName) {
        if (!empty($_SERVER[$headerName])) { $authorization = trim((string) $_SERVER[$headerName]); break; }
    }
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $headerName => $headerValue) {
            if (strcasecmp((string) $headerName, 'Authorization') === 0) { $authorization = trim((string) $headerValue); break; }
        }
    }
    if ($authorization !== '') {
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            jsonResponse(['ok' => false, 'message' => 'Authentication is invalid.'], 401);
        }
        $user = firebaseUserFromToken($matches[1]);
        requireOtpSession($user);
    }
    $account = firebaseServiceAccount();
    $aliases = $branch === 'santarosa' ? ['santarosa', 'santa_rosa'] : ['cabuyao', 'cabuyao_main'];
    $occupied = [];
    foreach ($aliases as $branchId) {
        foreach (availabilityFirestoreQuery($account, $branchId) as $row) {
            $fields = $row['document']['fields'] ?? null;
            if (!is_array($fields) || availabilityDateKey($fields['date'] ?? []) !== $date) continue;
            $status = strtolower(trim((string) ($fields['status']['stringValue'] ?? 'pending')));
            if (in_array($status, ['cancelled', 'canceled', 'archived', 'completed', 'expired', 'payment overdue'], true)) continue;
            $timeSlot = trim((string) ($fields['timeSlot']['stringValue'] ?? ''));
            if ($timeSlot !== '') $occupied[$timeSlot] = true;
        }
    }
    jsonResponse(['ok' => true, 'branchId' => $branch, 'date' => $date, 'occupiedSlots' => array_keys($occupied)]);
} catch (Throwable $error) {
    error_log('Slot availability lookup failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'Availability could not be loaded. Please retry shortly.'], 503);
}
