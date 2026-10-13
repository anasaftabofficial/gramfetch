<?php
/**
 * GramFetch configuration.
 * Edit these values after uploading to your hosting.
 */
return [
    // Brand name shown in API responses and downloaded file names
    'site_name'      => 'GramFetch',

    // Instagram web app ID (public value used by instagram.com itself)
    'ig_app_id'      => '936619743392459',

    // GraphQL document ID for loading a single post. Instagram changes this
    // from time to time. If posts stop loading, update it (see README.md).
    'graphql_doc_id' => getenv('IG_DOC_ID') ?: '8845758582119845',

    // OPTIONAL: sessionid cookie of a SPARE Instagram account (never your main
    // one, it can get banned). Needed for Stories and helps when Instagram
    // blocks logged-out requests. On Vercel set it as the IG_SESSION_ID
    // environment variable instead of writing it here.
    'session_id'     => getenv('IG_SESSION_ID') ?: '',

    // OPTIONAL: outgoing proxy, e.g. 'http://user:pass@1.2.3.4:8080'
    // Instagram often blocks shared hosting / datacenter IPs. A residential
    // proxy fixes most "Instagram is limiting requests" errors.
    'http_proxy'     => getenv('IG_HTTP_PROXY') ?: '',

    // How long a resolved link is cached (seconds). Instagram CDN links expire,
    // so keep this short.
    'cache_ttl'      => 600,

    // Max API requests per visitor IP inside the window below
    'rate_limit'     => 25,
    'rate_window'    => 600,

    'user_agent'     => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    'mobile_agent'   => 'Instagram 309.0.0.40.113 Android (33/13; 420dpi; 1080x2340; samsung; SM-G991B; o1s; exynos2100; en_US; 541635890)',
];
