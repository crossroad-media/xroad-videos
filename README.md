# Crossroad Videos (`xroad-videos`)

A single-file WordPress plugin by [Crossroad Media](https://crossroad.us). A privacy-first, click-to-load video gallery and a drop-in alternative to **Smash Balloon YouTube Feed** for any site running a cookie/consent manager, now with geo-aware GDPR consent modes, a built-in bulk importer, a site-wide settings page, and multiple layouts.

## Why it exists

Smash Balloon YouTube Feed (and standard YouTube embeds) fire requests to `youtube.com` / `i.ytimg.com` / `google.com` on **page load**, before consent. When a consent manager (CookieYes, Osano, Cookiebot, Complianz, etc.) is present, it tries to intercept those requests, and that interception is what produces the symptoms operators keep reporting:

- a consent **warning overlay** sitting on top of the player,
- a **black or blank player** that never loads,
- **cascade JavaScript failures** when the CMP and the feed's consent shim collide,
- and **corrupted GA4 attribution** when a page-refresh "fix" is used to force the player to load.

Every one of those has the same root cause: third-party requests firing **before a deliberate user action**.

This plugin inverts that. Every card starts as a **local poster image + a play button**: pure first-party HTML/CSS, zero requests to any Google domain, zero cookies, zero `localStorage`. Because nothing third-party fires before interaction, the consent manager has nothing to block, so no banner, overlay, or black player can appear. Only **on click** does it inject a `youtube-nocookie.com` iframe (the click is the consent) and push a `video_play` event to the dataLayer. Storing thumbnails locally hardens the guarantee: even the poster makes no call to `i.ytimg.com`.

This is the ["facade" pattern](https://stackoverflow.com/questions/5242429/what-is-the-facade-design-pattern).

## What it does

- **Curated CPT** `xroad_video`. Editors paste a YouTube URL and drag to reorder; no YouTube Data API key required.
- **Built-in bulk importer** (Videos → Import). Paste a list of URLs, point at a channel or playlist (optionally with a free API key for durations and upload dates), or upload a JSON file carrying full metadata and taxonomy terms. A dry-run preview shows new vs. already-in-library before anything is written, then the import runs in batched AJAX with a progress bar.
- **Local thumbnail sideload** on save (maxres with hqdefault fallback, checked by HTTP status), so the grid references `/wp-content/uploads/` and never calls `i.ytimg.com`. No-key oEmbed prefills the title and description on first save.
- **Layouts.** A featured carousel, a browse grid, or both in one shortcode (`layout="library"`), with lightbox or inline playback. Filtering (dropdown selects or chips for Series / Audience / Topic), keyword search, and sort (including shortest or longest by duration) are pure client-side toggles, so they run instantly with no network round-trip. Paged browse adds a Load More button and an optional Subscribe button, and the grid steps 3 to 2 to 1 columns on smaller screens.
- **Geo-aware consent modes.** Global (recommended), Strict GDPR, or No consent integration. **Global** shows a dismissible opt-in prompt only to EU/UK/EEA/CH visitors (resolved from an edge country header) and stays frictionless for everyone else, including US / CCPA, since the facade shares no data with YouTube until a click. Whenever a prompt is required the plugin makes zero contact with any Google domain until the visitor accepts. See **Privacy, consent & GDPR** below.
- **Site-wide settings page** (Videos → Settings). Set the consent mode, privacy URL, filter style, per-page counts, Subscribe URL, and YouTube Data API key once; every gallery inherits them, and any shortcode or block attribute still overrides.
- **Self-generating VideoObject JSON-LD** inside a `CollectionPage` / `ItemList`, merging with the site's Organization node via the `xrv_org_id` filter. Single-video pages emit a standalone `VideoObject` with transcript and key-moment `Clip`s for rich-result and AI-citation eligibility.
- **Shortcode, block, and block sidebar controls** under the collision-proof `xroad` namespace. The Gutenberg block exposes Layout, Browse, Privacy, and pre-filter panels through InspectorControls, with no build step.
- **Three REST-exposed taxonomies** (`xrv_series`, `xrv_audience`, `xrv_topic`); sites define their own terms.
- A `_xrv_provider` switch with Vimeo branches stubbed for 2.0.

No page builder, ACF, jQuery, or build step. The inline-asset architecture (CSS, JS, and SVG emitted once per request) survives a performance plugin's unused-CSS pass and moves between themes unchanged.

## Usage

```
[xroad-videos]
[xroad-videos layout="library" per_page="9" subscribe_url="https://youtube.com/@yourchannel"]
[xroad-videos series="webinars" columns="3" filter_ui="chips" consent_notice="geo" privacy_url="/privacy-policy/"]
```

Block: **xroad/videos** (a PHP-rendered dynamic block that shares the shortcode's render path). Every attribute below is also a block sidebar control, and anything left blank inherits the site-wide default from **Videos → Settings**, which a shortcode or block attribute always overrides.

**Content and filtering**

| Attribute | Default | What it does |
|---|---|---|
| `series`, `audience`, `topic` | all | Pre-filter to one or more taxonomy term slugs (comma-separated). |
| `limit` | all | Cap how many videos render. |
| `filter_ui` | `select` | Facet filter style: `select` (dropdowns) or `chips` (clickable rows). |
| `controls` | `true` | `false` hides the search / sort / filter bar. |

**Layout**

| Attribute | Default | What it does |
|---|---|---|
| `layout` | `grid` | `grid`, `carousel`, or `library` (a featured carousel above a browse grid). |
| `columns` | responsive | Fixed column count; blank gives responsive columns (masonry for `grid`, 3 for `library`/`carousel`). |
| `featured_limit` | `6` | How many videos feed the featured carousel. |
| `heading` | none | Optional centered section title above a `grid` or `carousel`. |
| `playback` | `lightbox` | `lightbox` pops the video into a centered overlay; `inline` plays it in the card. |
| `per_page` | `9` | Cards shown before a **Load More** button appears. |
| `load_more` | `3` | How many more cards each **Load More** click reveals. |
| `subscribe_url`, `subscribe_label` | off | Show a Subscribe button under the grid when `subscribe_url` is set; `subscribe_label` sets its text. |

**Privacy and consent**

| Attribute | Default | What it does |
|---|---|---|
| `consent_notice` | `off` | `geo` = **Global** (opt-in prompt for EU/UK/EEA/CH; one-click + facade elsewhere, including US / CCPA), `strict` = **Strict GDPR** (opt-in prompt for everyone), `off` = no consent layer. (`light` = legacy on-video caption, still honored.) See [Privacy, consent & GDPR](#privacy-consent--gdpr). |
| `consent_text` | built-in | Body text of the consent prompt. |
| `consent_button` | `Load video` | Accept-button label. |
| `consent_decline` | `No thanks` | Decline-button label. |
| `privacy_url` | WP privacy page | Privacy-policy link shown in the prompt. |

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
| `xrv_consent_required` | Override the geo consent decision for the current request (force a region, plug in MaxMind, defer to your CMP, and so on). Return `true` to require the consent prompt, `false` to allow one-click play. |

Example, merging schema with Yoast/Rank Math's Organization node:

```php
add_filter( 'xrv_org_id', fn() => 'https://example.com/#organization' );
```

## Verify the privacy guarantee

Open a page using the gallery in DevTools (Network tab, cache disabled) and confirm **zero** requests to `youtube.com` / `youtube-nocookie.com` / `google.com` / `i.ytimg.com` before any click, and zero YouTube cookies / `localStorage`. Repeat with your consent manager active and confirm no banner, overlay, or black screen. If any Google-domain request fires pre-click, that's a bug; open an issue.

## Privacy, consent & GDPR

The gallery is a **click-to-load facade**: every card is a local first-party poster + a play button. Nothing (no request, cookie, connection, or `localStorage`) reaches YouTube/Google until a visitor deliberately clicks. On click it injects a `youtube-nocookie.com` iframe; **that click is the consent** that loads the embed.

On top of that baseline, the **Consent mode** (Settings → Videos → Settings, or `consent_notice=`) sets how consent is obtained:

| Mode | `consent_notice` | What the visitor gets | Pre-click contact with YouTube |
|---|---|---|---|
| **Global** *(recommended)* | `geo` | EU/UK/EEA/CH visitors get the opt-in "Load video" prompt; everyone else (including US / CCPA) plays in one click | **none** for prompted visitors; preconnect for others |
| **Strict GDPR** | `strict` | opt-in prompt for **every** visitor, worldwide | **none** for anyone |
| **No consent integration** | `off` | no prompt or notice | preconnect on hover (connection only, no data/cookies) |

The opt-in prompt is declinable (× / "No thanks"), so refusing is as easy as accepting. **Global** satisfies GDPR where it applies (a prior-consent gate for EU/UK/EEA/CH) and the US notice-and-opt-out model elsewhere, because the facade shares no data with YouTube until the click. The legacy `light` value (an on-video caption) is still honored if set via shortcode. *(Informational only, not legal advice.)*

Key points:

- **The plugin sets no cookies of its own.** The only client-side storage it writes is a first-party `sessionStorage` flag caching the geo decision. That is not a cookie, not an identifier, and it clears on tab close.
- **Dismissible consent.** The prompt has an `×` and a **"No thanks"** button; declining closes it and loads nothing, so refusing is as easy as accepting.
- **Zero pre-click contact when gated.** Whenever a prompt is required (Strict for everyone; Compliance for EU/UK/EEA/CH), the hover `preconnect` is suppressed too, so the visitor's browser makes no DNS/TLS/HTTP contact with any Google domain until they accept.
- **Geo detection** reads an edge country header (`CF-IPCountry` / WP Engine / CloudFront) via a cache-safe REST call (`/wp-json/xrv/v1/region`); the page itself stays fully cacheable. With no header present it **fails safe** to showing the prompt to everyone. Override the logic with the `xrv_consent_required` filter.
- **Scope.** This governs the *video player* only. Other site trackers (analytics, ad tags, consent managers) are independent; gate those at your CMP / Google Consent Mode. The facade also doesn't forward video-viewing data anywhere (relevant to the US VPPA): the `video_play` dataLayer event is first-party; don't wire it to a third party with an identifier.
- **Not legal advice.** The click-to-load facade with an informed, dismissible prompt is the widely-recognized compliant pattern, but your DPO/counsel makes the final determination for your jurisdiction and content.

Settings for the prompt: `consent_text`, `consent_button` (accept label), `consent_decline` (decline label), `privacy_url` (defaults to your WordPress privacy page).

## Analytics (optional)

On play, the plugin pushes to `window.dataLayer`:

```js
{ event: 'video_play', video_provider, video_id, video_title, video_series }
```

Wire it in GTM with a Custom Event trigger on `video_play` and a GA4 event tag reading those data-layer variables. Because the facade never refreshes the page, it avoids the attribution corruption that page-refresh consent workarounds cause.

## Changelog

### 1.0.9

Adds automatic channel sync: the plugin can poll a YouTube channel or playlist for new uploads and add them to the library, either on a schedule you set or on demand. Still a single-file, zero-dependency plugin; the sync engine and its controls are admin-only and add nothing to the front-end payload (unchanged at about 29 KB raw / 8.3 KB gzipped per page).

- **Automatic channel sync** (Videos → Settings → Automatic channel sync). Point it at a channel URL (`/@handle`, `/channel/UC…`, `/user/…`) or a `?list=…` playlist and it scans the most recent uploads, adding any video not already in the library. Dedup is by video ID (`_xrv_video_id`), so a run is always idempotent and safe to repeat. Each new video gets its title, duration, upload date, description, and a sideloaded thumbnail, exactly like the importer. Requires the (free) YouTube Data API key already used by Import.
- **Scheduled or on demand.** Pick a check frequency of Hourly, Daily, Weekly, or Monthly (WP-Cron), or **On demand / never** to disable the schedule and update only when you click **Sync now**. The Settings page shows the next scheduled run and a last-run summary.
- **Publish or review.** Choose whether newly found videos go live immediately (Published) or land as Drafts for review before they appear, plus a cap on how many recent uploads to scan per run (1–50).
- **Clean lifecycle.** The cron event is rescheduled when settings change, cleared on deactivation, and removed (with its bookkeeping option) on uninstall. A short-lived lock prevents a scheduled run and a manual *Sync now* from overlapping and double-adding the same video.
- **Hardening & performance** (applies to all galleries, not just sync). The JSON-LD `<script>` output now hex-escapes `<`, `>`, and `&`, so a video title or description (including titles pulled from YouTube) can never break out of the schema block. The grid render now reads taxonomy terms from the cache WordPress already primes, cutting roughly three database queries per card down to a single bulk query. The one WP 5.3-only call introduced for sync is now guarded, keeping the plugin compatible back to WordPress 5.0.
- **Granular poster controls.** Each video now has a **Poster image** control in its editor: upload or choose any image from the media library to override the auto-fetched YouTube thumbnail, or **Reset to automatic** to re-fetch it. A custom poster is honored verbatim and is never overwritten by a re-sync. Settings adds a **Default poster image** used for any video that has no thumbnail of its own. The resolution order is custom upload → auto YouTube thumbnail → featured image → site default. Both pickers use the native WordPress media library (admin-only; no front-end weight).
- **Responsive poster images.** Card thumbnails now emit a `srcset` and `sizes` (filterable via `xrv_poster_sizes`), so a browser downloads an appropriately sized poster instead of always pulling the full `large` (up to 1024px) image into a ~480px card. Falls back to a single `src` when no media-library attachment backs the poster. Images are the dominant transfer on a gallery page, so this is the largest real-world load-time win in the release. Also fixes an undefined-variable notice in the featured carousel, which now honors the `card_meta` setting consistently with the grid.

### 1.0.8

Adds a native bulk importer, a full geo-aware GDPR consent system, a site-wide settings page, Gutenberg block sidebar controls, dropdown facet filters with duration sorts, and inline-asset de-duplication. Still a single-file, zero-dependency plugin: everything new on the front end stays behind the same zero-third-party-request-until-click guarantee, and the importer, settings, block UI, and geo diagnostics are admin-only.

- **Native bulk importer** (Videos → Import). Three input modes: paste or upload a list of YouTube URLs (title + sideloaded thumbnail via no-key oEmbed); a channel or playlist URL plus an optional free YouTube Data API key (pulls duration, date, and description); or a JSON metadata file for a full rich import with no API key. A dry-run Preview shows new vs. already-in-library (deduped on `_xrv_video_id`) with an explicit skip/overwrite choice, then imports in batches of five with a progress bar. Import JSON may also carry `series` / `audience` / `topic`, and terms are assigned by name and created when missing. Replaces the old WPCode/SSH seeding entirely. See `IMPORT.md`.
- **Geo-aware GDPR consent system.** A new `consent_notice` mode with four levels: `off`, `light` (an informational caption), `strict` (a first-click "Load video" prompt for everyone), and `geo` (that prompt only for EU/UK/EEA/CH visitors, frictionless elsewhere). The prompt is dismissible: an `×` and a "No thanks" button (`consent_decline`) load nothing, and whenever a prompt is required the background `preconnect` is suppressed too, so gated visitors make zero contact with any Google domain until they accept. Region is resolved cache-safely via a tiny REST call (`/wp-json/xrv/v1/region`) reading an edge country header; it fails safe to "required" when no header is present, and the `xrv_consent_required` filter overrides the decision. New attributes: `consent_text`, `consent_button`, `privacy_url`.
- **Settings page** (Videos → Settings, `manage_options`). Site-wide defaults every gallery inherits: consent mode and text, filter style, per-page and load-more counts, Subscribe URL and label, and the YouTube Data API key. Shortcode and block attributes still override, so setting Consent = Geo plus a privacy URL once makes a bare `[xroad-videos]` compliant. Stored in one `xrv_settings` option and cleaned up on uninstall.
- **Block sidebar controls.** The `xroad/videos` block gains Gutenberg InspectorControls (no build step) with Layout, Browse, Privacy & consent, and Pre-filter panels. Blank "site default" controls inherit the Settings defaults.
- **Browse: dropdown filters and duration sort.** Facet filters render as compact `select` menus by default (`filter_ui="select"`) or the original clickable `chips`. Sort gains Shortest and Longest first, driven by each card's ISO duration.
- **Card text control.** A `card_meta` setting / attribute / block control sets what appears beneath each thumbnail: `full` (title + description + tags, the default), `compact` (title + description), or `title` (thumbnail + title only, for a clean stock-feed grid). Applies to both the featured carousel and the browse grid; it only omits markup, so there is no front-end payload cost.
- **Tighter card typography.** Card titles are 16px / 700 / 1.2 line-height and descriptions are 13px / 1.3, with the description capped at five lines (line-clamp) so cards stay uniform and read like a clean video feed rather than running long.
- **Inline-asset de-duplication.** The inline SVG, CSS, and JS now emit once per request instead of once per gallery, so a page with two galleries (for example a featured carousel above a library) no longer ships the assets twice. Front-end payload is about 28.8 KB raw / 8.2 KB gzipped per page, with no behavior change.
- **Geo-source status panel** (Settings → Privacy). A live server-side readout of which visitor-country header is present (Cloudflare, GeoIP module / WP Engine GeoTarget, proxy `X-Country-Code`, or AWS CloudFront) and the country the request resolved to, with setup guidance if none is found. Admin-only; no IP lookup, no bundled GeoIP database, no external call.

### 1.0.7

Paged browse grid, Subscribe, and mobile UX.

- **Load More:** `per_page` (default 9) sets how many cards show before a **Load More** button reveals `load_more` (default 3) more, applied to the filtered/sorted set and reset when filters change.
- **Subscribe:** a Subscribe button (YouTube glyph) appears under the grid when `subscribe_url` is set (`subscribe_label` customizes the text).
- **Mobile:** the aligned grid steps 3 to 2 to 1 columns (tablet to phone) via the `--xrv-cols` custom property; on phones the featured carousel becomes a native, thumb-swipeable **scroll-snap** strip (it peeks the next card; arrows and dots hide) with no fragile carousel controls on touch.
- Example: `[xroad-videos layout="library" per_page="9" load_more="3" subscribe_url="https://youtube.com/@yourchannel"]`.

### 1.0.6

Single-page polish and rich schema. Single video pages now emit a **standalone `VideoObject`** (not the CollectionPage wrapper) with `transcript`, **key-moment `Clip`s** (from a new "Key moments" field), `keywords`, `inLanguage`, `isFamilyFriendly`, publisher, and a guaranteed `uploadDate` (it falls back to the post date) for maximum Video and key-moments rich-result and AI-citation eligibility. New meta-box inputs: **Transcript** and **Key moments**. The theme's author byline and duplicate featured image are hidden on single video pages (filter: `xrv_single_chrome_css`).

### 1.0.5

Layouts. New `layout="library"` renders a **Featured Videos** carousel (3-up, arrows and dots, responsive) above a **Browse our Library** grid in one shortcode; `layout="carousel"` is the carousel alone. Fixed `columns` now produce an aligned CSS grid (rows line up) instead of masonry. `controls="false"` hides the search/sort/filter bar. `featured_limit` sets how many videos feed the carousel. Multiple galleries per page are supported (the JS inits every `.xrv` root). Example: `[xroad-videos layout="library" columns="3" controls="false"]`.

### 1.0.4

Lightbox playback: clicking a card in the grid opens the video in a centered overlay over a dimmed backdrop (it "pops out"), closeable via the × button, click-outside, or Esc (which stops playback). Default for the grid; single-video pages still play inline. Choose per instance with the `playback="lightbox|inline"` shortcode attribute.

### 1.0.3

Fix: the click-to-load handler now binds by `.xrv-grid` class instead of `#xrv-grid` id, so the facade works on the single-video page (1.0.2) as well as the shortcode grid.

### 1.0.2

Single-video pages are never empty. Because the CPT is public, each video has a single-post URL; the facade only renders via the shortcode, so that URL used to show a blank body. Now if a video has a Dedicated Page URL it redirects there (default 301, filter `xrv_dedicated_redirect_status`); otherwise the single page renders the facade plus VideoObject schema itself.

### 1.0.1

Editor auto-title: pasting a URL fetches the title from the WordPress oEmbed proxy and fills it, so a URL-only video is immediately saveable (the block editor won't persist a post with an empty title and body). It never overwrites a title you've typed.

### 1.0

YouTube facade, local thumbnails, masonry, self-generating VideoObject schema, `video_play` event, shortcode + block.

### 2.0 (planned)

Vimeo as a second `_xrv_provider` value (ID parser, `vumbnail`/oEmbed thumbnail, `player.vimeo.com` facade with `dnt=1`). No render rewrite; the provider switch already routes for it.

## License

GPL-2.0-or-later.

---

Built by [Crossroad Media](https://crossroad.us).
