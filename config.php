<?php

declare(strict_types=1);

return [
    // The upstream zone that hosts the real game.
    'upstream_base_domain' => 'won.onl',

    // The public RU domain that points to this proxy.
    // Example: mirror.ru
    'public_base_domain' => 'won-onl.ru',

    // If true, requests to the bare public domain will proxy to the bare upstream domain.
    'allow_root_domain' => true,

    // If set, unknown or malformed hosts fall back to this upstream host.
    // Set to null to reject such requests with 400.
    'fallback_upstream_host' => null,

    // TLS verification for upstream HTTPS.
    'verify_upstream_tls' => true,

    // Upstream request timeout in seconds.
    'timeout' => 60,

    // Enable response body rewriting for text formats.
    'rewrite_response_bodies' => true,

    // Maximum body size to rewrite in-memory, in bytes.
    'rewrite_body_max_bytes' => 2 * 1024 * 1024,

    // Pass the incoming Host header to upstream instead of upstream host.
    // Usually should remain false.
    'preserve_original_host_header' => false,
];
