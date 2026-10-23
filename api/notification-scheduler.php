<?php
declare(strict_types=1);

// Invoke with POST and Authorization: Bearer <NOTIFICATION_CRON_TOKEN> every 5 minutes.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-engine.php';
requirePost();
$configuredToken = environment('NOTIFICATION_CRON_TOKEN', false);
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($configuredToken === '' || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
    || !hash_equals($configuredToken, trim($matches[1]))) {
    jsonResponse(['ok' => false, 'message' => 'Unauthorized.'], 401);
}

function notificationListDocuments(array $account, string $collection): array
{
    $documents = [];
    $token = '';
    do {
        $query = ['pageSize' => 1000];
        if ($token !== '') $query['pageToken'] = $token;
        $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
            . '/databases/(default)/documents/' . rawurlencode($collection) . '?' . http_build_query($query);
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 20, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context);
        if (!is_string($response) || !preg_match('/\s2\d\d\s/', $http_response_header[0] ?? '')) throw new RuntimeException('Unable to read ' . $collection . ' for notification scheduling.');
        $result = json_decode($response, true);
        foreach (($result['documents'] ?? []) as $document) {
            $path = explode('/', (string) ($document['name'] ?? ''));
            $id = (string) end($path);
            $row = ['id' => $id];
            foreach (($document['fields'] ?? []) as $key => $value) {
                foreach (['stringValue', 'integerValue', 'doubleValue', 'timestampValue'] as $type) {
                    if (array_key_exists($type, $value)) {
                        $row[$key] = in_array($type, ['integerValue', 'doubleValue'], true) ? (float) $value[$type] : $value[$type];
                        break;
                    }
                }
            }
            $documents[] = $row;
        }
        $token = (string) ($result['nextPageToken'] ?? '');
    } while ($token !== '');
    return $documents;
}

try {
    $account = firebaseServiceAccount();
    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $timezone);
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $sentReminders = 0;
    $sentSameDay = 0;
    foreach (notificationListDocuments($account, 'bookings') as $booking) {
        $status = strtolower((string) ($booking['status'] ?? ''));
        if (!in_array($status, ['pending', 'confirmed'], true)) continue;
        $bookingId = (string) $booking['id'];
        $booking['id'] = $bookingId;
        $date = (string) ($booking['date'] ?? '');
        $time = trim((string) ($booking['timeSlot'] ?? $booking['time'] ?? ''));
        $scheduled = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $date . ' ' . $time, $timezone)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
        if (!$scheduled) continue;
        $customerId = (string) ($booking['customerId'] ?? '');
        $customer = notificationReadDocument($account, 'users', $customerId);
        if (!$customer || !filter_var((string) ($customer['email'] ?? ''), FILTER_VALIDATE_EMAIL)) continue;
        $details = notificationBookingDetails($account, $booking);
        if ($date === $tomorrow) {
            notifyBookingReminder($account, $customerId, (string) $customer['email'], $details);
            $sentReminders++;
        }
        $minutesUntil = ($scheduled->getTimestamp() - $now->getTimestamp()) / 60;
        if ($date === $now->format('Y-m-d') && $minutesUntil >= 0 && $minutesUntil <= 30) {
            notifySameDayReminder($account, $customerId, (string) $customer['email'], $details);
            $sentSameDay++;
        }
    }
    jsonResponse(['ok' => true, 'appointmentReminders' => $sentReminders, 'sameDayReminders' => $sentSameDay]);
} catch (Throwable $error) {
    error_log('Notification scheduler failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'Notification schedule run failed.'], 500);
}
