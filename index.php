<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

main($config);

function main(array $config): void
{
    $incomingHost = getIncomingHost();
    $upstreamHost = resolveUpstreamHost($incomingHost, $config);

    if ($upstreamHost === null) {
        respondWithError(400, 'Invalid host');
    }

    $upstreamUrl = buildUpstreamUrl($upstreamHost);
    $clientIp = getClientIp();
    $response = proxyRequest($upstreamUrl, $incomingHost, $upstreamHost, $clientIp, $config);

    emitResponse($response, $incomingHost, $upstreamHost, $config);
}

function getIncomingHost(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = strtolower(trim($host));

    if ($host === '') {
        return '';
    }

    // Strip port from Host header.
    if (str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }

    return $host;
}

function resolveUpstreamHost(string $incomingHost, array $config): ?string
{
    if ($incomingHost === '') {
        return $config['fallback_upstream_host'];
    }

    $publicBase = strtolower($config['public_base_domain']);
    $upstreamBase = strtolower($config['upstream_base_domain']);

    if ($incomingHost === $publicBase) {
        return $config['allow_root_domain'] ? $upstreamBase : $config['fallback_upstream_host'];
    }

    $suffix = '.' . $publicBase;

    if (!str_ends_with($incomingHost, $suffix)) {
        return $config['fallback_upstream_host'];
    }

    $subdomain = substr($incomingHost, 0, -strlen($suffix));

    if ($subdomain === '' || !preg_match('/^[a-z0-9.-]+$/', $subdomain)) {
        return $config['fallback_upstream_host'];
    }

    return $subdomain . '.' . $upstreamBase;
}

function buildUpstreamUrl(string $upstreamHost): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return 'https://' . $upstreamHost . $uri;
}

function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }

    return '0.0.0.0';
}

function proxyRequest(string $url, string $incomingHost, string $upstreamHost, string $clientIp, array $config): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        respondWithError(500, 'Failed to initialize cURL');
    }

    $requestHeaders = buildUpstreamHeaders($incomingHost, $upstreamHost, $clientIp, $config);
    $responseHeaders = [];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => $config['timeout'],
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_SSL_VERIFYPEER => (bool) $config['verify_upstream_tls'],
        CURLOPT_SSL_VERIFYHOST => $config['verify_upstream_tls'] ? 2 : 0,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
            $trimmed = trim($headerLine);

            if ($trimmed !== '') {
                $responseHeaders[] = $trimmed;
            }

            return strlen($headerLine);
        },
    ]);

    $requestBody = file_get_contents('php://input');
    if ($requestBody !== false && $requestBody !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    }

    if (isWebSocketHandshake()) {
        // PHP+cURL cannot transparently tunnel upgraded WebSocket frames after handshake.
        respondWithError(501, 'WebSocket proxying requires Nginx or a dedicated async proxy');
    }

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        $code = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT ? 504 : 502;
        curl_close($ch);
        respondWithError($code, 'Upstream request failed: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $body,
        'content_type' => $contentType,
    ];
}

function buildUpstreamHeaders(string $incomingHost, string $upstreamHost, string $clientIp, array $config): array
{
    $headers = [];
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $forwardedFor = trim($forwardedFor);
    $forwardedFor = $forwardedFor !== '' ? $forwardedFor . ', ' . $clientIp : $clientIp;

    foreach (getallheadersSafe() as $name => $value) {
        $lowerName = strtolower($name);

        if (in_array($lowerName, [
            'host',
            'content-length',
            'connection',
            'keep-alive',
            'transfer-encoding',
            'upgrade',
            'proxy-connection',
            'x-forwarded-for',
            'x-forwarded-host',
            'x-forwarded-proto',
            'x-real-ip',
            'forwarded',
        ], true)) {
            continue;
        }

        $headers[] = $name . ': ' . $value;
    }

    $hostHeader = $config['preserve_original_host_header'] ? $incomingHost : $upstreamHost;
    $headers[] = 'Host: ' . $hostHeader;
    $headers[] = 'X-Forwarded-Host: ' . $incomingHost;
    $headers[] = 'X-Forwarded-Proto: https';
    $headers[] = 'X-Forwarded-For: ' . $forwardedFor;
    $headers[] = 'X-Real-IP: ' . $clientIp;
    $headers[] = 'Forwarded: for="' . $clientIp . '";host="' . $incomingHost . '";proto=https';

    return $headers;
}

function getallheadersSafe(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        return is_array($headers) ? $headers : [];
    }

    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with($key, 'HTTP_')) {
            continue;
        }

        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$name] = (string) $value;
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
    }

    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
    }

    return $headers;
}

function emitResponse(array $response, string $incomingHost, string $upstreamHost, array $config): void
{
    http_response_code($response['status']);

    $contentType = '';
    foreach ($response['headers'] as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            break;
        }
    }

    foreach ($response['headers'] as $headerLine) {
        if (stripos($headerLine, 'HTTP/') === 0) {
            continue;
        }

        [$name] = array_pad(explode(':', $headerLine, 2), 2, null);
        $lowerName = strtolower(trim((string) $name));

        if (in_array($lowerName, [
            'content-length',
            'transfer-encoding',
            'connection',
            'keep-alive',
        ], true)) {
            continue;
        }

        if ($lowerName === 'set-cookie') {
            header(rewriteSetCookieHeader($headerLine, $config), false);
            continue;
        }

        if ($lowerName === 'location') {
            header(rewriteLocationHeader($headerLine, $incomingHost, $upstreamHost, $config), true);
            continue;
        }

        header($headerLine, false);
    }

    $body = $response['body'];

    if (
        $config['rewrite_response_bodies']
        && is_string($body)
        && strlen($body) <= (int) $config['rewrite_body_max_bytes']
        && shouldRewriteBody($contentType)
    ) {
        $body = rewriteBody($body, $incomingHost, $upstreamHost, $config);
    }

    header('Content-Length: ' . strlen((string) $body), true);
    echo $body;
}

function rewriteSetCookieHeader(string $headerLine, array $config): string
{
    $publicBase = $config['public_base_domain'];
    $upstreamBase = $config['upstream_base_domain'];

    return preg_replace(
        '/Domain=\.?' . preg_quote($upstreamBase, '/') . '/i',
        'Domain=.' . $publicBase,
        $headerLine
    ) ?? $headerLine;
}

function rewriteLocationHeader(string $headerLine, string $incomingHost, string $upstreamHost, array $config): string
{
    $value = trim(substr($headerLine, strlen('Location:')));
    $publicBase = $config['public_base_domain'];
    $upstreamBase = $config['upstream_base_domain'];

    $rewritten = preg_replace_callback(
        '/https:\/\/([a-z0-9.-]+\.)?' . preg_quote($upstreamBase, '/') . '([\/?#][^\s]*)?/i',
        static function (array $matches) use ($publicBase): string {
            $sub = $matches[1] ?? '';
            $suffix = $matches[2] ?? '';
            return 'https://' . $sub . $publicBase . $suffix;
        },
        $value
    );

    if ($rewritten === null) {
        $rewritten = $value;
    }

    if ($rewritten === 'https://' . $upstreamHost) {
        $rewritten = 'https://' . $incomingHost;
    }

    return 'Location: ' . $rewritten;
}

function shouldRewriteBody(string $contentType): bool
{
    $contentType = strtolower($contentType);

    return str_contains($contentType, 'text/html')
        || str_contains($contentType, 'application/json')
        || str_contains($contentType, 'application/javascript')
        || str_contains($contentType, 'text/javascript')
        || str_contains($contentType, 'text/css')
        || str_contains($contentType, 'text/plain');
}

function rewriteBody(string $body, string $incomingHost, string $upstreamHost, array $config): string
{
    $publicBase = $config['public_base_domain'];
    $upstreamBase = $config['upstream_base_domain'];

    $subdomain = '';
    $suffix = '.' . $upstreamBase;
    if (str_ends_with($upstreamHost, $suffix)) {
        $subdomain = substr($upstreamHost, 0, -strlen($suffix));
    }

    if ($subdomain !== '') {
        $body = str_replace(
            [
                'https://' . $upstreamHost,
                '//' . $upstreamHost,
            ],
            [
                'https://' . $incomingHost,
                '//' . $incomingHost,
            ],
            $body
        );
    }

    return preg_replace_callback(
        '/https:\/\/([a-z0-9.-]+\.)?' . preg_quote($upstreamBase, '/') . '/i',
        static function (array $matches) use ($publicBase): string {
            $sub = $matches[1] ?? '';
            return 'https://' . $sub . $publicBase;
        },
        $body
    ) ?? $body;
}

function isWebSocketHandshake(): bool
{
    $connection = strtolower($_SERVER['HTTP_CONNECTION'] ?? '');
    $upgrade = strtolower($_SERVER['HTTP_UPGRADE'] ?? '');

    return str_contains($connection, 'upgrade') && $upgrade === 'websocket';
}

function respondWithError(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}
