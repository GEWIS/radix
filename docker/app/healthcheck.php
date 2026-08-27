<?php

declare(strict_types=1);

/**
 * Caddy is serving and the application behind it is healthy. Both are asked, because the admin API answers whether
 * or not PHP can serve, and /health answers whether or not Caddy is still listening on the port the proxy uses.
 *
 * /health is asked over 127.0.0.1 with the Host header the site block answers to, so a probe needs no DNS.
 */

$get = static function (string $url, int $timeout, array $header = []): string|false {
    return @file_get_contents($url, context: stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => $header,
        ],
    ]));
};

if (false === $get('http://localhost:2019/metrics', 5)) {
    exit(1);
}

exit(str_contains((string) $get('http://127.0.0.1/health', 20, ['Host: app']), '"healthy":true') ? 0 : 1);
