<?php

declare(strict_types=1);

// Web/PHP liveness only: deliberately does not boot Symfony, query DB or start a session.
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

if ('HEAD' !== $method) {
    echo "ok\n";
}
