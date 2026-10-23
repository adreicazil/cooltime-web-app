<?php
declare(strict_types=1);

/**
 * Shared in-app/email notification helpers. Include after api/config.php.
 * Firestore writes use the server service account; browser clients never write
 * notification records directly.
 */
const COOLTIME_SYSTEM_EMAIL = 'cooltimeautoaircon2@gmail.com';

function notificationCreate(array $account, string $id, array $data): ?bool
{
    $fields = [];
    foreach ($data as $key => $value) {
        if ($value === null) continue;
        if ($key === 'createdAt') $fields[$key] = ['timestampValue' => (string) $value];
        elseif (is_numeric($value) && in_array($key, ['serviceCost', 'paymentBalance'], true)) $fields[$key] = ['doubleValue' => (float) $value];
        else $fields[$key] = ['stringValue' => substr((string) $value, 0, 2000)];
    }
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/notifications?documentId=' . rawurlencode($id);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json",
        'content' => json_encode(['fields' => $fields], JSON_THROW_ON_ERROR),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (preg_match('/\s2\d\d\s/', $statusLine)) return true;
    // Deterministic IDs make event retries idempotent and prevent duplicate email dispatch.
    if (str_contains($statusLine, ' 409 ')) return false;
    error_log('CoolTime notification write failed (' . $statusLine . '): ' . substr((string) $response, 0, 500));
    return null;
}

function notificationReadDocument(array $account, string $collection, string $id): ?array
{
    if ($id === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,149}$/', $id)) return null;
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(), 'timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (str_contains($statusLine, ' 404 ')) return null;
    if (!is_string($response) || !preg_match('/\s2\d\d\s/', $statusLine)) throw new RuntimeException('Unable to read notification event details.');
    $document = json_decode($response, true);
    if (!is_array($document['fields'] ?? null)) return null;
    $decode = static function (array $value) use (&$decode): mixed {
        foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $type) {
            if (!array_key_exists($type, $value)) continue;
            return match ($type) {
                'integerValue' => (int) $value[$type], 'doubleValue' => (float) $value[$type], 'booleanValue' => (bool) $value[$type], default => $value[$type],
            };
        }
        if (isset($value['arrayValue']['values'])) return array_map($decode, $value['arrayValue']['values']);
        if (isset($value['mapValue']['fields'])) return array_map($decode, $value['mapValue']['fields']);
        return null;
    };
    $fields = [];
    foreach ($document['fields'] as $name => $value) $fields[$name] = $decode($value);
    return $fields;
}

function notificationBookingDetails(array $account, array $booking): array
{
    $service = notificationReadDocument($account, 'services', (string) ($booking['serviceId'] ?? '')) ?? [];
    $vehicle = notificationReadDocument($account, 'vehicles', (string) ($booking['vehicleId'] ?? '')) ?? [];
    $customer = notificationReadDocument($account, 'users', (string) ($booking['customerId'] ?? '')) ?? [];
    $branchId = (string) ($booking['branchId'] ?? '');
    $branchAliases = match (strtolower($branchId)) {
        'santarosa', 'santa rosa', 'santa-rosa' => ['santarosa', 'santa_rosa'],
        'cabuyao_main', 'cabuyao main' => ['cabuyao_main', 'cabuyao'],
        default => [$branchId],
    };
    $branch = [];
    foreach ($branchAliases as $branchAlias) {
        $branch = notificationReadDocument($account, 'branches', $branchAlias)
            ?? notificationReadDocument($account, 'branch', $branchAlias)
            ?? [];
        if ($branch) break;
    }
    $invoice = notificationReadDocument($account, 'invoices', 'web_' . (string) ($booking['id'] ?? '')) ?? [];
    $paymentStatus = (string) ($invoice['status'] ?? 'unbilled');
    $paymentBalance = (float) ($invoice['balance_due'] ?? $invoice['balanceDue'] ?? $invoice['runningTotal'] ?? 0);
    if (in_array(strtolower($paymentStatus), ['paid', 'cancelled', 'canceled'], true)) $paymentBalance = 0;
    return [
        'bookingId' => (string) ($booking['id'] ?? ''),
        'customerId' => (string) ($booking['customerId'] ?? ''),
        'customerName' => (string) ($customer['name'] ?? $customer['username'] ?? $booking['customerName'] ?? 'Customer'),
        'customerUsername' => (string) ($customer['username'] ?? $customer['name'] ?? 'Customer'),
        'customerEmail' => (string) ($customer['email'] ?? $booking['customerEmail'] ?? ''),
        'serviceName' => (string) ($service['name'] ?? $booking['serviceName'] ?? $booking['serviceId'] ?? 'Service'),
        'serviceCost' => (float) ($service['price'] ?? $invoice['runningTotal'] ?? $invoice['totalAmount'] ?? 0),
        'branchName' => (string) ($branch['name'] ?? $branchId ?? 'Branch'),
        'scheduledDate' => (string) ($booking['date'] ?? ''),
        'scheduledTime' => (string) ($booking['timeSlot'] ?? $booking['time'] ?? ''),
        'vehicleYear' => (string) ($vehicle['year'] ?? ''),
        'vehicleMake' => (string) ($vehicle['make'] ?? ''),
        'vehicleModel' => (string) ($vehicle['model'] ?? ''),
        'vehiclePlateNumber' => (string) ($vehicle['plateNumber'] ?? ''),
        'bookingStatus' => (string) ($booking['status'] ?? ''),
        'paymentStatus' => $paymentStatus,
        'paymentBalance' => $paymentBalance,
        'invoiceId' => (string) ($invoice['id'] ?? ('web_' . (string) ($booking['id'] ?? ''))),
    ];
}

function notificationHtml(array $details): string
{
    $labels = [
        'customerName' => 'Customer', 'serviceName' => 'Service', 'serviceCost' => 'Service cost (PHP)',
        'branchName' => 'Branch', 'scheduledDate' => 'Scheduled date', 'scheduledTime' => 'Scheduled time',
        'vehicleYear' => 'Vehicle year', 'vehicleMake' => 'Vehicle make', 'vehicleModel' => 'Vehicle model',
        'vehiclePlateNumber' => 'Plate number', 'bookingStatus' => 'Booking status', 'paymentStatus' => 'Payment status',
        'paymentBalance' => 'Balance (PHP)',
    ];
    $rows = '';
    foreach ($labels as $key => $label) {
        if (!array_key_exists($key, $details) || $details[$key] === '') continue;
        $value = in_array($key, ['serviceCost', 'paymentBalance'], true) ? number_format((float) $details[$key], 2) : (string) $details[$key];
        $rows .= '<tr><th align="left">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    return '<p>' . htmlspecialchars((string) ($details['message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p><table>' . $rows . '</table>';
}

function notificationEmail(string $to, string $subject, array $details): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $message = (string) ($details['message'] ?? 'CoolTime account notification');
    return trySendConfiguredEmail($to, $subject, notificationHtml($details), $message);
}

function notifyAdminRecipients(array $account, string $eventId, string $type, string $message, array $details, string $subject): void
{
    $record = array_merge($details, [
        'type' => $type, 'message' => $message, 'recipientRole' => 'admin',
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    if (notificationCreate($account, 'admin_' . $eventId, $record) !== false) notificationEmail(COOLTIME_SYSTEM_EMAIL, $subject, $record);
}

function notifyCustomerRecipient(array $account, string $eventId, string $customerId, string $email, string $type, string $message, array $details, string $subject): bool
{
    $record = array_merge($details, [
        'type' => $type, 'message' => $message, 'recipientRole' => 'customer',
        'userId' => $customerId, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    if (notificationCreate($account, 'customer_' . $customerId . '_' . $eventId, $record) === false) return false;
    return notificationEmail($email, $subject, $record);
}

function notifyNewBooking(array $account, array $details): void
{
    $name = (string) ($details['customerName'] ?? 'A customer');
    $message = "Booking confirmation: {$name} has submitted a booking appointment.";
    notifyAdminRecipients($account, 'booking_' . (string) ($details['bookingId'] ?? bin2hex(random_bytes(8))), 'booking_submitted', $message, $details, 'New CoolTime booking appointment');
}

function notifyBookingCancelled(array $account, array $booking, array $customer, array $details): bool
{
    $message = 'Appointment cancellation: Your service appointment has been successfully cancelled!';
    return notifyCustomerRecipient($account, 'booking_cancelled_' . (string) ($booking['id'] ?? ''), (string) ($booking['customerId'] ?? ''), (string) ($customer['email'] ?? ''), 'booking_cancelled', $message, $details, 'CoolTime appointment cancelled');
}

function notifyBookingReminder(array $account, string $customerId, string $customerEmail, array $details): void
{
    $when = trim((string) ($details['scheduledDate'] ?? '') . ' ' . (string) ($details['scheduledTime'] ?? ''));
    $message = "Appointment reminder: your service appointment is set on {$when}.";
    $slotKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $when) ?: bin2hex(random_bytes(4));
    notifyCustomerRecipient($account, 'reminder_' . (string) ($details['bookingId'] ?? '') . '_' . $slotKey, $customerId, $customerEmail, 'booking_reminder', $message, $details, 'CoolTime appointment reminder');
}

function notifyRescheduleRequest(array $account, array $details): void
{
    $name = (string) ($details['customerName'] ?? 'A customer');
    $when = trim((string) ($details['scheduledDate'] ?? '') . ' ' . (string) ($details['scheduledTime'] ?? ''));
    $message = "Reschedule request: {$name} requests to have their service appointment rescheduled on {$when}.";
    $slotKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $when) ?: bin2hex(random_bytes(4));
    notifyAdminRecipients($account, 'reschedule_request_' . (string) ($details['bookingId'] ?? bin2hex(random_bytes(8))) . '_' . $slotKey, 'reschedule_request', $message, $details, 'CoolTime appointment reschedule request');
}

function notifyRescheduleSuccess(array $account, string $customerId, string $customerEmail, array $details): bool
{
    $message = 'Reschedule successful: your service appointment is successfully rescheduled!';
    $slotKey = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($details['scheduledDate'] ?? '') . '_' . (string) ($details['scheduledTime'] ?? '')) ?: bin2hex(random_bytes(4));
    return notifyCustomerRecipient($account, 'reschedule_success_' . (string) ($details['bookingId'] ?? '') . '_' . $slotKey, $customerId, $customerEmail, 'reschedule_success', $message, $details, 'CoolTime appointment rescheduled');
}

function notifySameDayReminder(array $account, string $customerId, string $customerEmail, array $details): void
{
    $service = (string) ($details['serviceName'] ?? 'service');
    $time = (string) ($details['scheduledTime'] ?? '');
    $slotKey = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($details['scheduledDate'] ?? '') . '_' . $time) ?: bin2hex(random_bytes(4));
    notifyCustomerRecipient($account, 'same_day_' . (string) ($details['bookingId'] ?? '') . '_' . $slotKey, $customerId, $customerEmail, 'same_day_reminder', "your {$service} appointment is about to start on {$time}!", $details, 'CoolTime appointment starts today');
    $customerName = (string) ($details['customerUsername'] ?? $details['customerName'] ?? 'Customer');
    $message = "{$customerName}'s {$service} appointment is about to start on {$time}!";
    notifyAdminRecipients($account, 'same_day_' . (string) ($details['bookingId'] ?? '') . '_' . $slotKey, 'same_day_reminder', $message, $details, 'CoolTime appointment starting soon');
}

function notifyPaymentOverdue(array $account, string $customerId, string $customerEmail, array $details): void
{
    $amount = number_format((float) ($details['paymentBalance'] ?? 0), 2);
    $dateTime = trim((string) ($details['scheduledDate'] ?? '') . ', ' . (string) ($details['scheduledTime'] ?? ''));
    $message = "Payment overdue: You have a remaining balance of PHP {$amount} from {$dateTime} in your account. Please settle your bill soon by visiting our website or our shop on-site.";
    notifyCustomerRecipient($account, 'overdue_' . (string) ($details['invoiceId'] ?? ''), $customerId, $customerEmail, 'payment_overdue', $message, $details, 'CoolTime payment overdue');
    $username = (string) ($details['customerUsername'] ?? $details['customerName'] ?? 'Customer');
    $adminMessage = "Payment overdue: {$username}'s payment of PHP {$amount} is overdue from {$dateTime}.";
    notifyAdminRecipients($account, 'overdue_' . (string) ($details['invoiceId'] ?? ''), 'payment_overdue', $adminMessage, $details, 'CoolTime payment overdue');
}
