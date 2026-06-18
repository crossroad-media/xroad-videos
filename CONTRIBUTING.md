# Contributing to Crossroad Videos

Thanks for your interest in improving **Crossroad Videos** (`xroad-videos`), the privacy-first, click-to-load video gallery for WordPress by [Crossroad Media](https://crossroad.us). This guide explains how the project is built, the one rule every change has to respect, and how to get a patch merged cleanly.

By contributing you agree that your work is licensed under the project's [GPL-2.0-or-later](LICENSE) license, the same terms WordPress itself ships under.

## Code of Conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md). By participating you are expected to uphold it. Report unacceptable behavior through the [Crossroad Media contact form](https://crossroad.us/contact-us/?utm_source=github&utm_medium=repo&utm_campaign=xroad-videos).

## The one rule: never break the facade

The entire reason this plugin exists is that **nothing third-party loads before a deliberate click**. Every card starts as a local poster image plus a play button: first-party HTML and CSS, zero requests to any video host, zero cookies, zero `localStorage`. Only on click does the plugin inject the host's privacy-enhanced iframe (or a native `<video>` for self-hosted files).

Any change that causes a request to `youtube.com`, `i.ytimg.com`, `google.com`, Vimeo, Wistia, Loom, Dailymotion, or any other host **before the visitor clicks play** is a regression, no matter how useful the feature is. This includes fonts, posters, preconnect hints, analytics, and oEmbed calls. Posters are sideloaded locally on save for exactly this reason.

If a change touches rendering, embedding, consent, or preconnect logic, you must verify the pre-click network is clean (see [Testing](#testing)). A PR that cannot demonstrate this will not be merged.

## Project shape

- **One file does the work.** Almost everything lives in [`xroad-videos.php`](xroad-videos.php). There is **no build step**: no webpack, no npm, no Composer, no Sass. CSS, JS, and the SVG sprite are inlined in PHP and emitted once per request. This is deliberate, so the plugin survives a performance plugin's unused-CSS pass and moves between themes unchanged. Please keep it that way unless you are proposing the build system itself as a discussion first.
- **Prefixes are namespaced.** PHP functions use `xrv_`, the post type is `xroad_video`, taxonomies are `xrv_series` / `xrv_audience` / `xrv_topic`, the shortcode is `[xroad-videos]`, and the block is `xroad/videos`. Match these.
- **Provider seam.** Multi-source support routes through one `_xrv_provider` switch and the `xrv_providers()` / `xrv_detect_provider()` helpers. Add new hosts there, not with a parallel render path.
- **Docs are em-dash-free.** The README, this file, and all user-facing copy avoid the em-dash character. Use commas, colons, parentheses, or hyphens. Keep new prose consistent with the existing voice.

## WordPress and security conventions

This plugin passes a manual security audit and should stay that way. Follow [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) and these non-negotiables:

- **Escape on output.** HTML through `esc_html()` / `esc_attr()`, URLs through `esc_url()` / `esc_url_raw()`, JSON-LD through `wp_json_encode( ..., JSON_HEX_TAG | JSON_HEX_AMP )`. Never echo an unescaped value.
- **Gate every write.** Admin and AJAX writes require a nonce **and** a capability check (`edit_post`, `edit_others_posts`, or `manage_options` as appropriate). Settings go through the Settings API with a sanitize callback that whitelists enums and casts types.
- **No dynamic SQL.** `$wpdb` queries are static. Do not interpolate user input into a query; if you genuinely need a variable, use `$wpdb->prepare()`.
- **Scheme-guard server-side fetches.** Any remote fetch (thumbnail sideload, oEmbed) must be limited to `http(s)` and to fixed provider hosts. The user controls only the `url=` parameter, never the destination host.
- **Keep PHP 7.4 compatible.** The plugin header declares `Requires PHP: 7.4`. Do not use syntax newer than that (no enums, no `readonly`, no first-class callable syntax).
- **Internationalize new strings.** Wrap user-facing strings in `__()` / `esc_html__()` with the `xroad-videos` text domain.

If you believe you have found a security vulnerability, **do not open a public issue**. Follow [SECURITY.md](SECURITY.md).

## Testing

There is no automated test suite, so changes are validated by hand against a real WordPress install. Before opening a PR:

1. **Install on a WordPress site** running a consent manager (CookieYes, Cookiebot, Complianz, or similar). The consent manager is the whole point: it must have nothing to block.
2. **Confirm the pre-click network is clean.** Open browser DevTools, Network tab, hard-reload a page with a gallery, and confirm **zero** requests to any video host or Google domain before you click. Posters should resolve from `/wp-content/uploads/`.
3. **Confirm playback on click.** One click should inject exactly one host iframe (or a native `<video>` for self-hosted), play the video, and push a single `video_play` event to `dataLayer`.
4. **Check consent modes** if you touched consent: Global (prompt only for EU/UK/EEA/CH), Strict GDPR (prompt for everyone), Off. When a prompt is required, verify no host contact happens until Accept.
5. **Validate inline JavaScript.** The JS is shipped inside PHP heredocs. After editing any of them, syntax-check each block (for example with Node `new Function(src)`) before committing, since a single stray quote in adjacent HTML can desync the file.
6. **Run a payload check.** Note the front-end inline payload size (CSS + JS + SVG, raw and gzipped), admin-only JS, total PHP size and line count, and the built zip size, and flag any unexpected growth in your PR.

Please describe in the PR exactly what you tested and on what stack (WordPress version, PHP version, consent manager, theme, and which providers).

## Building the install zip

The packaged plugin is attached to releases, not committed (`xroad-videos.zip` is git-ignored). When you build one, do **not** use PowerShell `Compress-Archive`: it writes backslash path separators, which makes WordPress double-nest the folder and refuse the plugin ("Plugin file does not exist"). Build with .NET `ZipArchive` using explicit **forward-slash** entry names so the archive contains `xroad-videos/xroad-videos.php` and friends.

## Commits, branches, and pull requests

- **Branch** off `main` for your change. Do not commit directly to `main`.
- **Write clear commit messages**: a concise imperative subject line, then a body explaining the why if it is not obvious.
- **Keep PRs focused.** One logical change per PR is far easier to review than a grab bag.
- **Bump and document.** If your change is user-visible, bump the `Version` in the plugin header and `xrv_version`, and add a `### x.y.z` entry to the Changelog in [`README.md`](README.md) describing what changed.
- **Fill out the PR template.** It exists so reviewers can see at a glance that the facade still holds, output is escaped, and the change was tested.

Reviews focus first on the privacy guarantee and security posture, then on correctness, then on whether the change keeps the single-file, no-build, dependency-free shape of the plugin.

## Questions

Open a [discussion or issue](https://github.com/crossroad-media/xroad-videos/issues) for anything that is not a vulnerability, or reach us through the [Crossroad Media contact form](https://crossroad.us/contact-us/?utm_source=github&utm_medium=repo&utm_campaign=xroad-videos). Thanks for helping keep curated video private by default.
