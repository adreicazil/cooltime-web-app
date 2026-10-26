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
    $dateErrors = DateTimeImmutable::getLastErrors();
    $validTimeSlots = ['09:00 AM - 11:00 AM', '11:00 AM - 01:00 PM', '01:00 PM - 03:00 PM', '03:00 PM - 05:00 PM'];
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d') !== $requestedDate
        || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $date < $today || !in_array($requestedTime, $validTimeSlots, true)) {
        jsonResponse(['ok' => false, 'message' => 'Choose a date from today onward and a valid appointment time slot.'], 422);
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
