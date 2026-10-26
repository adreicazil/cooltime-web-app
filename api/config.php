<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (ob_get_level() === 0) {
    ob_start();
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

loadEnvironmentFile(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/gmail-mailer.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

const OTP_TTL_SECONDS = 300;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_COOLDOWN_SECONDS = 60;
const OTP_MAX_REQUESTS_PER_WINDOW = 3;
const OTP_REQUEST_WINDOW_SECONDS = 900;
const OTP_SESSION_TTL_SECONDS = 43200;

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode($data);
    exit;
}

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
}

function requestData(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['ok' => false, 'message' => 'Invalid request data.'], 400);
    }
    return $data;
}

function environment(string $name, bool $required = true): string
{
    $value = getenv($name);
    if ($required && ($value === false || !isConfiguredValue((string) $value))) {
        error_log("CoolTime OTP configuration is missing: {$name}");
        jsonResponse(['ok' => false, 'message' => 'Email verification is not configured on this server.'], 503);
    }
    return $value === false ? '' : trim($value);
}

function isConfiguredValue(string $value): bool
{
    $value = trim($value);
    return $value !== '' && !str_starts_with(strtolower($value), 'your_') && !str_contains(strtolower($value), 'your-gmail') && !str_contains(strtolower($value), 'yourfirebase');
}

function loadEnvironmentFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        // Windows editors may save .env as UTF-8 with a BOM. Remove it before
        // parsing the first key so configuration names are not silently altered.
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }
}

function authorizationToken(): string
{
    $header = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) { $header = trim((string) $_SERVER[$key]); break; }
    }
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0 || strcasecmp((string) $name, 'X-Authorization') === 0) {
                $header = trim((string) $value);
                break;
            }
        }
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
        jsonResponse(['ok' => false, 'message' => 'Authentication is required.'], 401);
    }
    return $matches[1];
}

function normalizeAccountRole(mixed $role): string
{
    return preg_replace('/[\s-]+/', '_', strtolower(trim((string) $role))) ?? '';
}

function firebaseUserFromToken(string $idToken): array
{
    // Firebase validates the ID token; this endpoint never trusts an email sent by the browser.
    $apiKey = environment('FIREBASE_API_KEY');
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . rawurlencode($apiKey);
    $payload = json_encode(['idToken' => $idToken]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $decoded = is_string($response) ? json_decode($response, true) : null;

    if (!is_array($decoded) || empty($decoded['users'][0]['localId']) || empty($decoded['users'][0]['email'])) {
        jsonResponse(['ok' => false, 'message' => 'Your sign-in session is invalid or has expired. Please log in again.'], 401);
    }

    return [
        'uid' => (string) $decoded['users'][0]['localId'],
        'email' => (string) $decoded['users'][0]['email'],
    ];
}

function requireOtpSession(array $user): void
{
    $uid = (string) $user['uid'];
    $sessionExpiry = (int) ($_SESSION['cooltime_mfa_sessions'][$uid] ?? 0);
    if ($sessionExpiry > time()) return;
    if ($sessionExpiry > 0) unset($_SESSION['cooltime_mfa_sessions'][$uid]);

    try {
        $account = firebaseServiceAccount();
        $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
            . '/databases/(default)/documents/mfa_sessions/' . rawurlencode($uid);
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(),
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $document = is_string($response) ? json_decode($response, true) : null;
        $expiry = (string) ($document['fields']['expiresAt']['timestampValue'] ?? '');
        if (is_array($document) && $expiry !== '' && strtotime($expiry) > time()) return;
    } catch (Throwable $error) {
        error_log('CoolTime Firebase OTP session lookup failed; no valid PHP session fallback: ' . $error->getMessage());
    }
    jsonResponse(['ok' => false, 'message' => 'Complete email verification before continuing.'], 403);
}

function revokeOtpSession(string $uid): void
{
    unset($_SESSION['cooltime_mfa_sessions'][$uid]);
    try {
        $account = firebaseServiceAccount();
        $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
            . '/databases/(default)/documents/mfa_sessions/' . rawurlencode($uid);
        $context = stream_context_create(['http' => [
            'method' => 'DELETE',
            'header' => 'Authorization: Bearer ' . firebaseAdminAccessToken(),
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = (int) ($matches[1] ?? 0);
        if (($status < 200 || $status >= 300) && $status !== 404) {
            error_log('CoolTime Firebase OTP session revoke failed (HTTP ' . $status . '); local PHP session was revoked.');
        }
    } catch (Throwable $error) {
        error_log('CoolTime Firebase OTP session revoke failed; local PHP session was revoked: ' . $error->getMessage());
    }
}

function createOtpSession(string $uid, int $expiresAt): void
{
    try {
        $account = firebaseServiceAccount();
        firestorePatchDocument($account, 'mfa_sessions', $uid, [
            'userId' => ['stringValue' => $uid],
            'verifiedAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z')],
            'expiresAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z', $expiresAt)],
        ]);
    } catch (Throwable $error) {
        error_log('CoolTime Firebase OTP session save failed; using PHP session fallback: ' . $error->getMessage());
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!headers_sent()) session_regenerate_id(true);
        $_SESSION['cooltime_mfa_sessions'][$uid] = $expiresAt;
    }
}

function otpStoragePath(string $purpose, string $subject): string
{
    $directory = environment('OTP_STORAGE_DIR', false);
    if ($directory === '') {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cooltime-otp';
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare OTP file storage.');
    }
    return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', "{$purpose}|{$subject}") . '.json';
}

function withOtpRecord(string $purpose, string $subject, callable $callback): mixed
{
    try {
        $path = otpStoragePath($purpose, $subject);
    } catch (Throwable $error) {
        error_log('CoolTime OTP file storage unavailable; using PHP session fallback: ' . $error->getMessage());
        return withSessionOtpRecord($purpose, $subject, $callback);
    }
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        error_log('CoolTime OTP file lock unavailable; using PHP session fallback.');
        return withSessionOtpRecord($purpose, $subject, $callback);
    }

    rewind($handle);
    $record = json_decode(stream_get_contents($handle) ?: '{}', true);
    $record = is_array($record) ? $record : [];
    $result = $callback($record);

    if (($result['delete'] ?? false) === true) {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($path);
        mirrorOtpRecordToSession($purpose, $subject, $result);
        return $result['response'];
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($result['record']));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    mirrorOtpRecordToSession($purpose, $subject, $result);
    return $result['response'];
}

function mirrorOtpRecordToSession(string $purpose, string $subject, array $result): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $key = hash('sha256', $purpose . '|' . $subject);
    if (!isset($_SESSION['cooltime_otp_records']) || !is_array($_SESSION['cooltime_otp_records'])) {
        $_SESSION['cooltime_otp_records'] = [];
    }
    if (($result['delete'] ?? false) === true) unset($_SESSION['cooltime_otp_records'][$key]);
    elseif (isset($result['record']) && is_array($result['record'])) $_SESSION['cooltime_otp_records'][$key] = $result['record'];
}

function withSessionOtpRecord(string $purpose, string $subject, callable $callback): mixed
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('PHP session storage is unavailable for email verification.');
    }
    $key = hash('sha256', $purpose . '|' . $subject);
    $records = &$_SESSION['cooltime_otp_records'];
    if (!is_array($records ?? null)) $records = [];
    $record = $records[$key] ?? [];
    $result = $callback(is_array($record) ? $record : []);
    if (($result['delete'] ?? false) === true) unset($records[$key]);
    else $records[$key] = $result['record'];
    return $result['response'];
}

function sendResendEmail(string $email, string $subject, string $html, string $text): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Resend email delivery requires the PHP cURL extension.');
    }

    $apiKey = environment('RESEND_API_KEY', false);
    if (!isConfiguredValue($apiKey)) {
        throw new RuntimeException('Resend email delivery is not configured on this server.');
    }
    $from = environment('RESEND_FROM_EMAIL', false) ?: 'CoolTime Auto Aircon <noreply@cooltimeautoaircon.online>';
    $payload = json_encode([
        'from' => $from,
        'to' => [$email],
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ], JSON_THROW_ON_ERROR);

    $curl = curl_init('https://api.resend.com/emails');
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Resend API request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Resend API request failed: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($response, true);
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['name'] ?? '') : '';
        throw new RuntimeException('Resend API returned HTTP ' . $status . ($message !== '' ? ': ' . $message : '.'));
    }
}

function sendOtpEmail(string $email, string $code, string $purpose = 'login'): void
{
    $isPasswordReset = $purpose === 'password_reset';
    $action = $isPasswordReset ? 'password reset' : 'sign-in verification';
    $escapedCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Inter,Arial,sans-serif;padding:20px;color:#0b1329">'
        . '<h2>CoolTime Auto Aircon Verification</h2>'
        . '<p>Your ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . ' code is:</p>'
        . '<h1 style="color:#38bdf8;font-size:32px">' . $escapedCode . '</h1>'
        . '<p>This code expires in 5 minutes. Do not share it with anyone.</p></div>';
    sendResendEmail($email, "Your CoolTime {$action} code", $html,
        "Your CoolTime {$action} code is: {$code}\n\nThis code expires in 5 minutes. Do not share it with anyone.");
}

function sendConfiguredEmail(string $email, string $subject, string $htmlBody, string $textBody = ''): void
{
    $plainText = $textBody !== '' ? $textBody : trim(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $htmlBody)));
    sendResendEmail($email, $subject, $htmlBody, $plainText);
}

// Payment callbacks must finish their database updates even if email delivery fails.
function trySendConfiguredEmail(string $email, string $subject, string $htmlBody, string $textBody = ''): bool
{
    try {
        sendConfiguredEmail($email, $subject, $htmlBody, $textBody);
        return true;
    } catch (Throwable $error) {
        error_log('CoolTime Gmail API email delivery failed: ' . $error->getMessage());
        return false;
    }
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function firebaseServiceAccountFile(): string
{
    $configuredPath = environment('FIREBASE_CREDENTIALS', false);
    if (str_starts_with(ltrim($configuredPath), '{')) {
        $configuredPath = '';
    }
    if ($configuredPath === '') {
        $configuredPath = environment('FIREBASE_SERVICE_ACCOUNT_FILE', false);
    }
    if ($configuredPath === '') {
        return '';
    }

    // Relative paths in .env are resolved from the project root, regardless
    // of the web server's current working directory.
    $path = $configuredPath;
    $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
        || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
        || str_starts_with($path, '\\\\');
    if (!$isAbsolute) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $path;
    }
    $resolved = realpath($path);
    return $resolved !== false && is_file($resolved) && is_readable($resolved) ? $resolved : '';
}

function firebaseServiceAccount(): array
{
    $json = environment('FIREBASE_SERVICE_ACCOUNT', false);
    if ($json === '') {
        $credentials = environment('FIREBASE_CREDENTIALS', false);
        if (str_starts_with(ltrim($credentials), '{')) {
            $json = $credentials;
        }
    }
    $file = firebaseServiceAccountFile();
    if ($json === '') {
        $json = $file !== '' ? @file_get_contents($file) : environment('FIREBASE_SERVICE_ACCOUNT_JSON', false);
    }
    $account = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($account) || empty($account['client_email']) || empty($account['private_key']) || empty($account['project_id'])) {
        throw new RuntimeException('Firebase service account configuration is missing or invalid.');
    }
    $account['private_key'] = normalizeFirebasePrivateKey((string) $account['private_key']);
    $privateKey = openssl_pkey_get_private($account['private_key']);
    if ($privateKey === false) {
        respondToInvalidFirebasePrivateKey('Firebase service-account private key could not be parsed');
    }
    return $account;
}

function normalizeFirebasePrivateKey(string $privateKey): string
{
    $privateKey = str_replace(
        ['\\\\r\\\\n', '\\\\n', '\\\\r', '\\r\\n', '\\n', '\\r', "\r\n", "\r"],
        "\n",
        $privateKey
    );
    $privateKey = trim($privateKey);
    if (preg_match('/\A(-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----)(.*?)(-----END [A-Z0-9 ]*PRIVATE KEY-----)\z/s', $privateKey, $matches)) {
        $body = preg_replace('/\s+/', '', $matches[2]) ?? '';
        $privateKey = $matches[1] . "\n" . implode("\n", str_split($body, 64)) . "\n" . $matches[3];
    }
    return $privateKey;
}

function logFirebaseOpenSslError(string $context): void
{
    $errors = [];
    while (($error = openssl_error_string()) !== false) {
        $errors[] = $error;
    }
    error_log($context . ': ' . ($errors !== [] ? implode('; ', $errors) : 'No OpenSSL error details available.'));
}

function respondToInvalidFirebasePrivateKey(string $context): never
{
    logFirebaseOpenSslError($context);
    jsonResponse(['ok' => false, 'message' => 'The server Firebase credentials are invalid.'], 500);
}

function firebaseAdminAccessToken(): string
{
    static $token = null;
    static $expiresAt = 0;
    if (is_string($token) && time() < $expiresAt - 60) {
        return $token;
    }

    $account = firebaseServiceAccount();
    $now = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = base64UrlEncode(json_encode([
        'iss' => $account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/identitytoolkit https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_THROW_ON_ERROR));
    $unsignedToken = "{$header}.{$claims}";
    $privateKey = openssl_pkey_get_private($account['private_key']);
    if ($privateKey === false) {
        respondToInvalidFirebasePrivateKey('Firebase service-account private key could not be loaded for signing');
    }
    try {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        }, E_WARNING);
        $signed = openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    } catch (Throwable $error) {
        logFirebaseOpenSslError('Firebase administrator JWT signing failed: ' . $error->getMessage());
        throw new RuntimeException('Unable to sign Firebase administrator request.', 0, $error);
    } finally {
        restore_error_handler();
    }
    if (!$signed) {
        logFirebaseOpenSslError('Firebase administrator JWT signing failed');
        throw new RuntimeException('Unable to sign Firebase administrator request.');
    }

    $response = httpJsonRequest('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => "{$unsignedToken}." . base64UrlEncode($signature),
    ], ['Content-Type: application/x-www-form-urlencoded'], true);
    if (empty($response['body']['access_token'])) {
        $providerError = $response['body']['error'] ?? '';
        if (is_array($providerError)) $providerError = $providerError['status'] ?? '';
        $providerError = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $providerError) ?: 'unspecified';
        error_log(sprintf(
            'Firebase service-account OAuth token request failed (HTTP %d, provider_error=%s).',
            (int) ($response['status'] ?? 0),
            $providerError
        ));
        throw new RuntimeException('Unable to authorize Firebase administrator request.');
    }
    $token = (string) $response['body']['access_token'];
    $expiresAt = $now + (int) ($response['body']['expires_in'] ?? 3600);
    return $token;
}

function httpJsonRequest(string $url, array $payload, array $headers = [], bool $formEncoded = false): array
{
    $content = $formEncoded ? http_build_query($payload) : json_encode($payload, JSON_THROW_ON_ERROR);
    if (!$formEncoded) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $body = is_string($response) ? json_decode($response, true) : null;
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    return ['status' => (int) ($matches[1] ?? 0), 'body' => is_array($body) ? $body : []];
}

function firebaseAdminLookupUserByEmail(string $email): ?array
{
    $account = firebaseServiceAccount();
    $response = httpJsonRequest(
        'https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/accounts:lookup',
        ['email' => [$email]],
        ['Authorization: Bearer ' . firebaseAdminAccessToken()]
    );
    return $response['status'] === 200 && !empty($response['body']['users'][0]) ? $response['body']['users'][0] : null;
}

function firebaseAdminUpdatePassword(string $uid, string $newPassword): void
{
    $account = firebaseServiceAccount();
    $response = httpJsonRequest(
        'https://identitytoolkit.googleapis.com/v1/projects/' . rawurlencode($account['project_id']) . '/accounts:update',
        ['localId' => $uid, 'password' => $newPassword],
        ['Authorization: Bearer ' . firebaseAdminAccessToken()]
    );
    if ($response['status'] !== 200) {
        throw new RuntimeException('Firebase password update failed.');
    }
}

function hasFirebaseServiceAccount(): bool
{
    $credentials = (string) getenv('FIREBASE_CREDENTIALS');
    return firebaseServiceAccountFile() !== ''
        || isConfiguredValue((string) getenv('FIREBASE_SERVICE_ACCOUNT'))
        || (str_starts_with(ltrim($credentials), '{') && isConfiguredValue($credentials))
        || isConfiguredValue((string) getenv('FIREBASE_SERVICE_ACCOUNT_JSON'));
}

// OTPs remain hashed and server-only. This optional audit record never exposes its code.
function recordOtpAudit(string $userId, string $email, string $codeHash, string $purpose, int $expiresAt, bool $used = false): void
{
    if (!hasFirebaseServiceAccount()) {
        return;
    }
    try {
        $account = firebaseServiceAccount();
        $recordId = hash('sha256', "{$purpose}|{$userId}");
        firestorePatchDocument($account, 'otp', $recordId, [
            'userId' => ['stringValue' => $userId],
            'email' => ['stringValue' => $email],
            'codeHash' => ['stringValue' => $codeHash],
            'purpose' => ['stringValue' => $purpose],
            'expiresAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z', $expiresAt)],
            'used' => ['booleanValue' => $used],
            'createdAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z')],
        ]);
        firestorePatchDocument($account, 'notifications', 'otp_' . $recordId, [
            'userId' => ['stringValue' => $userId],
            'email' => ['stringValue' => $email],
            'type' => ['stringValue' => 'otp_' . $purpose],
            'message' => ['stringValue' => $used ? 'OTP verification completed.' : 'OTP verification code sent.'],
            'status' => ['stringValue' => $used ? 'verified' : 'sent'],
            'createdAt' => ['timestampValue' => gmdate('Y-m-d\\TH:i:s\\Z')],
        ]);
    } catch (Throwable $error) {
        // OTP verification and its protected server session do not depend on this audit copy.
        error_log('CoolTime Firestore OTP audit failed: ' . $error->getMessage());
    }
}

function firestorePatchDocument(array $account, string $collection, string $documentId, array $fields): void
{
    $payload = json_encode(['fields' => $fields], JSON_THROW_ON_ERROR);
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($documentId);
        $context = stream_context_create(['http' => [
            'method' => 'PATCH',
            'header' => "Authorization: Bearer " . firebaseAdminAccessToken() . "\r\nContent-Type: application/json",
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            throw new RuntimeException('Firestore document update failed.');
        }
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $status = (int) ($matches[1] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Firestore document update failed.');
        }
}

function maskedEmail(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
}
