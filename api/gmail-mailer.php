<?php
declare(strict_types=1);

/** Send an HTML email through Gmail's REST API using a refresh token. */
if (!function_exists('gmailSendEmail')) {
function gmailSendEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Gmail API email delivery requires the PHP cURL extension.');
    }
    foreach (['GMAIL_CLIENT_ID', 'GMAIL_CLIENT_SECRET', 'GMAIL_REFRESH_TOKEN'] as $key) {
        if (!function_exists('isConfiguredValue') || !isConfiguredValue((string) getenv($key))) {
            throw new RuntimeException("Gmail API configuration is missing: {$key}");
        }
    }

    $tokenResponse = gmailHttpRequest('https://oauth2.googleapis.com/token', [
        'client_id' => trim((string) getenv('GMAIL_CLIENT_ID')),
        'client_secret' => trim((string) getenv('GMAIL_CLIENT_SECRET')),
        'refresh_token' => trim((string) getenv('GMAIL_REFRESH_TOKEN')),
        'grant_type' => 'refresh_token',
    ], ['Content-Type: application/x-www-form-urlencoded'], true);
    gmailLogProviderResponse('Google OAuth token exchange', $tokenResponse, true);
    $accessToken = (string) ($tokenResponse['body']['access_token'] ?? '');
    if ($tokenResponse['status'] < 200 || $tokenResponse['status'] >= 300 || $accessToken === '') {
        $oauthError = (string) ($tokenResponse['body']['error'] ?? '');
        if (in_array($oauthError, ['invalid_grant', 'unauthorized_client'], true)) {
            error_log('Gmail OAuth configuration requires attention: ' . $oauthError . '.');
        }
        throw new RuntimeException('Google OAuth token request failed (HTTP ' . $tokenResponse['status'] . '): ' . gmailErrorText($tokenResponse));
    }

    $sender = trim((string) (getenv('GMAIL_SENDER_EMAIL') ?: getenv('SYSTEM_EMAIL_ADDRESS') ?: 'noreply@cooltimeautoaircon.online'));
    $safeTo = gmailHeaderValue($to);
    $safeSender = gmailHeaderValue($sender);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $textBody = $textBody !== '' ? $textBody : trim(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $htmlBody)));
    $boundary = '=_CoolTime_' . bin2hex(random_bytes(12));
    $message = "From: {$safeSender}\r\nTo: {$safeTo}\r\nSubject: {$encodedSubject}\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
    $message .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($textBody));
    $message .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($htmlBody));
    $message .= "--{$boundary}--\r\n";

    $result = gmailHttpRequest('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
        'raw' => base64UrlEncode($message),
    ], ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    gmailLogProviderResponse('Gmail API send', $result);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new RuntimeException('Gmail API send failed (HTTP ' . $result['status'] . '): ' . gmailErrorText($result));
    }
    error_log('Gmail API email sent successfully to ' . $safeTo . '.');
}
}

if (!function_exists('gmailHttpRequest')) {
function gmailHttpRequest(string $url, array $payload, array $headers, bool $form = false): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Could not initialize Gmail API request.');
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $form ? http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        // Keep certificate and hostname checks enabled on every platform.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    // Prefer an explicit app setting, then standard cURL/OpenSSL settings and
    // common Windows PHP bundle locations. If none exist, libcurl may use its
    // compiled-in CA path. Never turn off TLS verification as a fallback.
    $caBundle = gmailCaBundlePath();
    if ($caBundle !== '') $options[CURLOPT_CAINFO] = $caBundle;
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if ($response === false) {
        $host = (string) parse_url($url, PHP_URL_HOST);
        error_log(sprintf('%s cURL request failed: HTTP %d; raw body: <none>; cURL error: %s', $host, $status, $curlError));
        throw new RuntimeException('Gmail API cURL request failed: ' . $curlError);
    }
    $decoded = json_decode($response, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => (string) $response];
}
}

if (!function_exists('gmailCaBundlePath')) {
function gmailCaBundlePath(): string
{
    $phpRoot = defined('PHP_BINARY') ? dirname(PHP_BINARY) : '';
    $cUrlInfo = function_exists('curl_version') ? curl_version() : [];
    $candidates = [
        getenv('GMAIL_CA_BUNDLE') ?: '',
        getenv('CURL_CA_BUNDLE') ?: '',
        getenv('SSL_CERT_FILE') ?: '',
        ini_get('curl.cainfo') ?: '',
        ini_get('openssl.cafile') ?: '',
        is_array($cUrlInfo) ? (string) ($cUrlInfo['cainfo'] ?? '') : '',
        $phpRoot !== '' ? $phpRoot . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem' : '',
        $phpRoot !== '' ? $phpRoot . DIRECTORY_SEPARATOR . 'cacert.pem' : '',
    ];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) return $candidate;
    }
    return '';
}
}

if (!function_exists('gmailLogProviderResponse')) {
function gmailLogProviderResponse(string $service, array $result, bool $redactAccessToken = false): void
{
    $status = (int) ($result['status'] ?? 0);
    $raw = (string) ($result['raw'] ?? '');
    if ($redactAccessToken && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (['access_token', 'refresh_token', 'id_token'] as $field) {
                if (isset($decoded[$field])) $decoded[$field] = '[REDACTED]';
            }
            $raw = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[OAuth response body could not be safely redacted]';
        }
    }
    error_log(sprintf('%s response: HTTP %d; raw body: %s', $service, $status, $raw));
}
}

if (!function_exists('gmailErrorText')) {
function gmailErrorText(array $result): string
{
    $body = $result['body'] ?? [];
    $error = $body['error'] ?? null;
    if (is_array($error)) return json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Unknown API error';
    return substr((string) ($result['raw'] ?? 'Unknown API error'), 0, 2000);
}
}

if (!function_exists('gmailHeaderValue')) {
function gmailHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}
}
