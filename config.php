<?php

declare(strict_types=1);

/*
 * Хостинг: в панели (LiteSpeed/cPanel) добавьте домен won-onl.ru к ЭТОМУ каталогу (public_html),
 * включите SSL для домена. Иначе будет страница «Why am I seeing this page?» — веб-сервер не
 * отдаёт site/PHP для этого Host, прокси не запускается.
 */

return [
    // The upstream zone that hosts the real game (Host / SNI / переписывание ссылок).
    'upstream_base_domain' => 'won.onl',

    // Прямой IP origin-сервера (обход Cloudflare/DNS). Соединение идёт на этот адрес,
    // при этом curl использует имя upstreamHost в URL и SNI — как будто запрос к *.won.onl.
    // Оставьте null, чтобы ходить на upstream по обычному DNS (через CF).
    'upstream_direct_ip' => '5.45.116.77',

    // Порт upstream (443 для HTTPS, 80 для HTTP если включён upstream_use_http).
    'upstream_direct_port' => 443,

    // Ходить на origin по HTTP (без TLS), resolve всё равно на upstream_direct_ip.
    'upstream_use_http' => false,

    // Основной домен в браузере (без поддомена = как won.onl на origin).
    'public_base_domain' => 'won-onl.ru',

    // Дополнительные имена, которые ведут на тот же upstream, что и корень (apex → won.onl).
    // Например www часто не создают отдельным сайтом в панели.
    'public_host_aliases' => [
        'www.won-onl.ru',
    ],

    // If true, requests to the bare public domain will proxy to the bare upstream domain.
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

    // Pass the incoming Host header to upstream instead of upstream host.
    // Usually should remain false.
    'preserve_original_host_header' => false,

    // Show detailed proxy errors (curl code, URL, hints). Disable in production after debugging.
    'show_debug_errors' => true,

    // On fatal PHP errors, try to output a visible HTML page (needs show_debug_errors or always on for fatals).
    'show_fatal_errors' => true,
];
