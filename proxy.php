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

    // www и прочие алиасы → тот же upstream, что у корня (won.onl), не www.won.onl.
    if (in_array($incomingHost, $aliases, true)) {
        return $config['allow_root_domain'] ? $upstreamBase : $config['fallback_upstream_host'];
    }

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

function proxyRequest(string $url, string $incomingHost, string $upstreamHost, string $clientIp, array $config): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        respondWithError($config, 500, 'Не удалось инициализировать cURL', []);
    }

    $requestHeaders = buildUpstreamHeaders($incomingHost, $upstreamHost, $clientIp, $config);
    $responseHeaders = [];

    $resolveLine = buildCurlResolve($upstreamHost, $config);
    $curlOpts = [
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
    ];

    if ($resolveLine !== null) {
        $curlOpts[CURLOPT_RESOLVE] = [$resolveLine];
    }

    curl_setopt_array($ch, $curlOpts);

    $requestBody = file_get_contents('php://input');
    if ($requestBody !== false && $requestBody !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    }

    if (isWebSocketHandshake()) {
        // PHP+cURL cannot transparently tunnel upgraded WebSocket frames after handshake.
        respondWithError($config, 501, 'WebSocket через этот PHP-прокси не поддерживается', [
            'hint' => 'Нужен Nginx/OpenResty с proxy_pass на upstream или отдельный прокси.',
        ]);
    }

    $body = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) {
        // 28 = CURLE_OPERATION_TIMEDOUT (число — на части хостингов константа не объявлена)
        $httpCode = $curlErrno === 28 ? 504 : 502;
        respondWithError($config, $httpCode, 'Нет ответа от upstream (ошибка cURL)', [
            'upstream_url' => $url,
            'curl_resolve' => $resolveLine ?? '(DNS как обычно)',
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'hint' => curlErrorHint($curlErrno, $config, $curlError),
        ]);
    }

    if ($status === 0) {
        respondWithError($config, 502, 'Upstream вернул HTTP 0 (соединение оборвано или TLS сбой)', [
            'upstream_url' => $url,
            'curl_resolve' => $resolveLine ?? '(DNS как обычно)',
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError ?: '(пусто)',
            'hint' => curlErrorHint($curlErrno, $config, $curlError),
        ]);
    }

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
