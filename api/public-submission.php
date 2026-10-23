<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function submissionResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function cleanSubmissionText(mixed $value, int $max): string
{
    return substr(trim((string) $value), 0, $max);
}

function isDisposableSubmissionEmail(string $email): bool
{
    $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));
    $blocked = ['tempmail.com', '10minutemail.com', 'guerrillamail.com', 'mailinator.com', 'yopmail.com', 'trashmail.com', 'dispostable.com', 'bitproy.com', 'getnada.com'];
    foreach ($blocked as $candidate) {
        if ($domain === $candidate || str_ends_with($domain, '.' . $candidate)) return true;
    }
    return false;
}

function isValidPhilippineMobile(string $phone): bool
{
    $cleaned = preg_replace('/[\s\-()+]/', '', $phone) ?? '';
    return preg_match('/^(639\d{9}|09\d{9})$/', $cleaned) === 1;
}

function containsInappropriateSubmissionContent(string $text): bool
{
    $words = ['tangina', 'gago', 'tanga', 'bobo', 'ulol', 'puta', 'kantot', 'putangina', 'fuck', 'shit', 'bitch', 'asshole', 'bastard', 'cunt', 'dick', 'pussy'];
    foreach ($words as $word) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $text) === 1) return true;
    }
    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') submissionResponse(['ok' => false, 'message' => 'Method not allowed.'], 405);
try {
    $isMultipart = str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $input = $isMultipart ? $_POST : requestData();
    $kind = (string) ($input['kind'] ?? 'feedback');
    if (!in_array($kind, ['feedback', 'inquiry'], true)) submissionResponse(['ok' => false, 'message' => 'Invalid submission type.'], 400);
    $submissionType = $kind === 'inquiry' && ($input['requestType'] ?? '') === 'manual_booking' ? 'manual_booking' : $kind;
    $name = cleanSubmissionText($input['name'] ?? '', 120);
    $email = strtolower(cleanSubmissionText($input['email'] ?? '', 254));
    $contact = cleanSubmissionText($input['contactNumber'] ?? '', 40);
    $message = cleanSubmissionText($input['message'] ?? '', 5000);
    $service = cleanSubmissionText($input['servicePerformed'] ?? '', 160);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $contact === '' || $message === '') {
        submissionResponse(['ok' => false, 'message' => 'Submission failed Fields must be filled.'], 422);
    }
    if (isDisposableSubmissionEmail($email)) submissionResponse(['ok' => false, 'message' => 'Please use a permanent email address.'], 422);
    if (!isValidPhilippineMobile($contact)) submissionResponse(['ok' => false, 'message' => 'Enter a valid Philippine mobile number.'], 422);
    $requestedService = cleanSubmissionText($input['requestedService'] ?? '', 160);
    if (containsInappropriateSubmissionContent($name . ' ' . $message . ' ' . $service . ' ' . $requestedService)) {
        submissionResponse(['ok' => false, 'message' => 'Please remove inappropriate language before submitting.'], 422);
    }

    $ticket = 'CT-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
    $attachmentName = '';
    $attachmentPath = '';
    if ($kind === 'feedback' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) submissionResponse(['ok' => false, 'message' => 'Attachment must be 5 MB or smaller.'], 413);
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg'], true)) submissionResponse(['ok' => false, 'message' => 'Only PNG and JPG photo attachments are allowed.'], 415);
        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!in_array($detectedMime, ['image/png', 'image/jpeg'], true)) submissionResponse(['ok' => false, 'message' => 'The attachment must be a PNG or JPG image.'], 415);
        $storageRoot = environment('FEEDBACK_STORAGE_DIR', false);
        if ($storageRoot === '') $storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cooltime-feedback';
        $storage = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'feedback';
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) throw new RuntimeException('Could not prepare secure attachment storage.');
        $storedName = $ticket . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $storage . DIRECTORY_SEPARATOR . $storedName)) throw new RuntimeException('Could not save the attachment.');
        $attachmentName = cleanSubmissionText($file['name'], 180);
        $attachmentPath = 'private:' . $storedName;
    }

    $account = firebaseServiceAccount();
    $collection = $kind === 'feedback' ? 'feedback' : 'inquiries';
    $fields = [
        'ticketNumber' => ['stringValue' => $ticket], 'type' => ['stringValue' => $submissionType],
        'name' => ['stringValue' => $name], 'email' => ['stringValue' => $email],
        'contactNumber' => ['stringValue' => $contact], 'message' => ['stringValue' => $message],
        'status' => ['stringValue' => 'new'], 'createdAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')],
    ];
    if ($service !== '') $fields['servicePerformed'] = ['stringValue' => $service];
    foreach (['requestedService', 'branchId', 'requestedDate', 'requestedTime'] as $optionalField) {
        $optionalValue = cleanSubmissionText($input[$optionalField] ?? '', 160);
        if ($optionalValue !== '') $fields[$optionalField] = ['stringValue' => $optionalValue];
    }
    if ($attachmentName !== '') {
        $fields['attachmentName'] = ['stringValue' => $attachmentName];
        $fields['attachmentPath'] = ['stringValue' => $attachmentPath];
    }
    firestorePatchDocument($account, $collection, $ticket, $fields);

    $safeTicket = htmlspecialchars($ticket, ENT_QUOTES, 'UTF-8');
    $safeKind = $kind === 'feedback' ? 'feedback/report' : 'inquiry';
    $emailSent = true;
    try {
        sendConfiguredEmail($email, "CoolTime {$safeKind} received — {$ticket}",
            '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>We received your ' . $safeKind . '. Your tracking ticket is <strong>' . $safeTicket . '</strong>.</p><p>Keep this number for follow-up.</p>',
            "Hello {$name}, we received your {$safeKind}. Tracking ticket: {$ticket}.");
    } catch (Throwable $mailError) {
        $emailSent = false;
        error_log('Submission confirmation email failed: ' . $mailError->getMessage());
    }
    submissionResponse(['ok' => true, 'ticketNumber' => $ticket, 'emailSent' => $emailSent]);
} catch (Throwable $error) {
    error_log('Public submission failed: ' . $error->getMessage());
    submissionResponse(['ok' => false, 'message' => 'The submission could not be saved. Please try again later.'], 500);
}
