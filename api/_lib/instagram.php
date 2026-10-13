<?php
declare(strict_types=1);

class IgException extends RuntimeException {}

// Vercel's filesystem is read-only except /tmp
define('IG_CACHE_DIR', getenv('VERCEL') ? sys_get_temp_dir() . '/gramfetch' : __DIR__ . '/../../cache');

/** Steps recorded for debug.php */
$GLOBALS['IG_DEBUG'] = [];

function ig_log(string $step, int $code, string $body, string $note = ''): void
{
    $GLOBALS['IG_DEBUG'][] = [
        'step'    => $step,
        'status'  => $code,
        'note'    => $note,
        'preview' => mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 300),
    ];
}

/* ---------------------------------------------------------------
 * HTTP
 * ------------------------------------------------------------- */
function ig_http(string $url, array $config, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $headers[] = 'Accept-Language: en-US,en;q=0.9';

    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => $opts['ua'] ?? $config['user_agent'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$respHeaders) {
            $respHeaders[] = $h;
            return strlen($h);
        },
    ]);
    // XAMPP on Windows often ships without a CA bundle. If a cacert.pem
    // sits next to this file, use it.
    if (is_file(__DIR__ . '/cacert.pem')) {
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/cacert.pem');
    }
    if (!empty($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post']);
    }
    if (!empty($opts['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $opts['cookie']);
    }
    if (!empty($config['http_proxy'])) {
        curl_setopt($ch, CURLOPT_PROXY, $config['http_proxy']);
    }

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        ig_log($opts['step'] ?? $url, 0, '', 'curl error: ' . $err);
        throw new IgException('Could not reach Instagram. Please try again. (' . $err . ')', 502);
    }
    ig_log($opts['step'] ?? $url, $code, (string) $body);
    if ($code === 429 || $code === 401) {
        throw new IgException('Instagram is limiting requests right now. Please try again in a few minutes.', 429);
    }
    return [$code, (string) $body, implode('', $respHeaders)];
}

/**
 * Logged-out instagram.com sets a csrftoken cookie and embeds an LSD token.
 * Newer GraphQL calls are rejected without them.
 */
function ig_web_tokens(array $config): array
{
    if ($cached = cache_get('tokens', 3600)) {
        return $cached;
    }
    [, $body, $headers] = ig_http('https://www.instagram.com/', $config, ['step' => 'homepage tokens']);

    $csrf = '';
    if (preg_match('/csrftoken=([^;\s]+)/i', $headers, $m) || preg_match('/"csrf_token":"([^"]+)"/', $body, $m)) {
        $csrf = $m[1];
    }
    $lsd = '';
    if (preg_match('/"LSD",\[\],\{"token":"([^"]+)"/', $body, $m) || preg_match('/"lsd":"([^"]+)"/', $body, $m)) {
        $lsd = $m[1];
    }
    $cookies = [];
    if (preg_match_all('/^set-cookie:\s*([^=;\s]+)=([^;\r\n]*)/im', $headers, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $c) {
            $cookies[$c[1]] = $c[1] . '=' . $c[2];
        }
    }
    $tokens = ['csrf' => $csrf, 'lsd' => $lsd, 'cookie' => implode('; ', $cookies)];
    if ($csrf !== '' || $lsd !== '') {
        cache_set('tokens', $tokens);
    }
    return $tokens;
}

/** Undo the JSON escaping Instagram uses inside HTML (\/, \", &) */
function ig_unescape(string $s): string
{
    for ($i = 0; $i < 3 && strpos($s, '\\') !== false; $i++) {
        $s = str_replace('\\\\', '\\', $s);
        $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $s);
        $s = str_replace(['\\/', '\\"'], ['/', '"'], $s);
    }
    return $s;
}

/* ---------------------------------------------------------------
 * Link parsing
 * ------------------------------------------------------------- */
function ig_parse_input(string $input, string $type): array
{
    $input = trim($input);

    // Plain username (profile tab)
    if ($type === 'profile' && preg_match('/^@?([A-Za-z0-9._]{1,30})$/', $input, $m)) {
        return ['kind' => 'profile', 'username' => strtolower($m[1])];
    }

    if (!preg_match('#^https?://(www\.|m\.)?instagram\.com/#i', $input)) {
        throw new IgException('That does not look like an Instagram link. Copy the link from the Instagram app and try again.', 400);
    }

    $path = (string) parse_url($input, PHP_URL_PATH);

    if (preg_match('#/(?:[A-Za-z0-9._]+/)?(p|reel|reels|tv)/([A-Za-z0-9_-]{5,})#', $path, $m)) {
        return ['kind' => 'post', 'shortcode' => $m[2]];
    }
    if (preg_match('#^/stories/([A-Za-z0-9._]+)(?:/(\d+))?#', $path, $m)) {
        return ['kind' => 'story', 'username' => strtolower($m[1]), 'story_id' => $m[2] ?? null];
    }
    if (preg_match('#^/([A-Za-z0-9._]{1,30})/?$#', $path, $m)) {
        $reserved = ['explore', 'accounts', 'direct', 'stories', 'reels', 'p', 'tv'];
        if (!in_array(strtolower($m[1]), $reserved, true)) {
            return ['kind' => 'profile', 'username' => strtolower($m[1])];
        }
    }
    throw new IgException('This type of Instagram link is not supported. Use a post, reel, story or profile link.', 400);
}

/* ---------------------------------------------------------------
 * Main resolver
 * ------------------------------------------------------------- */
function ig_resolve(string $input, string $type, array $config): array
{
    $target = ig_parse_input($input, $type);
    $key    = md5(json_encode($target));

    if ($cached = cache_get($key, (int) $config['cache_ttl'])) {
        return $cached;
    }

    switch ($target['kind']) {
        case 'post':
            $result = ig_post($target['shortcode'], $config);
            break;
        case 'profile':
            $result = ig_profile($target['username'], $config);
            break;
        case 'story':
            $result = ig_story($target['username'], $target['story_id'], $config);
            break;
        default:
            throw new IgException('Unsupported link.', 400);
    }

    if (empty($result['items'])) {
        throw new IgException('No downloadable media found. The account may be private or the post was removed.', 404);
    }
    cache_set($key, $result);
    return $result;
}

/* ---------------------------------------------------------------
 * Posts, reels, carousels
 * ------------------------------------------------------------- */
function ig_post(string $shortcode, array $config): array
{
    // Try each method in turn; Instagram blocks some of them depending on IP
    $methods = ['ig_post_page', 'ig_post_embed', 'ig_post_graphql', 'ig_post_media_info'];
    foreach ($methods as $fn) {
        try {
            $media = $fn($shortcode, $config);
        } catch (IgException $e) {
            if ($e->getCode() !== 429) {
                throw $e;
            }
            $media = null; // rate limited on this method, try the next one
        }
        if ($media !== null && !empty($media['items'])) {
            return $media;
        }
    }
    throw new IgException('Could not load this post. It may be private, deleted, or Instagram blocked the request.', 404);
}

/** Method 1: GraphQL with csrftoken + LSD tokens (what instagram.com itself does) */
function ig_post_graphql(string $shortcode, array $config): ?array
{
    $t = ig_web_tokens($config);
    $variables = json_encode([
        'shortcode'               => $shortcode,
        'fetch_tagged_user_count' => null,
        'hoisted_comment_id'      => null,
        'hoisted_reply_id'        => null,
    ]);
    $post = ['variables' => $variables, 'doc_id' => $config['graphql_doc_id']];
    if ($t['lsd'] !== '') {
        $post['lsd'] = $t['lsd'];
    }
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'X-IG-App-ID: ' . $config['ig_app_id'],
        'X-ASBD-ID: 129477',
        'X-FB-Friendly-Name: PolarisPostActionLoadPostQueryQuery',
        'Referer: https://www.instagram.com/p/' . $shortcode . '/',
        'Origin: https://www.instagram.com',
        'Sec-Fetch-Site: same-origin',
    ];
    if ($t['csrf'] !== '') {
        $headers[] = 'X-CSRFToken: ' . $t['csrf'];
    }
    if ($t['lsd'] !== '') {
        $headers[] = 'X-FB-LSD: ' . $t['lsd'];
    }

    [$code, $body] = ig_http('https://www.instagram.com/graphql/query', $config, [
        'step'    => 'graphql',
        'post'    => http_build_query($post),
        'headers' => $headers,
        'cookie'  => trim($t['cookie'] . ($config['session_id'] ? '; sessionid=' . $config['session_id'] : ''), '; '),
    ]);
    if ($code !== 200) {
        return null;
    }
    $json  = json_decode($body, true);
    $media = $json['data']['xdt_shortcode_media'] ?? $json['data']['shortcode_media'] ?? null;
    return is_array($media) ? ig_format_post($media, $shortcode) : null;
}

/** Method 3: the public embed page */
function ig_post_embed(string $shortcode, array $config): ?array
{
    [$code, $html] = ig_http('https://www.instagram.com/p/' . $shortcode . '/embed/captioned/', $config, ['step' => 'embed page']);
    if ($code !== 200 || $html === '') {
        return null;
    }

    // a) full post JSON in "contextJSON"
    if (preg_match('/"contextJSON":("(?:[^"\\\\]|\\\\.)*")/s', $html, $m)) {
        $ctx   = json_decode((string) json_decode($m[1]), true);
        $media = $ctx['gql_data']['shortcode_media'] ?? $ctx['context']['media'] ?? null;
        if (is_array($media) && (isset($media['display_url']) || isset($media['video_url']))) {
            return ig_format_post($media, $shortcode);
        }
    }

    // b) any other known format
    ig_debug_dump('embed', $html);
    if ($media = ig_media_from_html($html)) {
        return $media;
    }

    // c) single image tag (photo posts only)
    if (preg_match('/class="EmbeddedMediaImage"[^>]*src="([^"]+)"/', $html, $m)) {
        $img = html_entity_decode($m[1]);
        return [
            'title'    => 'Instagram post',
            'username' => '',
            'items'    => [['type' => 'image', 'url' => $img, 'thumb' => $img]],
        ];
    }
    return null;
}

/** Post page: logged-out HTML now carries the media JSON in data-sjs scripts */
function ig_post_page(string $shortcode, array $config): ?array
{
    $cookie = $config['session_id'] ? 'sessionid=' . $config['session_id'] : '';
    [$code, $html] = ig_http('https://www.instagram.com/p/' . $shortcode . '/', $config, [
        'step'   => 'post page',
        'cookie' => $cookie,
        'headers'=> ['Accept: text/html,application/xhtml+xml', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document'],
    ]);
    if ($code !== 200) {
        return null;
    }
    ig_debug_dump('post-page', $html);
    return ig_media_from_html($html);
}

/**
 * Pulls media out of any Instagram HTML page, trying every format seen so far.
 */
function ig_media_from_html(string $html): ?array
{
    foreach ([$html, ig_unescape($html)] as $src) {
        // a) v1 "web_info" object (current logged-out post page)
        $info = ig_json_after($src, 'xdt_api__v1__media__shortcode__web_info');
        if (!empty($info['items'][0])) {
            return ig_format_api_item($info['items'][0]);
        }
        // b) GraphQL shortcode_media objects
        foreach (['xdt_shortcode_media', 'shortcode_media'] as $k) {
            $m = ig_json_after($src, $k);
            if ($m && (isset($m['display_url']) || isset($m['video_url']))) {
                return ig_format_post($m, '');
            }
        }
    }

    // c) loose URL scan
    $flat   = ig_unescape($html);
    $videos = [];
    if (preg_match_all('/"video_versions":\[\{[^\]]*?"url":"(https:[^"]+)"/', $flat, $m)) {
        $videos = $m[1];
    }
    if (preg_match_all('/"video_url":"(https:[^"]+)"/', $flat, $m)) {
        $videos = array_merge($videos, $m[1]);
    }
    $videos = array_values(array_unique($videos));
    $thumb  = ig_meta($html, 'og:image');
    if ($videos) {
        $items = array_map(fn($v) => ['type' => 'video', 'url' => $v, 'thumb' => $thumb], $videos);
        return ['title' => 'Instagram post', 'username' => '', 'items' => $items];
    }

    // d) Open Graph image for photo posts
    $type = ig_meta($html, 'og:type');
    if ($thumb !== '' && stripos($type, 'video') === false) {
        return ['title' => 'Instagram post', 'username' => '', 'items' => [['type' => 'image', 'url' => $thumb, 'thumb' => $thumb]]];
    }
    return null;
}

/** <meta property="x" content="y"> in either attribute order */
function ig_meta(string $html, string $prop): string
{
    $p = preg_quote($prop, '/');
    if (preg_match('/<meta[^>]+property="' . $p . '"[^>]+content="([^"]*)"/i', $html, $m)
        || preg_match('/<meta[^>]+content="([^"]*)"[^>]+property="' . $p . '"/i', $html, $m)) {
        return html_entity_decode($m[1]);
    }
    return '';
}

/** Decode the JSON object that follows "key": in a big HTML/JS string */
function ig_json_after(string $s, string $key): ?array
{
    $pos = 0;
    while (($p = strpos($s, '"' . $key . '":', $pos)) !== false) {
        $pos   = $p + 1;
        $start = $p + strlen($key) + 3;
        while (isset($s[$start]) && ctype_space($s[$start])) {
            $start++;
        }
        if (($s[$start] ?? '') !== '{') {
            continue;
        }
        $depth = 0;
        $inStr = false;
        $len   = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($c === '\\') { $i++; continue; }
                if ($c === '"') { $inStr = false; }
                continue;
            }
            if ($c === '"') { $inStr = true; continue; }
            if ($c === '{') { $depth++; }
            if ($c === '}' && --$depth === 0) {
                $data = json_decode(substr($s, $start, $i - $start + 1), true);
                if (is_array($data)) {
                    return $data;
                }
                break;
            }
        }
    }
    return null;
}

/** Saves raw responses into cache/ while debug.php is running */
function ig_debug_dump(string $name, string $body): void
{
    if (!empty($GLOBALS['IG_DEBUG_DUMP'])) {
        @file_put_contents(cache_dir() . '/debug-' . $name . '.html', $body);
    }
}

/** Shortcode to numeric media id (Instagram's base64 alphabet) */
function ig_shortcode_to_id(string $shortcode): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $code = substr($shortcode, 0, 11);
    $id = 0; // fits in 64-bit PHP for all current shortcodes
    foreach (str_split($code) as $ch) {
        $id = $id * 64 + (int) strpos($alphabet, $ch);
    }
    return (string) $id;
}

/** Logged-in media/info API. Used only when session_id is set in config.php */
function ig_post_media_info(string $shortcode, array $config): ?array
{
    if (empty($config['session_id']) || PHP_INT_SIZE < 8) {
        return null;
    }
    [$code, $body] = ig_http('https://i.instagram.com/api/v1/media/' . ig_shortcode_to_id($shortcode) . '/info/', $config, [
        'step'    => 'media info (login)',
        'ua'      => $config['mobile_agent'],
        'headers' => ['X-IG-App-ID: ' . $config['ig_app_id']],
        'cookie'  => 'sessionid=' . $config['session_id'],
    ]);
    if ($code !== 200) {
        return null;
    }
    $json = json_decode($body, true);
    return !empty($json['items'][0]) ? ig_format_api_item($json['items'][0]) : null;
}

/** Format an item from Instagram's v1 API shape */
function ig_format_api_item(array $it): array
{
    $nodes = !empty($it['carousel_media']) ? $it['carousel_media'] : [$it];
    $items = [];
    foreach ($nodes as $n) {
        $img   = $n['image_versions2']['candidates'][0]['url'] ?? '';
        $video = $n['video_versions'][0]['url'] ?? '';
        if ($video || $img) {
            $items[] = ['type' => $video ? 'video' : 'image', 'url' => $video ?: $img, 'thumb' => $img];
        }
    }
    $caption = $it['caption']['text'] ?? '';
    return [
        'title'    => $caption !== '' ? mb_substr($caption, 0, 140) : 'Instagram post',
        'username' => $it['user']['username'] ?? '',
        'items'    => $items,
    ];
}

function ig_format_post(array $media, string $shortcode): array
{
    $items = [];
    $edges = $media['edge_sidecar_to_children']['edges'] ?? [];
    if ($edges) {
        foreach ($edges as $edge) {
            $items[] = ig_item($edge['node'] ?? []);
        }
    } else {
        $items[] = ig_item($media);
    }
    $items = array_values(array_filter($items, fn($i) => $i['url'] !== ''));

    $caption = $media['edge_media_to_caption']['edges'][0]['node']['text'] ?? '';
    return [
        'title'    => $caption !== '' ? mb_substr($caption, 0, 140) : 'Instagram post',
        'username' => $media['owner']['username'] ?? '',
        'items'    => $items,
    ];
}

function ig_item(array $node): array
{
    $isVideo = !empty($node['is_video']);
    $thumb   = $node['display_url'] ?? $node['thumbnail_src'] ?? '';
    return [
        'type'  => $isVideo ? 'video' : 'image',
        'url'   => $isVideo ? ($node['video_url'] ?? '') : $thumb,
        'thumb' => $thumb,
    ];
}

/* ---------------------------------------------------------------
 * Profile picture
 * ------------------------------------------------------------- */
function ig_user(string $username, array $config): array
{
    [$code, $body] = ig_http(
        'https://i.instagram.com/api/v1/users/web_profile_info/?username=' . rawurlencode($username),
        $config,
        [
            'ua'      => $config['mobile_agent'],
            'headers' => ['X-IG-App-ID: ' . $config['ig_app_id']],
            'cookie'  => $config['session_id'] ? 'sessionid=' . $config['session_id'] : '',
        ]
    );
    if ($code === 404) {
        throw new IgException('No Instagram account found with that username.', 404);
    }
    $user = json_decode($body, true)['data']['user'] ?? null;
    if ($code !== 200 || !is_array($user)) {
        throw new IgException('Could not load this profile right now. Please try again shortly.', 502);
    }
    return $user;
}

function ig_profile(string $username, array $config): array
{
    $user = ig_user($username, $config);
    $pic  = $user['profile_pic_url_hd'] ?? $user['profile_pic_url'] ?? '';
    return [
        'title'    => ($user['full_name'] ?? '') ?: '@' . $username,
        'username' => $user['username'] ?? $username,
        'items'    => $pic ? [['type' => 'image', 'url' => $pic, 'thumb' => $pic]] : [],
    ];
}

/* ---------------------------------------------------------------
 * Stories (needs session_id in config.php)
 * ------------------------------------------------------------- */
function ig_story(string $username, ?string $storyId, array $config): array
{
    if (empty($config['session_id'])) {
        throw new IgException('Story downloads are not available at the moment. Please try a post or reel link.', 503);
    }
    $user = ig_user($username, $config);
    if (!empty($user['is_private'])) {
        throw new IgException('This account is private, so its stories cannot be downloaded.', 403);
    }

    [$code, $body] = ig_http(
        'https://i.instagram.com/api/v1/feed/reels_media/?reel_ids=' . rawurlencode((string) $user['id']),
        $config,
        [
            'ua'      => $config['mobile_agent'],
            'headers' => ['X-IG-App-ID: ' . $config['ig_app_id']],
            'cookie'  => 'sessionid=' . $config['session_id'],
        ]
    );
    $json  = json_decode($body, true);
    $reel  = $json['reels'][(string) $user['id']] ?? ($json['reels_media'][0] ?? null);
    $items = [];

    foreach ($reel['items'] ?? [] as $s) {
        if ($storyId && (string) ($s['pk'] ?? '') !== $storyId) {
            continue;
        }
        $img   = $s['image_versions2']['candidates'][0]['url'] ?? '';
        $video = $s['video_versions'][0]['url'] ?? '';
        $items[] = [
            'type'  => $video ? 'video' : 'image',
            'url'   => $video ?: $img,
            'thumb' => $img,
        ];
    }
    if (!$items) {
        throw new IgException('No active stories found. Stories disappear after 24 hours.', 404);
    }
    return ['title' => '@' . $username . ' stories', 'username' => $username, 'items' => $items];
}

/* ---------------------------------------------------------------
 * Cache + rate limit (simple file based)
 * ------------------------------------------------------------- */
function cache_dir(): string
{
    if (!is_dir(IG_CACHE_DIR)) {
        @mkdir(IG_CACHE_DIR, 0755, true);
    }
    return IG_CACHE_DIR;
}

function cache_get(string $key, int $ttl): ?array
{
    $f = cache_dir() . '/c_' . $key . '.json';
    if (is_file($f) && filemtime($f) > time() - $ttl) {
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

function cache_set(string $key, array $data): void
{
    @file_put_contents(cache_dir() . '/c_' . $key . '.json', json_encode($data), LOCK_EX);
}

function rate_limit_ok(string $ip, int $limit, int $window): bool
{
    $f   = cache_dir() . '/rl_' . md5($ip) . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $window));
    if (count($hits) >= $limit) {
        return false;
    }
    $hits[] = $now;
    @file_put_contents($f, json_encode($hits), LOCK_EX);
    return true;
}
