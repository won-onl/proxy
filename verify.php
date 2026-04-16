<?php

declare(strict_types=1);

/**
 * https://won-onl.ru/verify.php — проверка, что запрос попал на РФ-хостинг с прокси.
 *
 * Диагностика origin: как целевой сервер отвечает на запрос «как у прокси» (порты, заголовки, превью).
 * Параметры:
 *   path=/   — путь на origin (по умолчанию /)
 *   key=…    — если в config.php задан verify_debug_secret, без верного key отчёт не покажется.
 */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Нужен PHP 8.0+\n";
    exit(1);
}

if (!extension_loaded('curl')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Нужно расширение cURL\n";
    exit(1);
}

define('PROXY_SKIP_MAIN', true);
require __DIR__ . '/proxy.php';

$config = require __DIR__ . '/config.php';

$secret = trim((string) ($config['verify_debug_secret'] ?? ''));
if ($secret !== '' && (string) ($_GET['key'] ?? '') !== $secret) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "403: укажите ?key=… как в verify_debug_secret в config.php, или очистите verify_debug_secret.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$incomingHost = getIncomingHost();
$upstreamHost = resolveUpstreamHost($incomingHost, $config);
$clientIp = getClientIp();
$path = isset($_GET['path']) ? (string) $_GET['path'] : '/';
$previewLen = isset($_GET['preview']) ? max(200, min(8000, (int) $_GET['preview'])) : 2000;

echo "proxy_wononl_ru_ok\n";
echo 'mirror_HTTP_HOST=' . $incomingHost . "\n";
echo 'mirror_REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo 'mirror_REMOTE_ADDR=' . $clientIp . "\n\n";

if ($upstreamHost === null) {
    echo "ОШИБКА: Host не сопоставлён с upstream (проверьте public_base_domain / DNS).\n";
    exit;
}

echo 'mapped_upstream_host=' . $upstreamHost . "\n";
echo 'upstream_direct_ip=' . ($config['upstream_direct_ip'] ?? '') . "\n";
echo 'https_ports_priority=' . implode(', ', array_map('strval', upstreamHttpsPortPriority($config))) . "\n";
echo 'retry_http_port=' . (int) ($config['retry_http_port'] ?? 80) . "\n";
echo 'probe_path=' . $path . "\n\n";

$attempts = upstreamDebugProbe($config, $incomingHost, $upstreamHost, $clientIp, $path, $previewLen);

foreach ($attempts as $i => $a) {
    $n = $i + 1;
    echo "========== Попытка {$n}: {$a['label']} ==========\n";
    echo 'URL: ' . ($a['url'] ?? '') . "\n";
    echo 'CURLOPT_RESOLVE: ' . ($a['curl_resolve'] ?? '(нет)') . "\n";

    if (isset($a['error'])) {
        echo 'ERROR: ' . $a['error'] . "\n\n";
        continue;
    }

    echo "---- Заголовки запроса к origin (как шлёт прокси) ----\n";
    foreach ($a['request_headers'] as $line) {
        echo $line . "\n";
    }

    echo "\n---- Ответ origin ----\n";
    echo 'curl_errno=' . ($a['curl_errno'] ?? '') . ' curl_error=' . ($a['curl_error'] ?? '') . "\n";
    echo 'HTTP=' . ($a['http_status'] ?? 0) . ' Content-Type=' . ($a['content_type'] ?? '') . "\n";
    echo 'TCP primary_ip=' . ($a['primary_ip'] ?? '') . ' primary_port=' . ($a['primary_port'] ?? 0) . "\n";
    echo 'TLS ssl_verify_result=' . ($a['ssl_verify_result'] ?? 0) . " (0 = OK при включённой проверке)\n";

    if (!empty($a['hint'])) {
        echo 'hint: ' . $a['hint'] . "\n";
    }

    echo 'похоже_на_заглушку_FastPanel=' . (!empty($a['hint_fastpanel_placeholder']) ? 'да' : 'нет') . "\n";

    echo "\n---- Заголовки ответа (что вернул сервер) ----\n";
    foreach ($a['response_headers'] as $rh) {
        echo $rh . "\n";
    }

    echo "\n---- Превью тела (первые байты) ----\n";
    echo 'body_length=' . ($a['body_length'] ?? 0) . "\n";
    echo ($a['body_preview'] ?? '') . "\n\n";
}

echo "========== Подсказка ==========\n";
echo "Если HTTPS = заглушка FASTPANEL, а HTTP = реальный сайт: на origin для :443 часто отдаётся default/static, а vhost игры на :80.\n";
echo "Прокси по умолчанию делает retry на HTTP (retry_upstream_http_on_fastpanel_placeholder в config.php).\n";
echo "Правильное исправление на сервере: в nginx на origin одинаковый root/server_name для ru.won.onl на :443 и :80.\n";
echo "Если везде заглушка: на IP нет vhost для Host=" . $upstreamHost . ".\n";
echo "Смена path: ?path=/game/\n";
