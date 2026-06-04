# the project Videos (`xroad-videos`)

A single-file WordPress plugin built by Crossroad Media for a foundation. A privacy-by-design, click-to-load video gallery that replaces the prior third-party social-feed plugin and consent-manager workaround.

## Why it exists

The previous setup fired requests to youtube.com / i.ytimg.com / google.com on **page load**, before consent. The consent manager tried to intercept those requests, and that interception produced the warning overlay, the black player, and a cascade JS failure. Page-refresh workarounds then corrupted GA4 attribution.

This plugin inverts that. Every card starts as a **local poster image + a play button** — pure first-party HTML/CSS, zero requests to any Google domain, zero cookies, zero localStorage. Because nothing third-party fires before interaction, the consent manager has nothing to block, so no banner, warning, or black screen can appear. Only **on click** does it inject a `youtube-nocookie.com` iframe (the click is the consent) and push a `video_play` event to the dataLayer.

## What it does

- **Curated CPT** `xroad_video` — editors paste a YouTube URL and drag to order; no YouTube Data API.
- **Local thumbnail sideload** on save (maxres → hq fallback, checked by HTTP status), so the grid references `/wp-content/uploads/` and never calls `i.ytimg.com`.
- **No-key oEmbed** prefill of title/description on first save.
- **Server-rendered CSS-columns masonry** grid with `content-visibility:auto`; client-side filter chips (Series / Audience / Topic) + keyword search + sort, all instant (no network round-trip).
- **Self-generating VideoObject JSON-LD** inside a `CollectionPage` / `ItemList`, merging with the site Organization node via the `xrv_org_id` filter; `about` → `MedicalCondition`.
- **Shortcode + block** under the collision-proof `xroad` namespace.
- Four REST-exposed taxonomies: `xrv_series`, `xrv_condition`, `xrv_audience`, `xrv_topic`.
- `_xrv_provider` switch with Vimeo branches stubbed for 2.0.

## Usage

```
[xroad-videos]
[xroad-videos series="lunch-and-learn" columns="3" limit="12"]
```

Block: **xroad/videos** (PHP-rendered dynamic block; shares the shortcode's render path).

Shortcode attributes: `series`, `audience`, `topic` (comma-separated slugs), `limit` (default all), `columns` (fixed count; blank = responsive 320px columns).

## Install

This is a single-file plugin. Either:

- Upload `xroad-videos.php` to `wp-content/plugins/xroad-videos/`, **or**
- Build a zip: `xroad-videos/xroad-videos.php` → upload via Plugins → Add New → Upload.

Activate. The CPT, taxonomies, and controlled vocabularies seed on activation (idempotent).

## Launch checklist (do not skip)

1. **Pin the Organization @id.** View-source the live site to confirm whether Rank Math or Yoast emits the Organization node, then pin `xrv_org_id` to that exact `#organization` @id so the schema merges instead of competing:

   ```php
   add_filter( 'xrv_org_id', function () {
       return 'https://example.com/#organization'; // match the SEO plugin's exact @id
   } );
   ```

2. **Pre-click network audit.** Open the Video Library in DevTools (cache disabled) and confirm **zero** requests to youtube.com / youtube-nocookie.com / google.com / i.ytimg.com before any click, and zero YouTube cookies / localStorage. Repeat with each consent manager active — confirm no banner, overlay, or black screen. If any Google-domain request fires pre-click, fix the facade before cutover.

3. **GTM / GA4 wiring (manual).** In container `GTM-5Q39VQG9`: a Custom Event trigger on `video_play` + a GA4 event tag (property `434355740`) reading `video_id`, `video_title`, `video_series`.

## Roadmap

- **1.0** — YouTube facade, local thumbnails, masonry, self-generating VideoObject schema, `video_play` event, shortcode + block.
- **2.0** — Vimeo as a second `_xrv_provider` value (ID parser, `vumbnail`/oEmbed thumbnail, `player.vimeo.com` facade with `dnt=1`). No render rewrite — the provider switch already routes for it.

---

Built by [Crossroad Media](https://crossroad.us) for a foundation.
