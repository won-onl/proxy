<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

if (!empty($config['show_fatal_errors'])) {
    register_shutdown_function(static function () use ($config): void {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        $msg = $err['message'] . "\n" . $err['file'] . ':' . $err['line'];
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Ошибка PHP</title></head><body>';
        echo '<h1>Фатальная ошибка PHP</h1>';
        if (!empty($config['show_debug_errors'])) {
            echo '<pre style="white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        } else {
            echo '<p>См. логи сервера.</p>';
        }
        echo '</body></html>';
    });
}

main($config);

function main(array $config): void
{
    if (!empty($config['require_upstream_direct_ip'])) {
        $dip = trim((string) ($config['upstream_direct_ip'] ?? ''));
        if ($dip === '' || filter_var($dip, FILTER_VALIDATE_IP) === false) {
            respondWithError($config, 503, 'Задайте upstream_direct_ip в config.php', [
                'hint' => 'Аналог Cloudflare: исходящее соединение должно идти на IP вашего сервера (origin), а не через DNS к Cloudflare. Укажите IPv4/IPv6 origin; иначе curl резолвит имя → CF → неверный vhost или заглушка.',
            ]);
        }
    }

    $incomingHost = getIncomingHost();
    $upstreamHost = resolveUpstreamHost($incomingHost, $config);

    if ($upstreamHost === null) {
        respondWithError($config, 400, 'Неверный Host', [
            'incoming_host' => $incomingHost,
            'hint' => 'Проверьте public_base_domain в config.php — он должен совпадать с доменом в браузере (например won-onl.ru).',
        ]);
    }

    $upstreamUrl = buildUpstreamUrl($upstreamHost, $config);
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

/**
 * Имя на origin для запросов к голому зеркалу (won-onl.ru): ru.won.onl или won.onl.
 */
function apexUpstreamHostname(array $config): string
{
    $upstreamBase = strtolower($config['upstream_base_domain']);
    $apex = trim((string) ($config['public_apex_upstream_subdomain'] ?? ''));

    return $apex === '' ? $upstreamBase : $apex . '.' . $upstreamBase;
}

/**
 * Какой Host в браузере соответствует имени на origin (*.won.onl).
 */
function publicHostForUpstreamHostname(string $hostname, array $config): string
{
    $hostname = strtolower($hostname);
    $upstreamBase = strtolower($config['upstream_base_domain']);
    $publicBase = strtolower($config['public_base_domain']);
    $apex = trim((string) ($config['public_apex_upstream_subdomain'] ?? ''));

    if ($hostname === $upstreamBase) {
        return $publicBase;
    }

    if ($apex !== '' && $hostname === $apex . '.' . $upstreamBase) {
        return $publicBase;
    }

    $suf = '.' . $upstreamBase;
    if (str_ends_with($hostname, $suf)) {
        $sub = substr($hostname, 0, -strlen($suf));
        if ($sub !== '' && preg_match('/^[a-z0-9.-]+$/', $sub)) {
            return $sub . '.' . $publicBase;
        }
    }

    return $hostname;
}

function resolveUpstreamHost(string $incomingHost, array $config): ?string
{
    if ($incomingHost === '') {
        return $config['fallback_upstream_host'];
    }

    $publicBase = strtolower($config['public_base_domain']);
    $upstreamBase = strtolower($config['upstream_base_domain']);
    $aliases = array_map(
        static fn (string $h): string => strtolower(trim($h)),
        $config['public_host_aliases'] ?? []
    );

    $apexHost = apexUpstreamHostname($config);

    // Алиасы и голый домен → один upstream (например ru.won.onl), не www.won.onl.
    if (in_array($incomingHost, $aliases, true)) {
        return $config['allow_root_domain'] ? $apexHost : $config['fallback_upstream_host'];
    }

    if ($incomingHost === $publicBase) {
        return $config['allow_root_domain'] ? $apexHost : $config['fallback_upstream_host'];
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

/**
 * В строке заголовка (Origin, Referer) заменить URL с зеркала на эквивалент *.won.onl.
 */
function rewritePublicUrlsToUpstream(string $value, array $config): string
{
    if ($value === '') {
        return $value;
    }

    $out = preg_replace_callback(
        '/(https?:\/\/)([^\/\s:]+)(?::(\d+))?(\/[^\s]*)?/i',
        static function (array $m) use ($config): string {
            $host = strtolower($m[2]);
            $upstream = resolveUpstreamHost($host, $config);
            if ($upstream === null) {
                return $m[0];
            }
            $port = isset($m[3]) && $m[3] !== '' ? ':' . $m[3] : '';
            $path = $m[4] ?? '';

            return $m[1] . $upstream . $port . $path;
        },
        $value
    );

    return is_string($out) ? $out : $value;
}

function buildUpstreamUrl(string $upstreamHost, array $config): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $scheme = !empty($config['upstream_use_http']) ? 'http' : 'https';
    return $scheme . '://' . $upstreamHost . $uri;
}

function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }

    return '0.0.0.0';
}

/**
 * Подмена адреса для libcurl: TCP к IP, имя хоста в URL/SNI/Host — upstreamHost (*.won.onl).
 * Формат: "host:port:ip" для CURLOPT_RESOLVE.
 */
function buildCurlResolve(string $upstreamHost, array $config): ?string
{
    $ip = trim((string) ($config['upstream_direct_ip'] ?? ''));
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    $useHttp = !empty($config['upstream_use_http']);
    $defaultPort = $useHttp ? 80 : 443;
    $port = isset($config['upstream_direct_port']) ? (int) $config['upstream_direct_port'] : $defaultPort;
    if ($port < 1 || $port > 65535) {
        $port = $defaultPort;
    }

    return $upstreamHost . ':' . $port . ':' . $ip;
}

function isCurlSslError(int $errno): bool
{
    return in_array($errno, [35, 51, 58, 60, 77], true);
}

/**
 * @return array{body: string|false, curl_errno: int, curl_error: string, status: int, content_type: string, response_headers: string[]}
 */
function executeUpstreamCurl(
    string $url,
    string $incomingHost,
    string $upstreamHost,
    string $clientIp,
    array $config,
    ?string $resolveLine,
    string $requestBody
): array {
    $ch = curl_init($url);

    if ($ch === false) {
        respondWithError($config, 500, 'Не удалось инициализировать cURL', []);
    }

    $requestHeaders = buildUpstreamHeaders($incomingHost, $upstreamHost, $clientIp, $config);
    $responseHeaders = [];

    $curlOpts = [
        CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
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
    ];

    if ($resolveLine !== null) {
        $curlOpts[CURLOPT_RESOLVE] = [$resolveLine];
    }

    curl_setopt_array($ch, $curlOpts);

    if ($requestBody !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    }

    $body = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'body' => $body,
        'curl_errno' => $curlErrno,
        'curl_error' => $curlError,
        'status' => $status,
        'content_type' => $contentType,
        'response_headers' => $responseHeaders,
    ];
}

function proxyRequest(string $url, string $incomingHost, string $upstreamHost, string $clientIp, array $config): array
{
    $rawBody = file_get_contents('php://input');
    $requestBody = $rawBody !== false ? (string) $rawBody : '';

    if (isWebSocketHandshake()) {
        // PHP+cURL cannot transparently tunnel upgraded WebSocket frames after handshake.
        respondWithError($config, 501, 'WebSocket через этот PHP-прокси не поддерживается', [
            'hint' => 'Нужен Nginx/OpenResty с proxy_pass на upstream или отдельный прокси.',
        ]);
    }

    $resolveLine = buildCurlResolve($upstreamHost, $config);
    $result = executeUpstreamCurl($url, $incomingHost, $upstreamHost, $clientIp, $config, $resolveLine, $requestBody);

    $failed = $result['body'] === false || $result['status'] === 0;

    if (
        $failed
        && empty($config['upstream_use_http'])
        && !empty($config['retry_upstream_http_on_ssl_failure'])
        && $result['body'] === false
        && isCurlSslError($result['curl_errno'])
    ) {
        $cfgHttp = array_merge($config, [
            'upstream_use_http' => true,
            'upstream_direct_port' => (int) ($config['retry_http_port'] ?? 80),
        ]);
        $urlHttp = buildUpstreamUrl($upstreamHost, $cfgHttp);
        $resolveHttp = buildCurlResolve($upstreamHost, $cfgHttp);
        $resultHttp = executeUpstreamCurl($urlHttp, $incomingHost, $upstreamHost, $clientIp, $cfgHttp, $resolveHttp, $requestBody);

        if ($resultHttp['body'] !== false && $resultHttp['status'] !== 0) {
            return [
                'status' => $resultHttp['status'],
                'headers' => $resultHttp['response_headers'],
                'body' => $resultHttp['body'],
                'content_type' => $resultHttp['content_type'],
            ];
        }

        respondWithError($config, 502, 'Нет ответа от upstream (HTTPS и HTTP fallback)', [
            'https_upstream_url' => $url,
            'https_curl_resolve' => $resolveLine ?? '(DNS как обычно)',
            'https_curl_errno' => $result['curl_errno'],
            'https_curl_error' => $result['curl_error'],
            'http_upstream_url' => $urlHttp,
            'http_curl_resolve' => $resolveHttp ?? '(DNS как обычно)',
            'http_curl_errno' => $resultHttp['curl_errno'],
            'http_curl_error' => $resultHttp['curl_error'],
            'hint' => 'Проверьте SSL-режим Cloudflare (Flexible → HTTP:80) и что origin принимает прямые запросы не только от CF.',
        ]);
    }

    if ($result['body'] === false) {
        // 28 = CURLE_OPERATION_TIMEDOUT
        $httpCode = $result['curl_errno'] === 28 ? 504 : 502;
        respondWithError($config, $httpCode, 'Нет ответа от upstream (ошибка cURL)', [
            'upstream_url' => $url,
            'curl_resolve' => $resolveLine ?? '(DNS как обычно)',
            'curl_errno' => $result['curl_errno'],
            'curl_error' => $result['curl_error'],
            'hint' => curlErrorHint($result['curl_errno'], $config, $result['curl_error']),
        ]);
    }

    if ($result['status'] === 0) {
        respondWithError($config, 502, 'Upstream вернул HTTP 0 (соединение оборвано или TLS сбой)', [
            'upstream_url' => $url,
            'curl_resolve' => $resolveLine ?? '(DNS как обычно)',
            'curl_errno' => $result['curl_errno'],
            'curl_error' => $result['curl_error'] ?: '(пусто)',
            'hint' => curlErrorHint($result['curl_errno'], $config, $result['curl_error']),
        ]);
    }

    return [
        'status' => $result['status'],
        'headers' => $result['response_headers'],
        'body' => $result['body'],
        'content_type' => $result['content_type'],
    ];
}

function buildUpstreamHeaders(string $incomingHost, string $upstreamHost, string $clientIp, array $config): array
{
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $forwardedFor = trim($forwardedFor);
    $forwardedFor = $forwardedFor !== '' ? $forwardedFor . ', ' . $clientIp : $clientIp;

    $fromClient = [];
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
            'x-forwarded-public-host',
            'x-real-ip',
            'forwarded',
            'x-original-host',
        ], true)) {
            continue;
        }

        // Браузер шлёт Origin/Referer с won-onl.ru — часть хостингов по ним выбирает vhost.
        if (in_array($lowerName, ['origin', 'referer'], true)) {
            $value = rewritePublicUrlsToUpstream($value, $config);
        }

        $fromClient[] = $name . ': ' . $value;
    }

    $hostHeader = $config['preserve_original_host_header'] ? $incomingHost : $upstreamHost;

    // Минимум как у прямого HTTPS к ru.won.onl: только Host (и SNI из URL). Без X-Forwarded-Host/Forwarded —
    // иначе LiteSpeed/FastPanel иногда выбирают vhost не по Host и отдают заглушку.
    $core = [
        'Host: ' . $hostHeader,
        'X-Forwarded-For: ' . $forwardedFor,
        'X-Real-IP: ' . $clientIp,
        'X-Forwarded-Proto: https',
    ];

    if (($config['mimic_cloudflare_to_origin'] ?? true)) {
        // Как у Cloudflare к origin: реальный IP посетителя и схема (часть бэкендов ожидают именно CF-*).
        array_splice($core, 1, 0, [
            'CF-Connecting-IP: ' . $clientIp,
            'CF-Visitor: {"scheme":"https"}',
        ]);
        $cc = isset($config['mimic_cf_ip_country']) ? trim((string) $config['mimic_cf_ip_country']) : '';
        if ($cc !== '' && strlen($cc) === 2) {
            array_splice($core, 3, 0, ['CF-IPCountry: ' . strtoupper($cc)]);
        }
    }

    if (!empty($config['upstream_extra_forward_headers'])) {
        array_splice($core, 1, 0, [
            'X-Forwarded-Host: ' . $hostHeader,
            'Forwarded: for="' . $clientIp . '";host="' . $hostHeader . '";proto=https',
        ]);
    }

    if (!empty($config['send_public_host_header']) && $incomingHost !== $upstreamHost) {
        $core[] = 'X-Forwarded-Public-Host: ' . $incomingHost;
    }

    return array_merge($core, $fromClient);
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

    if ($contentType === '' && !empty($response['content_type'])) {
        $contentType = trim((string) $response['content_type']);
    }

    $body = $response['body'];

    if (
        is_string($body)
        && $body === ''
        && $response['status'] >= 200
        && $response['status'] < 300
        && !empty($config['show_debug_errors'])
    ) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Прокси: пустой ответ</title></head><body>';
        echo '<h1>Пустой ответ от origin</h1>';
        echo '<p>Upstream host: <code>' . htmlspecialchars($upstreamHost, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
        echo '<p>Задайте в <code>config.php</code> прямой <code>upstream_direct_ip</code> (обход Cloudflare из РФ), проверьте SSL (upstream_use_http) и что на origin есть vhost для этого имени.</p>';
        echo '</body></html>';

        return;
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

    $rewriteOk = $config['rewrite_response_bodies']
        && is_string($body)
        && strlen($body) <= (int) $config['rewrite_body_max_bytes']
        && (shouldRewriteBody($contentType) || bodyLooksLikeRewritableWithoutContentType($body, $contentType));

    if ($rewriteOk) {
        $body = rewriteBody($body, $incomingHost, $upstreamHost, $config);
    }

    if ($contentType === '' && is_string($body) && strlen($body) > 0 && preg_match('/^\s*</', $body)) {
        header('Content-Type: text/html; charset=utf-8', true);
    }

    header('Content-Length: ' . strlen((string) $body), true);
    echo $body;
}

function bodyLooksLikeRewritableWithoutContentType(string $body, string $contentType): bool
{
    if ($contentType !== '') {
        return false;
    }

    $trim = ltrim(substr($body, 0, 4096));

    return str_starts_with($trim, '<')
        || str_starts_with($trim, '{')
        || str_starts_with($trim, '/');
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
    $upstreamBase = $config['upstream_base_domain'];

    $rewritten = preg_replace_callback(
        '/https:\/\/([a-z0-9.-]+\.)?' . preg_quote($upstreamBase, '/') . '([\/?#][^\s]*)?/i',
        static function (array $matches) use ($config): string {
            $sub = $matches[1] ?? '';
            $pathSuffix = $matches[2] ?? '';
            $hostOnOrigin = $sub === ''
                ? $config['upstream_base_domain']
                : rtrim($sub, '.') . '.' . $config['upstream_base_domain'];
            $public = publicHostForUpstreamHostname($hostOnOrigin, $config);

            return 'https://' . $public . $pathSuffix;
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
        static function (array $matches) use ($config): string {
            $sub = $matches[1] ?? '';
            $hostOnOrigin = $sub === ''
                ? $config['upstream_base_domain']
                : rtrim($sub, '.') . '.' . $config['upstream_base_domain'];
            $public = publicHostForUpstreamHostname($hostOnOrigin, $config);

            return 'https://' . $public;
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

function respondWithError(array $config, int $status, string $message, array $details = []): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    $show = !empty($config['show_debug_errors']);
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Прокси: ошибка</title>';
    echo '<style>body{font-family:system-ui,sans-serif;margin:2rem;background:#1a1a1a;color:#eee;max-width:52rem}';
    echo 'h1{color:#f66;font-size:1.25rem}code,pre{background:#333;padding:.2em .4em;border-radius:4px;white-space:pre-wrap;word-break:break-word}';
    echo 'p.detail{margin:.5rem 0;color:#ccc}.hint{color:#9cf}</style></head><body>';
    echo '<h1>' . $safeMessage . '</h1>';
    echo '<p><strong>HTTP ' . (int) $status . '</strong></p>';

    if ($show && $details !== []) {
        echo '<p class="detail"><strong>Детали:</strong></p><pre>';
        foreach ($details as $k => $v) {
            if (is_scalar($v) || $v === null) {
                echo htmlspecialchars((string) $k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': ';
                echo htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
            }
        }
        echo '</pre>';
    } elseif (!$show) {
        echo '<p class="detail">Подробности отключены (<code>show_debug_errors</code> в config.php).</p>';
    }

    echo '<p class="hint">Если видите пустую страницу без этого текста — проверьте версию PHP (нужен 8+) и логи веб-сервера.</p>';
    echo '</body></html>';
    exit;
}

function curlErrorHint(int $curlErrno, array $config, string $curlError = ''): string
{
    // Коды libcurl числами: на части хостингов константы CURLE_* в PHP не определены.
    // 6 resolve, 7 connect, 28 timeout, 35 SSL connect, 51/58/60/77 — типичные SSL/cert.
    $sslRelated = [35, 51, 58, 60, 77];
    if (in_array($curlErrno, $sslRelated, true)) {
        $verifyOn = !empty($config['verify_upstream_tls']);

        if (!$verifyOn) {
            return 'Проверка TLS к upstream отключена, но ошибка SSL всё равно есть — проверьте upstream_direct_port, upstream_use_http и что на IP слушается ожидаемый протокол.';
        }

        if (!empty($config['upstream_direct_ip'])) {
            return 'Прямое подключение к IP: на origin часто самоподписанный или «чужой» сертификат. В config.php задайте verify_upstream_tls => false или поставьте на origin валидный TLS для имён *.won.onl.';
        }

        return 'Проблема TLS/сертификата upstream. Для отладки: verify_upstream_tls => false в config.php; в проде — валидный сертификат на стороне origin.';
    }
    if ($curlErrno === 6) {
        if (!empty($config['upstream_direct_ip'])) {
            return 'Ошибка резолва при CURLOPT_RESOLVE — проверьте upstream_direct_ip и имя хоста.';
        }

        return 'DNS не резолвит upstream с этого сервера — проверьте сетевой доступ хостинга к won.onl.';
    }
    if ($curlErrno === 28) {
        return 'Таймаут — upstream не ответил за ' . (int) ($config['timeout'] ?? 60) . ' с.';
    }
    if ($curlErrno === 7) {
        return 'Не удалось установить TCP-соединение с upstream (файрвол, блокировка, неверный порт).';
    }

    return 'См. curl_errno в деталях выше и документацию cURL.';
}
