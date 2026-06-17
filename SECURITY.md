# Security Policy

## Reporting a vulnerability

Email **https://crossroad.us/contact-us/?utm_source=github&utm_medium=repo&utm_campaign=xroad-videos** (subject: "xroad-videos security") or open a private
GitHub security advisory. Please do not file public issues for vulnerabilities.
We aim to acknowledge within 3 business days.

## Supported versions

The latest released version receives security fixes. Run current.

## Posture (why a public repo is fine)

- **No secrets in the repo.** The YouTube Data API key is stored per-site in the
  WordPress options table, never in code. Client-specific config is git-ignored.
- **Zero pre-click third-party contact.** The click-to-load facade makes no
  request to any video host until a visitor presses play.
- **Server-side fetches are scheme-guarded** to `http(s)` before any remote
  thumbnail/oEmbed call (no SSRF via crafted source URLs).
- **Output is escaped:** JSON-LD is hex-escaped (`JSON_HEX_TAG|JSON_HEX_AMP`),
  HTML via `esc_*`. All `$wpdb` queries are static (no interpolation).
- **Writes are gated** by nonce **and** capability (`edit_post` / `edit_others_posts`
  / `manage_options`). The one public REST route returns only a country code +
  boolean, no DB, `no-store`.

## Recommended hardening for site operators

- **Restrict the YouTube Data API key** in Google Cloud: limit it to the *YouTube
  Data API v3* and lock it to your site's HTTP referrer, so a leaked key is inert
  elsewhere.
- Keep the plugin updated — the code is public, so staying current is the
  discipline that matters.
