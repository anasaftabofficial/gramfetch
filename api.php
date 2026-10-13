<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/instagram.php';

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$url  = trim((string) ($_POST['url'] ?? $_GET['url'] ?? ''));
$type = preg_replace('/[^a-z]/', '', strtolower((string) ($_POST['type'] ?? $_GET['type'] ?? 'video')));

if ($url === '') {
    out(['ok' => false, 'error' => 'Please paste an Instagram link first.'], 400);
}
if (strlen($url) > 500) {
    out(['ok' => false, 'error' => 'That link is too long.'], 400);
}

$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rate_limit_ok($ip, (int) $config['rate_limit'], (int) $config['rate_window'])) {
    out(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes and try again.'], 429);
}

try {
    $result = ig_resolve($url, $type, $config);
    out(['ok' => true] + $result);
} catch (IgException $e) {
    $code = $e->getCode();
    out(['ok' => false, 'error' => $e->getMessage()], ($code >= 400 && $code < 600) ? $code : 400);
} catch (Throwable $e) {
    error_log('[gramfetch] ' . $e->getMessage());
    out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
