# GramFetch: Instagram Downloader website

A self hosted Instagram downloader (videos, Reels, photos, carousels, stories, profile pictures). Plain PHP, HTML, CSS and JS. No database, no framework.

## Requirements

* PHP 7.4 or newer (8.x recommended) with the **curl** and **json** extensions
* Apache (cPanel / shared hosting works) or Nginx
* HTTPS on your domain (clipboard "Paste" button only works on HTTPS)

## Install

1. Upload every file and folder to `public_html` (or a subfolder).
2. Make sure the `cache` folder is writable (permission 755 or 775).
3. Open `config.php` and change `site_name` if you like.
4. In `index.html`, `privacy.html` and `terms.html` replace:
   * `https://www.example.com/` with your domain (canonical, og:url, schema)
   * `contact@example.com` and `dmca@example.com` with your emails
   * the brand name "GramFetch" if you renamed it
5. Visit your site and test with a public Reel link.

### Nginx

Apache uses the included `.htaccess` files. On Nginx add:

```
location ~ ^/(cache|lib)/ { deny all; }
location = /config.php { deny all; }
```

## File overview

| File | Purpose |
|---|---|
| `index.html` | Tool page with tabs and SEO content |
| `app.js` | Tabs, paste button, calls `api.php`, renders results |
| `api.php` | Takes the link, returns JSON with media URLs |
| `lib/instagram.php` | Talks to Instagram, cache, rate limit |
| `proxy.php` | Streams the file so the browser saves it (only allows Instagram CDN hosts) |
| `config.php` | Settings |

## Important: how reliable is this?

Instagram has no official download API. Every downloader site (including the big ones) uses Instagram's own web endpoints, and Instagram actively blocks them. Expect these issues:

1. **Shared hosting / datacenter IPs get blocked fast.** You will see "Instagram is limiting requests". Fix: set `http_proxy` in `config.php` to a rotating **residential proxy** (paid services like these are what big downloader sites use).
2. **`graphql_doc_id` changes.** If posts suddenly stop loading but the embed fallback still returns single photos, Instagram changed the ID. To find the new one: open any post on instagram.com while logged out, DevTools > Network, filter `graphql/query`, find the request whose form data has `PolarisPostActionLoadPostQueryQuery`, copy its `doc_id` into `config.php`.
3. **Stories need a logged in session.** Put the `sessionid` cookie of a **spare** Instagram account in `config.php`. Never use your main account; Instagram can ban accounts used this way. Without it, the Story tab shows a friendly "not available" message.
4. **Profile pictures** use a public endpoint that works without login but is rate limited.

Code was syntax checked but could not be tested live against Instagram from the build environment, so test on your hosting first.

## Legal note

Downloading through such tools goes against Instagram's Terms of Use, and the media belongs to its creators. Keep the "respect creators" notice, the "not affiliated with Instagram or Meta" line, and the DMCA page. Google AdSense has rejected or limited some downloader sites, so plan monetization with that in mind.
