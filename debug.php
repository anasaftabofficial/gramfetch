<?php
/**
 * Diagnostics page. Works only on localhost (XAMPP), never on the live site.
 * Open: http://localhost/gramfetch/debug.php?url=INSTAGRAM_LINK
 */
declare(strict_types=1);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/api/_lib/config.php';
require __DIR__ . '/api/_lib/instagram.php';

$url = trim((string) ($_GET['url'] ?? ''));
header('Content-Type: text/html; charset=utf-8');

// Clear cached tokens/results so every run is fresh
foreach (array_merge(glob(IG_CACHE_DIR . '/c_*.json') ?: [], glob(IG_CACHE_DIR . '/debug-*.html') ?: []) as $f) {
    @unlink($f);
}
$GLOBALS['IG_DEBUG_DUMP'] = true;

$result = null;
$error  = null;
if ($url !== '') {
    try {
        $result = ig_resolve($url, $_GET['type'] ?? 'video', $config);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$checks = [
    'PHP version'      => PHP_VERSION,
    'curl extension'   => extension_loaded('curl') ? 'yes' : 'NO (enable extension=curl in php.ini)',
    'curl SSL version' => function_exists('curl_version') ? (curl_version()['ssl_version'] ?? '?') : '-',
    'cacert.pem in api/_lib'=> is_file(__DIR__ . '/api/_lib/cacert.pem') ? 'yes' : 'no',
    'cache writable'   => is_writable(cache_dir()) ? 'yes' : 'NO',
    'proxy set'        => $config['http_proxy'] ? 'yes' : 'no',
];
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>GramFetch debug</title>
<style>
body{font:14px/1.5 system-ui,sans-serif;max-width:1000px;margin:24px auto;padding:0 16px;color:#17132b}
input{width:75%;padding:8px}button{padding:8px 14px}
table{border-collapse:collapse;width:100%;margin:12px 0}td,th{border:1px solid #ddd;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f4f2fa}.ok{color:#138a52;font-weight:700}.bad{color:#c62d3a;font-weight:700}
code{font-size:12px;word-break:break-all}
</style></head><body>
<h1>GramFetch debug</h1>
<form><input name="url" value="<?= htmlspecialchars($url) ?>" placeholder="Instagram link"> <button>Test</button></form>

<h2>Server</h2>
<table><?php foreach ($checks as $k => $v): ?><tr><th><?= $k ?></th><td><?= htmlspecialchars((string) $v) ?></td></tr><?php endforeach; ?></table>

<?php if ($url !== ''): ?>
<h2>Result</h2>
<?php if ($error): ?>
  <p class="bad"><?= htmlspecialchars($error) ?></p>
<?php else: ?>
  <p class="ok">Success: <?= count($result['items']) ?> item(s) found</p>
<?php endif; ?>

<h2>Requests to Instagram</h2>
<table>
<tr><th>Step</th><th>Status</th><th>Response preview</th></tr>
<?php foreach ($GLOBALS['IG_DEBUG'] as $d): ?>
<tr>
  <td><?= htmlspecialchars($d['step']) ?></td>
  <td class="<?= $d['status'] === 200 ? 'ok' : 'bad' ?>"><?= $d['status'] ?: 'failed' ?> <?= htmlspecialchars($d['note']) ?></td>
  <td><code><?= htmlspecialchars($d['preview']) ?></code></td>
</tr>
<?php endforeach; ?>
</table>
<?php $dumps = glob(IG_CACHE_DIR . '/debug-*.html') ?: []; if ($dumps): ?>
<h2>Saved responses</h2>
<p>These files are saved on your computer in the <code>gramfetch\cache</code> folder. If the result is not a success, send them along with a screenshot.</p>
<ul><?php foreach ($dumps as $d): ?><li><code><?= htmlspecialchars(basename($d)) ?></code> (<?= round(filesize($d) / 1024) ?> KB)</li><?php endforeach; ?></ul>
<?php endif; ?>
<p>Take a screenshot of this page and share it if the result is not a success.</p>
<?php endif; ?>
</body></html>
