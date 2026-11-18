<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
requirePost();

function availabilityFirestoreQuery(array $account, string $fieldName, array $branchValues, string $dateField, string $date): array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents:runQuery';
    $payload = [
        'structuredQuery' => [
            'select' => ['fields' => [
                ['fieldPath' => 'branchId'], ['fieldPath' => 'date'], ['fieldPath' => 'appointmentDate'],
                ['fieldPath' => 'branch'], ['fieldPath' => 'timeSlot'], ['fieldPath' => 'preferredTimeSlot'], ['fieldPath' => 'preferredDate'], ['fieldPath' => 'status'],
            ]],
            'from' => [['collectionId' => 'bookings']],
            'where' => ['compositeFilter' => ['op' => 'AND', 'filters' => [
                ['fieldFilter' => ['field' => ['fieldPath' => $fieldName], 'op' => count($branchValues) === 1 ? 'EQUAL' : 'IN', 'value' => count($branchValues) === 1
                    ? ['stringValue' => $branchValues[0]]
                    : ['arrayValue' => ['values' => array_map(static fn(string $value): array => ['stringValue' => $value], $branchValues)]]]],
                ['fieldFilter' => ['field' => ['fieldPath' => $dateField], 'op' => 'EQUAL', 'value' => ['stringValue' => $date]]],
            ]]],
        ],
    ];
    $response = secureHttpRequest($url, 'POST', [
        'Authorization: Bearer ' . firebaseAdminAccessToken(), 'Content-Type: application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR), 15);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $errorBody = $response['body'] !== '' ? json_decode($response['body'], true) : null;
        $providerError = is_array($errorBody) ? ($errorBody['error']['status'] ?? $errorBody['error']['message'] ?? '') : '';
        $providerError = substr(preg_replace('/[^A-Za-z0-9_. -]/', '', (string) $providerError) ?? '', 0, 120);
        error_log(sprintf('Firestore availability query failed (HTTP=%d, cURL=%s, response=%s).',
            $response['status'], $response['error'] ?: 'none',
            substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500) ?: '[empty]'));
        writeSystemLog('API Failure', sprintf('Firestore availability query failed: HTTP %d; %s', $response['status'],
            $response['error'] ?: substr(preg_replace('/\s+/', ' ', $response['body']) ?? '', 0, 500)),
            '/api/slot-availability.php', (int) $response['status']);
        throw new RuntimeException(sprintf(
            'Firestore availability query failed (HTTP %d, provider_error=%s).',
            $response['status'],
            $providerError !== '' ? $providerError : 'unspecified'
        ));
    }
    $rows = json_decode($response['body'], true);
    if (!is_array($rows)) throw new RuntimeException('Firestore availability response was invalid.');
    return $rows;
}

function availabilityNormalizeBranch(string $value): string
{
    // Accept canonical labels and legacy "... Branch" labels as well as IDs.
    return normalizeBranchId(trim($value));
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

function availabilityDailyCapacity(array $account): int
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/system_settings/general_daily_slot_capacity';
    $response = secureHttpRequest($url, 'GET', ['Authorization: Bearer ' . firebaseAdminAccessToken()], null, 10);
    if ($response['status'] < 200 || $response['status'] >= 300) return 10;
    $document = json_decode($response['body'], true);
    $value = $document['fields']['value']['stringValue'] ?? null;
    return is_numeric($value) && (int) $value > 0 ? (int) $value : 10;
}

try {
    $data = requestData();
    $branch = availabilityNormalizeBranch((string) ($data['branchId'] ?? $data['branch'] ?? ''));
    $date = trim((string) ($data['date'] ?? ''));
    if (!in_array($branch, ['cabuyao', 'santa_rosa'], true)
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
    $cacheFile = slotAvailabilityCacheFile($branch, $date);
    if (($data['action'] ?? '') === 'invalidate') {
        @unlink($cacheFile);
        jsonResponse(['ok' => true]);
    }
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached)) {
        $cacheEntry = json_decode($cached, true);
        if (is_array($cacheEntry) && (int) ($cacheEntry['expiresAt'] ?? 0) > time() && is_array($cacheEntry['response'] ?? null)) {
            jsonResponse($cacheEntry['response']);
        }
    }
    $account = firebaseServiceAccount();
    $aliases = $branch === 'santa_rosa'
        ? ['santarosa', 'santa_rosa', 'santa_rosa_branch', 'santarosa_branch', 'Santa Rosa', 'Santa Rosa Branch', 'Santa Rosa branch', 'santa rosa branch']
        : ['cabuyao', 'cabuyao_main', 'cabuyao_branch', 'cabuyao_main_branch', 'Cabuyao', 'Cabuyao Branch', 'Cabuyao branch', 'Cabuyao Main Branch', 'Cabuyao main branch', 'cabuyao branch'];
    $branchFieldValues = $branch === 'santa_rosa'
        ? ['Santa Rosa', 'Santa Rosa Branch', 'Santa Rosa branch', 'santa rosa branch', 'santarosa', 'santa_rosa']
        : ['Cabuyao', 'Cabuyao Branch', 'Cabuyao branch', 'Cabuyao Main Branch', 'Cabuyao main branch', 'cabuyao branch', 'cabuyao', 'cabuyao_main'];
    $bookings = [];
    foreach ([['branchId', $aliases], ['branch', $branchFieldValues]] as [$fieldName, $branchValues]) {
      foreach (['date', 'appointmentDate', 'preferredDate'] as $dateField) {
        foreach (availabilityFirestoreQuery($account, $fieldName, $branchValues, $dateField, $date) as $row) {
            $fields = $row['document']['fields'] ?? null;
            if (!is_array($fields) || availabilityDateKey($fields[$dateField] ?? []) !== $date) continue;
            $status = strtolower(trim((string) ($fields['status']['stringValue'] ?? 'pending')));
            if (in_array($status, ['cancelled', 'canceled', 'archived', 'completed', 'expired', 'payment overdue'], true)) continue;
            $timeSlot = trim((string) ($fields['timeSlot']['stringValue'] ?? $fields['preferredTimeSlot']['stringValue'] ?? ''));
            if ($timeSlot !== '') {
                $documentName = (string) ($row['document']['name'] ?? $timeSlot . '|' . count($bookings));
                $bookings[$documentName] = $timeSlot;
            }
        }
      }
    }
    $counts = [];
    foreach ($bookings as $storedSlot) {
        if (preg_match('/^\s*(\d{1,2}:\d{2}\s*(?:AM|PM))/i', $storedSlot, $match)) {
            $start = strtoupper(preg_replace('/\s+/', ' ', trim($match[1])));
            $counts[$start] = ($counts[$start] ?? 0) + 1;
        }
    }
    $dailyCapacity = availabilityDailyCapacity($account);
    $response = ['ok' => true, 'branchId' => $branch, 'date' => $date,
        'slotCounts' => $counts, 'dailyBookingCount' => count($bookings),
        'slotCapacity' => $branch === 'cabuyao' ? 3 : 4, 'dailyCapacity' => $dailyCapacity];
    @file_put_contents($cacheFile, json_encode(['expiresAt' => time() + 15, 'response' => $response], JSON_THROW_ON_ERROR), LOCK_EX);
    jsonResponse($response);
} catch (Throwable $error) {
    error_log('Slot availability lookup failed: ' . $error->getMessage());
    writeSystemLog('API Failure', 'Slot availability lookup failed: ' . $error->getMessage(), '/api/slot-availability.php', 503);
    jsonResponse(['ok' => false, 'message' => 'Availability could not be loaded. Please retry shortly.'], 503);
}
