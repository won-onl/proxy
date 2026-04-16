<?php

declare(strict_types=1);

/*
 * Размещение: прокси (этот код) — на РФ-хостинге, домен won-onl.ru.
 * Целевая игра won.onl — на другом сервере; origin не знает про won-onl.ru. В запросе к
 * origin: TCP на upstream_direct_ip (минуя DNS Cloudflare), в TLS/HTTP — только имя *.won.onl
 * (URL, SNI, Host). Без «лишних» заголовков, из‑за которых панель путает vhost.
 *
 * Cloudflare → origin (IP задаётся только upstream_direct_ip в вашей локальной копии):
 * - SSL «Flexible»: CF ходит на origin по HTTP:80 — прямой HTTPS к :443 часто даёт самоподпись;
 *   включён retry на HTTP (retry_upstream_http_on_ssl_failure) или вручную upstream_use_http.
 * - «Full» / «Full (strict)»: CF → origin по HTTPS:443 — оставьте upstream_use_http = false.
 *
 * Если на origin включён «только IP Cloudflare» / Authenticated Origin Pulls — прямой запрос
 * с РФ-хостинга может блокироваться (не только SSL).
 */

return [
    // The upstream zone that hosts the real game (Host / SNI / переписывание ссылок).
    'upstream_base_domain' => 'won.onl',

    // IP сервера игры (origin). DNS won.onl указывает на Cloudflare — curl по имени попал бы на CF.
    // Здесь задаётся прямой IP + CURLOPT_RESOLVE: TCP на IP, SNI/Host = *.won.onl (как CF к origin).
    'upstream_direct_ip' => '5.45.116.77',

    // Без валидного IP прокси не стартует (иначе резолв уйдёт в CF).
    'require_upstream_direct_ip' => true,

    // false: на origin только Host + IP клиента + X-Forwarded-Proto (как почти прямой браузер к *.won.onl).
    // true: добавить X-Forwarded-Host и Forwarded (часть панелей из‑за них ломают выбор сайта).
    'upstream_extra_forward_headers' => false,

    // Добавить к запросу на origin заголовки в духе Cloudflare → origin (CF-Connecting-IP, CF-Visitor и т.д.).
    // User-Agent / Sec-* / Accept-* по-прежнему берутся из браузера (getallheaders). Полностью «как CF»
    // по TLS/JA3 невозможно из PHP+cURL — только по HTTP-заголовкам.
    'mimic_cloudflare_to_origin' => true,

    // Двухбуквенный код страны для CF-IPCountry (если пусто — заголовок не шлём).
    'mimic_cf_ip_country' => '',

    // Порт upstream (443 для HTTPS, 80 для HTTP если включён upstream_use_http).
    'upstream_direct_port' => 443,

    // Ходить на origin по HTTP (как Cloudflare Flexible: только порт 80 на сервер).
    'upstream_use_http' => false,

    // Если HTTPS к origin падает с ошибкой TLS (часто при Flexible), один раз повторить запрос по HTTP.
    'retry_upstream_http_on_ssl_failure' => true,

    // Порт для повтора по HTTP (обычно 80).
    'retry_http_port' => 80,

    // Публичный корень зеркала (без поддомена в браузере).
    'public_base_domain' => 'won-onl.ru',

    // Поддомен на origin для запросов к голому won-onl.ru → {sub}.won.onl (например ru → ru.won.onl).
    // Пустая строка: корень ведёт на голый won.onl (старое поведение).
    'public_apex_upstream_subdomain' => 'ru',

    // Алиасы того же upstream, что и корень (как won-onl.ru).
    'public_host_aliases' => [
        'www.won-onl.ru',
    ],

    // If true, requests to the bare public domain will proxy to apex upstream (см. public_apex_upstream_subdomain).
    'allow_root_domain' => true,

    // If set, unknown or malformed hosts fall back to this upstream host.
    // Set to null to reject such requests with 400.
    'fallback_upstream_host' => null,

    // Проверка TLS к upstream. При подключении по upstream_direct_ip origin часто отдаёт
    // самоподписанный или «не тот» сертификат (обход Cloudflare) — тогда оставьте false.
    // Поставьте true, если на IP висит валидный сертификат на нужные имена *.won.onl.
    'verify_upstream_tls' => false,

    // Upstream request timeout in seconds.
    'timeout' => 60,

    // Enable response body rewriting for text formats.
    'rewrite_response_bodies' => true,

    // Maximum body size to rewrite in-memory, in bytes.
    'rewrite_body_max_bytes' => 2 * 1024 * 1024,

    // true = слать на origin Host: won-onl.ru (сломает vhost на *.won.onl). Держите false.
    'preserve_original_host_header' => false,

    // Если true — на origin уйдёт X-Forwarded-Public-Host с won-onl.ru (origin «узнает» зеркало).
    // Обычно false: сервер должен думать, что обратились только к *.won.onl.
    'send_public_host_header' => false,

    // Show detailed proxy errors (curl code, URL, hints). Disable in production after debugging.
    'show_debug_errors' => true,

    // On fatal PHP errors, try to output a visible HTML page (needs show_debug_errors or always on for fatals).
    'show_fatal_errors' => true,
];
