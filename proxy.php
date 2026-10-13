<?php
/**
 * Streams a file from Instagram's CDN to the visitor so the browser saves it
 * as a real file (a plain link would just open the video in a new tab).
 * Only Instagram / Facebook CDN hosts are allowed, so this cannot be abused
 * as an open proxy.
 */
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

$url    = (string) ($_GET['url'] ?? '');
$inline = isset($_GET['inline']);
$name   = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['name'] ?? 'instagram'));
$name   = substr($name ?: 'instagram', 0, 60);

$parts = parse_url($url);
$host  = strtolower($parts['host'] ?? '');
if (($parts['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)(cdninstagram\.com|fbcdn\.net)$/', $host)) {
    http_response_code(400);
    exit('Invalid file link.');
}

@set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

$ctype = 'application/octet-stream';
$len   = null;
$sent  = false;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_USERAGENT      => $config['user_agent'],
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$ctype, &$len) {
        if (stripos($h, 'HTTP/') === 0) {
            $len = null; // new response block after a redirect
        } elseif (stripos($h, 'Content-Type:') === 0) {
            $ctype = trim(substr($h, 13));
        } elseif (stripos($h, 'Content-Length:') === 0) {
            $len = (int) trim(substr($h, 15));
        }
        return strlen($h);
    },
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$sent, &$ctype, &$len, $name, $inline) {
        if (!$sent) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code !== 200 || !preg_match('#^(image|video)/#', $ctype)) {
                return -1; // abort
            }
            $ext = [
                'video/mp4' => 'mp4', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
                'image/png' => 'png', 'image/heic' => 'heic',
            ][strtok($ctype, ';')] ?? 'bin';

            header('Content-Type: ' . $ctype);
            if ($len) {
                header('Content-Length: ' . $len);
            }
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '.' . $ext . '"');
            header('Cache-Control: private, max-age=600');
            header('X-Content-Type-Options: nosniff');
            $sent = true;
        }
        echo $data;
        flush();
        return strlen($data);
    },
]);
if (!empty($config['http_proxy'])) {
    curl_setopt($ch, CURLOPT_PROXY, $config['http_proxy']);
}
curl_exec($ch);
curl_close($ch);

if (!$sent) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not fetch this file. The link may have expired. Go back and search again.';
}
