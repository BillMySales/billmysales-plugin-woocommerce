<?php

declare(strict_types=1);

/**
 * BillMySales for WooCommerce.
 *
 * Copyright (c) 2026 BillMySales <https://www.billmysales.com>
 * Licensed under the GNU Affero General Public License v3.0 or later.
 * See LICENSE file for more details.
 */

/**
 * End-to-end tests: webhook receiver (stands in for BillMySales). Run with
 * PHP's built-in server; stores every request as received, named after the
 * test case (/e2e/case) and the time: the raw body in <name>.body (to
 * recompute the signature) and the headers, the decoded payload and the
 * status answered in <name>.json; and
 * answers with the HTTP status in /e2e/respond (200 when missing), so a
 * test can simulate BillMySales failing.
 */

$dir = '/e2e/webhooks';
$respond = is_file('/e2e/respond') ? (int) trim((string) file_get_contents('/e2e/respond')) : 200;
$status = $respond >= 100 ? $respond : 200;

$body = (string) file_get_contents('php://input');
$case = is_file('/e2e/case') ? trim((string) file_get_contents('/e2e/case')) : '00';
$name = sprintf('%s/%s-%.6f', $dir, $case, microtime(true));
file_put_contents($name . '.body', $body);
file_put_contents($name . '.json', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => getallheaders(),
    'responded' => $status,
    'payload' => json_decode($body, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

http_response_code($status);
echo $status === 200 ? 'ok' : 'error';
