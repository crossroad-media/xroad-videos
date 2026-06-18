<!--
Thanks for contributing to Crossroad Videos. Please read CONTRIBUTING.md first.
Keep the PR focused on one logical change, and fill out the sections below so a
reviewer can confirm the privacy guarantee and security posture at a glance.
-->

## Summary

<!-- What does this change do, and why? Link any related issue with "Closes #123". -->

## Type of change

- [ ] Bug fix (no behavior change beyond fixing the bug)
- [ ] New feature or enhancement
- [ ] Documentation only
- [ ] Refactor or tooling (no user-facing change)

## The facade still holds

The plugin's whole purpose is that nothing third-party loads before a click.

- [ ] I verified in DevTools that a page with a gallery makes **zero** requests to any video host or Google domain before the visitor clicks play.
- [ ] On click, exactly one host iframe (or a native `<video>` for self-hosted) loads, the video plays, and a single `video_play` event is pushed to `dataLayer`.
- [ ] If I touched consent logic, no host contact happens until Accept in the modes that require a prompt (Global for EU/UK/EEA/CH, Strict for everyone).
- [ ] Not applicable (this change cannot affect pre-click network behavior).

## Security and standards

- [ ] All new output is escaped (`esc_html` / `esc_attr` / `esc_url`; JSON-LD via `wp_json_encode` with `JSON_HEX_TAG | JSON_HEX_AMP`).
- [ ] Any new write path is gated by nonce **and** capability; new settings go through the Settings API with a sanitize callback.
- [ ] No dynamic SQL was introduced (`$wpdb` queries stay static, or use `prepare()`).
- [ ] Code stays PHP 7.4 compatible, and new user-facing strings are wrapped for translation with the `xroad-videos` text domain.
- [ ] Any edited inline JS heredoc was syntax-checked before committing.

## Housekeeping

- [ ] If user-visible, I bumped the `Version` header and `xrv_version`, and added a `### x.y.z` entry to the README Changelog.
- [ ] New or changed documentation is em-dash-free, consistent with the existing voice.
- [ ] I kept the single-file, no-build, dependency-free shape of the plugin (or opened a discussion first if not).

## How I tested this

<!--
Describe the stack and what you checked. For example:
WordPress 6.5.2, PHP 8.1, Divi child theme, CookieYes active, providers: YouTube + Vimeo.
Pre-click network clean; play injects one nocookie iframe; video_play fired once.
-->
