# Crossroad Videos — Import Specification

The built-in importer (**Videos → Import** in wp-admin) turns a list of YouTube
videos into curated `xroad_video` posts — title, duration, upload date,
description, a **locally sideloaded** thumbnail, and (optionally) Series /
Audience / Topic terms. It is the supported way to populate a library at scale.
No WPCode, no SSH, no external service, no build step.

---

## 1. Goals

- A brand-new user can seed a library with **the minimum they have on hand** — even just a list of video URLs.
- Richer inputs (a channel + an API key, or a prepared metadata file) produce **richer schema** automatically.
- Every run is a **dry-run first**: you see exactly what will be created vs. what already exists before anything is written.
- Re-running is **safe and idempotent** — videos are matched on their YouTube ID, never duplicated.
- Large libraries import in **batches with a progress bar**, staying under host PHP time limits.

## 2. Three input modes

All three are entered in the same box (paste) or via **Choose file** (upload). The
importer auto-detects which mode applies.

| Mode | Input | What you get | Setup |
|---|---|---|---|
| **1. URLs** | YouTube video links / IDs, one per line (or a `.txt` / `.csv`) | Title + locally sideloaded thumbnail (title via no-key oEmbed) | None |
| **2. Channel / playlist** | A channel or playlist URL **+ a free YouTube Data API key** | Whole channel/playlist enumerated, with duration, upload date, description | Paste a free API key (stored in `xrv_yt_api_key`) |
| **3. Metadata file** | A `.json` array of records | Everything in the file — title, duration, date, description, **and Series/Audience/Topic** — with **no API key** | None |

Mode 3 is what makes a fully-formed library (rich schema + taxonomy) portable as
a single file. It is detected when the input, trimmed, starts with `[`.

## 3. JSON record schema (Mode 3)

```jsonc
[
  {
    "id": "dQw4w9WgXcQ",          // YouTube video ID  (or "url": "https://youtu.be/…")
    "title": "…",                  // optional — falls back to oEmbed, then the ID
    "duration": "PT58M46S",        // optional — ISO 8601 duration
    "upload": "2026-04-16",        // optional — YYYY-MM-DD
    "desc": "…",                   // optional — plain-text description
    "watch_page": false,           // optional: false / "0" / "no" / "off" = no standalone watch page; true / "1" = on; omit = leave as is
    "series":   "Lunch & Learn",                       // optional — string OR array
    "audience": ["Patients & Caregivers"],             // optional — string OR array
    "topic":    ["Rare Cancer Education", "Patient Stories"]  // optional — string OR array
  }
]
```

- `id` **or** `url` is the only required field. Everything else is optional and only written when present.
- Taxonomy fields accept either a single string or an array; a string may be **comma / pipe / semicolon-separated** (`"Webinars, Galas"`).
- Terms are assigned **by name** and **created if they don't exist** — so the file defines your taxonomy as a side effect. Names are matched exactly (case/spacing as written).
- When an API key is *also* supplied, the file wins; the API only **fills gaps** (e.g. a missing duration).
- `watch_page` controls the video's standalone watch page. Combine it with **Overwrite** to switch it for videos already in the library.
- For a full migration (exact slugs, original dates, existing poster images, dedicated URLs, collections, resumable runs and rollback) use the WP-CLI importer in [section 8](#8-wp-cli), which reads a stricter superset of this format.

## 4. Flow

```
        ┌─────────┐   Preview (dry run)   ┌──────────────────────┐   Import selected   ┌──────────────┐
 input ─▶│ resolve │ ────────────────────▶│ table: NEW vs EXISTS, │ ───────────────────▶│ batched write │
        │  to IDs │                       │ + suggested terms     │   skip / overwrite  │  + progress  │
        └─────────┘                       └──────────────────────┘                      └──────────────┘
```

1. **Preview** (`xrv_import_preview`) — resolves the input to a deduped ID list, gathers
   whatever metadata is available, checks each ID against existing `_xrv_video_id`
   meta, and returns a table: thumbnail, title, duration, **suggested Series ·
   Audience · Topic** (Mode 3 only), and a **NEW / EXISTS** badge. Nothing is written.
2. **Conflict choice** — one radio for the whole run:
   - **Skip** (default) — leave existing videos untouched; only create new ones.
   - **Overwrite** — refresh title, metadata, taxonomy, and (if missing) thumbnail on existing videos too.
3. **Import** (`xrv_import_run`) — the selected rows are sent in **batches of 5**;
   the progress bar advances per batch and the run ends with a created /
   updated / skipped / failed tally.

## 5. What a row writes

For each imported video:

- `_xrv_provider` = `youtube`, `_xrv_video_id`, `_xrv_source_url`
- `_xrv_duration_iso`, `_xrv_upload_date`, `_xrv_description` — when provided
- **Taxonomy terms** for `xrv_series` / `xrv_audience` / `xrv_topic` — created by name as needed, then assigned
- **Local thumbnail** sideloaded into the Media Library (maxres → hqdefault fallback) and set as the featured image — only if the post doesn't already have one (the expensive step is skipped on re-runs)
- `menu_order` appended for new posts (after the current highest Order number; the order of existing videos is left alone)

## 6. Idempotency & safety

- **Match key:** `_xrv_video_id`. A video already in the library is detected regardless of how it was created (importer, editor, or an earlier seed) and is never duplicated.
- **Re-runnable:** run the same file twice — the second pass reports everything as EXISTS and (on Skip) writes nothing.
- **Auth:** AJAX endpoints require the `xrv_import` nonce and the `edit_others_posts` capability (editors/admins).
- **No destructive ops:** the importer only creates/updates; it never deletes posts, terms, or media.

## 7. Example: seeding a library from a classified file

A local classify script merges a proposed Series/Audience/Topic map into a
harvested `videos.json` to produce `videos-classified.json`. Uploading that file
(Mode 3, Overwrite) seeds the whole library, rich schema and taxonomy together,
with no API key required. The taxonomy is entirely yours to define; a typical map
might look like:

- **Series:** Webinars · Tutorials · Events · Interviews
- **Audience:** General · Practitioners · Supporters
- **Topic:** Getting Started · Deep Dives · Announcements · Case Studies

These are starting suggestions only. Terms stay editable in wp-admin afterward, and
re-importing with an edited map (Overwrite) re-syncs them.

---

## 8. WP-CLI

Version 2.11.0 adds `wp xrv`, a command-line importer and configuration tool for migrations: exact slugs, original dates, existing poster images, dedicated URLs, collections, settings and permalinks, all with dry runs, logs and rollback. It never contacts YouTube or any other host: no oEmbed, no API, no remote thumbnail.

Run every command with the global `--user=<admin>` flag. Writes are capability-gated, and kses filtering of titles needs a real administrator.

| Command | What it does |
|---|---|
| `wp xrv import <file.json>` | Create or update videos from a JSON file (format below). |
| `wp xrv collection set <slug> --ids=<ids>` | Create or update one collection. Idempotent. |
| `wp xrv export --file=<manifest.json>` | Write settings, permalinks, collections and videos to a manifest. |
| `wp xrv apply <manifest.json>` | Apply a manifest's sections to this site. |
| `wp xrv handover <ids> \| --all` | Hand video addresses to XRV (or back with `--to=old`), in batches or all at once. 2.11.1. |
| `wp xrv list` | Show who serves each video's own address right now. 2.11.1. |
| `wp xrv rollback <log.json>` | Undo an import, collection, apply or handover run from its log. |

`wp help xrv <command>` prints every option.

### Shared behaviour

- **`--dry-run`** on every command prints per-field diffs (field, before, after) and writes nothing except a run log marked as a dry run.
- **Run log.** Every run writes a JSON log, rewritten after each record, to `wp-content/xrv-runs/` (or `--log=<path>`). The file name ends in 16 random hex characters. Logs hold before-images of everything a run changed, so `wp xrv rollback` can undo it. The folder gets an `index.php` and a deny-all `.htaccess`, but Nginx hosts ignore `.htaccess`: download the logs you need and delete the folder once the migration is final. The YouTube API key is never written to a log.
- **One writer at a time.** Imports, applies, rollbacks and channel sync share one lock. A run that finds it held prints who holds it and stops. A lock with no heartbeat for 15 minutes (a killed SSH session) is taken over by the next run.
- **Resumable.** Hosts such as WP Engine drop an idle SSH session after 10 minutes. `--max-seconds=<n>` (default 420) stops cleanly between records, writes the log and prints the exact command that resumes the run. Every record prints a progress line, so the session is never idle. `--limit=<n>` processes at most n records per invocation.
- **Crash-safe inserts.** An intent entry is written to the log before each insert, and every created post is stamped with the run id (`_xrv_run_id`), so `--resume` and `rollback` find a post even when the process died between creating it and logging it.
- **`xrv_library_changed`** fires with the affected post IDs after a run writes, so a cache purge can hook it.

### `wp xrv import`

```
wp xrv import videos.json --dry-run --user=admin
wp xrv import videos.json --status=draft --user=admin
wp xrv import videos.json --resume=wp-content/xrv-runs/xrv-import-<stamp>-<hex>.json --user=admin
```

| Option | Default | What it does |
|---|---|---|
| `--status=draft\|publish` | `draft` | Status of the videos this run **creates**. Updates never change a status. |
| `--on-existing=skip\|update` | `skip` | A video that already exists (same provider and ID, in any status, trash included). |
| `--limit=<n>` | all | Stop after n records and print the resume command. |
| `--resume=<log>` | | Continue a run from its log; finished records are skipped. |
| `--max-seconds=<n>` | `420` | Time budget per invocation (0 = none). |
| `--log=<path>` | `wp-content/xrv-runs/` | Run-log file or folder. |

The file is a JSON array of records (or `{"videos": [...]}`). Formats are strict; a record that fails validation is rejected with the reason and nothing is written for it. Duplicate IDs in one file are rejected before anything is written.

| Field | Format |
|---|---|
| `provider` | `youtube` (the only provider in 2.11.0). |
| `id` | The 11-character YouTube ID. Required. |
| `title` | Text. |
| `slug` | The watch page slug. Must be unused by every other video, in any status. Other content at the same address (a page, a post, a category archive) is reported as a warning. |
| `post_date` | `YYYY-MM-DD HH:MM:SS` in the site's timezone. The GMT date is set too. |
| `menu_order` | Integer (the curated Order number). |
| `watch_page` | `true` / `false` (also `"1"` / `"0"`, `"yes"` / `"no"`). |
| `dedicated_url` | A site-relative path (`/old-page/`, resolved against the site address) or an absolute `http(s)` URL. The video's own address redirects there. `null` or `""` removes it. |
| `description` (or `desc`) | Text; percent signs, backslashes and brackets are kept. |
| `upload` | `YYYY-MM-DD`, a real calendar date. |
| `published_at` | ISO 8601 with a zone, e.g. `2025-03-04T18:30:00Z`. Stored in UTC; it breaks ties in the newest / oldest order. |
| `duration` | ISO 8601, e.g. `PT58M46S` (not `58:46`). |
| `is_short` | `true` for a YouTube Short (stored with a `/shorts/` source URL); `false` on an update un-marks it. |
| `poster_id` | An existing image in the Media Library whose file exists. Reused, never copied and never deleted by a rollback. |
| `poster_path` | A local image file, imported into the Media Library for this video (a rollback deletes it). Never a URL. |
| `terms` | `{"xrv_series": [...], "xrv_audience": [...], "xrv_topic": [...]}`, by name, created when missing. An empty list clears that taxonomy on an update. |

### `wp xrv collection set`

```
wp xrv collection set featured --ids=youtube:yahxL3E6azk,eEnZMJPAadY --layout=carousel --user=admin
```

`--ids` is the ordered list (`provider:id` or a bare YouTube ID). Optional `--layout=grid|carousel|library`, `--orderby=curated|newest|oldest|title` and `--title`. The collection is published with exactly that slug (numeric slugs are refused, because the shortcode reads them as a post ID). Unknown IDs are errors and nothing is written. Running the same command twice changes nothing the second time.

### `wp xrv export` and `wp xrv apply`

```
wp xrv export --file=manifest.json --user=admin
wp xrv apply manifest.json --dry-run --user=admin
wp xrv apply manifest.json --user=admin
wp xrv apply manifest.json --sections=permalinks,videos --confirm-urls --user=admin
```

A manifest has four sections: `settings` (never the API key), `permalinks`, `collections` (slug, title, layout, order and an ordered `provider:id` list) and `videos` (provider, ID, slug, status, date, Order and meta, with site-relative URLs).

- `apply` defaults to `--sections=settings,collections`. **Permalinks are applied only when named and confirmed** with `--confirm-urls`; first the command lists everything the new base would shadow (a page, post, category or rewrite rule already at that address) and every dedicated URL that will no longer match.
- `apply` validates every section first and writes nothing if any record is invalid. Before changing anything it writes a **pre-image** to its log: the raw settings and permalinks, each collection and video it touches, and the channel-sync schedule.
- Settings are merged onto the current ones and sanitized; `sync_*` keys are never touched unless you pass `--include-sync`.
- The `videos` section patches only status, `dedicated_url` (including removal), `watch_page`, Order and date.
- One run applies settings, then permalinks (the rewrite rules are rebuilt at once), then videos, then collections, then flushes the object cache (and WP Engine's caches when present). Because it is one process, there is no moment where the new address exists but the old redirect still points away.

### Handing addresses over: `wp xrv handover` and `wp xrv list` (2.11.1)

When the video base matches addresses other content already uses (base `videos` on a site whose old video posts live at `/blog/videos/<postname>/`), XRV serves one of those addresses only for a **published** video that has been **handed** it. Until then, WordPress serves the address as if XRV were not there, so the old page keeps it. With nothing else at the address, XRV serves it anyway, so there is never a 404 or a redirect loop.

A video has not been handed its address while its **dedicated URL is that address**. `handover` removes the dedicated URL (and remembers it, so `--to=old` can put it back):

```
wp xrv handover yahxL3E6azk,4LAX2feihbE --dry-run --user=admin
wp xrv handover yahxL3E6azk,4LAX2feihbE --user=admin       # one batch
wp xrv handover yahxL3E6azk --to=old --user=admin           # give one back
wp xrv handover --all --user=admin                          # everything left: the single cutover
wp xrv list --state=old --user=admin                        # what is still with the old pages
```

- Each real run purges the object cache (and WP Engine's caches) and writes a run log; `wp xrv rollback <handover log>` undoes the batch.
- A video whose watch page is off is kept (its address would redirect home) unless you pass `--force`.
- A draft can be handed over; XRV serves its address once it is published.
- `wp xrv list` states: `xrv` (XRV serves it), `old` (the old page serves it), `redirect` (its dedicated URL points elsewhere), `home` (watch page off), `draft`. The All Videos screen shows the same in its Watch page column.
- Editors can check a video's XRV page before handing it over by adding `?preview=true` to its address.

### `wp xrv rollback`

```
wp xrv rollback wp-content/xrv-runs/xrv-import-<stamp>-<hex>.json --dry-run --user=admin
```

- **Handover log:** gives each address back exactly as it was before the run (and purges caches).
- **Import or collection log:** force-deletes (never trashes) the posts the run created and the poster files it imported for them; reused posters are never deleted. A created video whose status has changed since the run is kept unless `--force`. Updated videos get every changed field back. Term counts are recounted.
- **Apply log:** an exact restore of the pre-image (options written raw, permalinks restored and rewrite rules rebuilt, the sync schedule restored). It refuses when something changed after the apply, unless `--force`.

## 9. Staging to production

A move from another video plugin, rehearsed on staging first. Every step has its own log and rollback.

1. **Rehearse on staging.** Import the library, build the collections, run every step below and its rollback, and keep the manifest (`wp xrv export`).
2. **Apply settings with sync off, then import as drafts.** On production: `wp xrv apply manifest.json` (settings and collections only). **Never carry `permalinks` in this first apply**; the video base changes last. Then `wp xrv import videos.json --status=draft`, with each record's exact slug, original `post_date` (the GMT date is set from it), existing `poster_id`, and a temporary `dedicated_url` pointing at the page the video lives on today.
3. **Publish.** Publish the drafts (the manifest's `videos` section, or the editor). With **Dedicated URL redirect** set to **302** in Settings, each video's own address sends visitors to the old page while the migration is in progress, and browsers do not cache that.
4. **Build the gallery pages** with collections and `[xroad-videos orderby="newest"]`.
5. **Move the video base onto the old addresses, invisibly.** An `apply` with only `--sections=permalinks` (base `videos`) and `--confirm-urls`, keeping the dedicated URLs. Read the shadow report first. Every video whose dedicated URL is now its own address is "not handed over", so the old pages keep serving and nothing visible changes. Videos whose old page lives somewhere else keep redirecting there.
6. **Hand the addresses over**, either way:
   - **In batches:** `wp xrv handover <ten IDs>`, check those pages, then the next batch. `wp xrv rollback <handover log>` (or `--to=old`) gives a batch back.
   - **All at once:** `wp xrv handover --all`. (The 2.11.0 path still works too: one `apply` with `--sections=permalinks,videos --confirm-urls` that also removes the dedicated URLs.)

   `wp xrv list --state=old` shows what is left. Switch **Dedicated URL redirect** back to 301 when no temporary redirects remain.
7. **Then** turn on channel sync, as draft or publish.

Two traps:

- **Don't use Quick Edit on imported drafts.** For a draft, Quick Edit can reset the date to the moment of the edit and the original publish date is lost. Use the full editor or `apply`.
- **Keep `post_date_gmt`.** The importer sets it; a draft created any other way without it gets "now" as its date the next time it is updated.
