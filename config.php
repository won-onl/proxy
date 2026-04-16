<?php

declare(strict_types=1);

/*
 * Размещение: прокси (этот код) — на РФ-хостинге, домен won-onl.ru.
 * Целевая игра won.onl — на другом сервере; origin не знает про won-onl.ru. В запросе к
 * origin должны быть только имена *.won.onl (Host, SNI, URL, X-Forwarded-Host, Referer…).
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

    // Прямой IP origin-сервера (обход Cloudflare/DNS). Соединение идёт на этот адрес,
    // при этом curl использует имя upstreamHost в URL и SNI — как будто запрос к *.won.onl.
    // Прямой IP origin (без Cloudflare). Не коммить реальный адрес в публичный репозиторий.
    // Оставьте null — тогда будет обычный DNS (часто через CF). Из РФ часто «пустая» страница
    // без прямого IP: запрос к CF режется или ответ пустой — задайте IP origin локально.
    'upstream_direct_ip' => '5.45.116.77',

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
