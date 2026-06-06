# Crossroad Videos (`xroad-videos`)

A single-file WordPress plugin by [Crossroad Media](https://crossroad.us). A privacy-first, click-to-load video gallery — a drop-in alternative to **Smash Balloon YouTube Feed** for any site running a cookie/consent manager.

## Why it exists

Smash Balloon YouTube Feed (and standard YouTube embeds) fire requests to `youtube.com` / `i.ytimg.com` / `google.com` on **page load**, before consent. When a consent manager (CookieYes, Osano, Cookiebot, Complianz, etc.) is present, it tries to intercept those requests — and that interception is what produces the symptoms operators keep reporting:

- a consent **warning overlay** sitting on top of the player,
- a **black or blank player** that never loads,
- **cascade JavaScript failures** when the CMP and the feed's consent shim collide,
- and **corrupted GA4 attribution** when a page-refresh "fix" is used to force the player to load.

Every one of those has the same root cause: third-party requests firing **before a deliberate user action**.

This plugin inverts that. Every card starts as a **local poster image + a play button** — pure first-party HTML/CSS, zero requests to any Google domain, zero cookies, zero `localStorage`. Because nothing third-party fires before interaction, the consent manager has nothing to block, so no banner, overlay, or black player can appear. Only **on click** does it inject a `youtube-nocookie.com` iframe (the click is the consent) and push a `video_play` event to the dataLayer. Storing thumbnails locally hardens the guarantee: even the poster makes no call to `i.ytimg.com`.

This is the [web.dev "facade" pattern](https://web.dev/articles/third-party-facades).

## What it does

- **Curated CPT** `xroad_video` — editors paste a YouTube URL and drag to order; no YouTube Data API key.
- **Local thumbnail sideload** on save (maxres → hqdefault fallback, checked by HTTP status), so the grid references `/wp-content/uploads/` and never calls `i.ytimg.com`.
- **No-key oEmbed** prefill of the title/description on first save.
- **Server-rendered CSS-columns masonry** grid with `content-visibility:auto`; client-side filter chips (Series / Audience / Topic) + keyword search + sort — all instant, no network round-trip.
- **Self-generating VideoObject JSON-LD** inside a `CollectionPage` / `ItemList`, merging with the site's Organization node via the `xrv_org_id` filter.
- **Shortcode + block** under the collision-proof `xroad` namespace.
- Three REST-exposed taxonomies (`xrv_series`, `xrv_audience`, `xrv_topic`); sites define their own terms.
- A `_xrv_provider` switch with Vimeo branches stubbed for 2.0.

No page builder, ACF, jQuery, or build step. Inline-asset architecture survives a performance plugin's unused-CSS pass and moves between themes unchanged.

## Usage

```
[xroad-videos]
[xroad-videos series="webinars" columns="3" limit="12"]
```

Block: **xroad/videos** (PHP-rendered dynamic block; shares the shortcode's render path).

Shortcode attributes: `series`, `audience`, `topic` (comma-separated term slugs), `limit` (default all), `columns` (fixed count; blank = responsive 320px columns).

## Install

Single-file plugin. Either upload `xroad-videos.php` to `wp-content/plugins/xroad-videos/`, or zip the folder (`xroad-videos/xroad-videos.php`) and upload via **Plugins → Add New → Upload**. Activate; the CPT and taxonomies register on activation.

## Configuration filters

| Filter | Purpose |
|---|---|
| `xrv_org_id` | Pin the publisher `@id` to your SEO plugin's exact Organization `@id` so the two schema nodes merge instead of competing. |
| `xrv_seed_terms` | Pre-seed taxonomy terms on activation: return `[ taxonomy => [ slug => name ] ]`. Idempotent. |
| `xrv_synonym_map` | Extend the keyword index: return `[ term-slug => 'extra search aliases' ]`. |
| `xrv_video_schema` | Modify a single `VideoObject` node (add `transcript`, `regionsAllowed`, `about`, etc.). |
| `xrv_list_name` | Override the `CollectionPage` / `ItemList` name. |

Example — merge schema with Yoast/Rank Math's Organization node:

```php
add_filter( 'xrv_org_id', fn() => 'https://example.com/#organization' );
```

## Verify the privacy guarantee

Open a page using the gallery in DevTools (Network tab, cache disabled) and confirm **zero** requests to `youtube.com` / `youtube-nocookie.com` / `google.com` / `i.ytimg.com` before any click, and zero YouTube cookies / `localStorage`. Repeat with your consent manager active — confirm no banner, overlay, or black screen. If any Google-domain request fires pre-click, that's a bug; open an issue.

## Analytics (optional)

On play, the plugin pushes to `window.dataLayer`:

```js
{ event: 'video_play', video_provider, video_id, video_title, video_series }
```

Wire it in GTM with a Custom Event trigger on `video_play` and a GA4 event tag reading those data-layer variables. Because the facade never refreshes the page, it avoids the attribution corruption that page-refresh consent workarounds cause.

## Roadmap

- **1.0.7** — Paged browse grid, Subscribe, and mobile UX.
  - **Load More:** `per_page` (default 9) sets how many cards show before a **Load More** button reveals `load_more` (default 3) more — applied to the filtered/sorted set, and resets when filters change.
  - **Subscribe:** a Subscribe button (YouTube glyph) appears under the grid when `subscribe_url` is set (`subscribe_label` customizes the text).
  - **Mobile:** the aligned grid steps 3 → 2 → 1 columns (tablet → phone) via the `--xrv-cols` custom property; on phones the featured carousel becomes a native, thumb-swipeable **scroll-snap** strip (peeks the next card; arrows/dots hide) — no fragile carousel controls on touch.
  - Example: `[xroad-videos layout="library" per_page="9" load_more="3" subscribe_url="https://youtube.com/@yourchannel"]`.
- **1.0.6** — Single-page polish + rich schema. Single video pages now emit a **standalone `VideoObject`** (not the CollectionPage wrapper) with `transcript`, **key-moment `Clip`s** (from a new "Key moments" field), `keywords`, `inLanguage`, `isFamilyFriendly`, publisher, and a guaranteed `uploadDate` (falls back to the post date) — for maximum Video / key-moments rich-result and AI-citation eligibility. New meta-box inputs: **Transcript** and **Key moments**. The theme's author byline and duplicate featured image are hidden on single video pages (filter: `xrv_single_chrome_css`).
- **1.0.5** — Layouts. New `layout="library"` renders a **Featured Videos** carousel (3-up, arrows + dots, responsive) above a **Browse our Library** grid in one shortcode; `layout="carousel"` for the carousel alone. Fixed `columns` now produce an aligned CSS grid (rows line up) instead of masonry. `controls="false"` hides the search/sort/filter bar. `featured_limit` sets how many videos feed the carousel. Multiple galleries per page are now supported (JS inits every `.xrv` root). Example: `[xroad-videos layout="library" columns="3" controls="false"]`.
- **1.0.4** — Lightbox playback: clicking a card in the grid now opens the video in a centered overlay over a dimmed backdrop ("pops out"), closeable via the × button, click-outside, or Esc (which stops playback). Default for the grid; single-video pages still play inline. Choose per instance with the `playback="lightbox|inline"` shortcode attribute.
- **1.0.3** — Fix: the click-to-load handler now binds by `.xrv-grid` class instead of `#xrv-grid` id, so the facade works on the single-video page (1.0.2) as well as the shortcode grid.
- **1.0.2** — Single-video pages are never empty. Because the CPT is public, each video has a single-post URL; the facade only renders via the shortcode, so that URL used to show a blank body. Now: if a video has a Dedicated Page URL it redirects there (default 301, filter `xrv_dedicated_redirect_status`); otherwise the single page renders the facade + VideoObject schema itself.
- **1.0.1** — Editor auto-title: pasting a URL fetches the title from the WordPress oEmbed proxy and fills it, so a URL-only video is immediately saveable (the block editor won't persist a post with an empty title and body). Never overwrites a title you've typed.
- **1.0** — YouTube facade, local thumbnails, masonry, self-generating VideoObject schema, `video_play` event, shortcode + block.
- **2.0** — Vimeo as a second `_xrv_provider` value (ID parser, `vumbnail`/oEmbed thumbnail, `player.vimeo.com` facade with `dnt=1`). No render rewrite — the provider switch already routes for it.

## License

GPL-2.0-or-later.

---

Built by [Crossroad Media](https://crossroad.us).
