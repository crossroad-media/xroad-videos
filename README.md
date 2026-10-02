# Crossroad Videos (`xroad-videos`)

A single-file WordPress plugin by [Crossroad Media](https://crossroad.us). A privacy-first, click-to-load video gallery and a drop-in alternative to **Smash Balloon YouTube Feed** for any site running a cookie/consent manager. Supports **YouTube, Vimeo, Wistia, Loom, Dailymotion, and self-hosted MP4 / WebM** behind one facade, with geo-aware GDPR consent modes, a built-in bulk importer, a site-wide settings page, and multiple layouts.

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

- **Curated CPT** `xroad_video`. Editors paste a video URL from any supported host and set the order with each video's numeric **Order** field (or a gallery sorts itself newest, oldest or by title); the provider is auto-detected and no API key is required.
- **Built-in bulk importer** (Videos → Import). Paste a list of URLs, point at a channel or playlist (optionally with a free API key for durations and upload dates), or upload a JSON file carrying full metadata and taxonomy terms. A dry-run preview shows new vs. already-in-library before anything is written, then the import runs in batched AJAX with a progress bar.
- **Local thumbnail sideload** on save, so the grid references `/wp-content/uploads/` and never calls the host's image CDN. YouTube uses its predictable poster URLs (maxres with hqdefault fallback, checked by HTTP status); every other host supplies its poster, and for all but YouTube its duration, from its own no-key oEmbed endpoint, which also prefills the title on first save.
- **Layouts.** A featured carousel, a browse grid, or both in one shortcode (`layout="library"`), with lightbox or inline playback. Filtering (dropdown selects or chips for Series / Audience / Topic), keyword search, and sort (including shortest or longest by duration) are pure client-side toggles, so they run instantly with no network round-trip. Paged browse adds a Load More button and an optional Subscribe button, and the grid steps 3 to 2 to 1 columns on smaller screens.
- **Geo-aware consent modes.** Global (recommended), Strict GDPR, or Facade only (no prompt). **Global** shows a dismissible opt-in prompt only to EU/UK/EEA/CH visitors (resolved from an edge country header) and stays frictionless for everyone else, including US / CCPA, since the facade shares no data with YouTube until a click. Whenever a prompt is required the plugin makes zero contact with any Google domain until the visitor accepts. See **Privacy, consent & GDPR** below.
- **Site-wide settings page** (Videos → Settings). Set the consent mode, privacy URL, filter style, per-page counts, Subscribe URL, and YouTube Data API key once; every gallery inherits them, and any shortcode or block attribute still overrides.
- **Self-generating VideoObject JSON-LD** inside a `CollectionPage` / `ItemList`, merging with the site's Organization node via the `xrv_org_id` filter. Single-video pages emit a standalone `VideoObject` with transcript and key-moment `Clip`s for rich-result and AI-citation eligibility.
- **Shortcode, block, and block sidebar controls** under the collision-proof `xroad` namespace. The Gutenberg block exposes Collection, Layout, Browse, Card look, and pre-filter panels through InspectorControls, with no build step; every 2.11.0 control has a **Site default** choice.
- **Three REST-exposed taxonomies** (`xrv_series`, `xrv_audience`, `xrv_topic`); sites define their own terms.
- **Multi-source.** One `_xrv_provider` switch routes YouTube, Vimeo, Wistia, Loom, Dailymotion, and self-hosted files, auto-detected from the pasted URL. Hosted videos load on click as the host's privacy-enhanced iframe (Vimeo with `dnt=1`); self-hosted files play in a native `<video>` element with zero third-party contact ever. Vimeo unlisted and domain-private videos keep their privacy hash, and galleries can mix sources in a single grid.

- **WP-CLI** (`wp xrv import | collection set | apply | export | handover | list | rollback`) for scripted, resumable migrations: dry runs with per-field diffs, a JSON run log per run, and an exact rollback. Video addresses can be handed from old pages to XRV in batches or all at once, at the same URLs. See [IMPORT.md](IMPORT.md#8-wp-cli).

No page builder, ACF, jQuery, or build step. The inline-asset architecture (CSS, JS, and SVG emitted once per request) survives a performance plugin's unused-CSS pass and moves between themes unchanged.

## Usage

```
[xroad-videos]
[xroad-videos layout="library" per_page="9" subscribe_url="https://youtube.com/@yourchannel"]
[xroad-videos series="webinars" columns="3" filter_ui="chips" consent_notice="geo" privacy_url="/privacy-policy/"]
```

Block: **xroad/videos** (a PHP-rendered dynamic block that shares the shortcode's render path). Every attribute below is also a block sidebar control, and anything left blank inherits the site-wide default from **Videos → Settings**, which a shortcode or block attribute always overrides.

### Sources

Editors never pick a provider by hand: paste a URL and the host is detected automatically (the **Provider** selector defaults to Auto-detect). Supported sources:

| Source | Paste | Metadata without an API key |
|---|---|---|
| YouTube | watch, `youtu.be`, Shorts, or embed URL | title, poster |
| Vimeo | `vimeo.com/...` (including unlisted `vimeo.com/{id}/{hash}`) | title, poster, duration, description |
| Wistia | a `wistia.com/medias/...` URL | title, poster, duration |
| Loom | `loom.com/share/...` | title, poster, duration |
| Dailymotion | `dailymotion.com/video/...` or `dai.ly/...` | title, poster |
| TikTok | a `tiktok.com/@user/video/...` link (vertical, rendered 9:16) | title, poster |
| Self-hosted file | a direct `.mp4` or `.webm` URL (pick **Self-hosted file**) | none; set the title and upload a poster yourself |

Hosted videos load on click as the host's privacy-enhanced player (Vimeo uses `dnt=1` with no title or byline chrome); **self-hosted files** play in a native `<video>` element, so they make no third-party contact at any point. Galleries can mix sources freely in one grid. The bulk importer and channel sync are currently YouTube-only; extending bulk import to every provider is a planned roadmap item.

**Content and filtering**

| Attribute | Default | What it does |
|---|---|---|
| `series`, `audience`, `topic` | all | Pre-filter to one or more taxonomy term slugs (comma-separated). |
| `limit` | all | Cap how many videos render. |
| `filter_ui` | `select` | Facet filter style: `select` (dropdowns) or `chips` (clickable rows). |
| `controls` | `true` | `false` hides the search / sort / filter bar. |
| `orderby` | `curated` | `curated` (each video's Order number, then date, then ID; a collection keeps its own order), `newest` / `oldest` (upload date, else the post date, then the exact publish time, then ID), or `title`. The Sort menu starts on it and Reset returns to it. |
| `collection` | none | Render a saved Collection by slug (or ID). An unknown, trashed, draft or deleted collection renders nothing for visitors; logged-in editors see a short notice. An empty one shows "No videos found." |
| `ids` | none | An exact, ordered list of video post IDs (what collections ride on). |
| `shorts` | `all` | `all`, `only` (a swipe shelf), or `hide` YouTube Shorts. |

**Layout**

| Attribute | Default | What it does |
|---|---|---|
| `layout` | `grid` | `grid`, `carousel`, or `library` (a featured carousel above a browse grid). |
| `columns` | responsive | Fixed column count; blank gives responsive columns (masonry for `grid`, 3 for `library`/`carousel`). |
| `featured_limit` | `6` | How many videos feed the featured carousel. |
| `heading` | none | Optional centered section title above a `grid` or `carousel`. |
| `playback` | `lightbox` | `lightbox` pops the video into a centered overlay; `inline` plays it in the card; `lightbox-desktop` / `lightbox-mobile` use the overlay on that device class only (decided in the browser, so pages stay cacheable). |
| `lightbox_details` | `true` | Title, date and description under the player in the lightbox. |
| `lightbox_desc` | `collapsed` | `collapsed` (four lines with **Show more** / **Show less**) or `full`. |
| `lightbox_page_link` | `false` | `true` adds an "Open video page" link to the lightbox caption, to the same page as the card title. |
| `per_page` | `9` | Cards shown before a **Load More** button appears. |
| `load_more` | `3` | How many more cards each **Load More** click reveals. |
| `subscribe_url`, `subscribe_label` | off | Show a Subscribe button under the grid when `subscribe_url` is set; `subscribe_label` sets its text. |

**Card look**

| Attribute | Default | What it does |
|---|---|---|
| `card_meta` | `full` | Text under each poster: `full` (description + tags), `compact` (description), or `title`. |
| `card_align` | `auto` | `auto` (grids left, carousels centred), `left`, or `center`. Title, date, description and tags follow it. |
| `card_date` | `false` | `true` shows the upload date (or the post date) under each title as `<time datetime>`; format with the `xrv_card_date_format` filter. |
| `desc_chars` | `0` | Trim the visible card description to N characters at a word boundary with "…" (0 = no trim). The lightbox keeps the full text. Screen-reader users also hear only the trimmed text on the card. |
| `show_duration` | `true` | `false` hides the running-time badge (the duration sort still works). |
| `hover_style` | `zoom` | `zoom`, `dim` (darkens the poster), or `none`. Also used on keyboard focus; reduced-motion visitors get no zoom or fade. |
| `thumb_link` | `none` | `watch` makes the poster a real link to the same page as the title: a plain click still plays, Ctrl / Cmd / middle click opens the page. |
| `subscribe_icon` | `brand` | `brand` (red YouTube mark) or `mono` (the button's text color, triangle cut out). |

Booleans accept `true` / `false` (also `1` / `0`, `yes` / `no`, `on` / `off`).

**Privacy and consent**

| Attribute | Default | What it does |
|---|---|---|
| `consent_notice` | `off` | `geo` = **Global** (opt-in prompt for EU/UK/EEA/CH; one-click + facade elsewhere, including US / CCPA), `strict` = **Strict GDPR** (opt-in prompt for everyone), `off` = **Facade only** (no prompt). See [Privacy, consent & GDPR](#privacy-consent--gdpr). Watch pages and `[xroad-video]` embeds follow the site setting. |
| `consent_text` | built-in | Body text of the consent prompt. |
| `consent_button` | `Load video` | Accept-button label. |
| `consent_decline` | `No thanks` | Decline-button label. |
| `privacy_url` | WP privacy page | Privacy-policy link shown in the prompt. |
| `preconnect` | `false` | `true` opens a DNS + TLS connection to the video host when a visitor hovers or tabs to a video (a slightly faster start, but contact with the host before the click). Never while a consent prompt is required. Off by default since 2.11.0. |

Site-only settings (no attribute): **Watch page display** (text under the player, a plain or rich description, and 301 or 302 for dedicated URLs) and Auto-sync's **Only videos published since**.

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
| `xrv_card_date_format` | The PHP date format of the card date. Default `'F j, Y'`. |
| `xrv_inline_script_attrs` | Extra attributes for the inline `<script id="xrv-js">`, as `[ name => value ]` (`true` prints a bare attribute), e.g. `[ 'data-no-optimize' => '1', 'nowprocket' => true ]` to keep a "delay JS" optimizer away from the facade. Args: `$attrs, $id`. |
| `xrv_dedicated_redirect_status` | The status for a video's dedicated-URL redirect. Args: `$status` (the **Dedicated URL redirect** setting, 301 by default), `$post_id`. Clamped to 301 / 302 / 307 / 308. |
| `xrv_watch_page_off_url` | Where a video with its watch page off redirects. Args: `$url` (`home_url('/')`), `$post_id`. |
| `xrv_watch_page_off_status` | That redirect's status. Args: `$status` (302), `$post_id`. Clamped to 301 / 302 / 307 / 308; 404 or 410 serve the theme's not-found page with that status instead. |
| `xrv_poster_sizes` | The `sizes` attribute of card posters. Args: `$sizes` (`'(max-width: 782px) 100vw, 480px'`), `$attachment_id`. |
| `xrv_single_chrome_css` | The small CSS that hides the theme's byline and featured image on a watch page. Arg: `$css`. |
| `xrv_video_language` | `inLanguage` on a watch page's VideoObject. Args: `$lang` (`'en'`), `$post_id`. |
| `xrv_handover_query_vars` | The query vars WordPress serves when XRV steps aside at an address it has not been handed (the old page's). Args: `$alt_vars`, `$xrv_vars`, `$post_id` (0 when no published video has that slug). Return `$xrv_vars` to make XRV serve anyway. |
| `xrv_lock_stale_after` | Seconds after which a write lock with no heartbeat (a killed sync or WP-CLI run) can be taken over. Default 900. |

Action: `xrv_library_changed` fires with an array of post IDs after channel sync or a WP-CLI command writes videos (hook a cache purge to it).

## Styling tokens

Every gallery reads these CSS custom properties, so a theme can restyle XRV without overriding selectors. Set them on `.xrv` (or any ancestor).

| Token | Default | Styles |
|---|---|---|
| `--xrv-title-size` / `--xrv-title-weight` / `--xrv-title-color` | `16px` / `700` / `--xrv-primary` | Card titles. |
| `--xrv-date-color` | `--xrv-muted` | The card date. |
| `--xrv-radius` | `6px` | Poster, player and consent overlay corners. |
| `--xrv-button-weight` | `600` | Load more and Subscribe text weight. |
| `--xrv-loadmore-bg` / `--xrv-loadmore-color` / `--xrv-loadmore-radius` / `--xrv-loadmore-hover-bg` | `--xrv-text` / `#fff` / `5px` / `--xrv-primary` | The Load more button. |
| `--xrv-primary`, `--xrv-action`, `--xrv-accent`, `--xrv-link`, `--xrv-text`, `--xrv-muted`, `--xrv-border`, `--xrv-subscribe`, `--xrv-font` | brand defaults | Colors and font across the gallery (as before). |

Example, merging schema with Yoast/Rank Math's Organization node:

```php
add_filter( 'xrv_org_id', fn() => 'https://example.com/#organization' );
```

## Verify the privacy guarantee

1. Open a page using the gallery in DevTools (Network tab, cache disabled) and confirm **zero** requests to `youtube.com` / `youtube-nocookie.com` / `google.com` / `i.ytimg.com` before any click, and zero YouTube cookies / `localStorage`.
2. **Check for connection hints too.** A `preconnect` or `dns-prefetch` never shows in the Network tab, because it is a connection, not a request. Hover a poster, press Tab onto one, then in the Console run `document.querySelectorAll('link[rel=preconnect],link[rel=dns-prefetch]')`: with the default settings the list has no video host in it. To see the sockets themselves, record a `chrome://net-export` log while you hover and tab, and look for the host in it.
3. Repeat with your consent manager active and confirm no banner, overlay, or black screen.

If any video-host request or connection happens pre-click with **Warm-up on hover** off, that's a bug; open an issue.

## Privacy, consent & GDPR

The gallery is a **click-to-load facade**: every card is a local first-party poster + a play button. With the default settings nothing (no request, cookie, connection, or `localStorage`) reaches the video host until a visitor deliberately clicks. The one opt-in exception is **Warm-up on hover** (`preconnect`), which opens a cookie-free connection when a visitor hovers or tabs to a video; it is off by default since 2.11.0 and never runs while a prompt is required. On click it injects the host's privacy-enhanced player (a `youtube-nocookie.com` iframe for YouTube, `player.vimeo.com` with `dnt=1` for Vimeo, the equivalent for Wistia / Loom / Dailymotion, or a native `<video>` for self-hosted files); **that click is the consent** that loads the embed.

On top of that baseline, the **Consent mode** (Settings → Videos → Settings, or `consent_notice=`) sets how consent is obtained:

| Mode | `consent_notice` | What the visitor gets | Pre-click contact with the host |
|---|---|---|---|
| **Global** *(recommended)* | `geo` | EU/UK/EEA/CH visitors get the opt-in "Load video" prompt; everyone else (including US / CCPA) plays in one click | **none** (with Warm-up on hover turned on: none for prompted visitors, a hover connection for others) |
| **Strict GDPR** | `strict` | opt-in prompt for **every** visitor, worldwide | **none** for anyone |
| **Facade only** | `off` | no prompt or notice | **none** (with Warm-up on hover turned on: a hover connection, no data or cookies) |

The opt-in prompt is declinable (× / "No thanks"), so refusing is as easy as accepting. **Global** satisfies GDPR where it applies (a prior-consent gate for EU/UK/EEA/CH) and the US notice-and-opt-out model elsewhere, because the facade shares no data with YouTube until the click. Galleries, watch pages and `[xroad-video]` embeds all follow the same mode. *(Informational only, not legal advice.)*

Key points:

- **The plugin sets no cookies of its own.** The only client-side storage it writes is a first-party `sessionStorage` flag caching the geo decision. That is not a cookie, not an identifier, and it clears on tab close.
- **Dismissible consent.** The prompt has an `×` and a **"No thanks"** button; declining closes it and loads nothing, so refusing is as easy as accepting.
- **Zero pre-click contact when gated.** Whenever a prompt is required (Strict for everyone; Global for EU/UK/EEA/CH), the opt-in hover `preconnect` is suppressed too, so the visitor's browser makes no DNS/TLS/HTTP contact with any Google domain until they accept.
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

### 2.11.2

Front-end routing and WP-CLI fixes. Sites whose old video plugin did not use XRV's exact URL base see no front-end change.

- **Old pages keep their addresses when the old plugin used the same base.** When another post type's URL base is exactly XRV's (for example an old video plugin at `/blog/videos/<slug>/` and XRV's base moved to `videos` under the same prefix), the two generate rewrite rules with identical keys and XRV's replaced the old plugin's. 2.11.1 then found nothing to step aside to, so XRV served every address at once, before any handover, and old pages with no XRV video returned a 404. XRV now rebuilds the rules it overwrote and checks them first, so each old page keeps serving until its video is handed over.
- **`wp xrv rollback` no longer reports a correct restore as incomplete.** Its final check compared a fingerprint of the whole rewrite table, which also changes when other plugins' rules are rebuilt in another context (a plugin update, a flush from a web request). It now compares XRV's own rules only. Logs written by earlier versions keep the old check.
- **The rule check follows a base change inside one command**, so an `apply` that moves the base and a handover check in the same process see the new rules.

### 2.11.1

Front-end routing and WP-CLI. A migration can now hand video addresses from the old pages to XRV **in batches or all at once**, at the same URLs. Sites whose video base doesn't share addresses with other content see no change.

**Behaviour fixes you may notice**

- **XRV steps aside at addresses it hasn't been handed.** When the video base matches addresses other content already uses (for example base `videos` on a site whose posts live at `/blog/videos/<postname>/`), 2.11.0 claimed every one of them, so an old page whose XRV video was a draft, or not handed over yet, returned a 404. XRV now serves such an address only for a published video that has been handed it; otherwise WordPress resolves the address as if XRV's rules weren't there, and the page that lives there keeps serving (including its attachment and comment-page URLs). With nothing else at the address, XRV serves it as before, so there is no 404 and no redirect loop.
- **A dedicated URL equal to the video's own address now means "not handed over yet".** The old page keeps that address until the dedicated URL is removed. 2.11.0 ignored such a dedicated URL and served XRV.

**New**

- **`wp xrv handover <ids> | --all`** hands addresses to XRV (or back, with `--to=old`) for one batch or everything left. It purges the object cache (and WP Engine's caches), writes a run log, and `wp xrv rollback <handover log>` undoes it. Videos whose watch page is off are kept unless `--force`, because handing them over would redirect the address home.
- **`wp xrv list [--state=xrv|old|redirect|home|draft]`** shows who serves each video's own address.
- The All Videos **Watch page** column notes when the old page still serves a video's address, or when it redirects to its dedicated URL. The video editor's dedicated-URL notice explains the same.
- Adding `?preview=true` to an address shows the XRV page before it is handed over.
- Filter **`xrv_handover_query_vars`** (`$alt_vars`, `$xrv_vars`, `$post_id`) can veto a step-aside per request.

**Upgrading and rollback**

- No settings change; nothing to migrate.
- Before downgrading to 2.11.0 with the video base on the old addresses, hand every address over (`wp xrv handover --all`) or move the base back first: 2.11.0 claims every address under the base and would 404 the old pages.

### 2.11.0

Front-end, admin and WP-CLI. Built for moving a library off a YouTube feed plugin onto XRV without losing URLs, dates or posters: a scripted, resumable WP-CLI importer with rollback, sort orders, and a round of display options. Every new option defaults to 2.10.0 behaviour except one.

**DEFAULT CHANGE**

- **Warm-up on hover is now off.** In 2.10.0 every gallery opened a DNS + TLS connection to the video host (for example `youtube-nocookie.com`) when a visitor hovered or tabbed to a card: no cookies, but contact with the host before any click. It is now an opt-in setting: Settings → Privacy & consent → **Warm-up on hover**, or `preconnect="true"`. When on, it is still never made while a consent prompt is required, and it adds one `<link>` per host per page instead of one per card.

**Behaviour fixes you may notice**

- **Watch pages and `[xroad-video]` embeds honour the consent mode.** Their markup carried only the playback mode, so Strict and Global never prompted there and hover always warmed up. They now share the gallery's consent, warm-up and display settings.
- **Carousel titles are centred.** A left-align rule beat the centred caption. The "No videos match" heading is centred too.
- **The lightbox fits the screen.** The player is sized from the viewport height (`vh`, then `dvh`) as well as its width, so at 1366×657 the title and the close button stay on screen (the caption used to shrink to 38 px). Landscape phones get the caption in a column beside the player, and long links in a description wrap. The description toggle reads **Show more** / **Show less**, and the close button's label is set on every open.
- **Shortcodes typed into a description no longer run.** The watch page's content filter ran before `do_shortcode`, so a `[video src=…]` in a description executed, could load a third party before the click, and broke the JSON-LD. It now runs after, and brackets in card text, data attributes and JSON-LD strings are encoded.
- **Auto-sync skips unlisted videos** (and private ones), skips failed, rejected and deleted uploads, and waits for processing, live and upcoming videos. When YouTube's details call fails, nothing is added (2.10.0 created posts titled with the raw video ID). New videos are dated from their YouTube publish time.
- **Curated order is stable.** Videos with the same Order number fall back to their date, then post ID, instead of the database's whim; client-side sorts break ties on the curated position.
- **Redirects** from a dedicated URL send `X-Redirect-By: XRV`, never point a page at itself, and skip editor previews and oEmbed views.
- **Deactivating the plugin removes its rewrite rules** (the post type is now unregistered before the flush), and changing or clearing the collection base no longer leaves the old archive rules behind.
- **Block themes no longer break the inline script.** Block themes run `wptexturize()` over the whole page, which turned `&&` in the facade script into `&#038;&#038;`. The script body now sits in an HTML comment, which `wptexturize()` skips.
- **Cards past "Show before Load more" are printed hidden**, so a delayed or failed script no longer flashes the whole library; with JavaScript off every card shows.
- **JSON-LD names** no longer print `Doctor&#8217;s` or `&amp;`, and `uploadDate` falls back to the post date.

**New options** (Settings, plus a matching `[xroad-videos]` attribute and block control with a "Site default" choice)

- **Default order** (`orderby`): curated, newest, oldest or title. Newest means the upload date, then the exact publish time, then the post ID. Sorted on the server before facets are counted and `limit` is applied; the Sort menu starts on it and Reset returns to it. Collections get their own **Order** select.
- **Hover effect** (`hover_style`): zoom (as before), dim, or none, on hover and on keyboard focus, with a reduced-motion fallback.
- **Card text alignment** (`card_align`): automatic (grids left, carousels centred), left, or centred.
- **Upload date on cards** (`card_date`): a `<time>` under the title; format via the new `xrv_card_date_format` filter.
- **Trim card descriptions** (`desc_chars`): a word-safe trim with "…"; the lightbox keeps the full text.
- **Duration badge** (`show_duration`) can be hidden; duration sorting still works.
- **Subscribe button icon** (`subscribe_icon`): YouTube red (unchanged pixels) or the button's text color with the triangle cut out.
- **Lightbox description** (`lightbox_desc`): four lines with Show more, or in full; **Open video page** link (`lightbox_page_link`).
- **Poster click** (`thumb_link`): the poster can also be a real link to the watch page. A plain click still plays; Ctrl / Cmd / middle click opens the page; Space plays.
- **Watch page display**: text under the player (full, compact, title only) and a **rich** description with line breaks and clickable links (`rel="nofollow noopener"` on external ones). The watch page poster now loads eagerly.
- **Dedicated URL redirect**: 301 (as before) or 302 while a migration is in progress (with no-cache headers). The `xrv_dedicated_redirect_status` filter now receives the post ID and is clamped to 301 / 302 / 307 / 308.
- **Auto-sync "Only videos published since"** date.
- **Styling tokens**: `--xrv-title-size`, `--xrv-title-weight`, `--xrv-title-color`, `--xrv-date-color`, `--xrv-radius`, `--xrv-button-weight` (600, as before), `--xrv-loadmore-bg`, `--xrv-loadmore-color`, `--xrv-loadmore-radius`, `--xrv-loadmore-hover-bg`.
- **`xrv_inline_script_attrs`** filter and stable `xrv-css` / `xrv-js` ids, so a "delay JS" optimizer can be told to leave the facade alone.
- **WP-CLI**: `wp xrv import`, `collection set`, `export`, `apply` and `rollback`, with dry runs, JSON run logs, a shared write lock (also used by sync), crash-safe resume, and exact rollback. See [IMPORT.md](IMPORT.md#8-wp-cli).

**Fixes**

- The settings sanitizer is idempotent and safe on partial input: a second pass no longer wipes the play-button colors or turns consent off, and array input no longer throws a PHP 8 TypeError. `xrv_settings_prepare()` merges a partial array onto the stored settings.
- A PHP 8 fatal in the gallery renderer when a video had terms (a loop variable overwrote the settings array).
- The block's "0 = site default" on **Show before Load more** rendered one card per page.
- An unknown or trashed collection slug, or `collection="0"`, rendered the whole library. Those, and draft collections (which rendered their videos), now render nothing for visitors, with a short notice for editors. An empty collection shows "No videos found." without querying.
- Percent signs (`50%`, `%20` in links) in descriptions, transcripts and chapters survive a save, an import and a sync.
- **Rebuild local posters** and uninstall never delete an image that another post uses or that belongs to another post.
- Imported and synced Shorts keep a `/shorts/` source URL, so saving the video in the editor no longer turns them back into 16:9 cards.
- Upload dates are stored as a real site-local day; the editor's date picker no longer allows tomorrow in UTC-behind time zones.
- The Video URLs card shows full addresses including the permalink front (`/blog/videos/…` on a site whose posts live under `/blog/`), and a base that repeats the front is refused instead of becoming `blogvideos`. Saving unchanged URLs rebuilds the rewrite rules.
- The video editor shows a read-only notice when a dedicated URL is set.
- The keyboard focus ring on posters is drawn inside the poster, where `content-visibility` can no longer clip it.
- Watch pages between 561 and 880 px wide rendered at half width.
- On phones the search box stretched to 320 px tall under the stacked filter bar, and the filter menus started at different points; both are fixed.
- Documentation no longer claims drag-to-reorder: the order is the numeric Order field (Post Attributes), and collections use up / down buttons. Privacy docs now say how to check for connection hints, which the Network tab cannot show.

**Upgrading and rollback**

- No migration step: new settings read their defaults until Settings is saved. Review **Warm-up on hover** if you relied on it.
- Downgrading to 2.10.0 is safe, but 2.10.0 drops the new settings keys the first time Settings is saved there. **Delete dedicated URLs before downgrading mid-migration**: 2.10.0 sends every dedicated-URL redirect as a 301, which browsers cache.

### 2.10.0

Front-end + admin. Two lightbox upgrades: the pop-out modal becomes a **per-device** choice, and it can now carry a **details panel** (title, date, collapsible description) under the player. The zero-third-party-request-before-click guarantee is untouched.

- **Device-scoped playback.** XRV → Settings → *Browse defaults* → **Play videos in** now offers: lightbox on **all devices**, **lightbox on desktop / inline on mobile**, **lightbox on mobile / inline on desktop**, or **inline everywhere**. The XRV Video block and `[xroad-videos playback="…"]` accept the same four values (plus *Site default*), so any gallery can override the site setting.
- **Decided in the browser, not on the server.** The desktop / mobile split is resolved client-side by viewport width (under 768 px is mobile) at the moment of the click, so the rendered HTML is identical for every visitor and the page stays fully cacheable — no UA sniffing, no cache fragmentation. A rotate or resize lands the next play in the right mode with no reload.
- **Lightbox details panel.** A watch-page-style caption can now sit under the player in the lightbox: the **title**, a relative **upload date** (“2 months ago”), and the **description** collapsed to four lines behind a *Description* toggle. On by default; turn it off at Settings → *Browse defaults* → **Lightbox details**, or per gallery with `[xroad-videos lightbox_details="false"]` / the block toggle. The panel is text rendered from the video’s own stored metadata — no view count, and no extra network calls. Shorts stay player-only.

### 2.9.0

Adds **Collections** — build more than one curated gallery and drop each anywhere on the site, including the hero. Removes the 2.8.0 privacy self-test. Existing single galleries are unchanged; the zero-third-party-request-before-click guarantee is untouched.

- **Collections** (new *Collections* screen under XRV Video). A collection is a saved, placeable gallery: **hand-pick and order** specific videos, choose a **layout** (grid / carousel / library), and drop it with `[xroad-videos collection="slug"]` or the XRV Video block's *Collection slug* field — in a page, a widget, an FSE template part, or the theme hero. Presentation (columns, heading, etc.) is set on the shortcode/block at each placement. Dependency-free editor (no wp.media, no build step).
- **`ids` attribute.** `[xroad-videos ids="12,7,30"]` renders an exact, ordered set via `post__in` — what collections ride on, usable on its own too.
- **Multiple galleries per page.** The facade is now fully instance-scoped (wrapper and every control are class- or per-instance-id based), so two carousels plus a filtered grid coexist on one page with independent search / sort / filter.
- **Removed:** the privacy self-test shipped in 2.8.0 — low practical value in an isolated render; may return later as a real-page check.

### 2.8.0

Adds a **Privacy self-test** that turns the zero-third-party guarantee from a claim into an in-product proof. Front-end harness + admin diagnostic; no new dependencies, no front-end weight on real pages.

- **Resource-Timing privacy self-test** (Settings → Privacy self-test). One click renders your real gallery in an isolated, admin-only frame, reads the browser's `performance.getEntriesByType('resource')` log without ever dispatching a play, and reports a clear **PASS/FAIL** — naming by URL any pre-click request to a video-provider host (YouTube, Vimeo, Wistia, Loom, Dailymotion, TikTok and their CDNs). It is both the strongest privacy demo for a regulated-industry buyer and a standing regression guard: a future change that leaked a pre-click request turns this red. Requests to other origins you control (e.g. an image CDN) are reported but do not fail the test.
- **Verify the detector.** A negative-control mode loads a deliberately leaky page (one planted provider request) so you can watch the detector catch a real leak — the only place XRV ever contacts a provider without a click, and only when an admin explicitly runs it.
- Doc-hygiene: corrected the stale note that multi-source bulk import was "planned for 2.5."

### 2.7.1

Admin-only. Enhanced Watch Page Controls: finer control over each video's standalone page, plus a clearer home for the bulk switch.

- **Editable watch page slug in the editor.** Ticking "Give this video its own watch page" now reveals an editable URL-slug field, so the standalone page's address is set inline (kept unique by WordPress). It hides when the video is gallery-only.
- **Watch Page Controls, relocated.** The bulk on/off card (renamed from "Bulk watch pages") now sits beside the Video URLs card in Settings, with its own nav chip, grouped where it belongs since watch pages are the per-video URLs.

### 2.7.0

Admin-only. Bulk controls for the two things a privacy-first curated library has that a feed plugin does not: per-video SEO **watch pages** and **local-poster** integrity.

- **Bulk Actions on All Videos.** The native Bulk Actions dropdown gains **Watch page: turn off / turn on** (set a selected set's standalone-page state in one go) and **Rebuild local posters** (re-pull each selected video's poster from its provider, swap in the fresh copy, and drop the stale one — the repair tool for broken or missing thumbnails). A new **Watch page** column shows each video's On/Off state at a glance.
- **One-click whole-library toggle.** Settings gains **Turn off all watch pages** / **Turn all back on** buttons that apply to every video at once, for when you want the library gallery-only without selecting row by row.

### 2.6.0

Admin-only. Extends the bulk importer so the **watch page** state is part of the import record, making it controllable per video from a file.

- **`watch_page` import field.** A `.json` import record now accepts `"watch_page": "0"` (no standalone watch page) or `"1"` (force on); omit it to leave the video's current setting untouched. Accepts string, number, or boolean (`false`/`"no"`/`"off"` all map to off), so a hand-authored file can switch watch pages off without knowing the stored value. Paired with **Overwrite**, a re-import flips videos already in the library; on a fresh import it sets the state at creation. The URL/channel/playlist paste paths are unaffected (they carry no such field, so the default stays on).

### 2.5.1

Reworks the per-video "dedicated page" control into a clearer **watch page** toggle.

- **Watch page toggle (on by default).** Each video can have its own standalone watch page — its own URL, with the embedded player and VideoObject schema — and the gallery card **title** links to it. The card still plays in place in the modal; the title link is a separate affordance, not a bypass. Untick to keep a video gallery-only: its title stops linking and its standalone URL redirects home (filterable via `xrv_watch_page_off_url` / `xrv_watch_page_off_status`).
- The old free-text "dedicated page URL" field is retired from the editor. Any URL already saved on a video is still honored as the title's link target, so nothing breaks.

### 2.5.0

Two new front-end capabilities plus a per-video editor pass.

- **Embed any video anywhere.** `[xroad-video url="..."]` now renders the privacy-first click-to-load facade for any supported URL with no library entry needed, so an editor can drop a video into any post or page by pasting its link. Optional `poster` (a local attachment id or URL) and `title` attributes; the poster is sideloaded locally on first render and cached, so the embed still makes zero third-party requests before the click. Curated library videos (`[xroad-video id="123"]`) keep their richer VideoObject schema.
- **TikTok support.** TikTok joins YouTube, Vimeo, Wistia, Loom, and Dailymotion as an auto-detected source. Paste a `tiktok.com/@user/video/...` link; it renders vertical (9:16) and loads on click as TikTok's native iframe player (no `embed.js`), poster fetched keyless via oEmbed. Works in galleries, single embeds, and the URL embed above.

A per-video editor pass focused on a calmer, more intuitive add/edit flow. Admin-facing; the front-end gains optional mobile posters and auto-generated image renditions.

- **Single-field editor.** The `xroad_video` post type no longer loads the full block editor body (videos are defined by their meta, not post content). The Add/Edit screen is now just a title field plus the Video Details box.
- **Opt-in dedicated-page link.** The "Dedicated page URL" field is hidden behind a checkbox, so it only appears when you actually want a card to link out — a clearer default.
- **Friendly duration entry.** Type the running time as `minutes:seconds` and the schema-accurate ISO 8601 value (`PT12M30S`) fills itself. A help accordion explains the format and links to a converter.
- **Native upload-date picker.** Upload date is now a real date picker instead of a hand-typed `YYYY-MM-DD` string.
- **Poster images, expanded.** The poster control is split into a clear **Primary poster** (16:9, grids/desktop) and an optional **Mobile poster** (a portrait/square crop shown ≤600px, served via `<picture>` and still 100% local — no third-party calls). Recommended specs live in an inline help accordion.
- **Auto-generated renditions.** Registered `xrv-poster` (1280×720) and `xrv-poster-mobile` (720×960) image sizes, so WordPress generates desktop and mobile crops of every poster on upload.

### 2.4.0

A full admin polish pass. One shared design system now brands every screen, and a bold-carat Q&A accordion puts answers one click away. Admin-facing only; no front-end or behavior change.

- **One design system across every screen.** A single, scoped admin stylesheet (Crossroad palette, card system, buttons, badges, line-icons) is now shared by *Settings*, *Import*, and the per-video editor, so the whole plugin reads as one finished product. The *Import* screen, previously stock WordPress chrome, now matches *Settings* — branded header lockup, cards, and a 1-2-3 step indicator for the import workflow.
- **Bold-carat Q&A accordions.** Each screen gains a *Questions & answers* card: common admin questions (consent mode, API key, URL/SEO, thumbnails, Shorts, duplicates) expand inline with a bold rotating carat. The per-video editor's *Rich video schema* disclosure and contextual help use the same pattern.
- **Premium page headers.** Every screen opens with a logo-locked hero (eyebrow, title, one-line summary, version chip) instead of a bare `<h1>`.
- **Sharper navigation.** The Settings pill nav gains section icons and a scroll-spy active state that tracks the section in view.
- **Tidied structure.** The on-demand *Run a sync now* control is now its own card (it previously floated outside the card grid), and section headers carry consistent icon chips.
- **Single source of version truth.** A new `XRV_VERSION` constant feeds the script handles and update check.

### 2.3.0

Faster first paint and a live edge-region check. The front-end gains an LCP optimization; the new diagnostic is admin-only.

- **Faster first paint.** The first card in a grid now loads its poster with `loading="eager"` and `fetchpriority="high"` (the LCP image); every other card stays lazy. No markup or API change for callers.
- **Edge check (Settings, Privacy & consent).** A new admin-only diagnostic runs a live region check against the REST endpoint, times the round trip with a count-up, names the detected country, and contrasts XRV (zero extra calls, zero cookies, cache intact) with a typical geo-IP plugin. It shows a one-toggle Cloudflare hint when no region header is present. The front-end gallery is unchanged.

### 2.2.2

Improved UI aesthetics. Admin-facing only; no front-end or behavior change.

- **Settings cards de-cluttered.** Removed the decorative `{` brace accent that ran down the left edge of every Settings card and tightened the reclaimed left gutter so card content now aligns symmetrically with the right edge.

### 2.2.0

Admin UX cleanup from a full review. No front-end behavior change; admin code got smaller, not bigger.

- **Settings consolidated.** The separate *Default poster*, *Play button*, and *Shorts* cards are now one **Appearance** card (one nav pill instead of three).
- **Consent copy de-scared.** "No Consent Integration" is now "Facade only (no prompt)," and the card leads with "all three modes are cookie-free until the click" so the safe option no longer reads as unsafe. The geo-source diagnostic is hidden unless the *Global* mode is selected (it is irrelevant otherwise).
- **Block trimmed.** The block Inspector no longer duplicates site policy/look — filter style, card text, consent mode, and privacy URL now simply inherit the site defaults; the block keeps the genuinely per-instance controls (layout, columns, playback, heading, shorts, per-page, load-more, subscribe, pre-filter terms).
- **Shortcode footgun fixed.** Using the singular `[xroad-video]` without a valid `id` now shows editors a visible hint pointing to the correct usage (and to the plural `[xroad-videos]` for galleries), instead of failing silently.
- **Dead code removed.** Deleted the unreachable `light` consent mode and the vestigial write-once `locked` flag.
- **URL safety upgraded.** Settings → *Video URLs* now shows how many published videos use the current base, warns prominently when any are live, and rewrite rules auto-flush on version change so a changed base resolves without a manual re-save. Menu label is now "XRV Video" for clarity.

### 2.1.2

Rebrands the product to **XRV (Xroad Video)** and makes the URL base editable. Admin-facing only; no breaking changes.

- **Rebrand to XRV.** The admin menu, Settings title, plugin name, and block are now **XRV** (the maker, Crossroad Media, stays as author/logo). Internal identifiers are deliberately unchanged — the post type (`xroad_video`), shortcodes (`[xroad-videos]`, `[xroad-video]`), block name (`xroad/videos`), option keys, and text domain stay the same, so existing content, embeds, and URLs keep working.
- **Default URL base is now `/video/`** (was `/xroad-video/`) for a cleaner single-video path. Fresh installs pick this up automatically.
- **The video URL base is now editable** in Settings → *Video URLs*, rather than write-once. Each change is gated behind a confirmation box, an inline warning, and a **two-part test**: before changing a base that is already live, check (1) organic/search traffic to that path and (2) inbound or internal links to it. **If either exists, it is a showstopper** — don't change the base without a full 301-redirect migration first. (Also avoid a base that collides with an existing page slug.)

### 2.1.1

Finishes the Shorts story end-to-end, refines the admin UI, and bounds bulk imports. No new dependencies; the only front-end weight added is the swipe-shelf CSS.

- **Import is capped to 50 videos per run.** A pasted playlist or channel of thousands no longer fires thousands of metadata + Shorts-probe + thumbnail requests at once — the preview reports the overflow ("N more found — capped to 50 per import") and the run step enforces it server-side. Run the import again to add the rest. Self-hosted MP4/WebM stays one file at a time (one video per post).
- **Admin brand accent fixed.** The card `{` accent is now uniformly scaled and vertically centered (`background-size:contain`) instead of stretched to full card height — stretching a stroked brace distorted it into a thin wobbly line.

- **Synced/imported Shorts are auto-flagged.** The channel sync and the bulk importer now detect a Short for each *new* video (a one-time HEAD probe of the canonical `/shorts/` URL — the Data API exposes no aspect ratio) and store the flag, so Shorts pulled in automatically render vertical with a portrait poster instead of needing a manual `/shorts/` paste or re-save.
- **Swipe shelf for `shorts="only"`.** A verticals-only gallery now lays out as a horizontal, thumb-swipeable scroll-snap strip (the familiar Shorts shelf) rather than a grid of tall cards. Pure CSS, scoped to that one layout.
- **Block-editor Shorts control.** The Crossroad Videos block gains a *YouTube Shorts* selector (Site default / Show alongside / Only / Hide) in its Browse panel, mirroring the shortcode `shorts` attribute. Blank inherits the Settings default (the render now falls back to the site default rather than forcing `all`).
- **DM Sans on the admin chrome.** The Settings UI now prefers DM Sans where it's present on the system, falling back to the native UI sans stack. Deliberately **not** bundled: Google Sans (the brand display face) is proprietary and not licensed for self-hosting, and shipping font binaries would contradict the plugin's zero-dependency, no-third-party-request posture. The palette and logo already carry the brand; this adds the typeface for anyone who has it at zero cost.

### 2.1.0

Closes the gaps raised in a video-infrastructure review. All four additions reuse existing rendering; no new dependencies, front-end payload effectively unchanged.

- **YouTube Shorts.** Shorts are auto-detected from their `/shorts/` URL and treated as the vertical (9:16) videos they are: a **portrait poster** (sideloaded from YouTube's original-aspect `oardefault` image, not a letterboxed 16:9 frame), a **9:16 facade**, and a **portrait lightbox** that pops on click and **loops** (the Shorts feel). In `inline` playback they play in their vertical card instead. A `shorts` attribute — `all` (default), `only` (a verticals-only shelf), or `hide` — plus a default in the new Settings → *Shorts* tab controls how each gallery treats them. This directly resolves the common WordPress Shorts headaches (black-bar aspect ratio, theme/oEmbed wrapper conflicts, letterboxed thumbnails, manual `/shorts/`→`/embed/` conversion, pre-consent cookies, heavy on-load iframes) — the facade architecture pre-empts most by design. (Channel-imported shorts normalize to a watch URL, so flag those by adding via the `/shorts/` URL or a re-save.)
- **Selectable video URL structure (write-once).** Settings → *Video URLs* lets you choose the single-post base (e.g. `/video/{slug}`) and an optional collection/archive base (e.g. `/videos/`) — any combination. It's a deliberate, **one-time** choice: once locked it can't be changed (live URLs and SEO depend on it), and locking flushes rewrite rules once. Defaults preserve the historical `/xroad-video/` base, so existing installs are unchanged until an admin sets a structure.
- **Single-video embed.** New `[xroad-video id="123"]` shortcode drops one video inline anywhere — same privacy-first click-to-load facade and VideoObject schema as its dedicated page, for editorial placements like an About page that sit a specific video in the page flow (replacing raw `youtube-nocookie` iframes that otherwise contact Google on page load). Reuses `xrv_render_single()`, so it works for every 2.0 source. Embed by post ID; URL-paste resolution is a documented upgrade path. Add `playback="lightbox"` to pop the video in a centered modal (matching a Divi/Magnific video-popup) instead of playing in place.
- **Full description on dedicated pages.** A video's own page now shows its complete description (the card line-clamp is lifted only in single-video context), so there's a place to read the full text the grid preview truncates.
- **Incomplete videos stay out of galleries.** A video with no resolved platform ID can't play, so it is no longer rendered as a dead, unclickable card — it is skipped until a working URL is added. Fixes the "some videos show with no thumbnail and aren't clickable" report at the source.
- **Settings UI redesign.** The flat run of option rows is now a set of clearly-headed cards with a sticky section nav (Privacy, Browse, API key, Default poster, Play button, Auto-sync), so groups are scannable and harder to miss. The long consent-mode explainer collapses into a disclosure so the default view stays compact. The video editor's **Video Details** box now tucks the optional transcript/chapters ("Rich video schema") behind a disclosure too, so the core fields lead. Pure CSS / native `<details>` — no admin JS added. (The Import screen was left as-is: it's a focused task flow, not a list.) The admin chrome is themed to the **Crossroad Media brand palette** (deep purple lead, brand-blue support, warm-gray neutrals, brand radii + signature shadow; orange left reserved) — applied to the plugin's own UI only; the front-end gallery still inherits each tenant's brand. No web fonts loaded (keeps the zero-dependency, no-third-party-request posture). Also de-duplicated the YouTube provider-meta write shared by the importer and channel sync into one helper.
- **Play-button color control.** Settings → *Play button color* adds a brand color combo (idle + hover) for the click-to-load play icon, via two native color pickers. Tick to override; left off, the button inherits the theme's `--xrv-primary` / `--xrv-action` brand tokens. Emits two scoped CSS variables (`--xrv-play`, `--xrv-play-hover`) only when set, so it never affects the other elements that share the brand tokens.
- **`SECURITY.md`** added: disclosure contact, security posture (no secrets in the repo, scheme-guarded fetches, escaped output, gated writes), and the recommended YouTube API-key restriction for site operators.

### 2.0.1.beta

Beta release for testing the playlist picker before 2.0.1 stable. Adds a YouTube **playlist picker** to the importer. On the Import screen, enter a channel (URL, `@handle`, or name) and click **List playlists** to see that channel's public playlists with their video counts; pick one and it loads into the Videos box, ready to preview and import. Saves you finding and pasting an exact `?list=` playlist URL. Needs the same free YouTube Data API key the importer already uses; admin-only, no front-end change.

### 2.0.0

Multi-source. The gallery is no longer YouTube-only: it now plays **Vimeo, Wistia, Loom, Dailymotion, and self-hosted MP4 / WebM files** through the same click-to-load facade, with the provider auto-detected from the pasted URL. Still a single-file, zero-dependency plugin, and the zero-third-party-request-until-click guarantee holds for every source (self-hosted files make no third-party contact at all). No new API keys: each host's title, poster, and (for all but YouTube) duration come from its no-key oEmbed endpoint.

- **Five new providers via the existing `_xrv_provider` seam.** Vimeo, Wistia, Loom, Dailymotion, and self-hosted files join YouTube. Each routes through the same switch for ID parsing, embed URL, oEmbed endpoint, and poster source, so the renderer, schema, lightbox, consent modes, layouts, and search/sort are unchanged and behave identically across sources. Galleries can mix providers in one grid.
- **Auto-detect provider.** The editor's Provider selector defaults to **Auto-detect**: paste a URL and the host is recognized from it, so editors never choose a provider by hand. The selector is still there to force a choice and to pick Self-hosted file.
- **Self-hosted files (`provider:file`).** Paste a direct MP4 / WebM URL and the card plays it in a native `<video>` element on click. No iframe, no oEmbed, no external request at any point: the most private option, and a natural fit with the password-gate stack for client review reels. Upload a poster image in the editor, since self-hosted files have no remote thumbnail to fetch.
- **Vimeo privacy hash.** Unlisted and domain-private Vimeo videos (`vimeo.com/{id}/{hash}`) keep their hash, so the on-click embed is reconstructed correctly. Vimeo plays with `dnt=1` and no title / byline / portrait chrome.
- **No third-party thumbnail relay.** Posters for the new hosts are sideloaded from each host's own oEmbed `thumbnail_url` and stored locally like the YouTube poster, so there is no dependency on any external thumbnail service.
- **Duration normalization.** oEmbed durations (returned in seconds) are normalized to ISO 8601 on save, so the existing clock display and shortest/longest sort work across every source with no other change.
- **Scope.** The bulk importer and automatic channel sync stay YouTube-only in this release; multi-source bulk import is planned for 2.5. The enterprise tier (Brightcove, Kaltura) is intentionally out of scope, since composite account/player identity needs a providers settings panel.
- **Payload.** Front-end inline assets are about 30.3 KB raw / 8.7 KB gzipped per page (the provider routing adds roughly 1.2 KB of JS over 1.0.9); admin and importer code is unchanged, and everything is still emitted once per request.

### 1.0.9

Adds automatic channel sync: the plugin can poll a YouTube channel or playlist for new uploads and add them to the library, either on a schedule you set or on demand. Still a single-file, zero-dependency plugin; the sync engine and its controls are admin-only and add nothing to the front-end payload (unchanged at about 29 KB raw / 8.3 KB gzipped per page).

- **Automatic channel sync** (Videos → Settings → Automatic channel sync). Point it at a channel URL (`/@handle`, `/channel/UC…`, `/user/…`) or a `?list=…` playlist and it scans the most recent uploads, adding any video not already in the library. Dedup is by video ID (`_xrv_video_id`), so a run is always idempotent and safe to repeat. Each new video gets its title, duration, upload date, description, and a sideloaded thumbnail, exactly like the importer. Requires the (free) YouTube Data API key already used by Import.
- **Scheduled or on demand.** Pick a check frequency of Hourly, Daily, Weekly, or Monthly (WP-Cron), or **On demand / never** to disable the schedule and update only when you click **Sync now**. The Settings page shows the next scheduled run and a last-run summary.
- **Publish or review.** Choose whether newly found videos go live immediately (Published) or land as Drafts for review before they appear, plus a cap on how many recent uploads to scan per run (1 to 50).
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

### 2.5 (planned)

Multi-source bulk import and channel/showcase sync (Vimeo showcases, Wistia projects), extending the YouTube-only importer to every provider.

## License

GPL-2.0-or-later.

---

Built by [Crossroad Media](https://crossroad.us).
