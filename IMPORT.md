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
- `menu_order` appended for new posts (preserves drag-order; existing order is left alone)

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
