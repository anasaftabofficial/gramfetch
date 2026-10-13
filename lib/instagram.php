<?php
declare(strict_types=1);

class IgException extends RuntimeException {}

const IG_CACHE_DIR = __DIR__ . '/../cache';

/* ---------------------------------------------------------------
 * HTTP
 * ------------------------------------------------------------- */
function ig_http(string $url, array $config, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $headers[] = 'Accept-Language: en-US,en;q=0.9';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => $opts['ua'] ?? $config['user_agent'],
        CURLOPT_HTTPHEADER     => $headers,
    ]);
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
        throw new IgException('Could not reach Instagram. Please try again. (' . $err . ')', 502);
    }
    if ($code === 429 || $code === 401) {
        throw new IgException('Instagram is limiting requests right now. Please try again in a few minutes.', 429);
    }
    return [$code, (string) $body];
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
    $media = ig_post_graphql($shortcode, $config) ?? ig_post_embed($shortcode, $config);

    if ($media === null) {
        throw new IgException('Could not load this post. It may be private, deleted, or Instagram blocked the request.', 404);
    }
    return $media;
}

function ig_post_graphql(string $shortcode, array $config): ?array
{
    $variables = json_encode([
        'shortcode'               => $shortcode,
        'fetch_tagged_user_count' => null,
        'hoisted_comment_id'      => null,
        'hoisted_reply_id'        => null,
    ]);

    try {
        [$code, $body] = ig_http('https://www.instagram.com/graphql/query', $config, [
            'post'    => http_build_query(['variables' => $variables, 'doc_id' => $config['graphql_doc_id']]),
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-IG-App-ID: ' . $config['ig_app_id'],
                'X-FB-Friendly-Name: PolarisPostActionLoadPostQueryQuery',
                'Referer: https://www.instagram.com/p/' . $shortcode . '/',
                'Origin: https://www.instagram.com',
            ],
        ]);
    } catch (IgException $e) {
        if ($e->getCode() === 429) {
            return null; // try embed fallback
        }
        throw $e;
    }

    if ($code !== 200) {
        return null;
    }
    $json  = json_decode($body, true);
    $media = $json['data']['xdt_shortcode_media'] ?? $json['data']['shortcode_media'] ?? null;
    if (!is_array($media)) {
        return null;
    }
    return ig_format_post($media, $shortcode);
}

function ig_post_embed(string $shortcode, array $config): ?array
{
    [$code, $html] = ig_http('https://www.instagram.com/p/' . $shortcode . '/embed/captioned/', $config);
    if ($code !== 200 || $html === '') {
        return null;
    }

    // Newer embed pages carry the full post JSON in "contextJSON"
    if (preg_match('/"contextJSON":("(?:[^"\\\\]|\\\\.)*")/s', $html, $m)) {
        $ctx   = json_decode((string) json_decode($m[1]), true);
        $media = $ctx['gql_data']['shortcode_media'] ?? $ctx['context']['media'] ?? null;
        if (is_array($media) && (isset($media['display_url']) || isset($media['video_url']))) {
            return ig_format_post($media, $shortcode);
        }
    }

    // Fallback: single image only
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
