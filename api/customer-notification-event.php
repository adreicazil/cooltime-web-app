<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification-engine.php';
requirePost();

try {
    $input = requestData();
    $action = (string) ($input['action'] ?? '');
    if (!in_array($action, ['booking_created', 'request_reschedule'], true)) jsonResponse(['ok' => false, 'message' => 'Unsupported notification event.'], 422);
    $user = firebaseUserFromToken(authorizationToken());
    requireOtpSession($user);
    $account = firebaseServiceAccount();
    $profile = notificationReadDocument($account, 'users', $user['uid']);
    if (!$profile || normalizeAccountRole($profile['role'] ?? '') !== 'customer' || strtolower((string) ($profile['status'] ?? 'active')) !== 'active') {
        jsonResponse(['ok' => false, 'message' => 'Customer access is required.'], 403);
    }
    $bookingId = trim((string) ($input['bookingId'] ?? ''));
    $booking = notificationReadDocument($account, 'bookings', $bookingId);
    if (!$booking || (string) ($booking['customerId'] ?? '') !== $user['uid']) jsonResponse(['ok' => false, 'message' => 'Booking was not found.'], 404);
    $booking['id'] = $bookingId;
    $details = notificationBookingDetails($account, $booking);
    if ($action === 'booking_created') {
        notifyNewBooking($account, $details);
        jsonResponse(['ok' => true]);
    }
    if (!in_array(strtolower((string) ($booking['status'] ?? '')), ['pending', 'confirmed'], true)) {
        jsonResponse(['ok' => false, 'message' => 'Only pending or confirmed bookings can be rescheduled.'], 409);
    }
    $requestedDate = trim((string) ($input['requestedDate'] ?? ''));
    $requestedTime = trim((string) ($input['requestedTime'] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate, new DateTimeZone('Asia/Manila'));
    $requestedTime = strtoupper(preg_replace('/\s+/', ' ', $requestedTime));
    $requestedSlot = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $requestedDate . ' ' . $requestedTime, new DateTimeZone('Asia/Manila'));
    $normalizedTime = preg_replace('/^0(?=\d:)/', '', $requestedTime) ?: $requestedTime;
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date || $date->format('Y-m-d') !== $requestedDate
        || !$requestedSlot || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $requestedSlot->format('Y-m-d g:i A') !== $requestedDate . ' ' . $normalizedTime
        || $requestedSlot <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
        jsonResponse(['ok' => false, 'message' => 'Enter a valid future date and appointment time.'], 422);
    }
    $details['scheduledDate'] = $requestedDate;
    $details['scheduledTime'] = $requestedTime;
    $details['requestedDate'] = $requestedDate;
    $details['requestedTime'] = $requestedTime;
    notifyRescheduleRequest($account, $details);
    jsonResponse(['ok' => true, 'message' => 'Your reschedule request was sent to CoolTime staff.']);
} catch (Throwable $error) {
    error_log('Customer notification event failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'message' => 'The booking was saved, but its notification could not be dispatched.'], 500);
}
