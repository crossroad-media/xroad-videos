<?php
/**
 * Plugin Name:       XRV (Xroad Video)
 * Plugin URI:        https://crossroad.us
 * Description:        Privacy-first, click-to-load video gallery. A curated custom-post-type model
 *                     (editors add and order each video) that renders a server-side masonry grid of
 *                     LOCALLY stored thumbnails and, with the default settings, makes ZERO network calls or
 *                     connections to YouTube or Google until a visitor clicks play (an opt-in hover warm-up can
 *                     preconnect), so no consent manager has anything to block and no banner,
 *                     warning overlay, or black player can ever appear. A drop-in alternative to Smash
 *                     Balloon YouTube Feed for sites running a cookie/consent manager. On click it injects
 *                     a youtube-nocookie.com iframe and pushes a video_play event to dataLayer. Self-
 *                     generates VideoObject JSON-LD inside a CollectionPage/ItemList that merges with the
 *                     site's Organization node. Shortcode [xroad-videos] and block (xroad/videos).
 *                     By Crossroad Media.
 * Version:           2.11.2
 * Author:            Crossroad Media
 * Author URI:        https://crossroad.us
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP:      7.4
 * Text Domain:       xroad-videos
 *
 * ARCHITECTURE (read this first)
 * ------------------------------------------------------------------------------------------------
 * THE PROBLEM THIS REPLACES. Smash Balloon YouTube Feed (and standard YouTube embeds) fire requests to
 * youtube.com / i.ytimg.com / google.com on PAGE LOAD, before consent. When a cookie/consent manager
 * (CookieYes, Osano, Cookiebot, etc.) is present it tries to intercept those requests, and that
 * interception is what produces the warning overlay, the black/blank player, and cascade JS failures.
 * Page-refresh "fixes" that force the player to load then corrupt GA4 attribution. Every one of those
 * failures has the same root cause: third-party requests before a deliberate user action.
 *
 * THE FIX (a facade). The initial state of every card is a LOCAL poster image plus a play button: pure
 * first-party HTML and CSS, zero requests to any Google domain, zero cookies, zero localStorage. Because
 * nothing third-party fires before interaction, the CMP has nothing to block, so no banner, no warning,
 * and no black screen can appear. Only ON CLICK does the plugin inject an iframe pointed at
 * youtube-nocookie.com (privacy-enhanced mode). This is the web.dev "facade" pattern. Storing thumbnails
 * locally hardens the guarantee: even the poster image makes no call to i.ytimg.com.
 *
 * THE RENDER MODEL. EVERY video is printed into the initial HTML server-side. No AJAX, no client
 * templating, no spinner; a page cache (or performance-optimization plugin) caches finished HTML and
 * first paint already contains every card. Filtering and sorting are pure client-side display toggles on
 * nodes already in the DOM, so interaction is instantaneous. The keyword index is GENERATED server-side
 * from each record's taxonomy terms plus an (optionally filtered) synonym map; editors never hand-write a
 * keyword blob, they just pick terms.
 *
 * ZERO FRAMEWORK DEPENDENCY. This plugin owns the entire stack: the data model, the markup, the inline
 * CSS, the vanilla JS, and the schema. No page builder, no ACF, no jQuery, no build step. It drops into a
 * page-builder Text module, a core Shortcode block, or the xroad/videos block, and renders identically.
 *
 * PROVIDER ROUTING. A scalar _xrv_provider meta routes every provider-specific operation (ID parser,
 * embed URL, oEmbed endpoint, poster source) through a switch(). 2.0 supports youtube, vimeo, wistia,
 * loom, dailymotion, and self-hosted files; a new provider is additive, not a rewrite. The provider is
 * auto-detected from the pasted URL, so editors normally never touch the selector.
 * ------------------------------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// Single source of truth for the version (header above stays literal for WordPress to read).
if ( ! defined( 'XRV_VERSION' ) ) {
	define( 'XRV_VERSION', '2.11.2' );
}

/* =================================================================================================
 * 1. DATA MODEL  (this plugin owns the post type)
 *    We register `xroad_video` as the curated source for the grid. has_archive is false by default: a
 *    card can link out to a dedicated page per video via the _xrv_dedicated_url meta, so the CPT itself
 *    only needs to feed the gallery rather than expose its own archive.
 * ================================================================================================= */

/* Video URL structure. single = single-post base (default "video" => /video/{slug}); archive = optional
 * collection/archive base (empty = no archive). Editable in Settings, but changing a base that is already
 * live is an SEO risk (see the in-UI warning + two-part test), so the form gates each change behind a
 * confirmation checkbox. */
function xrv_permalinks() {
	return wp_parse_args( (array) get_option( 'xrv_permalinks', array() ), array(
		'single'  => 'video',
		'archive' => '',
	) );
}

/* 2.11.0: the site's permalink front ("blog" for /blog/%category%/%postname%/), without slashes.
 * Video URLs are built with_front, so the front is already part of every video URL. */
function xrv_permalink_front() {
	global $wp_rewrite;
	return ( $wp_rewrite instanceof WP_Rewrite ) ? trim( (string) $wp_rewrite->front, '/' ) : '';
}

/* 2.11.0: sanitize one URL base the way 2.10.0 did (sanitize_title), but REJECT a base that repeats the
 * permalink front ("blog/videos" on an /blog/ site) instead of mangling it into "blogvideos".
 * Returns the base, $fallback for an empty value, or WP_Error( 'xrv_base_has_front' ). */
function xrv_sanitize_permalink_base( $raw, $fallback = '' ) {
	$raw   = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	$front = strtolower( xrv_permalink_front() );
	if ( '' !== $front ) {
		$probe = strtolower( trim( $raw, "/ \t" ) );
		if ( $probe === $front || 0 === strpos( $probe, $front . '/' ) ) {
			/* translators: %s: the site's permalink front, e.g. "blog". */
			return new WP_Error( 'xrv_base_has_front', sprintf( __( 'Enter the base without the permalink front: "%1$s" is added automatically (so "videos" gives /%1$s/videos/{slug}/).', 'xroad-videos' ), $front ) );
		}
	}
	$base = sanitize_title( $raw );
	return '' === $base ? (string) $fallback : $base;
}

/* 2.11.0: the ONE writer for the video URL structure (Settings form and WP-CLI). Returns the stored
 * array or a WP_Error; the option hooks below rebuild the rewrite rules whenever the value changes. */
function xrv_set_permalinks( $single, $archive ) {
	$s = xrv_sanitize_permalink_base( $single, 'video' );
	if ( is_wp_error( $s ) ) { return $s; }
	$a = xrv_sanitize_permalink_base( $archive, '' );
	if ( is_wp_error( $a ) ) { return $a; }
	$new = array( 'single' => $s, 'archive' => $a );
	update_option( 'xrv_permalinks', $new );
	return $new;
}

/* 2.11.0: re-register the post type with the current bases, then flush, so a changed (or restored, or
 * deleted) xrv_permalinks takes effect in the same request with no gap and no stale rules. */
function xrv_rebuild_video_rewrites() {
	// Unregister first: re-registering alone adds the new archive rule but never removes the old one.
	if ( function_exists( 'unregister_post_type' ) && post_type_exists( 'xroad_video' ) ) {
		unregister_post_type( 'xroad_video' );
	}
	xrv_register_data_model();
	flush_rewrite_rules();
}
add_action( 'add_option_xrv_permalinks', 'xrv_rebuild_video_rewrites', 10, 0 );
add_action( 'update_option_xrv_permalinks', 'xrv_rebuild_video_rewrites', 10, 0 );
add_action( 'delete_option_xrv_permalinks', 'xrv_rebuild_video_rewrites', 10, 0 );

add_action( 'init', 'xrv_register_data_model' );
function xrv_register_data_model() {
	$pl = xrv_permalinks();

	register_post_type( 'xroad_video', array(
		'labels' => array(
			'name'               => 'Videos',
			'singular_name'      => 'Video',
			'add_new_item'       => 'Add New Video',
			'edit_item'          => 'Edit Video',
			'new_item'           => 'New Video',
			'view_item'          => 'View Video',
			'search_items'       => 'Search Videos',
			'not_found'          => 'No videos found',
			'not_found_in_trash' => 'No videos found in Trash',
			'all_items'          => 'All Videos',
			'menu_name'          => 'XRV Video',
		),
		'public'        => true,
		'has_archive'   => ( '' !== $pl['archive'] ) ? $pl['archive'] : false, // optional collection archive (admin-selected, write-once)
		'show_in_rest'  => true,
		'menu_icon'     => 'dashicons-video-alt3',
		'rewrite'       => array( 'slug' => $pl['single'] ),  // single-post base (admin-selected, write-once)
		// A video is defined entirely by its meta (URL, poster, taxonomy), not post body — so we drop
		// 'editor' to collapse the screen to a single title field + the Video Details box. The front-end
		// single page is built from meta by xrv_single_content(), so no post_content is needed.
		'supports'      => array( 'title', 'thumbnail', 'page-attributes' ), // page-attributes => menu_order: the numeric Order field (Post Attributes)
	) );

	// Three controlled-vocabulary taxonomies, each mapping to one filter group: series, audience, topic.
	// All non-hierarchical and REST-exposed so the block editor and future tooling can read them. Sites
	// add their own terms; nothing is pre-seeded.
	$taxonomies = array(
		'xrv_series'    => 'Series',
		'xrv_audience'  => 'Audience',
		'xrv_topic'     => 'Topic',
	);
	foreach ( $taxonomies as $slug => $label ) {
		register_taxonomy( $slug, 'xroad_video', array(
			'labels'            => array( 'name' => $label, 'singular_name' => $label ),
			'hierarchical'      => false,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => false,
		) );
	}

	// 2.9.0 (Galleries anywhere): a "Collection" is a named, reusable, *placeable* gallery — a saved layout
	// over a hand-picked set of videos, ordered with up / down buttons in its editor. Config-only: it has no
	// public single page (it is dropped into pages/templates via [xroad-videos collection="slug"] or the
	// block), so publicly_queryable / rewrite / has_archive are all off — it adds ZERO front-end rewrite
	// rules, so no flush is needed for it to work. Slug = post_name (looked up with get_page_by_path);
	// page-attributes gives menu_order to order the collection list.
	register_post_type( 'xrv_collection', array(
		'labels' => array(
			'name'               => 'Collections',
			'singular_name'      => 'Collection',
			'add_new_item'       => 'Add New Collection',
			'edit_item'          => 'Edit Collection',
			'new_item'           => 'New Collection',
			'view_item'          => 'View Collection',
			'search_items'       => 'Search Collections',
			'not_found'          => 'No collections yet',
			'not_found_in_trash' => 'No collections in Trash',
			'all_items'          => 'Collections',
			'menu_name'          => 'Collections',
		),
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => true,
		'show_in_menu'        => 'edit.php?post_type=xroad_video', // grouped under the XRV Video menu
		'show_in_rest'        => false, // config-only CPT; no REST / headless consumer, so keep it off the API surface
		'rewrite'             => false,
		'has_archive'         => false,
		'exclude_from_search' => true,
		'capability_type'     => 'post',
		'supports'            => array( 'title', 'page-attributes' ), // title = collection name; page-attributes = menu_order
	) );
}

/* Poster renditions WordPress auto-generates on upload, so every poster has a right-sized desktop crop
 * (16:9) and a mobile-friendly portrait crop (3:4) ready without manual work. Hard crops (the `true`) keep
 * a predictable shape across a mixed library. The responsive grid already serves the smallest sufficient
 * 'large' rendition via srcset; these add purpose-built desktop/mobile shapes for art-directed posters. */
add_action( 'after_setup_theme', 'xrv_register_image_sizes' );
function xrv_register_image_sizes() {
	add_image_size( 'xrv-poster', 1280, 720, true );        // desktop / grid card (16:9)
	add_image_size( 'xrv-poster-mobile', 720, 960, true );  // mobile portrait (3:4)
}

/* -------------------------------------------------------------------------------------------------
 * 1a. Net-new scalar meta. Native register_post_meta (no ACF). All single, REST-exposed, edit-gated.
 * ------------------------------------------------------------------------------------------------- */
add_action( 'init', 'xrv_register_meta' );
function xrv_register_meta() {
	$fields = array(
		'_xrv_provider'      => 'string',  // youtube | vimeo | wistia | loom | dailymotion | file
		'_xrv_video_hash'    => 'string',  // optional privacy hash (Vimeo unlisted / domain-private videos)
		'_xrv_video_id'      => 'string',  // the platform video ID (11 chars for YouTube)
		'_xrv_source_url'    => 'string',  // canonical watch URL
		'_xrv_dedicated_url' => 'string',  // legacy optional: a custom page the title links to (still honored)
		'_xrv_watch_page'    => 'string',  // '0' = no standalone watch page; default (absent/'1') = on
		'_xrv_duration_iso'  => 'string',  // ISO 8601, e.g. PT12M30S
		'_xrv_upload_date'   => 'string',  // YYYY-MM-DD
		'_xrv_description'    => 'string', // plain-language summary
		'_xrv_local_thumb_id' => 'integer', // media-library attachment ID for the locally stored poster
		'_xrv_mobile_thumb_id' => 'integer', // optional: separate portrait/square poster shown on phones
		'_xrv_transcript'    => 'string',  // full transcript -> VideoObject.transcript (rich results + AI citation)
		'_xrv_chapters'      => 'string',  // "M:SS Label" per line -> hasPart Clip[] (key-moments rich result)
	);
	foreach ( $fields as $key => $type ) {
		register_post_meta( 'xroad_video', $key, array(
			'type'          => $type,
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
		) );
	}

	// 2.9.0: collection meta (_xrvc_video_ids, _xrvc_layout) is written/read directly via update/get_post_meta
	// in the collection editor + resolver — no register_post_meta needed (no REST/headless consumer).
}

/* -------------------------------------------------------------------------------------------------
 * 1b. Activation. Register the CPT + taxonomies so rewrite rules are correct, then flush once. The
 *     plugin ships with NO preset terms — every site defines its own Series / Audience / Topic vocabulary
 *     in the taxonomy editor. A site can pre-seed terms via the `xrv_seed_terms` filter (returning a
 *     [ taxonomy => [ slug => name ] ] map); each insert is guarded by term_exists so it is idempotent.
 * ------------------------------------------------------------------------------------------------- */
register_activation_hook( __FILE__, 'xrv_activate' );
function xrv_activate() {
	xrv_register_data_model(); // Ensure CPT + taxonomies exist before inserting any terms.

	$terms = apply_filters( 'xrv_seed_terms', array() );
	if ( is_array( $terms ) ) {
		foreach ( $terms as $tax => $set ) {
			if ( ! taxonomy_exists( $tax ) || ! is_array( $set ) ) {
				continue;
			}
			foreach ( $set as $slug => $name ) {
				if ( ! term_exists( $slug, $tax ) ) {
					wp_insert_term( $name, $tax, array( 'slug' => $slug ) );
				}
			}
		}
	}

	update_option( 'xrv_version', XRV_VERSION );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'xrv_deactivate_rewrites' );
/* 2.11.0: a bare flush on deactivation still saw xroad_video registered (init already ran in this request),
 * so it wrote the video rules straight back. Unregister the post type first (that drops its permastruct and
 * archive rules), then flush. unregister_post_type() is WP 4.5+, hence the guard. */
function xrv_deactivate_rewrites() {
	if ( function_exists( 'unregister_post_type' ) && post_type_exists( 'xroad_video' ) ) {
		unregister_post_type( 'xroad_video' );
	}
	flush_rewrite_rules();
}

/* On a plugin UPDATE (a fresh activation already flushes), re-flush rewrite rules once when the stored
 * version changes, so a changed default base (e.g. /video/) starts resolving without a manual re-save. */
add_action( 'admin_init', 'xrv_maybe_flush_on_update' );
function xrv_maybe_flush_on_update() {
	if ( get_option( 'xrv_version' ) !== XRV_VERSION ) {
		xrv_register_data_model();
		flush_rewrite_rules();
		update_option( 'xrv_version', XRV_VERSION );
	}
}

/* Set the video URL structure and flush rewrite rules. Editable, but each change is gated behind a
 * confirmation box in Settings (the editor must run the two-part test) since live URLs depend on it. */
add_action( 'admin_post_xrv_lock_urls', 'xrv_lock_urls_handler' );
function xrv_lock_urls_handler() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
	check_admin_referer( 'xrv_lock_urls' );
	// Changeable, but every change is gated behind the confirmation box (the editor must have run the
	// two-part test in the UI). 2.11.0: xrv_set_permalinks() is the one writer (its option hooks re-register
	// the CPT and flush). A rejected base comes back as a WP_Error; only a whitelisted CODE goes into the
	// redirect, and the Settings card maps it to fixed text, so nothing typed is ever echoed back.
	$args = array( 'xrv_urls' => '0' );
	if ( ! empty( $_POST['xrv_confirm'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by xrv_set_permalinks()
		$single  = isset( $_POST['xrv_single'] ) ? wp_unslash( $_POST['xrv_single'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by xrv_set_permalinks()
		$archive = isset( $_POST['xrv_archive'] ) ? wp_unslash( $_POST['xrv_archive'] ) : '';
		$fired   = did_action( 'add_option_xrv_permalinks' ) + did_action( 'update_option_xrv_permalinks' );
		$result  = xrv_set_permalinks( $single, $archive );
		if ( is_wp_error( $result ) ) {
			$args = array(
				'xrv_urls' => 'err',
				'xrv_code' => ( 'xrv_base_has_front' === $result->get_error_code() ) ? 'base_has_front' : 'invalid',
			);
		} else {
			// An unchanged value fires no option hook, so rebuild here: saving the same bases again stays a
			// manual "repair the video rewrite rules" button.
			if ( did_action( 'add_option_xrv_permalinks' ) + did_action( 'update_option_xrv_permalinks' ) === $fired ) {
				xrv_rebuild_video_rewrites();
			}
			$args = array( 'xrv_urls' => '1' );
		}
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php?post_type=xroad_video&page=xrv-settings' ) ) . '#xrv-sec-urls' );
	exit;
}

/* 2.11.0: the full public address for a video base, permalink front included (video URLs are built
 * with_front), e.g. xrv_video_base_url( 'videos', 'example-video' ) gives
 * https://example.org/blog/videos/example-video/ on an /blog/%category%/%postname%/ site.
 * An empty $slug gives the base itself (the archive URL); an empty $base too gives home plus the front. */
function xrv_video_base_url( $base, $slug = '' ) {
	$parts = array( xrv_permalink_front(), trim( (string) $base, '/' ), trim( (string) $slug, '/' ) );
	$path  = implode( '/', array_filter( $parts, 'strlen' ) );
	return home_url( user_trailingslashit( '/' . $path ) );
}

/* =================================================================================================
 * 2. PROVIDER ROUTING  (the additive seam for Vimeo in 2.0)
 *    Every provider-specific operation routes through a switch($provider). 1.0 implements youtube only;
 *    vimeo is reserved. Adding it in 2.0 means filling three branches, not rewriting the renderer.
 * ================================================================================================= */

/** Whitelist of providers xroad-videos can render. */
function xrv_providers() {
	return array( 'youtube', 'vimeo', 'wistia', 'loom', 'dailymotion', 'tiktok', 'file' );
}

/** Sniff the provider from a pasted URL. Returns '' when nothing matches (caller falls back to youtube). */
function xrv_detect_provider( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) { return ''; }
	if ( preg_match( '#(?:youtube(?:-nocookie)?\.com|youtu\.be)#i', $url ) ) { return 'youtube'; }
	if ( preg_match( '#vimeo\.com#i', $url ) )                               { return 'vimeo'; }
	if ( preg_match( '#(?:wistia\.(?:com|net)|wi\.st)#i', $url ) )           { return 'wistia'; }
	if ( preg_match( '#loom\.com#i', $url ) )                                { return 'loom'; }
	if ( preg_match( '#(?:dailymotion\.com|dai\.ly)#i', $url ) )             { return 'dailymotion'; }
	if ( preg_match( '#tiktok\.com#i', $url ) )                              { return 'tiktok'; }
	if ( preg_match( '#\.(?:mp4|m4v|webm|ogv|ogg|mov)(?:[?\#]|$)#i', $url ) ) { return 'file'; }
	return '';
}

/** Vimeo unlisted / domain-private videos carry a privacy hash (vimeo.com/{id}/{hash}). '' otherwise. */
function xrv_extract_video_hash( $url, $provider = 'youtube' ) {
	if ( 'vimeo' !== $provider ) { return ''; }
	$url = trim( (string) $url );
	if ( preg_match( '#vimeo\.com/\d+/([A-Za-z0-9]+)#i', $url, $m ) ) { return $m[1]; }
	if ( preg_match( '#[?&]h=([A-Za-z0-9]+)#i', $url, $m ) )          { return $m[1]; }
	return '';
}

/** Whole seconds (from an oEmbed duration) -> ISO 8601 (PT#H#M#S), so the existing clock + sort reuse it. */
function xrv_seconds_to_iso( $sec ) {
	$sec = (int) $sec;
	if ( $sec <= 0 ) { return ''; }
	$h = intdiv( $sec, 3600 ); $m = intdiv( $sec % 3600, 60 ); $s = $sec % 60;
	$out = 'PT';
	if ( $h ) { $out .= $h . 'H'; }
	if ( $m ) { $out .= $m . 'M'; }
	if ( $s || 'PT' === $out ) { $out .= $s . 'S'; }
	return $out;
}

/** Extract the platform video ID from a pasted URL (or a bare ID). Returns '' if none found. */
function xrv_extract_video_id( $url, $provider = 'youtube' ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}
	switch ( $provider ) {
		case 'vimeo':
			// Vimeo IDs are numeric; accept a bare ID or a player/clip URL (the privacy hash, if any,
			// is captured separately by xrv_extract_video_hash()).
			if ( preg_match( '/(?:vimeo\.com\/|video\/)(\d+)/', $url, $m ) ) {
				return $m[1];
			}
			return preg_match( '/^\d+$/', $url ) ? $url : '';

		case 'wistia':
			// Hashed alphanumeric ID (e.g. 01a1d9f97c). Accept a medias/embed URL or a bare ID.
			if ( preg_match( '#(?:wistia\.(?:com|net)|wi\.st)/(?:medias|embed/(?:iframe|medias|playlists))/([A-Za-z0-9]+)#i', $url, $m ) ) {
				return $m[1];
			}
			return preg_match( '/^[A-Za-z0-9]{8,}$/', $url ) ? $url : '';

		case 'loom':
			// Share/embed ID (hex). Accept a share/embed URL or a bare ID.
			if ( preg_match( '#loom\.com/(?:share|embed)/([A-Za-z0-9]+)#i', $url, $m ) ) {
				return $m[1];
			}
			return preg_match( '/^[A-Za-z0-9]{20,}$/', $url ) ? $url : '';

		case 'dailymotion':
			// IDs look like x7tgad0 / k.... Accept a video, embed, or dai.ly short URL.
			if ( preg_match( '#(?:dailymotion\.com/(?:video|embed/video)/|dai\.ly/)([A-Za-z0-9]+)#i', $url, $m ) ) {
				return $m[1];
			}
			return preg_match( '/^[A-Za-z0-9]{5,}$/', $url ) ? $url : '';

		case 'tiktok':
			// Long numeric ID. Accept @user/video, player, or embed URLs, or a bare ID.
			// ponytail: canonical URLs only; vm.tiktok.com / tiktok.com/t short links redirect and would
			// need a network call to resolve — paste the full @user/video link instead.
			if ( preg_match( '#tiktok\.com/(?:@[\w.\-]+/video/|v/|embed/v2/|player/v1/)(\d+)#i', $url, $m ) ) {
				return $m[1];
			}
			return preg_match( '/^\d{8,}$/', $url ) ? $url : '';

		case 'file':
			// Self-hosted: the media URL (or a site-relative path) is itself the identifier.
			return ( preg_match( '#^https?://#i', $url ) || 0 === strpos( $url, '/' ) ) ? $url : '';

		case 'youtube':
		default:
			// Already a bare 11-char ID?
			if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $url ) ) {
				return $url;
			}
			// watch?v=, youtu.be/, /embed/, /shorts/, /live/ — all 11-char IDs.
			if ( preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m ) ) {
				return $m[1];
			}
			return '';
	}
}

/** Ordered list of candidate thumbnail source URLs to try when sideloading the local poster. */
function xrv_thumb_candidates( $id, $provider = 'youtube', $is_short = false ) {
	// Only YouTube has stable ID-derived poster URLs. Every other host's poster comes from its oEmbed
	// thumbnail_url, which the save / import path passes to xrv_sideload_thumbnail() explicitly, so this
	// returns an empty list for them (no third-party thumbnail relay, no broken ID guesses).
	if ( 'youtube' !== $provider ) {
		return array();
	}
	// Shorts are vertical: oardefault.jpg is the ORIGINAL-aspect-ratio (9:16) poster, so the local thumbnail
	// fills the vertical card instead of a letterboxed 16:9 maxres frame. Falls back to hqdefault.
	if ( $is_short ) {
		return array(
			'https://i.ytimg.com/vi/' . $id . '/oardefault.jpg',
			'https://i.ytimg.com/vi/' . $id . '/oar2.jpg',
			'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
		);
	}
	// maxres is absent on older uploads and YouTube serves a valid-looking 404 BODY, so the sideload
	// routine must check HTTP status, not image bytes. hqdefault always exists.
	return array(
		'https://i.ytimg.com/vi_webp/' . $id . '/maxresdefault.webp',
		'https://i.ytimg.com/vi/' . $id . '/maxresdefault.jpg',
		'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
	);
}

/** The privacy-enhanced embed URL injected ON CLICK only. autoplay=1 because the click is the consent. */
function xrv_embed_url( $id, $provider = 'youtube', $hash = '' ) {
	switch ( $provider ) {
		case 'vimeo':
			// dnt=1 is Vimeo's do-not-track flag; title/byline/portrait stripped for a clean player.
			return 'https://player.vimeo.com/video/' . rawurlencode( $id ) . '?autoplay=1&dnt=1&title=0&byline=0&portrait=0' . ( '' !== $hash ? '&h=' . rawurlencode( $hash ) : '' );

		case 'wistia':
			return 'https://fast.wistia.net/embed/iframe/' . rawurlencode( $id ) . '?autoPlay=true';

		case 'loom':
			return 'https://www.loom.com/embed/' . rawurlencode( $id ) . '?autoplay=1';

		case 'dailymotion':
			return 'https://www.dailymotion.com/embed/video/' . rawurlencode( $id ) . '?autoplay=1';

		case 'tiktok':
			// Native iframe player (no embed.js). Music/description chrome off for a clean facade.
			return 'https://www.tiktok.com/player/v1/' . rawurlencode( $id ) . '?autoplay=1&controls=1&description=0&music_info=0';

		case 'file':
			// Self-hosted: the media URL is played directly in a <video> element (handled client-side).
			return $id;

		case 'youtube':
		default:
			return 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0&modestbranding=1';
	}
}

/** The remote thumbnail URL used as a SECONDARY thumbnailUrl in schema (not on the rendered card). */
function xrv_remote_thumb_url( $id, $provider = 'youtube' ) {
	// YouTube has a stable ID-based poster URL. Other hosts expose their poster via oEmbed, which is
	// sideloaded into the LOCAL poster, so there is no separate remote URL to advertise here.
	return ( 'youtube' === $provider ) ? 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' : '';
}

/** The canonical watch URL for a video, used for schema contentUrl and as a source-URL fallback. */
function xrv_watch_url( $id, $provider = 'youtube' ) {
	switch ( $provider ) {
		case 'vimeo':
			return 'https://vimeo.com/' . $id;
		case 'wistia':
			return 'https://fast.wistia.com/medias/' . $id;
		case 'loom':
			return 'https://www.loom.com/share/' . $id;
		case 'dailymotion':
			return 'https://www.dailymotion.com/video/' . $id;
		case 'tiktok':
			// Canonical watch URL needs the @handle, which the bare ID lacks; the pasted source URL is used
			// for oEmbed instead, so an empty string here is fine (schema contentUrl just omits it).
			return '';
		case 'file':
			return $id;
		case 'youtube':
		default:
			return 'https://www.youtube.com/watch?v=' . $id;
	}
}

/* -------------------------------------------------------------------------------------------------
 * 2a. No-API-key metadata lookup. oEmbed returns title + author + thumbnail with no key and no auth.
 *     Runs server-side on first save to prefill the title and description so schema is never blank.
 * ------------------------------------------------------------------------------------------------- */
function xrv_fetch_oembed( $watch_url, $provider = 'youtube' ) {
	switch ( $provider ) {
		case 'vimeo':
			$endpoint = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( $watch_url );
			break;
		case 'wistia':
			$endpoint = 'https://fast.wistia.com/oembed?url=' . rawurlencode( $watch_url );
			break;
		case 'loom':
			$endpoint = 'https://www.loom.com/v1/oembed?url=' . rawurlencode( $watch_url );
			break;
		case 'dailymotion':
			$endpoint = 'https://www.dailymotion.com/services/oembed?url=' . rawurlencode( $watch_url );
			break;
		case 'tiktok':
			// Keyless; returns title + thumbnail_url (extension-less URL handled by the sideloader's sniffing).
			$endpoint = 'https://www.tiktok.com/oembed?url=' . rawurlencode( $watch_url );
			break;
		case 'youtube':
		default:
			$endpoint = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( $watch_url );
			break;
	}
	$res = wp_remote_get( $endpoint, array( 'timeout' => 8 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return is_array( $data ) ? $data : array();
}

/* -------------------------------------------------------------------------------------------------
 * 2b. Local thumbnail sideloading — the no-Google-call hardening. Downloads the best available poster
 *     into the media library so the rendered grid references a LOCAL /wp-content/uploads/ image and makes
 *     ZERO requests to i.ytimg.com before the click. Candidate URLs are tried in order; HTTP status is
 *     checked (not image bytes) because YouTube serves a valid-looking 404 body for missing maxres.
 *     Returns the attachment ID on success, or a WP_Error.
 * ------------------------------------------------------------------------------------------------- */
function xrv_sideload_thumbnail( $post_id, $id, $provider = 'youtube', $candidates = null ) {
	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$last_error = new WP_Error( 'xrv_no_candidate', 'No thumbnail candidate was reachable.' );

	$list = ( null !== $candidates ) ? array_values( array_filter( (array) $candidates ) ) : xrv_thumb_candidates( $id, $provider );
	foreach ( $list as $src ) {
		// Defense-in-depth: only ever fetch http(s) candidates. The non-YouTube thumbnail_url is returned
		// by the provider's oEmbed, so this rejects any non-web scheme before a server-side fetch.
		if ( ! preg_match( '#^https?://#i', (string) $src ) ) {
			continue;
		}
		// HEAD-style status check first: YouTube returns 200 for hqdefault and a real 404 for absent maxres.
		$head = wp_remote_head( $src, array( 'timeout' => 8, 'redirection' => 2 ) );
		if ( is_wp_error( $head ) || 200 !== (int) wp_remote_retrieve_response_code( $head ) ) {
			continue;
		}

		$tmp = download_url( $src, 12 );
		if ( is_wp_error( $tmp ) ) {
			$last_error = $tmp;
			continue;
		}

		$ext = 'jpg';
		if ( preg_match( '/\.(webp|png|jpe?g|gif)(?:[?\#]|$)/i', $src, $em ) ) {
			$ext = ( 'jpeg' === strtolower( $em[1] ) ) ? 'jpg' : strtolower( $em[1] );
		}
		$file_array = array(
			'name'     => 'xrv-' . $provider . '-' . $id . '.' . $ext,
			'tmp_name' => $tmp,
		);

		$attach_id = media_handle_sideload( $file_array, $post_id, get_the_title( $post_id ) );
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $tmp ); // download_url's temp file must be cleaned up on failure.
			$last_error = $attach_id;
			continue;
		}

		return (int) $attach_id; // media_handle_sideload removes the temp file itself on success.
	}

	return $last_error;
}

/* =================================================================================================
 * 3. THE SEARCH INDEX GENERATOR
 *    For each video we build one lowercase keyword string from the title, description, and every selected
 *    taxonomy term name AND slug, optionally expanded by a SYNONYM MAP. Written into data-search on the
 *    card; the front-end keyword search matches against it. Editors never hand-write a keyword blob —
 *    tagging a video builds its index automatically.
 * ================================================================================================= */

/**
 * Optional synonym map: term slug => extra search aliases appended whenever a record carries that term,
 * so a search for a lay phrasing resolves to a video tagged with the formal term (and vice versa). Empty
 * by default; sites extend it via the `xrv_synonym_map` filter, e.g.
 *   add_filter( 'xrv_synonym_map', fn( $m ) => $m + array( 'webinars' => 'webinar online talk session' ) );
 */
function xrv_synonym_map() {
	return (array) apply_filters( 'xrv_synonym_map', array() );
}

/**
 * Build the data-search string for one video.
 *
 * @param int   $post_id  The xroad_video post ID.
 * @param array $term_map Pre-fetched [taxonomy => [slugs...]] for this post (avoids repeat queries).
 * @param string $desc    The plain-language description.
 * @return string Lowercase, space-separated keyword index.
 */
function xrv_build_search_index( $post_id, $term_map, $desc ) {
	$parts   = array();
	$parts[] = get_the_title( $post_id );
	$parts[] = (string) $desc;

	$synonyms = xrv_synonym_map();

	foreach ( $term_map as $tax => $slugs ) {
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, $tax );
			if ( $term ) {
				$parts[] = $term->name;
			}
			$parts[] = $slug;
			if ( isset( $synonyms[ $slug ] ) ) {
				$parts[] = $synonyms[ $slug ];
			}
		}
	}

	$index = strtolower( implode( ' ', array_filter( $parts ) ) );
	$index = preg_replace( '/\s+/', ' ', $index );
	return trim( $index );
}

/* =================================================================================================
 * 4. SMALL RENDER HELPERS
 * ================================================================================================= */

/** ISO 8601 duration (PT12M30S) -> clock string (12:30). Returns '' for an empty/unparseable value. */
function xrv_iso_to_clock( $iso ) {
	$iso = trim( (string) $iso );
	if ( $iso === '' || ! preg_match( '/^P/', $iso ) ) {
		return '';
	}
	try {
		$d = new DateInterval( $iso );
	} catch ( Exception $e ) {
		return '';
	}
	$h = (int) $d->h + ( (int) $d->d * 24 );
	$m = (int) $d->i;
	$s = (int) $d->s;
	if ( $h > 0 ) {
		return sprintf( '%d:%02d:%02d', $h, $m, $s );
	}
	return sprintf( '%d:%02d', $m, $s );
}

/** ISO 8601 duration -> total seconds (0 when missing/invalid). Used for the duration sort. */
function xrv_iso_to_seconds( $iso ) {
	$iso = trim( (string) $iso );
	if ( $iso === '' || ! preg_match( '/^P/', $iso ) ) { return 0; }
	try { $d = new DateInterval( $iso ); } catch ( Exception $e ) { return 0; }
	return ( (int) $d->d * 86400 ) + ( (int) $d->h * 3600 ) + ( (int) $d->i * 60 ) + (int) $d->s;
}

/** Format a Unix timestamp in the SITE timezone. wp_date() is WP 5.3+; date_i18n() covers the 5.0 floor. */
function xrv_local_date( $format, $ts ) {
	if ( function_exists( 'wp_date' ) ) { return (string) wp_date( $format, (int) $ts ); }
	return (string) date_i18n( $format, (int) $ts + (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
}

/**
 * 2.11.0: normalise an upload date to a site-local YYYY-MM-DD, or '' when it is not a real date. A bare
 * YYYY-MM-DD is accepted only when checkdate() passes; a Unix timestamp or an ISO 8601 date-time WITH a
 * zone (what the YouTube API returns) is converted to the SITE-LOCAL day; anything else is rejected.
 */
function xrv_normalize_ymd( $v ) {
	if ( ! is_scalar( $v ) ) { return ''; }
	$v = trim( (string) $v );
	if ( '' === $v ) { return ''; }
	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $v : '';
	}
	$ts = null;
	if ( preg_match( '/^\d{9,11}$/', $v ) ) {
		$ts = (int) $v;
	} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})$/i', $v ) ) {
		$t  = strtotime( $v );
		$ts = ( false === $t ) ? null : $t;
	}
	return ( null === $ts ) ? '' : xrv_local_date( 'Y-m-d', $ts );
}

/**
 * 2.11.0: the ONE resolved day for a video, shared by the card date, data-when, the newest/oldest sort
 * key and both JSON-LD emitters: the normalised _xrv_upload_date, else the post's own (local) date.
 */
function xrv_video_ymd( $id ) {
	$ymd = xrv_normalize_ymd( get_post_meta( $id, '_xrv_upload_date', true ) );
	if ( '' === $ymd ) {
		$p   = get_post( $id );
		$ymd = ( $p && '0000-00-00 00:00:00' !== $p->post_date ) ? (string) mysql2date( 'Y-m-d', $p->post_date, false ) : '';
	}
	return $ymd;
}

/**
 * 2.11.0: sanitize_textarea_field() deletes every percent-encoded octet (%20, %2F, the "50% o" in
 * "50% off"), which mangles URLs and prose in descriptions. Shield each % behind a private-use code point
 * while sanitizing, then restore it. Used for every multi-line text field (description, transcript,
 * chapters) on the editor, importer, sync and WP-CLI paths.
 */
function xrv_sanitize_multiline( $v ) {
	if ( ! is_scalar( $v ) ) { return ''; }
	$shield = "\xEE\x80\x80"; // U+E000
	return str_replace( $shield, '%', sanitize_textarea_field( str_replace( '%', $shield, (string) $v ) ) );
}

/**
 * 2.11.0: true only when an attachment belongs to this ONE video and nothing else uses it: parented to the
 * video, not the site default poster, not an ad-hoc embed poster, and not the poster, mobile poster or
 * featured image of any other post. Every force-delete of a poster goes through this check.
 */
function xrv_attachment_is_exclusive( $att_id, $post_id ) {
	$att_id  = (int) $att_id;
	$post_id = (int) $post_id;
	if ( ! $att_id || 'attachment' !== get_post_type( $att_id ) ) { return false; }
	if ( (int) wp_get_post_parent_id( $att_id ) !== $post_id ) { return false; }
	if ( $att_id === (int) xrv_get_settings()['default_thumb_id'] ) { return false; }
	if ( in_array( $att_id, array_map( 'intval', (array) get_option( 'xrv_adhoc_thumbs', array() ) ), true ) ) { return false; }
	global $wpdb;
	$others = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id','_xrv_local_thumb_id','_xrv_mobile_thumb_id') AND meta_value = %s AND post_id <> %d",
		(string) $att_id,
		$post_id
	) );
	return 0 === $others;
}

/**
 * Resolve the attachment ID used as a card's poster, in priority order:
 *   1. the per-video poster (a custom upload OR the auto-sideloaded YouTube thumbnail) — `_xrv_local_thumb_id`
 *   2. the post's featured image, if one was set separately
 *   3. the site-wide default poster set in Settings
 * Returns 0 when none resolves (the card then shows the CSS placeholder).
 *
 * @param int      $post_id  The xroad_video post ID.
 * @param int|null $thumb_id Pre-fetched _xrv_local_thumb_id (pass it to avoid a repeat lookup), or null.
 * @return int Attachment ID (0 if none).
 */
function xrv_effective_thumb_id( $post_id, $thumb_id = null ) {
	$thumb_id = ( null === $thumb_id ) ? (int) get_post_meta( $post_id, '_xrv_local_thumb_id', true ) : (int) $thumb_id;
	if ( $thumb_id ) {
		return $thumb_id;
	}
	$featured = (int) get_post_thumbnail_id( $post_id );
	if ( $featured ) {
		return $featured;
	}
	$s = xrv_get_settings();
	return (int) $s['default_thumb_id'];
}

/** The local poster URL for a card, following the xrv_effective_thumb_id() priority chain. */
function xrv_local_poster_url( $post_id, $thumb_id ) {
	$id = xrv_effective_thumb_id( $post_id, $thumb_id );
	if ( $id ) {
		$url = wp_get_attachment_image_url( $id, 'large' );
		if ( $url ) {
			return $url;
		}
	}
	return '';
}

/**
 * Responsive srcset + sizes for a card poster, so the browser fetches an appropriately sized image
 * instead of always shipping the 1024px "large". Returns empty strings when no media-library attachment
 * backs the poster (e.g. an external featured image), in which case the card just uses its single src.
 *
 * @param int $thumb_id The poster attachment ID (0 when none).
 * @return array{srcset:string,sizes:string}
 */
function xrv_poster_srcset( $thumb_id ) {
	$id = (int) $thumb_id;
	if ( ! $id ) {
		return array( 'srcset' => '', 'sizes' => '' );
	}
	$srcset = wp_get_attachment_image_srcset( $id, 'large' );
	if ( ! $srcset ) {
		return array( 'srcset' => '', 'sizes' => '' );
	}
	// Cards render ~full-width on phones and ~480px on larger screens; this lets the browser pick the
	// smallest sufficient candidate. Filterable for themes with a materially different grid width.
	$sizes = apply_filters( 'xrv_poster_sizes', '(max-width: 782px) 100vw, 480px', $id );
	return array( 'srcset' => $srcset, 'sizes' => (string) $sizes );
}

/**
 * The optional separate mobile poster URL (a manual portrait/square upload, _xrv_mobile_thumb_id). Empty
 * when none is set, in which case the card simply serves a smaller rendition of the primary poster via the
 * normal <img> srcset. Always a local /uploads/ URL, so the click-to-load facade stays zero-third-party.
 */
function xrv_mobile_poster_url( $post_id ) {
	$mid = (int) get_post_meta( $post_id, '_xrv_mobile_thumb_id', true );
	if ( $mid ) {
		$url = wp_get_attachment_image_url( $mid, 'large' );
		if ( $url ) { return $url; }
	}
	return '';
}

/* =================================================================================================
 * 5. THE RENDERER
 *    Queries every video in curated order (each video's numeric Order field, then date, then ID; 2.11.0
 *    adds newest / oldest / title, sorted in PHP), prints the full markup
 *    server-side — inline critical CSS, the SVG sprite, the filter bar, one card per video, the inline
 *    facade + filter JS, and the JSON-LD graph — and returns it as one string. Theme-agnostic; nothing
 *    here references Divi. Shortcode attributes pre-filter the query and set the column width.
 * ================================================================================================= */

/* A YouTube Short = a vertical (9:16) video at /shorts/{id}. Flagged on save (_xrv_short); we also sniff
 * the stored source URL so shorts added before this feature still render vertically. */
function xrv_is_short( $post_id, $source_url = '' ) {
	if ( '1' === (string) get_post_meta( $post_id, '_xrv_short', true ) ) { return true; }
	if ( '' === $source_url ) { $source_url = (string) get_post_meta( $post_id, '_xrv_source_url', true ); }
	return false !== strpos( $source_url, '/shorts/' );
}

/* 2.9.0: resolve a saved Collection (by slug or numeric ID) into base atts for xrv_render. A collection is a
 * hand-picked, ordered set of videos plus a layout (and, since 2.11.0, an order); the ordered ids become an
 * `ids` list. 2.11.0: only a PUBLISHED collection resolves. Unknown, trashed, draft or deleted returns null
 * (xrv_render then prints nothing publicly, never the whole library); a numeric value must be a positive ID
 * (collection="0" used to reach get_post( 0 ), i.e. the current global post); an EMPTY collection comes back
 * with 'empty' => true and renders "No videos found." without a query (the old '-1' sentinel was absint()ed
 * by WP_Query into post ID 1). */
function xrv_collection_atts( $slug ) {
	$slug = trim( (string) $slug );
	if ( '' === $slug ) {
		return null;
	}
	if ( ctype_digit( $slug ) ) {
		$pid  = absint( $slug );
		$post = $pid ? get_post( $pid ) : null;
	} else {
		$post = get_page_by_path( sanitize_title( $slug ), OBJECT, 'xrv_collection' );
	}
	if ( ! $post || 'xrv_collection' !== $post->post_type || 'publish' !== $post->post_status ) {
		return null;
	}
	$ids  = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) get_post_meta( $post->ID, '_xrvc_video_ids', true ) ) ) );
	$atts = array(
		'layout'  => (string) get_post_meta( $post->ID, '_xrvc_layout', true ),
		'orderby' => (string) get_post_meta( $post->ID, '_xrvc_orderby', true ),
		'ids'     => implode( ',', $ids ),
	);
	// Drop an empty layout / order so they inherit the site default.
	$atts = array_filter( $atts, function( $v ) { return '' !== $v; } );
	$atts['empty'] = empty( $ids );
	return $atts;
}

/* 2.11.0: an editor-only explanation (never shown to visitors) for a placement that renders nothing. */
function xrv_editor_notice( $html ) {
	return current_user_can( 'edit_posts' )
		? '<span class="xrv-editor-notice" style="display:inline-block;padding:8px 12px;border:1px dashed #f3d199;background:#fff8ef;border-radius:6px;font-size:13px;color:#7a4f00">' . $html . '</span>'
		: '';
}

/* 2.11.0: esc_html() / esc_attr() that also encode [ and ], so title or description text can never form a
 * shortcode when the rendered gallery passes through do_shortcode again. The zero-padded &#091; / &#093; form
 * matters: do_shortcode() ends with unescape_invalid_shortcodes(), which turns &#91; back into a bare [, which
 * a second pass would then execute (a page builder module running do_shortcode, or the block path, which
 * renders in do_blocks at the_content priority 9, BEFORE do_shortcode at 11). */
function xrv_esc_html( $s ) {
	return str_replace( array( '[', ']' ), array( '&#091;', '&#093;' ), esc_html( (string) $s ) );
}
function xrv_esc_attr( $s ) {
	return str_replace( array( '[', ']' ), array( '&#091;', '&#093;' ), esc_attr( (string) $s ) );
}

/* 2.11.0: a shortcode / block switch, parsed with the existing list: '0', 'false', 'no', 'off' are off. */
function xrv_att_on( $v ) {
	if ( is_bool( $v ) ) { return $v; }
	if ( ! is_scalar( $v ) ) { return false; }
	return ! in_array( strtolower( trim( (string) $v ) ), array( '0', 'false', 'no', 'off' ), true );
}
function xrv_att_enum( $v, $allowed, $default ) {
	$v = is_scalar( $v ) ? strtolower( trim( (string) $v ) ) : '';
	return in_array( $v, $allowed, true ) ? $v : $default;
}

/**
 * 2.11.0: every per-gallery display option, normalised ONCE from a merged settings + attributes array (the
 * shortcode_atts() result, or xrv_get_settings() for a watch page or embed). The card, the carousel and the
 * root read only this array, so a gallery, a watch page and an [xroad-video] embed behave alike.
 */
function xrv_display_opts( $a ) {
	$d = xrv_settings_defaults();
	$a = is_array( $a ) ? $a : array();
	$g = function( $k ) use ( $a, $d ) {
		return array_key_exists( $k, $a ) ? $a[ $k ] : ( isset( $d[ $k ] ) ? $d[ $k ] : '' );
	};
	$privacy = is_scalar( $g( 'privacy_url' ) ) ? trim( (string) $g( 'privacy_url' ) ) : '';
	return array(
		'playback'        => xrv_att_enum( $g( 'playback' ), array( 'inline', 'lightbox', 'lightbox-desktop', 'lightbox-mobile' ), 'lightbox' ),
		'lb_details'      => xrv_att_on( $g( 'lightbox_details' ) ),
		'lb_desc'         => xrv_att_enum( $g( 'lightbox_desc' ), array( 'collapsed', 'full' ), 'collapsed' ),
		'lb_page_link'    => xrv_att_on( $g( 'lightbox_page_link' ) ),
		'consent'         => xrv_att_enum( $g( 'consent_notice' ), array( 'off', 'strict', 'geo' ), 'off' ),
		'consent_text'    => (string) $g( 'consent_text' ),
		'consent_btn'     => (string) $g( 'consent_button' ),
		'consent_decline' => (string) $g( 'consent_decline' ),
		'privacy_url'     => '' !== $privacy ? esc_url_raw( $privacy ) : esc_url_raw( (string) get_privacy_policy_url() ),
		'card_meta'       => xrv_att_enum( $g( 'card_meta' ), array( 'full', 'compact', 'title' ), 'full' ),
		'hover'           => xrv_att_enum( $g( 'hover_style' ), array( 'zoom', 'dim', 'none' ), 'zoom' ),
		'align'           => xrv_att_enum( $g( 'card_align' ), array( 'auto', 'left', 'center' ), 'auto' ),
		'card_date'       => xrv_att_on( $g( 'card_date' ) ),
		'desc_chars'      => max( 0, (int) $g( 'desc_chars' ) ),
		'show_duration'   => xrv_att_on( $g( 'show_duration' ) ),
		'subscribe_icon'  => xrv_att_enum( $g( 'subscribe_icon' ), array( 'brand', 'mono' ), 'brand' ),
		'thumb_link'      => xrv_att_enum( $g( 'thumb_link' ), array( 'none', 'watch' ), 'none' ),
		'orderby'         => xrv_att_enum( $g( 'orderby' ), array( 'curated', 'newest', 'oldest', 'title' ), 'curated' ),
		'preconnect'      => xrv_att_on( $g( 'preconnect' ) ),
		'desc_mode'       => 'plain',
	);
}

/**
 * 2.11.0: the root element's class + data attributes, shared by the gallery root and BOTH single roots (the
 * watch page / [xroad-video id] and [xroad-video url]). In 2.10.0 the single roots carried only
 * data-playback, so a watch page or embed ignored geo / strict consent and always warmed up on hover.
 */
function xrv_root_attrs( $o, $classes = array(), $data = array() ) {
	$cls = array_merge( array( 'xrv' ), (array) $classes, array( 'xrv--hover-' . $o['hover'], 'xrv--align-' . $o['align'] ) );
	if ( ! $o['show_duration'] ) { $cls[] = 'xrv--nodur'; }
	if ( 'mono' === $o['subscribe_icon'] ) { $cls[] = 'xrv--yt-mono'; }
	$attrs = array_merge( array(
		'data-playback'        => $o['playback'],
		'data-lb-details'      => $o['lb_details'] ? '1' : '0',
		'data-lb-desc'         => $o['lb_desc'],
		'data-lb-link'         => $o['lb_page_link'] ? '1' : '0',
		'data-consent'         => $o['consent'],
		'data-consent-text'    => $o['consent_text'],
		'data-consent-btn'     => $o['consent_btn'],
		'data-consent-decline' => $o['consent_decline'],
		'data-privacy'         => $o['privacy_url'],
		'data-preconnect'      => $o['preconnect'] ? '1' : '0',
		'data-i18n-more'       => __( 'Show more', 'xroad-videos' ),
		'data-i18n-less'       => __( 'Show less', 'xroad-videos' ),
		'data-i18n-page'       => __( 'Open video page', 'xroad-videos' ),
		'data-i18n-close'      => __( 'Close video', 'xroad-videos' ),
	), (array) $data );
	if ( 'geo' === $o['consent'] ) {
		$attrs['data-region-url'] = rest_url( 'xrv/v1/region' );
	}
	$out = ' class="' . esc_attr( implode( ' ', $cls ) ) . '"';
	foreach ( $attrs as $k => $v ) {
		$v    = (string) $v;
		$out .= ' ' . $k . '="' . ( in_array( $k, array( 'data-privacy', 'data-region-url' ), true ) ? esc_url( $v ) : xrv_esc_attr( $v ) ) . '"';
	}
	return $out;
}

/**
 * 2.11.0: order a query's posts for orderby newest | oldest | title, in PHP and BEFORE the records loop, so
 * the facet counts and `limit` see the same set the visitor sees. Newest = the resolved upload day
 * (xrv_video_ymd), then the exact publish time (_xrv_published_at, else the post's GMT date), then post ID.
 * Title = the plain title (tags stripped, entities decoded, accents folded), then post ID. Every key ends in
 * the post ID, so the order is total and identical on every page load.
 */
function xrv_sort_posts( $posts, $orderby ) {
	if ( ! in_array( $orderby, array( 'newest', 'oldest', 'title' ), true ) ) {
		return $posts;
	}
	$k = array();
	foreach ( $posts as $p ) {
		if ( 'title' === $orderby ) {
			$t = remove_accents( html_entity_decode( wp_strip_all_tags( (string) $p->post_title ), ENT_QUOTES, 'UTF-8' ) );
			$k[ $p->ID ] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
			continue;
		}
		$pub = strtotime( (string) get_post_meta( $p->ID, '_xrv_published_at', true ) );
		if ( false === $pub ) {
			$gmt = (string) $p->post_date_gmt;
			$pub = ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) ? strtotime( $gmt . ' UTC' ) : strtotime( (string) $p->post_date );
		}
		$k[ $p->ID ] = array( xrv_video_ymd( $p->ID ), (int) $pub );
	}
	usort( $posts, function( $a, $b ) use ( $k, $orderby ) {
		if ( 'title' === $orderby ) {
			$c = strcmp( $k[ $a->ID ], $k[ $b->ID ] );
			return 0 !== $c ? $c : ( $a->ID <=> $b->ID );
		}
		$c = strcmp( $k[ $a->ID ][0], $k[ $b->ID ][0] );
		if ( 0 === $c ) { $c = $k[ $a->ID ][1] <=> $k[ $b->ID ][1]; }
		if ( 0 === $c ) { $c = $a->ID <=> $b->ID; }
		return ( 'newest' === $orderby ) ? -$c : $c;
	} );
	return $posts;
}

/**
 * 2.11.0 (desc_chars): trim a description to at most $max characters at a word boundary and add "…". Multibyte
 * safe; every preg_* call is NULL-guarded (it returns NULL on invalid UTF-8). The full text stays in
 * data-desc for the lightbox; only the visible card paragraph is trimmed.
 */
function xrv_trim_desc( $text, $max ) {
	$text = (string) $text;
	$max  = (int) $max;
	if ( $max < 1 ) {
		return $text;
	}
	$flat = preg_replace( '/\s+/u', ' ', $text );
	if ( null === $flat ) {
		$flat = preg_replace( '/\s+/', ' ', $text );
	}
	$flat = trim( null === $flat ? $text : $flat );
	if ( mb_strlen( $flat, 'UTF-8' ) <= $max ) {
		return $text;
	}
	$cut  = mb_substr( $flat, 0, $max, 'UTF-8' );
	$next = mb_substr( $flat, $max, 1, 'UTF-8' );
	if ( ' ' !== $next ) {
		// Mid-word: back up to the previous space, unless that would throw away most of the text.
		$short = preg_replace( '/\s+\S*$/u', '', $cut );
		if ( null !== $short && '' !== $short && mb_strlen( $short, 'UTF-8' ) >= (int) floor( $max * 0.6 ) ) {
			$cut = $short;
		}
	}
	$cut = rtrim( $cut, " \t\n\r\0\x0B,;:.-" );
	return $cut . "\u{2026}";
}

/* 2.11.0 (card_date): the visible card date, <time datetime="Y-m-d">, formatted in the site timezone with
 * the xrv_card_date_format filter (default 'F j, Y'). wp_date() is WP 5.3+; date_i18n() covers 5.0. */
function xrv_card_date_html( $ymd ) {
	$fmt   = (string) apply_filters( 'xrv_card_date_format', 'F j, Y' );
	$label = '';
	if ( function_exists( 'wp_date' ) && function_exists( 'wp_timezone' ) ) {
		$dt = date_create( $ymd . ' 12:00:00', wp_timezone() );
		if ( $dt ) { $label = (string) wp_date( $fmt, $dt->getTimestamp() ); }
	} else {
		$ts = strtotime( $ymd . ' 12:00:00' );
		if ( $ts ) { $label = (string) date_i18n( $fmt, $ts ); }
	}
	return '' === $label ? '' : '<time class="xrv-date" datetime="' . esc_attr( $ymd ) . '">' . xrv_esc_html( $label ) . '</time>';
}

/* 2.11.0 (watch_desc = rich): the description with its line breaks kept and web addresses made clickable.
 * Only <a href> and <br> survive wp_kses; links to other sites get rel="nofollow noopener" (internal links
 * get none). wp_is_internal_link() is WP 6.2+, so older sites compare the host with home_url(). */
function xrv_rich_desc_html( $text ) {
	// Escape < > & only: an escaped quote (&quot;) would be swallowed into a URL that ends right before it.
	// Quotes are harmless in element text, and wp_kses below re-checks everything.
	$html = make_clickable( htmlspecialchars( (string) $text, ENT_NOQUOTES, 'UTF-8' ) );
	$html = nl2br( $html, false );
	$html = wp_kses( $html, array( 'a' => array( 'href' => true ), 'br' => array() ) );
	$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$html = preg_replace_callback( '/<a href="([^"]*)">/i', function( $m ) use ( $home ) {
		$href     = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		$internal = function_exists( 'wp_is_internal_link' )
			? wp_is_internal_link( $href )
			: ( '' !== $home && strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ) === $home );
		return '<a href="' . $m[1] . '"' . ( $internal ? '' : ' rel="nofollow noopener"' ) . '>';
	}, $html );
	if ( null === $html ) {
		return xrv_esc_html( $text );
	}
	return str_replace( array( '[', ']' ), array( '&#091;', '&#093;' ), $html );
}

function xrv_render( $atts = array() ) {

	// Drop empty attrs so a blank shortcode value OR an unset/"site default" block control falls through to
	// the site defaults below (instead of overriding them with an empty string).
	$atts = array_filter( (array) $atts, function( $v ) { return '' !== $v && null !== $v; } );
	// 2.11.0: "0 = site default" on the block's range controls (or a stray per_page="0") means unset. The '0'
	// used to survive the filter above, and max( 1, 0 ) then showed one card per page.
	foreach ( array( 'per_page', 'load_more' ) as $k ) {
		if ( isset( $atts[ $k ] ) && (int) $atts[ $k ] < 1 ) { unset( $atts[ $k ] ); }
	}

	// 2.9.0: a `collection` resolves a saved Collection's config into the base atts; any attribute passed
	// explicitly on the shortcode/block still overrides the collection. A hand-picked collection arrives here
	// as an ordered `ids` list. 2.11.0: an unknown / trashed / draft / deleted collection (or collection="0")
	// renders NOTHING publicly, never the whole library, and returns BEFORE xrv_head_assets_once() so a later
	// gallery on the same page still prints the shared assets.
	$collection_empty = false;
	if ( isset( $atts['collection'] ) ) {
		$cslug = (string) $atts['collection'];
		$cfg   = xrv_collection_atts( $cslug );
		unset( $atts['collection'] );
		if ( null === $cfg ) {
			/* translators: %s: the collection slug used on the shortcode or block. */
			return xrv_editor_notice( sprintf( esc_html__( 'XRV: there is no published collection "%s". Check the slug under XRV Video > Collections. Visitors see nothing here.', 'xroad-videos' ), xrv_esc_html( $cslug ) ) );
		}
		$collection_empty = ! empty( $cfg['empty'] ) && ! isset( $atts['ids'] );
		unset( $cfg['empty'] );
		$atts = array_merge( $cfg, $atts ); // explicit atts win over collection config
	}
	if ( $collection_empty ) {
		return '<p>No videos found.</p>'; // a real collection with nothing in it yet
	}

	$s = xrv_get_settings(); // site-wide defaults (Videos -> Settings); explicit shortcode/block attrs override these
	$atts = shortcode_atts( array(
		'series'   => '',   // comma-separated xrv_series slugs to pre-filter
		'audience' => '',   // comma-separated xrv_audience slugs
		'topic'    => '',   // comma-separated xrv_topic slugs
		'limit'    => -1,   // max videos (default: all)
		'columns'  => '',   // fixed column count; blank = responsive (masonry for grid, 3 for library/carousel)
		'playback' => $s['playback'], // lightbox (all) | lightbox-desktop | lightbox-mobile | inline — the modal can be scoped to a device class (see below)
		'lightbox_details' => $s['lightbox_details'], // show title + date + description inside the lightbox (1/0)
		'layout'   => 'grid',      // 'grid' | 'carousel' | 'library' (featured carousel + browse grid)
		'controls' => 'true',      // show the search / sort / filter / count bar on the grid
		'filter_ui' => $s['filter_ui'],   // facet filters as dropdown 'select' menus (default) or clickable 'chips'
		'card_meta' => $s['card_meta'],   // card text under the title: 'full' (desc+tags) | 'compact' (desc) | 'title' (title only)
		'featured_limit' => 6,     // how many videos feed the featured carousel (library/carousel layout)
		'heading'  => '',          // optional centered section heading (grid/carousel layout)
		'per_page' => $s['per_page'],     // browse grid: how many cards show before "Load more"
		'load_more' => $s['load_more'],   // how many more cards each "Load more" click reveals
		'subscribe_url'   => $s['subscribe_url'],   // YouTube channel URL; when set, a "Subscribe" button shows under the grid
		'subscribe_label' => $s['subscribe_label'],
		'consent_notice'  => $s['consent_notice'],  // off | strict | geo  — informed-consent UI at the facade
		'consent_text'    => $s['consent_text'],
		'consent_button'  => $s['consent_button'],
		'consent_decline' => $s['consent_decline'], // label for the decline/dismiss control on the prompt
		'privacy_url'     => $s['privacy_url'],      // privacy policy link in the notice; defaults to the WP privacy page
		'shorts'   => $s['shorts_default'],          // all | only | hide — YouTube Shorts (vertical 9:16) handling for this gallery
		'ids'      => '',                            // 2.9.0: explicit ordered post-id list (hand-picked collection); when set, it is the exact set + order
		// 2.11.0 display options; each defaults to its site setting (see xrv_display_opts).
		'orderby'            => $s['orderby'],            // curated | newest | oldest | title
		'hover_style'        => $s['hover_style'],        // zoom | dim | none
		'card_align'         => $s['card_align'],         // auto | left | center
		'card_date'          => $s['card_date'],          // show the upload date on each card (1/0)
		'desc_chars'         => $s['desc_chars'],         // trim the visible card description to N characters (0 = no trim)
		'show_duration'      => $s['show_duration'],      // duration badge on the poster (1/0)
		'subscribe_icon'     => $s['subscribe_icon'],     // brand | mono
		'lightbox_desc'      => $s['lightbox_desc'],      // collapsed | full
		'lightbox_page_link' => $s['lightbox_page_link'], // "Open video page" link in the lightbox caption (1/0)
		'thumb_link'         => $s['thumb_link'],         // none | watch: the poster is also a link to the video's page
		'preconnect'         => $s['preconnect'],         // warm up the video host on hover / focus (1/0)
	), $atts, 'xroad-videos' );

	// 2.11.0: every display option normalised once; the card, the carousel and the root read only $o.
	// Playback: 'lightbox' (pop-out modal on every device) | 'inline' (plays in the card) | device-scoped
	// 'lightbox-desktop' / 'lightbox-mobile' (modal on that device class, inline on the other). The desktop /
	// mobile split is decided CLIENT-SIDE at click time (viewport width), so the page stays fully cacheable.
	$o = xrv_display_opts( $atts );

	$layout    = in_array( $atts['layout'], array( 'grid', 'carousel', 'library' ), true ) ? $atts['layout'] : 'grid';
	$controls  = ! in_array( strtolower( (string) $atts['controls'] ), array( 'false', '0', 'no', 'off' ), true );
	$filter_ui = ( 'chips' === strtolower( (string) $atts['filter_ui'] ) ) ? 'chips' : 'select';
	$card_meta = $o['card_meta'];
	$per_page  = max( 1, (int) $atts['per_page'] );
	$load_step = max( 1, (int) $atts['load_more'] );
	$subscribe_url = esc_url( $atts['subscribe_url'] );
	$limit     = (int) $atts['limit'];

	$tax_query = array();
	foreach ( array( 'xrv_series' => 'series', 'xrv_audience' => 'audience', 'xrv_topic' => 'topic' ) as $tax => $key ) {
		$slugs = array_filter( array_map( 'trim', explode( ',', (string) $atts[ $key ] ) ) );
		if ( $slugs ) {
			$tax_query[] = array( 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $slugs );
		}
	}

	// The query is ALWAYS in curated order (the editor's Order numbers, then date, then ID, so equal Order
	// values tie-break identically on every load); a newest / oldest / title order is applied in PHP below.
	$query_args = array(
		'post_type'      => 'xroad_video',
		'post_status'    => 'publish',
		'posts_per_page' => ( 'curated' === $o['orderby'] && $limit > 0 ) ? $limit : -1,
		'orderby'        => 'menu_order date ID',
		'order'          => 'ASC',
	);
	if ( $tax_query ) {
		$query_args['tax_query'] = $tax_query;
	}

	// Shorts filter: only = just verticals; hide = exclude them; all = mix (default).
	$shorts = in_array( $atts['shorts'], array( 'all', 'only', 'hide' ), true ) ? $atts['shorts'] : $s['shorts_default'];
	if ( 'only' === $shorts )     { $query_args['meta_query'] = array( array( 'key' => '_xrv_short', 'compare' => 'EXISTS' ) ); }
	elseif ( 'hide' === $shorts ) { $query_args['meta_query'] = array( array( 'key' => '_xrv_short', 'compare' => 'NOT EXISTS' ) ); }

	// 2.9.0: an explicit ordered id list (a hand-picked collection, or `[xroad-videos ids="12,7,30"]`) is the
	// exact set AND its curated order. It takes precedence over taxonomy and Shorts filtering.
	$ids = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $atts['ids'] ) ) ) );
	if ( $ids ) {
		$query_args['post__in']       = $ids;
		$query_args['orderby']        = 'post__in'; // preserve the hand-picked order
		$query_args['posts_per_page'] = count( $ids );
		unset( $query_args['tax_query'], $query_args['meta_query'] );
	}

	$q = new WP_Query( $query_args );
	if ( ! $q->have_posts() ) {
		return '<p>No videos found.</p>';
	}

	// 2.11.0: note each post's curated rank, apply the requested order, then `limit`, all BEFORE the records
	// loop, so the facet counts below describe exactly the cards that are printed. (An `ids` list keeps its
	// 2.10.0 behaviour of ignoring `limit`.)
	$posts = $q->posts;
	$cpos  = array();
	foreach ( $posts as $i => $p ) {
		$cpos[ $p->ID ] = $i;
	}
	$posts = xrv_sort_posts( $posts, $o['orderby'] );
	if ( $limit > 0 && ! $ids ) {
		$posts = array_slice( $posts, 0, $limit );
	}

	// First pass: normalise a record per post so we can both count facets and render.
	$records = array();
	$facet   = array( 'series' => array(), 'audience' => array(), 'topic' => array() );
	$tax_for = array( 'series' => 'xrv_series', 'audience' => 'xrv_audience', 'topic' => 'xrv_topic' );

	foreach ( $posts as $p ) {
		$id = $p->ID;

		// ponytail: a video with no platform ID can't play and would render a dead card; keep it out of the grid.
		$vid = (string) get_post_meta( $id, '_xrv_video_id', true );
		if ( '' === $vid ) { continue; }

		// Pull slugs for each filterable taxonomy once; tally facet counts from the live result set.
		$term_map = array();
		$groups   = array();
		foreach ( $tax_for as $group => $tax ) {
			// get_the_terms() reads the term cache WP_Query already primed for this post type, so the whole
			// grid costs one bulk term query instead of three uncached queries per card (wp_get_post_terms).
			$terms = get_the_terms( $id, $tax );
			$slugs = is_array( $terms ) ? wp_list_pluck( $terms, 'slug' ) : array();
			$term_map[ $tax ] = $slugs;
			$groups[ $group ] = $slugs;
			// 2.11.0: the loop variable was $s, which overwrote the settings array (a PHP 8 fatal on any later
			// $s[...] read once a video had terms).
			foreach ( $slugs as $slug ) {
				$facet[ $group ][ $slug ] = ( $facet[ $group ][ $slug ] ?? 0 ) + 1;
			}
		}
		$provider  = (string) get_post_meta( $id, '_xrv_provider', true );
		$provider  = $provider !== '' ? $provider : 'youtube';
		$desc      = (string) get_post_meta( $id, '_xrv_description', true );
		// Title link target: a legacy custom URL wins; else the video's own watch page when on; else none.
		$custom_url = (string) get_post_meta( $id, '_xrv_dedicated_url', true );
		$watch_on   = '0' !== (string) get_post_meta( $id, '_xrv_watch_page', true ); // default on
		$watch_url  = $watch_on ? (string) get_permalink( $id ) : '';
		$dedicated  = '' !== $custom_url ? $custom_url : $watch_url;
		$dur_iso   = (string) get_post_meta( $id, '_xrv_duration_iso', true );
		$ymd       = xrv_video_ymd( $id ); // 2.11.0: the one resolved day (normalised upload date, else the post date)
		$thumb_id  = (int) get_post_meta( $id, '_xrv_local_thumb_id', true );
		$poster_ss = xrv_poster_srcset( xrv_effective_thumb_id( $id, $thumb_id ) );

		$records[] = array(
			'id'         => $id,
			'title'      => get_the_title( $id ),
			'provider'   => $provider,
			'vid'        => $vid,
			'hash'       => (string) get_post_meta( $id, '_xrv_video_hash', true ),
			'source_url' => (string) get_post_meta( $id, '_xrv_source_url', true ),
			'desc'       => $desc,
			'dedicated'  => $dedicated,
			'dur_iso'    => $dur_iso,
			'dur_clock'  => xrv_iso_to_clock( $dur_iso ),
			'dur_sec'    => xrv_iso_to_seconds( $dur_iso ),
			'upload'     => $ymd,
			'ymd'        => $ymd,
			'poster'     => xrv_local_poster_url( $id, $thumb_id ),
			'poster_mobile' => xrv_mobile_poster_url( $id ),
			'poster_srcset' => $poster_ss['srcset'],
			'poster_sizes'  => $poster_ss['sizes'],
			'series'     => $groups['series'],
			'audience'   => $groups['audience'],
			'topic'      => $groups['topic'],
			'date_key'   => '' !== $ymd ? (int) str_replace( '-', '', $ymd ) : 0,
			'search'     => xrv_build_search_index( $id, $term_map, $desc ),
			'is_short'   => xrv_is_short( $id ),
			// Set only when this video's own watch page is actually SERVED (on, and no dedicated URL sending
			// visitors elsewhere): it becomes the VideoObject @id in the gallery schema.
			'watch_url'  => ( '' === $custom_url && $watch_on ) ? $watch_url : '',
			'cpos'       => $cpos[ $id ],
		);
	}
	wp_reset_postdata();

	$total = count( $records );

	// The featured carousel (library/carousel layout) is the first N records in the gallery's order.
	$featured_limit = max( 1, (int) $atts['featured_limit'] );
	$featured       = array_slice( $records, 0, $featured_limit );

	// Column rule. A fixed count → an ALIGNED CSS grid (rows line up, matches a feed layout). library and
	// carousel default to 3 columns. Bare grid with no count keeps the responsive masonry (CSS columns).
	$fixed_cols = (int) $atts['columns'];
	if ( ( 'library' === $layout || 'carousel' === $layout ) && $fixed_cols < 1 ) {
		$fixed_cols = 3;
	}
	$use_cols   = $fixed_cols > 0;
	$grid_class = $use_cols ? 'xrv-grid xrv-grid--cols' : 'xrv-grid';
	if ( 'only' === $shorts ) { $grid_class .= ' xrv-grid--shelf'; } // verticals-only reads as a horizontal swipe shelf
	// For the aligned grid, set the column count as a CUSTOM PROPERTY (not grid-template-columns directly)
	// so the responsive media queries — which step it down to 2 then 1 on tablet/mobile — can override it.
	$grid_style = $use_cols
		? '--xrv-cols:' . $fixed_cols
		: 'column-width:320px';
	$caro_cols  = $fixed_cols > 0 ? $fixed_cols : 3;

	$show_carousel = ( 'library' === $layout || 'carousel' === $layout );
	$show_grid     = ( 'library' === $layout || 'grid' === $layout );

	ob_start();
	?>
<div<?php echo xrv_root_attrs( $o, array( 'xrv--' . $layout ), array( 'data-layout' => $layout, 'data-sort' => $o['orderby'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- every value escaped in xrv_root_attrs ?>>
	<?php echo xrv_head_assets_once(); ?>

	<?php if ( 'library' !== $layout && '' !== $atts['heading'] ) : ?>
		<h2 class="xrv-section-title"><?php echo xrv_esc_html( $atts['heading'] ); ?></h2>
	<?php endif; ?>

	<?php if ( $show_carousel ) : ?>
		<?php if ( 'library' === $layout ) : ?><h2 class="xrv-section-title">Featured Videos</h2><?php endif; ?>
		<?php echo xrv_render_carousel( $featured, $caro_cols, $card_meta, $o ); ?>
	<?php endif; ?>

	<?php if ( $show_grid ) : ?>
		<?php if ( 'library' === $layout ) : ?><h2 class="xrv-section-title">Browse our Library</h2><?php endif; ?>
		<section class="xrv-tool">
			<?php if ( $controls ) : ?>
			<div class="xrv-bar">
				<div class="xrv-search">
					<svg class="xrv-ic"><use href="#xrv-i-search"/></svg>
					<input type="text" class="xrv-q" placeholder="Search videos&hellip;" aria-label="Search videos by keyword">
				</div>
				<div class="xrv-ctrls">
					<?php if ( 'select' === $filter_ui ) {
						// Facet filters as compact dropdown selects, grouped with Sort on the right of the bar.
						echo xrv_render_filter_select( 'series',   'Series',   'xrv_series',   $facet['series'] );
						echo xrv_render_filter_select( 'audience', 'Audience', 'xrv_audience', $facet['audience'] );
						echo xrv_render_filter_select( 'topic',    'Topic',    'xrv_topic',    $facet['topic'] );
					} ?>
					<div class="xrv-sortwrap">
						<?php $sort_id = wp_unique_id( 'xrv-sort-' ); // per-instance so the label/for pairing survives multiple galleries on one page ?><label for="<?php echo esc_attr( $sort_id ); ?>">Sort</label>
						<select id="<?php echo esc_attr( $sort_id ); ?>" class="xrv-sort">
							<option value="curated"<?php selected( $o['orderby'], 'curated' ); ?>>Curated order</option>
							<option value="newest"<?php selected( $o['orderby'], 'newest' ); ?>>Newest first</option>
							<option value="oldest"<?php selected( $o['orderby'], 'oldest' ); ?>>Oldest first</option>
							<option value="title"<?php selected( $o['orderby'], 'title' ); ?>>Title (A&ndash;Z)</option>
							<option value="short">Shortest first</option>
							<option value="long">Longest first</option>
						</select>
					</div>
				</div>
			</div>

			<?php
			if ( 'chips' === $filter_ui ) {
				// Filter chip rows, one per facet, built from the live counts so no empty filter ever shows.
				echo xrv_render_filter_group( 'series',   'Series',   'xrv_series',   $facet['series'] );
				echo xrv_render_filter_group( 'audience', 'Audience', 'xrv_audience', $facet['audience'] );
				echo xrv_render_filter_group( 'topic',    'Topic',    'xrv_topic',    $facet['topic'] );
			}
			?>

			<div class="xrv-statusbar">
				<div class="xrv-count">Showing <strong class="xrv-shown"><?php echo (int) min( $per_page, $total ); ?></strong> of <strong class="xrv-total"><?php echo (int) $total; ?></strong> videos</div>
				<button type="button" class="xrv-reset"><svg class="xrv-ic"><use href="#xrv-i-reset"/></svg> Reset</button>
			</div>
			<?php endif; ?>

			<div class="<?php echo esc_attr( $grid_class ); ?>" style="<?php echo esc_attr( $grid_style ); ?>" data-perpage="<?php echo (int) $per_page; ?>" data-loadstep="<?php echo (int) $load_step; ?>">
				<?php
				// First card eager + high priority = the LCP image. 2.11.0: cards past per_page are printed with
				// `hidden`, so a delayed or failed script never flashes the whole library (JS takes over on init).
				foreach ( $records as $i => $r ) {
					echo xrv_render_card( $r, $card_meta, 0 === $i, $o + array( 'pos' => $i, 'hidden' => $i >= $per_page ) );
				}
				?>
			</div>

			<div class="xrv-empty" style="display:none">
				<h3>No videos match your filters</h3>
				<p>Try removing a filter or clearing the keyword search.</p>
			</div>

			<div class="xrv-more">
				<button type="button" class="xrv-loadmore"<?php echo ( $total > $per_page ) ? '' : ' style="display:none"'; ?>>Load More&hellip;</button>
				<?php if ( $subscribe_url !== '' ) : ?>
					<a class="xrv-subscribe" href="<?php echo esc_url( $subscribe_url ); ?>" target="_blank" rel="noopener"><svg class="xrv-yt" viewBox="0 0 24 24" aria-hidden="true"><use href="#xrv-i-yt"/></svg> <?php echo xrv_esc_html( $atts['subscribe_label'] ); ?></a>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php echo xrv_footer_js_once(); ?>
</div>
	<?php
	// Emit VideoObject schema for the full set, but only from a layout that shows the grid (so a
	// standalone carousel does not duplicate the library page's schema).
	if ( $show_grid ) {
		echo xrv_schema_jsonld( $records, get_permalink() );
	}

	return ob_get_clean();
}

/* -------------------------------------------------------------------------------------------------
 * 5d. Featured carousel. A horizontal, paged track of cards (3 per page on desktop, 2 on tablet, 1 on
 *     mobile) with prev/next arrows and pagination dots. Reuses the same facade card; cards play in the
 *     lightbox. Pure CSS + a small vanilla controller (see xrv_inline_js).
 * ------------------------------------------------------------------------------------------------- */
function xrv_render_carousel( $records, $cols, $card_meta = 'full', $opts = array() ) {
	if ( empty( $records ) ) {
		return '';
	}
	$cols = max( 1, (int) $cols );
	ob_start();
	?>
	<div class="xrv-carousel" data-cols="<?php echo esc_attr( $cols ); ?>">
		<button type="button" class="xrv-caro-arrow xrv-caro-prev" aria-label="Previous videos">&#8249;</button>
		<div class="xrv-caro-viewport">
			<div class="xrv-caro-track">
				<?php foreach ( $records as $i => $r ) { echo xrv_render_card( $r, $card_meta, false, (array) $opts + array( 'pos' => $i ) ); } ?>
			</div>
		</div>
		<button type="button" class="xrv-caro-arrow xrv-caro-next" aria-label="More videos">&#8250;</button>
		<div class="xrv-caro-dots" aria-hidden="true"></div>
	</div>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------------------------------
 * 5a. One video card. The initial state is a LOCAL poster + a native <button> play control. No iframe,
 *     no third-party request, no cookie. The facade JS swaps in the youtube-nocookie iframe on click.
 *     2.11.0: $opts carries the display options (xrv_display_opts) plus per-card pos / hidden. With
 *     thumb_link = watch the poster is an <a> to the same page as the title: a plain primary click still
 *     plays (the JS cancels the navigation), a modified or middle click opens the page.
 * ------------------------------------------------------------------------------------------------- */
function xrv_render_card( $r, $meta = 'full', $eager = false, $opts = array() ) {
	$o = array_merge( array(
		'card_date'  => false,
		'desc_chars' => 0,
		'thumb_link' => 'none',
		'desc_mode'  => 'plain',
		'hidden'     => false,
		'pos'        => null,
	), (array) $opts );
	$title     = $r['title'];
	$dedicated = $r['dedicated'];

	// Tag chips: the display names across the three facet taxonomies, de-duplicated.
	$tags_html = '';
	$seen = array();
	foreach ( array( 'xrv_series' => $r['series'], 'xrv_audience' => $r['audience'], 'xrv_topic' => $r['topic'] ) as $tax => $slugs ) {
		foreach ( $slugs as $slug ) {
			$t = get_term_by( 'slug', $slug, $tax );
			if ( $t && empty( $seen[ $t->name ] ) ) {
				$tags_html .= '<span>' . xrv_esc_html( $t->name ) . '</span>';
				$seen[ $t->name ] = true;
			}
		}
	}

	$poster = $r['poster'];

	// The resolved day (2.11.0: one source for the card date, data-when, the sort key and the schema).
	$ymd = isset( $r['ymd'] ) ? (string) $r['ymd'] : xrv_normalize_ymd( $r['upload'] ?? '' );

	// Relative upload date ("2 months ago") for the lightbox details panel — rendered server-side so the
	// modal can show it without any client-side date math. Empty when the video has no date.
	$when = '';
	if ( '' !== $ymd ) {
		$ts = strtotime( $ymd );
		/* translators: %s: a human time difference such as "2 months". */
		if ( $ts ) { $when = sprintf( __( '%s ago', 'xroad-videos' ), human_time_diff( $ts ) ); }
	}

	$desc_full   = (string) $r['desc'];
	$desc_vis    = ( (int) $o['desc_chars'] > 0 ) ? xrv_trim_desc( $desc_full, (int) $o['desc_chars'] ) : $desc_full;
	$link_poster = ( 'watch' === $o['thumb_link'] && '' !== $dedicated );
	/* translators: %s: the video title. */
	$play_label  = sprintf( __( 'Play video: %s', 'xroad-videos' ), $title );

	ob_start();
	?>
	<figure class="xrv-card"<?php if ( ! empty( $r['is_short'] ) ) echo ' data-short="1"'; ?><?php if ( ! empty( $o['hidden'] ) ) echo ' hidden'; ?>
		data-vid="<?php echo xrv_esc_attr( $r['vid'] ); ?>"
		data-provider="<?php echo esc_attr( $r['provider'] ); ?>"
		data-hash="<?php echo esc_attr( $r['hash'] ?? '' ); ?>"
		data-series="<?php echo esc_attr( implode( ' ', $r['series'] ) ); ?>"
		data-audience="<?php echo esc_attr( implode( ' ', $r['audience'] ) ); ?>"
		data-topic="<?php echo esc_attr( implode( ' ', $r['topic'] ) ); ?>"
		data-date="<?php echo esc_attr( $r['date_key'] ); ?>"
		data-seconds="<?php echo (int) ( $r['dur_sec'] ?? 0 ); ?>"
		data-title="<?php echo xrv_esc_attr( strtolower( $title ) ); ?>"
		<?php if ( null !== $o['pos'] ) : ?>data-pos="<?php echo (int) $o['pos']; ?>" <?php endif; ?>
		<?php if ( isset( $r['cpos'] ) ) : ?>data-cpos="<?php echo (int) $r['cpos']; ?>" <?php endif; ?>
		<?php if ( '' !== $when ) : ?>data-when="<?php echo xrv_esc_attr( $when ); ?>" <?php endif; ?>
		<?php if ( '' !== $desc_full ) : ?>data-desc="<?php echo xrv_esc_attr( $desc_full ); ?>" <?php endif; ?>
		data-search="<?php echo xrv_esc_attr( $r['search'] ); ?>">
		<div class="xrv-frame">
			<?php if ( $link_poster ) : ?>
			<a class="xrv-facade no-prefetch" href="<?php echo esc_url( $dedicated ); ?>" draggable="false" aria-label="<?php echo xrv_esc_attr( $play_label ); ?>">
			<?php else : ?>
			<button type="button" class="xrv-facade" aria-label="<?php echo xrv_esc_attr( $play_label ); ?>">
			<?php endif; ?>
				<?php if ( $poster !== '' ) : ?>
					<?php $mobile = $r['poster_mobile'] ?? ''; $use_pic = ( '' !== $mobile && empty( $r['is_short'] ) ); ?>
					<?php if ( $use_pic ) : ?><picture><source media="(max-width: 600px)" srcset="<?php echo esc_url( $mobile ); ?>"><?php endif; ?>
					<img class="xrv-thumb" src="<?php echo esc_url( $poster ); ?>"<?php if ( ! empty( $r['poster_srcset'] ) ) : ?> srcset="<?php echo esc_attr( $r['poster_srcset'] ); ?>" sizes="<?php echo esc_attr( $r['poster_sizes'] ); ?>"<?php endif; ?> width="480" height="360" loading="<?php echo $eager ? 'eager' : 'lazy'; ?>"<?php echo $eager ? ' fetchpriority="high"' : ''; ?> decoding="async" alt="<?php echo xrv_esc_attr( $title ); ?>"><?php if ( $use_pic ) : ?></picture><?php endif; ?>
				<?php else : ?>
					<span class="xrv-thumb xrv-thumb--ph" aria-hidden="true"></span>
				<?php endif; ?>
				<span class="xrv-play" aria-hidden="true"><svg viewBox="0 0 68 48"><path class="xrv-play__bg" d="M66.5 7.7c-.8-2.9-2.5-5.2-5.4-6C55.8.3 34 .3 34 .3S12.2.3 6.9 1.6C4 2.4 2.3 4.8 1.5 7.7.2 13 .2 24 .2 24s0 11 1.3 16.3c.8 2.9 2.5 5.2 5.4 6C12.2 47.7 34 47.7 34 47.7s21.8 0 27.1-1.4c2.9-.8 4.6-3.1 5.4-6C67.8 35 67.8 24 67.8 24s0-11-1.3-16.3z"/><path d="M27 34V14l18 10-18 10z" fill="#fff"/></svg></span>
				<?php if ( $r['dur_clock'] !== '' ) : ?>
					<span class="xrv-dur"><?php echo esc_html( $r['dur_clock'] ); ?></span>
				<?php endif; ?>
			<?php echo $link_poster ? '</a>' : '</button>'; ?>
		</div>
		<figcaption class="xrv-cap">
			<h3 class="xrv-title"><?php if ( $dedicated !== '' ) : ?><a class="xrv-title-link" href="<?php echo esc_url( $dedicated ); ?>"><?php echo xrv_esc_html( $title ); ?></a><?php else : echo xrv_esc_html( $title ); endif; ?></h3>
			<?php if ( ! empty( $o['card_date'] ) && '' !== $ymd ) { echo xrv_card_date_html( $ymd ); } ?>
			<?php if ( 'title' !== $meta && '' !== $desc_full ) : ?>
				<?php if ( 'rich' === $o['desc_mode'] ) : ?><p class="xrv-desc xrv-desc--rich"><?php echo xrv_rich_desc_html( $desc_full ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped then wp_kses'd in the helper ?></p>
				<?php else : ?><p class="xrv-desc"><?php echo xrv_esc_html( $desc_vis ); ?></p><?php endif; ?>
			<?php endif; ?>
			<?php if ( 'full' === $meta && $tags_html !== '' ) : ?><p class="xrv-tags"><?php echo $tags_html; ?></p><?php endif; ?>
		</figcaption>
	</figure>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------------------------------
 * 5b. Filter-group renderer. Prints a chip toggle only for terms that actually occur, with a live count,
 *     ordered by the seeded vocabulary rather than count order.
 * ------------------------------------------------------------------------------------------------- */
function xrv_render_filter_group( $group, $heading, $taxonomy, $counts ) {
	if ( empty( $counts ) ) {
		return '';
	}
	$ordered = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'orderby' => 'term_id' ) );
	if ( is_wp_error( $ordered ) || empty( $ordered ) ) {
		return '';
	}
	ob_start(); ?>
	<div class="xrv-fg">
		<span class="xrv-ft"><?php echo esc_html( $heading ); ?></span>
		<div class="xrv-chips">
			<?php foreach ( $ordered as $t ) :
				if ( empty( $counts[ $t->slug ] ) ) { continue; } ?>
				<button type="button" class="xrv-chip" data-group="<?php echo esc_attr( $group ); ?>" value="<?php echo esc_attr( $t->slug ); ?>" aria-pressed="false"><?php echo esc_html( $t->name ); ?><span class="xrv-cnt"><?php echo (int) $counts[ $t->slug ]; ?></span></button>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------------------------------
 * 5c. Filter-select renderer. A compact dropdown per facet (single choice + "All"), shown only for
 *     terms that actually occur, ordered by the seeded vocabulary. Used when filter_ui="select".
 * ------------------------------------------------------------------------------------------------- */
function xrv_render_filter_select( $group, $heading, $taxonomy, $counts ) {
	if ( empty( $counts ) ) {
		return '';
	}
	$ordered = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'orderby' => 'term_id' ) );
	if ( is_wp_error( $ordered ) || empty( $ordered ) ) {
		return '';
	}
	$id = wp_unique_id( 'xrv-fsel-' . $group . '-' ); // per-instance id so each label/for pairing is valid with multiple galleries on one page
	ob_start(); ?>
	<div class="xrv-fselwrap">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $heading ); ?></label>
		<select class="xrv-fsel" id="<?php echo esc_attr( $id ); ?>" data-group="<?php echo esc_attr( $group ); ?>">
			<option value="">All <?php echo esc_html( $heading ); ?></option>
			<?php foreach ( $ordered as $t ) :
				if ( empty( $counts[ $t->slug ] ) ) { continue; } ?>
				<option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?> (<?php echo (int) $counts[ $t->slug ]; ?>)</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------------------------------
 * 5d. Region endpoint for the geo-aware consent notice (consent_notice="geo"). Cache-safe: the page
 *     stays fully cacheable; only this tiny uncached REST call decides whether the visitor is in a
 *     consent-required region. Country comes from an edge header (Cloudflare CF-IPCountry / WP Engine /
 *     a GeoIP var); sites without one fail SAFE to "consent required". Override via the xrv_consent_required filter.
 * ------------------------------------------------------------------------------------------------- */
add_action( 'rest_api_init', function () {
	register_rest_route( 'xrv/v1', '/region', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'xrv_rest_region',
	) );
} );
/* Shared edge/server geo detection — used by BOTH the REST endpoint and the Settings status panel. Returns
 * the 2-letter country ('' if none), a human label, and the exact header it came from. No IP lookup, no
 * external call, no bundled DB — the plugin only reads a header the edge/CDN/host already provides. */
function xrv_geo_country() {
	$sources = array(
		'HTTP_CF_IPCOUNTRY'              => 'Cloudflare',
		'GEOIP_COUNTRY_CODE'             => 'GeoIP module / WP Engine GeoTarget',
		'HTTP_X_COUNTRY_CODE'            => 'Proxy header (X-Country-Code)',
		'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'AWS CloudFront',
	);
	foreach ( $sources as $k => $label ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			return array( 'country' => strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ), 0, 2 ) ), 'source' => $label, 'header' => $k );
		}
	}
	return array( 'country' => '', 'source' => '', 'header' => '' );
}
function xrv_is_consent_region( $cc ) {
	$eea_uk_ch = array( 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','IS','LI','NO','GB','CH' );
	return ( '' === $cc || 'XX' === $cc ) ? true : in_array( $cc, $eea_uk_ch, true ); // unknown -> fail safe to required
}
function xrv_rest_region() {
	$g = xrv_geo_country();
	$required = (bool) apply_filters( 'xrv_consent_required', xrv_is_consent_region( $g['country'] ), $g['country'] );
	$res = new WP_REST_Response( array( 'country' => $g['country'], 'consent_required' => $required, 'source' => $g['source'] ), 200 );
	$res->header( 'Cache-Control', 'no-store, max-age=0' );
	return $res;
}

/* =================================================================================================
 * 6. JSON-LD SCHEMA  (self-generating VideoObject inside CollectionPage > ItemList)
 *    Built inline on every render and regenerated from the records, so adding or editing a video updates
 *    the schema with no manual JSON editing. Each VideoObject carries the required and recommended Google
 *    fields (name, description, thumbnailUrl, uploadDate, duration, contentUrl, embedUrl, publisher). The
 *    publisher @id is host-derived and exposed via apply_filters('xrv_org_id', ...) so it can be pinned
 *    to be IDENTICAL to the Organization @id your SEO plugin already emits — the two then MERGE into one
 *    entity instead of competing.
 *
 *    LAUNCH NOTE: if an SEO plugin (Yoast, Rank Math, etc.) emits an Organization node in the page head,
 *    view-source to find its exact @id and pin xrv_org_id to that string. The filter makes this a one-
 *    line config in the theme's functions.php, not a code change here. With no SEO plugin, the default
 *    host-derived @id and the minimal Organization node below stand on their own.
 * ================================================================================================= */

function xrv_org_id() {
	return apply_filters( 'xrv_org_id', untrailingslashit( home_url() ) . '/#organization' );
}

function xrv_schema_jsonld( $records, $page_url = '' ) {
	$org_id     = xrv_org_id();
	$website_id = untrailingslashit( home_url() ) . '/#website';
	if ( empty( $page_url ) ) {
		$qid      = get_queried_object_id();
		$page_url = $qid ? get_permalink( $qid ) : home_url( '/' );
	}
	$org_ref = array( '@id' => $org_id ); // lean reference; the full node is defined once, below.

	$items = array();
	$pos   = 1;

	foreach ( $records as $r ) {
		if ( $r['vid'] === '' ) {
			continue; // a record with no video ID cannot emit a valid VideoObject.
		}

		// 2.11.0: decode the title's HTML entities (get_the_title() texturizes "Doctor's" into
		// "Doctor&#8217;s", which JSON-LD printed literally). @id is the watch page's own VideoObject @id,
		// added only when that page is actually served, so the two nodes merge into one entity.
		$name = html_entity_decode( (string) $r['title'], ENT_QUOTES, 'UTF-8' );
		$node = array( '@type' => 'VideoObject' );
		if ( ! empty( $r['watch_url'] ) ) {
			$node['@id'] = $r['watch_url'] . '#video';
		}
		$node['name'] = $name;

		$desc = $r['desc'] !== '' ? $r['desc'] : $name;
		$node['description'] = $desc;

		// thumbnailUrl: the LOCAL upload first (what the page actually renders), then the platform URL.
		$thumbs = array();
		if ( $r['poster'] !== '' ) {
			$thumbs[] = $r['poster'];
		}
		$rt = xrv_remote_thumb_url( $r['vid'], $r['provider'] );
		if ( '' !== $rt ) { $thumbs[] = $rt; }
		$node['thumbnailUrl'] = $thumbs;

		$upload = isset( $r['ymd'] ) ? (string) $r['ymd'] : (string) $r['upload']; // 2.11.0: the resolved day (xrv_video_ymd)
		if ( $upload !== '' ) {
			$node['uploadDate'] = $upload;
		}
		if ( $r['dur_iso'] !== '' ) {
			$node['duration'] = $r['dur_iso'];
		}

		$node['contentUrl'] = ! empty( $r['source_url'] ) ? $r['source_url'] : xrv_watch_url( $r['vid'], $r['provider'] );
		$node['embedUrl']   = xrv_embed_url( $r['vid'], $r['provider'], isset( $r['hash'] ) ? $r['hash'] : '' );
		$node['publisher']  = $org_ref;
		// 2.11.0: url = the page the card's title (and a linked poster) points to.
		if ( ! empty( $r['dedicated'] ) ) {
			$node['url'] = ( 0 === strpos( (string) $r['dedicated'], '/' ) && 0 !== strpos( (string) $r['dedicated'], '//' ) ) ? home_url( $r['dedicated'] ) : $r['dedicated'];
		}

		// Let sites extend a single VideoObject node (e.g. add `about`, `transcript`, `regionsAllowed`).
		$node = apply_filters( 'xrv_video_schema', $node, $r );

		$items[] = array( '@type' => 'ListItem', 'position' => $pos, 'item' => $node );
		$pos++;
	}

	// A minimal Organization node, defined once. Its @id matches xrv_org_id(), so when an SEO plugin emits
	// its own Organization node under the same @id the two MERGE into one entity instead of competing.
	$org_node = array(
		'@type' => 'Organization',
		'@id'   => $org_id,
		'name'  => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
		'url'   => home_url( '/' ),
	);

	$list_name = html_entity_decode( (string) apply_filters( 'xrv_list_name', get_bloginfo( 'name' ) . ' video library' ), ENT_QUOTES, 'UTF-8' );

	$collection = array(
		'@type'      => 'CollectionPage',
		'@id'        => $page_url . '#videos',
		'name'       => $list_name,
		'isPartOf'   => array( '@id' => $website_id ),
		'publisher'  => $org_ref,
		'mainEntity' => array(
			'@type'           => 'ItemList',
			'name'            => $list_name,
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		),
	);
	if ( ! empty( $page_url ) ) {
		$collection['mainEntityOfPage'] = $page_url;
	}

	$graph = array(
		'@context' => 'https://schema.org',
		'@graph'   => array( $org_node, $collection ),
	);

	return xrv_jsonld_script( $graph );
}

/* 2.11.0: print a JSON-LD graph. Brackets INSIDE JSON strings are written as [ / ], so a
 * description containing [video src=…] can never become a shortcode if the page passes through
 * do_shortcode after us (the block path). Structural brackets (arrays) are untouched. */
function xrv_jsonld_script( $graph ) {
	$json = (string) wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	$safe = preg_replace_callback( '/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/s', function( $m ) {
		return str_replace( array( '[', ']' ), array( '\u005b', '\u005d' ), $m[0] );
	}, $json );
	return "\n" . '<script type="application/ld+json">' . ( null === $safe ? $json : $safe ) . '</script>' . "\n";
}

/* -------------------------------------------------------------------------------------------------
 * 6a. Parse a "key moments" textarea ("M:SS Label" per line, or "H:MM:SS Label") into schema.org Clip
 *     nodes with startOffset / endOffset / a deep-linked url. Drives Google's key-moments rich result.
 * ------------------------------------------------------------------------------------------------- */
function xrv_parse_chapters( $text, $content_url ) {
	$starts = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( $line === '' ) {
			continue;
		}
		if ( preg_match( '/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\s+(.+)$/', $line, $m ) ) {
			$h = $m[1] !== '' ? (int) $m[1] : 0;
			$start = ( $h * 3600 ) + ( (int) $m[2] * 60 ) + (int) $m[3];
			$starts[] = array( 'start' => $start, 'name' => trim( $m[4] ) );
		}
	}
	$clips = array();
	$n = count( $starts );
	for ( $i = 0; $i < $n; $i++ ) {
		$clip = array( '@type' => 'Clip', 'name' => $starts[ $i ]['name'], 'startOffset' => $starts[ $i ]['start'] );
		if ( $i + 1 < $n ) {
			$clip['endOffset'] = $starts[ $i + 1 ]['start'];
		}
		if ( $content_url !== '' ) {
			$sep = ( strpos( $content_url, '?' ) !== false ) ? '&' : '?';
			$clip['url'] = $content_url . $sep . 't=' . $starts[ $i ]['start'] . 's';
		}
		$clips[] = $clip;
	}
	return $clips;
}

/* -------------------------------------------------------------------------------------------------
 * 6b. STANDALONE rich VideoObject for a single video's own page. Emitted instead of the CollectionPage
 *     wrapper so the page's main entity is the video itself, with every field Google and AI answer
 *     engines reward: name, description, thumbnailUrl[], uploadDate (REQUIRED — falls back to the post
 *     date), duration, contentUrl, embedUrl, publisher (merged org @id), inLanguage, isFamilyFriendly,
 *     transcript, key-moment Clips, and keywords. Validated against the Video rich-results requirements.
 * ------------------------------------------------------------------------------------------------- */
function xrv_single_video_schema( $post_id ) {
	$provider = (string) get_post_meta( $post_id, '_xrv_provider', true );
	$provider = $provider !== '' ? $provider : 'youtube';
	$vid      = (string) get_post_meta( $post_id, '_xrv_video_id', true );
	$hash     = (string) get_post_meta( $post_id, '_xrv_video_hash', true );
	if ( $vid === '' ) {
		return '';
	}

	$title  = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ); // 2.11.0: no literal &#8217; in the name
	$desc   = (string) get_post_meta( $post_id, '_xrv_description', true );
	$desc   = $desc !== '' ? $desc : $title;
	$upload = xrv_video_ymd( $post_id ); // uploadDate is required: the normalised upload date, else the post date
	$dur    = (string) get_post_meta( $post_id, '_xrv_duration_iso', true );
	$thumb  = (int) get_post_meta( $post_id, '_xrv_local_thumb_id', true );
	$source = (string) get_post_meta( $post_id, '_xrv_source_url', true );
	$transcript = (string) get_post_meta( $post_id, '_xrv_transcript', true );
	$chapters   = (string) get_post_meta( $post_id, '_xrv_chapters', true );

	$content_url = $source !== '' ? $source : xrv_watch_url( $vid, $provider );
	$page        = get_permalink( $post_id );

	$thumbs = array();
	$poster = xrv_local_poster_url( $post_id, $thumb );
	if ( $poster !== '' ) {
		$thumbs[] = $poster;
	}
	$rt = xrv_remote_thumb_url( $vid, $provider );
	if ( '' !== $rt ) { $thumbs[] = $rt; }

	$node = array(
		'@type'            => 'VideoObject',
		'@id'              => $page . '#video',
		'name'             => $title,
		'description'      => $desc,
		'thumbnailUrl'     => $thumbs,
		'uploadDate'       => $upload,
		'contentUrl'       => $content_url,
		'embedUrl'         => xrv_embed_url( $vid, $provider, $hash ),
		'publisher'        => array( '@id' => xrv_org_id() ),
		'inLanguage'       => apply_filters( 'xrv_video_language', 'en', $post_id ),
		'isFamilyFriendly' => true,
		'mainEntityOfPage' => $page,
	);
	if ( $dur !== '' ) {
		$node['duration'] = $dur;
	}
	if ( $transcript !== '' ) {
		$node['transcript'] = $transcript;
	}
	$clips = xrv_parse_chapters( $chapters, $content_url );
	if ( $clips ) {
		$node['hasPart'] = $clips;
	}

	// keywords: every taxonomy term name assigned to the video (series + audience + topic + condition).
	$kw = array();
	foreach ( array( 'xrv_series', 'xrv_audience', 'xrv_topic', 'xrv_condition' ) as $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}
		$terms = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) ) {
			$kw = array_merge( $kw, $terms );
		}
	}
	$kw = array_values( array_unique( array_filter( $kw ) ) );
	if ( $kw ) {
		$node['keywords'] = implode( ', ', $kw );
	}

	$node = apply_filters( 'xrv_video_schema', $node, array( 'id' => $post_id, 'vid' => $vid, 'provider' => $provider ) );

	$graph = array(
		'@context' => 'https://schema.org',
		'@graph'   => array(
			array( '@type' => 'Organization', '@id' => xrv_org_id(), 'name' => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), 'url' => home_url( '/' ) ),
			$node,
		),
	);
	return xrv_jsonld_script( $graph );
}

/* =================================================================================================
 * 7. INLINE ASSETS  (SVG sprite, scoped critical CSS, vanilla JS)
 *    Emitted inline inside the rendered block, namespaced under .xrv- and the .xrv wrapper class. Inlining is
 *    deliberate: first paint is self-sufficient, the styles survive a performance plugin's unused-CSS
 *    pass (inline styles are not removal candidates), and the tool stays theme-independent.
 *    WCAG AA: 4.5:1 text contrast, 3:1 non-text/focus ring.
 * ================================================================================================= */

/* Emit the shared inline assets (SVG sprite + CSS, and the JS) only ONCE per request, so multiple
 * galleries/shortcodes on a single page don't duplicate ~29KB of identical inline payload. The JS already
 * initialises every .xrv root, so one copy serves all instances. */
function xrv_head_assets_once() {
	static $done = false;
	if ( $done ) { return ''; }
	$done = true;
	// 2.11.0: the no-JS / blocked-JS restore for cards printed past per_page with the hidden attribute (the
	// script reveals them in pages; without it, every card shows and the Load more button hides).
	$noscript = '<noscript><style>.xrv .xrv-grid .xrv-card[hidden]{display:block!important}.xrv .xrv-loadmore{display:none!important}</style></noscript>';
	return xrv_icon_sprite() . "\n" . xrv_inline_css() . xrv_dynamic_css() . $noscript;
}

/* Per-site brand override for the play-button color combo (Settings -> Play button color). Emits the two
 * CSS vars only when set; otherwise the button inherits --xrv-primary / --xrv-action (the brand tokens).
 * Values are sanitize_hex_color'd on save, so they are safe to inline here. */
function xrv_dynamic_css() {
	$s    = xrv_get_settings();
	$base = isset( $s['icon_color'] ) ? $s['icon_color'] : '';
	$hov  = isset( $s['icon_hover'] ) ? $s['icon_hover'] : '';
	if ( '' === $base && '' === $hov ) { return ''; }
	$v  = '' !== $base ? '--xrv-play:' . $base . ';' : '';
	$v .= '' !== $hov ? '--xrv-play-hover:' . $hov . ';' : '';
	return "\n" . '<style id="xrv-dynamic">.xrv{' . $v . '}</style>';
}
function xrv_footer_js_once() {
	static $done = false;
	if ( $done ) { return ''; }
	$done = true;
	return xrv_inline_script_tag( 'xrv-js', xrv_inline_js() );
}

/* 2.11.0: the inline script tag with a stable id (xrv-js, like the xrv-css style) and the
 * xrv_inline_script_attrs filter, so a performance plugin's delay / defer / combine step can be told to leave
 * the facade alone, e.g. add_filter( 'xrv_inline_script_attrs', fn() => array( 'data-no-optimize' => '1',
 * 'data-no-defer' => '1', 'nowprocket' => true ) ). A true value prints a bare attribute. */
function xrv_inline_script_tag( $id, $js ) {
	$attrs = (array) apply_filters( 'xrv_inline_script_attrs', array(), $id );
	$out   = '<script id="' . esc_attr( $id ) . '"';
	foreach ( $attrs as $k => $v ) {
		$k = preg_replace( '/[^A-Za-z0-9_:\-]/', '', (string) $k );
		if ( '' === $k || 'id' === strtolower( $k ) || false === $v || null === $v ) { continue; }
		$out .= ( true === $v ) ? ' ' . $k : ' ' . $k . '="' . esc_attr( (string) $v ) . '"';
	}
	// The body sits inside an HTML comment (<!-- ... //-->, read by JS engines as two line comments). Block
	// themes run wptexturize() over the whole rendered template, and its HTML splitter treats a JS "<" (as in
	// "a <= b") as the start of a tag and rewrites every "&" up to the next ">" as "&#038;", which breaks
	// "&&". wptexturize skips comments entirely. The script contains no "-->" and no "</script".
	return $out . ">\n<!--\n" . $js . "\n//-->\n</script>";
}

function xrv_icon_sprite() {
	return <<<'SVG'
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<symbol id="xrv-i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol>
<symbol id="xrv-i-reset" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></symbol>
<symbol id="xrv-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></symbol>
<symbol id="xrv-i-yt" viewBox="0 0 24 24"><path style="fill:var(--xrv-yt-body,#FF0000);fill-rule:var(--xrv-yt-rule,nonzero)" d="M23 7.5a3 3 0 0 0-2.1-2.1C19 4.9 12 4.9 12 4.9s-7 0-8.9.5A3 3 0 0 0 1 7.5 31 31 0 0 0 .5 12 31 31 0 0 0 1 16.5a3 3 0 0 0 2.1 2.1c1.9.5 8.9.5 8.9.5s7 0 8.9-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 23.5 12 31 31 0 0 0 23 7.5zM9.75 15.5l6-3.5-6-3.5z"/><path style="fill:var(--xrv-yt-play,#fff)" d="M9.75 15.5l6-3.5-6-3.5z"/></symbol>
</defs></svg>
SVG;
}

function xrv_inline_css() {
	return <<<'CSS'
<style id="xrv-css">
.xrv{font-family:var(--xrv-font, 'Gotham',Helvetica,Arial,sans-serif) !important;color:var(--xrv-text,#1a2332) !important;line-height:1.55 !important;max-width:1180px;margin:0 auto;padding:0}
.xrv *,.xrv *::before,.xrv *::after{box-sizing:border-box}
.xrv h3{font-family:inherit !important;line-height:1.3 !important;margin:0;font-weight:700;text-align:left}
.xrv h3.xrv-title{font-weight:var(--xrv-title-weight,700)}
.xrv p{margin:0}
.xrv a{color:var(--xrv-link,#017A8E);text-decoration:none}
.xrv a:hover{text-decoration:underline}
.xrv-ic{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-2px;flex:0 0 auto}
.xrv-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.xrv-search{position:relative;flex:1 1 320px;min-width:240px}
.xrv-search .xrv-ic{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--xrv-muted,#5a6573)}
.xrv-search input{width:100%;padding:10px 12px 10px 36px;border:1px solid var(--xrv-border,#c4ccd6);border-radius:4px;font-family:inherit;font-size:14px;color:var(--xrv-text,#1a2332);background:#fff}
.xrv-search input:focus{outline:2px solid var(--xrv-accent,#019AB3);outline-offset:-1px;border-color:var(--xrv-accent,#019AB3)}
.xrv-ctrls{display:flex;align-items:center;gap:10px 16px;flex-wrap:wrap}
.xrv-sortwrap,.xrv-fselwrap{display:flex;align-items:center;gap:9px;font-size:12px}
.xrv-sortwrap label,.xrv-fselwrap label{letter-spacing:.08em;text-transform:uppercase;color:var(--xrv-muted,#5a6573);font-weight:700;white-space:nowrap}
.xrv-sortwrap select,.xrv-fselwrap select{padding:7px 10px;border:1px solid var(--xrv-border,#c4ccd6);border-radius:4px;font-family:inherit;font-size:13px;background:#fff;color:var(--xrv-text,#1a2332);cursor:pointer;max-width:180px}
.xrv-fselwrap select:focus,.xrv-sortwrap select:focus{outline:2px solid var(--xrv-accent,#019AB3);outline-offset:-1px;border-color:var(--xrv-accent,#019AB3)}
.xrv-fselwrap select[data-active="1"]{border-color:var(--xrv-primary,#013C60);background:#f0f6fa;font-weight:600}
.xrv-fg{display:flex;align-items:baseline;gap:10px;margin:0 0 10px;flex-wrap:wrap}
.xrv-ft{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--xrv-muted,#5a6573);font-weight:700;flex:0 0 64px;padding-top:5px}
.xrv-chips{display:flex;flex-wrap:wrap;gap:7px}
.xrv-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;background:#fff;border:1px solid var(--xrv-border,#c4ccd6);border-radius:999px;font-family:inherit;font-size:12.5px;color:var(--xrv-text,#1a2332);cursor:pointer;transition:background .12s ease,border-color .12s ease,color .12s ease}
.xrv-chip:hover{border-color:var(--xrv-accent,#019AB3);color:var(--xrv-link,#017A8E)}
.xrv-chip[aria-pressed="true"]{background:var(--xrv-primary,#013C60);border-color:var(--xrv-primary,#013C60);color:#fff}
.xrv-chip:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-cnt{font-size:11px;color:#636c79;font-variant-numeric:tabular-nums}
.xrv-chip[aria-pressed="true"] .xrv-cnt{color:rgba(255,255,255,.75)}
.xrv-statusbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0 16px;border-bottom:2px solid var(--xrv-primary,#013C60);margin-bottom:20px}
.xrv-count{font-size:14px;color:var(--xrv-muted,#5a6573)}
.xrv-count strong{color:var(--xrv-primary,#013C60);font-weight:700;font-size:16px}
.xrv-reset{background:transparent;border:1px solid var(--xrv-border,#c4ccd6);color:var(--xrv-muted,#5a6573);font-family:inherit;font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;padding:6px 11px;border-radius:3px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.xrv-reset:hover{border-color:var(--xrv-primary,#013C60);color:var(--xrv-primary,#013C60)}
.xrv-reset:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-grid{column-gap:20px}
.xrv-card{break-inside:avoid;-webkit-column-break-inside:avoid;page-break-inside:avoid;margin:0 0 24px;display:inline-block;width:100%;content-visibility:auto;contain-intrinsic-size:auto 320px}
.xrv .xrv-card[hidden]{display:none!important}
.xrv-frame{position:relative;width:100%}
.xrv-facade{display:block;position:relative;width:100%;padding:0;margin:0;border:none;background:#0a1622;border-radius:var(--xrv-radius,6px);overflow:hidden;cursor:pointer;aspect-ratio:16/9;line-height:0}
.xrv a.xrv-facade,.xrv a.xrv-facade:hover{color:inherit;text-decoration:none !important;-webkit-user-drag:none}
.xrv-card[data-short],.xrv-card[data-provider="tiktok"]{max-width:300px;margin-left:auto;margin-right:auto}
.xrv-card[data-short] .xrv-facade,.xrv-card[data-short] .xrv-iframe,.xrv-card[data-provider="tiktok"] .xrv-facade,.xrv-card[data-provider="tiktok"] .xrv-iframe{aspect-ratio:9/16}
.xrv-facade:focus-visible{outline:none}
.xrv-facade:focus-visible::after{content:"";position:absolute;inset:0;z-index:3;border-radius:inherit;box-shadow:inset 0 0 0 3px var(--xrv-accent,#019AB3),inset 0 0 0 5px #fff;pointer-events:none}
@media (forced-colors:active){.xrv-facade:focus-visible{outline:3px solid CanvasText;outline-offset:-3px}}
.xrv-thumb{display:block;width:100%;height:100%;object-fit:cover;border:0;transition:transform .3s ease,opacity .2s ease}
.xrv-thumb--ph{background:linear-gradient(135deg,var(--xrv-primary,#013C60),var(--xrv-link,#017A8E))}
.xrv-facade:hover .xrv-thumb{transform:scale(1.04);opacity:.92}
.xrv-play{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:64px;height:46px;display:flex;align-items:center;justify-content:center;transition:transform .15s ease}
.xrv-play svg{width:100%;height:100%;display:block}
.xrv-play__bg{fill:var(--xrv-play,var(--xrv-primary,#013C60));fill-opacity:.92;transition:fill .15s ease}
.xrv-facade:hover .xrv-play{transform:translate(-50%,-50%) scale(1.08)}
.xrv-facade:hover .xrv-play__bg{fill:var(--xrv-play-hover,var(--xrv-action,#007A53));fill-opacity:1}
.xrv-dur{position:absolute;right:8px;bottom:8px;background:rgba(10,22,34,.85);color:#fff;font-size:12px;font-weight:600;line-height:1;padding:4px 6px;border-radius:3px;font-variant-numeric:tabular-nums}
.xrv-iframe,.xrv-video{display:block;width:100%;aspect-ratio:16/9;border:0;border-radius:var(--xrv-radius,6px)}
.xrv-video{background:#000;object-fit:contain}
.xrv-cap{padding:12px 2px 0}
.xrv-consent-note{font-size:11.5px;color:var(--xrv-muted,#5a6573);line-height:1.4;margin:7px 0 0}
.xrv-consent-note a{color:var(--xrv-link,#017A8E)}
.xrv-consent{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:11px;padding:18px;text-align:center;background:rgba(10,22,34,.88);border-radius:var(--xrv-radius,6px);z-index:4}
.xrv-consent-msg{color:#fff;font-size:13.5px;line-height:1.45;margin:0;max-width:34em}
.xrv-consent-go{background:var(--xrv-primary,#013C60);color:#fff;border:0;border-radius:4px;padding:9px 20px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:background .15s ease}
.xrv-consent-go:hover{background:var(--xrv-action,#007A53)}
.xrv-consent-go:focus-visible,.xrv-consent-decline:focus-visible,.xrv-consent-x:focus-visible{outline:3px solid var(--xrv-accent,#019AB3);outline-offset:2px}
.xrv-consent-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:center}
.xrv-consent-decline{background:transparent;color:#cfe9e0;border:1px solid rgba(255,255,255,.45);border-radius:4px;padding:9px 16px;font-family:inherit;font-size:14px;cursor:pointer;transition:border-color .15s ease,color .15s ease}
.xrv-consent-decline:hover{border-color:#fff;color:#fff}
.xrv-consent-x{position:absolute;top:7px;right:10px;background:transparent;border:0;color:rgba(255,255,255,.7);font-size:22px;line-height:1;cursor:pointer;padding:2px 7px;border-radius:3px}
.xrv-consent-x:hover{color:#fff}
.xrv-consent-link{color:#cfe9e0;font-size:12px}
.xrv-title{font-size:var(--xrv-title-size,16px) !important;font-weight:var(--xrv-title-weight,700);color:var(--xrv-title-color,var(--xrv-primary,#013C60)) !important;margin:0 0 6px !important;line-height:1.2 !important}
.xrv-title-link{color:inherit !important;text-decoration:none !important}
.xrv-title-link:hover,.xrv-title-link:focus{text-decoration:underline !important}
.xrv-date{display:block;margin:-2px 0 7px;font-size:12.5px;line-height:1.3;color:var(--xrv-date-color,var(--xrv-muted,#5a6573));font-variant-numeric:tabular-nums}
/* Match the production carousel caption (13px / 1.3) and cap the blurb at ~5 lines so cards stay uniform. */
.xrv-desc{font-size:13px;color:var(--xrv-desc,#4a5663);line-height:1.3;margin:0 0 9px;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:5;line-clamp:5;overflow:hidden}
.xrv--single .xrv-desc{-webkit-line-clamp:unset;line-clamp:unset;display:block;overflow:visible;font-size:14px;margin-bottom:14px}
.xrv--single .xrv-grid{column-count:1 !important}
.xrv-desc--rich a{word-break:break-word}
.xrv-tags{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:11.5px;color:var(--xrv-muted,#5a6573);margin:0 0 9px}
.xrv-tags span::before{content:"#";color:var(--xrv-border,#c4ccd6);margin-right:1px}
.xrv-page-link{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;letter-spacing:.02em;color:var(--xrv-link,#017A8E) !important}
.xrv-page-link .xrv-ic{width:13px;height:13px;transition:transform .15s ease}
.xrv-page-link:hover{text-decoration:none !important}
.xrv-page-link:hover .xrv-ic{transform:translateX(3px)}
.xrv-empty{text-align:center;padding:50px 20px;color:var(--xrv-muted,#5a6573)}
.xrv-empty h3{color:var(--xrv-text,#1a2332) !important;font-size:18px !important;margin-bottom:6px !important;text-align:center}
@media (max-width:880px){
.xrv-grid{column-width:auto !important;column-count:2 !important}
.xrv-ft{flex-basis:100%}
}
@media (max-width:560px){
.xrv-grid{column-count:1 !important}
.xrv-bar{flex-direction:column;align-items:stretch}
.xrv-search{flex:0 0 auto}
.xrv-ctrls{flex-direction:column;align-items:stretch;gap:10px}
.xrv-fselwrap label,.xrv-sortwrap label{flex:0 0 78px}
.xrv-fselwrap,.xrv-sortwrap{justify-content:space-between}
.xrv-fselwrap select,.xrv-sortwrap select{max-width:none;flex:1 1 auto;margin-left:10px}
}
/* Section heading (library/carousel layout): centered, matches the page's existing section titles. */
.xrv-section-title{font-size:32px !important;line-height:1.2 !important;color:var(--xrv-primary,#013C60) !important;text-align:center !important;font-weight:700 !important;margin:0 0 22px !important}
.xrv--library .xrv-tool{margin-top:6px}
.xrv--library .xrv-carousel{margin-bottom:46px}
.xrv--library .xrv-section-title + .xrv-tool,.xrv--library .xrv-carousel + .xrv-section-title{margin-top:30px}
/* Aligned column grid (fixed columns) instead of masonry: rows line up like a feed. Column count comes
   from the --xrv-cols custom property so the media queries below can step it down on tablet/mobile. */
.xrv-grid--cols{display:grid;gap:30px 24px;grid-template-columns:repeat(var(--xrv-cols,3),minmax(0,1fr))}
.xrv-grid--cols .xrv-card{display:block;width:auto;margin:0;break-inside:auto}
@media (max-width:880px){.xrv-grid--cols{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.xrv-grid--cols{grid-template-columns:1fr}}
/* Featured carousel */
.xrv-carousel{position:relative;padding:0 8px}
.xrv-caro-viewport{overflow:hidden}
.xrv-caro-track{display:flex;flex-wrap:nowrap;transition:transform .4s ease;will-change:transform}
.xrv-caro-track .xrv-card{flex:0 0 33.3333%;max-width:33.3333%;box-sizing:border-box;padding:0 12px;margin:0}
.xrv-carousel .xrv-cap{text-align:center}
.xrv-carousel .xrv-title{text-align:center}
.xrv-carousel .xrv-tags,.xrv-carousel .xrv-page-link{display:none}
.xrv--align-center .xrv-cap,.xrv--align-center .xrv-title{text-align:center}
.xrv--align-center .xrv-tags{justify-content:center}
.xrv--align-left .xrv-carousel .xrv-cap,.xrv--align-left .xrv-carousel .xrv-title{text-align:left}
.xrv-caro-arrow{position:absolute;top:calc(50% - 38px);transform:translateY(-50%);z-index:5;width:42px;height:42px;border-radius:50%;border:1px solid #d4dae2;background:#fff;color:var(--xrv-primary,#013C60);font-size:24px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 8px rgba(1,60,96,.12)}
.xrv-caro-arrow:hover{background:var(--xrv-primary,#013C60);color:#fff;border-color:var(--xrv-primary,#013C60)}
.xrv-caro-arrow:disabled{opacity:.35;cursor:default;background:#fff;color:var(--xrv-primary,#013C60);border-color:#d4dae2}
.xrv-caro-arrow:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-caro-prev{left:-10px}
.xrv-caro-next{right:-10px}
.xrv-caro-dots{display:flex;justify-content:center;gap:9px;margin-top:22px}
.xrv-caro-dot{width:11px;height:11px;border-radius:50%;border:none;padding:0;background:#cfd6df;cursor:pointer}
.xrv-caro-dot.is-active{background:var(--xrv-primary,#013C60)}
.xrv-caro-dot:focus-visible{outline:2px solid var(--xrv-accent,#019AB3);outline-offset:2px}
@media (max-width:880px){.xrv-caro-track .xrv-card{flex-basis:50%;max-width:50%}}
/* Mobile: drop the JS arrow/dot carousel for a native, thumb-swipeable scroll-snap strip (peeks the next
   card). transform:none overrides the JS translateX so native scroll takes over; arrows/dots hide. */
@media (max-width:560px){
	.xrv-carousel{padding:0}
	.xrv-caro-viewport{overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}
	.xrv-caro-viewport::-webkit-scrollbar{display:none}
	.xrv-caro-track{transform:none !important}
	.xrv-caro-track .xrv-card{flex:0 0 84%;max-width:84%;scroll-snap-align:center}
	.xrv-caro-arrow,.xrv-caro-dots{display:none}
	.xrv-section-title{font-size:26px !important}
}
/* Load more + subscribe row (under the browse grid) */
.xrv-more{display:flex;justify-content:center;align-items:center;gap:14px;flex-wrap:wrap;margin-top:36px}
.xrv-loadmore{background:var(--xrv-loadmore-bg,var(--xrv-text,#1a2332));color:var(--xrv-loadmore-color,#fff);border:none;font-family:inherit;font-size:14px;font-weight:var(--xrv-button-weight,600);letter-spacing:.01em;padding:13px 28px;border-radius:var(--xrv-loadmore-radius,5px);cursor:pointer;transition:background .15s ease}
.xrv-loadmore:hover{background:var(--xrv-loadmore-hover-bg,var(--xrv-primary,#013C60))}
.xrv-loadmore:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-subscribe{display:inline-flex;align-items:center;gap:9px;background:var(--xrv-subscribe,#00AA77);color:#fff !important;text-decoration:none !important;font-family:inherit;font-size:14px;font-weight:var(--xrv-button-weight,600);padding:12px 22px;border-radius:5px;transition:background .15s ease}
.xrv-subscribe:hover{background:var(--xrv-action,#007A53);text-decoration:none !important}
.xrv-subscribe:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-yt{width:22px;height:22px;flex:0 0 auto;vertical-align:-5px}
.xrv--yt-mono .xrv-yt{--xrv-yt-body:currentColor;--xrv-yt-play:transparent;--xrv-yt-rule:evenodd}
/* Lightbox modal. UNSCOPED on purpose: the overlay is appended to <body>, outside the .xrv wrapper. */
.xrv-modal{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:56px 24px 24px;font-family:var(--xrv-font, 'Gotham',Helvetica,Arial,sans-serif)}
.xrv-modal[hidden]{display:none}
.xrv-modal__backdrop{position:absolute;inset:0;background:rgba(8,16,28,.85)}
.xrv-modal__dialog{position:relative;width:100%;max-width:min(1100px,calc((100vh - 80px) * 16 / 9));max-width:min(1100px,calc((100dvh - 80px) * 16 / 9));display:flex;flex-direction:column;max-height:calc(100vh - 80px);max-height:calc(100dvh - 80px)}
.xrv-modal--has-caption .xrv-modal__dialog{max-width:min(1100px,max(320px,calc((100vh - 216px) * 16 / 9)));max-width:min(1100px,max(320px,calc((100dvh - 216px) * 16 / 9)))}
.xrv-modal__frame{position:relative;width:100%;flex:none;aspect-ratio:16/9;background:#000;border-radius:8px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.55)}
.xrv-modal--has-caption .xrv-modal__dialog{filter:drop-shadow(0 24px 70px rgba(0,0,0,.55))}
.xrv-modal--has-caption .xrv-modal__frame{border-radius:8px 8px 0 0;box-shadow:none}
.xrv-modal__caption{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;background:#fff;border-radius:0 0 8px 8px;padding:18px 22px 20px;color:#3c4a57}
.xrv-modal__head{border-bottom:1px solid #e6e6eb;padding-bottom:13px;margin-bottom:14px}
.xrv-modal__title{margin:0 0 4px;font-size:20px;line-height:1.3;font-weight:700;color:var(--xrv-primary,#16263a)}
.xrv-modal__meta{font-size:13px;color:#5a6b7b}
.xrv-modal__desc{font-size:14px;line-height:1.55;color:#3c4a57;white-space:pre-line;overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:4;overflow:hidden}
.xrv-modal__desc.is-open,.xrv-modal__desc--full{-webkit-line-clamp:unset;display:block;overflow:visible}
.xrv-modal__page{display:inline-block;margin-top:7px;font-size:13px;font-weight:600;color:var(--xrv-link,#017A8E);text-decoration:underline;text-underline-offset:2px}
.xrv-modal__page:focus-visible{outline:2px solid var(--xrv-accent,#019AB3);outline-offset:2px}
.xrv-modal__more{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;margin-top:12px;padding:8px 12px;font-family:inherit;font-size:13px;font-weight:600;color:var(--xrv-primary,#16263a);background:#f3f4f5;border:0;border-radius:8px;cursor:pointer}
.xrv-modal__more:hover{background:#eceef0}
.xrv-modal__more:focus-visible{outline:2px solid var(--xrv-accent,#019AB3);outline-offset:2px}
.xrv-modal__more svg{width:14px;height:14px;transition:transform .2s}
.xrv-modal__more.is-open svg{transform:rotate(180deg)}
.xrv-modal__frame .xrv-iframe,.xrv-modal__frame .xrv-video{position:absolute;inset:0;width:100%;height:100%;aspect-ratio:auto;border-radius:8px}
.xrv-modal--short .xrv-modal__dialog{max-width:none;width:auto;display:flex;flex-direction:row;align-items:center;justify-content:center}
.xrv-modal--short .xrv-modal__frame{aspect-ratio:9/16;width:auto;height:min(calc(100vh - 80px),760px);height:min(calc(100dvh - 80px),760px);max-width:94vw}
.xrv-modal__frame iframe,.xrv-modal__frame video{position:absolute;inset:0;width:100%;height:100%;border:0;background:#000}
.xrv-modal__close{position:absolute;top:-46px;right:0;width:38px;height:38px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.55);color:#fff;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;padding:0}
.xrv-modal__close:hover{background:rgba(255,255,255,.28)}
.xrv-modal__close:focus-visible{outline:3px solid var(--xrv-accent,#019AB3);outline-offset:2px}
body.xrv-modal-open{overflow:hidden}
@media (max-width:600px){.xrv-modal{padding:52px 14px 14px}.xrv-modal__close{top:-42px}.xrv-modal__caption{padding:14px 16px 16px}.xrv-modal__title{font-size:18px;line-height:1.25}}
/* Landscape phones (844x390): the player takes the full height and the caption becomes a scrollable
   column beside it; the close button moves to the right-hand margin. */
@media (orientation:landscape) and (max-height:520px){
	.xrv-modal{padding:12px 60px 12px 12px}
	.xrv-modal__dialog,.xrv-modal--has-caption .xrv-modal__dialog{max-width:min(calc(100vw - 72px),calc((100vh - 24px) * 16 / 9));max-width:min(calc(100vw - 72px),calc((100dvh - 24px) * 16 / 9));max-height:calc(100vh - 24px);max-height:calc(100dvh - 24px)}
	.xrv-modal--has-caption .xrv-modal__dialog{flex-direction:row;max-width:calc(100vw - 72px)}
	.xrv-modal--has-caption .xrv-modal__frame{width:min(calc(100vw - 292px),calc((100vh - 24px) * 16 / 9));width:min(calc(100vw - 292px),calc((100dvh - 24px) * 16 / 9));border-radius:8px 0 0 8px;align-self:center}
	.xrv-modal--has-caption .xrv-modal__caption{flex:1 1 0;min-width:0;border-radius:0 8px 8px 0;padding:12px 14px}
	.xrv-modal__close{top:0;right:-48px}
	.xrv-modal__title{font-size:16px}
}
/* 2.11.0 hover styles. Zoom is the base rules above. Dim and None neutralise them on every device (so a
   touch tap never leaves a zoomed card behind); Dim's overlay fades in on hover for hover-capable pointers
   and on keyboard focus everywhere. The play colour stays the idle colour for both. */
.xrv--hover-dim .xrv-facade::before{content:"";position:absolute;inset:0;z-index:1;background:rgba(0,0,0,.6);opacity:0;transition:opacity .2s ease;pointer-events:none}
.xrv--hover-dim .xrv-play,.xrv--hover-dim .xrv-dur{z-index:2}
.xrv--hover-dim .xrv-play{transition:opacity .2s ease}
.xrv--hover-dim .xrv-facade:hover .xrv-thumb,.xrv--hover-none .xrv-facade:hover .xrv-thumb{transform:none;opacity:1}
.xrv--hover-dim .xrv-facade:hover .xrv-play,.xrv--hover-none .xrv-facade:hover .xrv-play{transform:translate(-50%,-50%)}
.xrv--hover-dim .xrv-facade:hover .xrv-play__bg,.xrv--hover-none .xrv-facade:hover .xrv-play__bg,.xrv--hover-dim .xrv-facade:focus-visible .xrv-play__bg,.xrv--hover-none .xrv-facade:focus-visible .xrv-play__bg{fill:var(--xrv-play,var(--xrv-primary,#013C60));fill-opacity:.92}
@media (hover:hover){
	.xrv--hover-dim .xrv-facade:hover::before{opacity:1}
	.xrv--hover-dim .xrv-facade:hover .xrv-play{opacity:.5}
}
.xrv--hover-dim .xrv-facade:focus-visible::before{opacity:1}
.xrv--hover-dim .xrv-facade:focus-visible .xrv-play{opacity:.5}
.xrv--nodur .xrv-dur{display:none}
@media (prefers-reduced-motion:reduce){
	.xrv-thumb,.xrv-play,.xrv-play__bg,.xrv-facade::before,.xrv-caro-track,.xrv-page-link .xrv-ic,.xrv-modal__more svg,.xrv-chip,.xrv-loadmore,.xrv-subscribe{transition:none !important}
	.xrv-facade:hover .xrv-thumb{transform:none !important}
	.xrv-facade:hover .xrv-play{transform:translate(-50%,-50%) !important}
}
/* Shorts shelf: shorts="only" lays the verticals out as a horizontal, thumb-swipeable scroll-snap strip. */
.xrv-grid--shelf{display:flex;gap:16px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;padding-bottom:12px;scrollbar-width:thin}
.xrv-grid--shelf::-webkit-scrollbar{height:8px}
.xrv-grid--shelf::-webkit-scrollbar-thumb{background:rgba(0,0,0,.22);border-radius:8px}
.xrv-grid--shelf .xrv-card,.xrv-grid--shelf .xrv-card[data-short]{flex:0 0 auto;width:230px;max-width:72vw;margin:0;scroll-snap-align:start;display:block}
</style>
CSS;
}

function xrv_inline_js() {
	return <<<'JS'
(function(){
	/* ---- Shared lightbox modal (one per page, lazy, appended to <body>) ---- */
	function xrvGetModal(){
		var m = document.getElementById('xrv-modal');
		if(m) return m;
		m = document.createElement('div');
		m.id = 'xrv-modal';
		m.className = 'xrv-modal';
		m.setAttribute('hidden', '');
		m.setAttribute('role', 'dialog');
		m.setAttribute('aria-modal', 'true');
		m.setAttribute('aria-label', 'Video player');
		m.innerHTML = '<div class="xrv-modal__backdrop" data-xrv-close></div>'
			+ '<div class="xrv-modal__dialog"><button type="button" class="xrv-modal__close" data-xrv-close aria-label="Close video">✕</button>'
			+ '<div class="xrv-modal__frame"></div></div>';
		document.body.appendChild(m);
		m.addEventListener('click', function(e){ if(e.target && e.target.hasAttribute && e.target.hasAttribute('data-xrv-close')) xrvCloseModal(); });
		return m;
	}
	var xrvLastFocus = null;
	var xrvPreconnected = {}; // host => 1: one preconnect per host per page (opt-in, see initRoot)
	// The optional details panel under the player: title + relative date + an optional "Open video page" link
	// + the description, either in full or collapsed to four lines behind Show more / Show less. Built from
	// the card's data-* attributes, text-only; the labels come from the gallery root (translatable).
	function xrvBuildCaption(info){
		var t = info.i18n || {};
		var cap = document.createElement('div'); cap.className = 'xrv-modal__caption';
		var head = document.createElement('div'); head.className = 'xrv-modal__head';
		var h = document.createElement('h2'); h.className = 'xrv-modal__title'; h.textContent = info.title || ''; head.appendChild(h);
		if(info.when){ var mt = document.createElement('div'); mt.className = 'xrv-modal__meta'; mt.textContent = info.when; head.appendChild(mt); }
		if(info.pageUrl){ var pl = document.createElement('a'); pl.className = 'xrv-modal__page'; pl.href = info.pageUrl; pl.textContent = t.page || 'Open video page'; head.appendChild(pl); }
		cap.appendChild(head);
		if(info.desc){
			var full = (info.descMode === 'full');
			var d = document.createElement('div'); d.className = 'xrv-modal__desc' + (full ? ' xrv-modal__desc--full' : ''); d.textContent = info.desc; cap.appendChild(d);
			if(!full){
				var more = t.more || 'Show more', less = t.less || 'Show less';
				var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'xrv-modal__more'; btn.setAttribute('aria-expanded', 'false');
				btn.innerHTML = '<span></span><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
				var lbl = btn.querySelector('span'); lbl.textContent = more;
				btn.addEventListener('click', function(){ var ex = d.classList.toggle('is-open'); btn.classList.toggle('is-open', ex); btn.setAttribute('aria-expanded', ex ? 'true' : 'false'); lbl.textContent = ex ? less : more; });
				cap.appendChild(btn);
			}
		}
		return cap;
	}
	function xrvOpenModal(node, info){
		info = info || {};
		var title = info.title || '', isShort = !!info.isShort;
		var m = xrvGetModal();
		m.classList.toggle('xrv-modal--short', isShort);
		var dlg = m.querySelector('.xrv-modal__dialog');
		var frame = m.querySelector('.xrv-modal__frame');
		frame.innerHTML = '';
		frame.appendChild(node);
		var oldCap = dlg.querySelector('.xrv-modal__caption'); if(oldCap){ oldCap.parentNode.removeChild(oldCap); }
		// Shorts stay player-only (a 9:16 tower + caption would overflow); the caption is a desktop/landscape device.
		var hasCap = !!(info.details && !isShort && (title || info.desc || info.when));
		if(hasCap){ dlg.appendChild(xrvBuildCaption(info)); }
		m.classList.toggle('xrv-modal--has-caption', hasCap);
		m.setAttribute('aria-label', title || 'Video player');
		// The close button's accessible name is set on EVERY open (galleries on one page may differ in language).
		var cl = m.querySelector('.xrv-modal__close'); if(cl){ cl.setAttribute('aria-label', (info.i18n && info.i18n.close) || 'Close video'); }
		xrvLastFocus = document.activeElement;
		m.removeAttribute('hidden');
		document.body.classList.add('xrv-modal-open');
		// Drop the Show more toggle when the text already fits (nothing to expand), measured post-layout.
		if(hasCap){ var dd = dlg.querySelector('.xrv-modal__desc'), more = dlg.querySelector('.xrv-modal__more'); if(dd && more && dd.scrollHeight <= dd.clientHeight + 1){ more.style.display = 'none'; } }
		var c = m.querySelector('.xrv-modal__close'); if(c) c.focus();
	}
	function xrvCloseModal(){
		var m = document.getElementById('xrv-modal');
		if(!m || m.hasAttribute('hidden')) return;
		m.setAttribute('hidden', '');
		m.classList.remove('xrv-modal--has-caption');
		document.body.classList.remove('xrv-modal-open');
		var frame = m.querySelector('.xrv-modal__frame'); if(frame) frame.innerHTML = ''; // stop playback
		var cap = m.querySelector('.xrv-modal__caption'); if(cap){ cap.parentNode.removeChild(cap); }
		if(xrvLastFocus && xrvLastFocus.focus){ xrvLastFocus.focus(); }
	}
	document.addEventListener('keydown', function(e){ if(e.key === 'Escape' || e.keyCode === 27) xrvCloseModal(); });

	function xrvEmbedSrc(provider, id, hash, isShort){
		switch(provider){
			case 'vimeo':
				return 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?autoplay=1&dnt=1&title=0&byline=0&portrait=0' + (hash ? '&h=' + encodeURIComponent(hash) : '');
			case 'wistia':
				return 'https://fast.wistia.net/embed/iframe/' + encodeURIComponent(id) + '?autoPlay=true';
			case 'loom':
				return 'https://www.loom.com/embed/' + encodeURIComponent(id) + '?autoplay=1';
			case 'dailymotion':
				return 'https://www.dailymotion.com/embed/video/' + encodeURIComponent(id) + '?autoplay=1';
			case 'tiktok':
				return 'https://www.tiktok.com/player/v1/' + encodeURIComponent(id) + '?autoplay=1&controls=1&description=0&music_info=0';
			default:
				return 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1&playsinline=1' + (isShort ? '&loop=1&playlist=' + encodeURIComponent(id) : '');
		}
	}
	// Build the element injected on click: a <video> for self-hosted files, an <iframe> for every host.
	function xrvEmbedNode(provider, id, hash, title, isShort){
		if(provider === 'file'){
			var v = document.createElement('video');
			v.className = 'xrv-video';
			v.src = id;
			v.controls = true; v.autoplay = true; v.setAttribute('playsinline', ''); v.setAttribute('preload', 'metadata');
			v.title = title || 'Video player';
			return v;
		}
		var iframe = document.createElement('iframe');
		iframe.className = 'xrv-iframe';
		iframe.setAttribute('allowfullscreen', '');
		iframe.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
		iframe.title = title || 'Video player';
		iframe.src = xrvEmbedSrc(provider, id, hash, isShort);
		return iframe;
	}

	function initRoot(ROOT){
		if(!ROOT || ROOT.dataset.xrvReady) return;
		ROOT.dataset.xrvReady = '1';

		var playbackMode = ROOT.getAttribute('data-playback') || 'lightbox';
		// Device-scoped playback ('lightbox-desktop' / 'lightbox-mobile'): the modal is reserved for one device
		// class and the other plays inline. The split is resolved here, in the browser, at click time — never
		// server-side UA sniffing — so the rendered HTML is identical for every visitor and stays cacheable.
		// Re-checked per click, so a rotate/resize lands the visitor in the right mode without a reload.
		var XRV_DESKTOP_MIN = 768; // px: at/above is "desktop", below is "mobile"
		function effectivePlayback(){
			if(playbackMode === 'lightbox-desktop') return (window.innerWidth >= XRV_DESKTOP_MIN) ? 'lightbox' : 'inline';
			if(playbackMode === 'lightbox-mobile')  return (window.innerWidth >= XRV_DESKTOP_MIN) ? 'inline' : 'lightbox';
			return (playbackMode === 'inline') ? 'inline' : 'lightbox';
		}
		var lbDetails = ROOT.getAttribute('data-lb-details') !== '0'; // show title/date/description inside the lightbox
		var lbDesc = ROOT.getAttribute('data-lb-desc') === 'full' ? 'full' : 'collapsed'; // 2.11.0
		var lbLink = ROOT.getAttribute('data-lb-link') === '1';                           // 2.11.0: "Open video page"
		var i18n = { more: ROOT.getAttribute('data-i18n-more'), less: ROOT.getAttribute('data-i18n-less'), page: ROOT.getAttribute('data-i18n-page'), close: ROOT.getAttribute('data-i18n-close') };

		// ---- Informed-consent notice (consent_notice = off | strict | geo) ----
		// The facade still makes ZERO third-party requests until a click. This only governs whether an
		// informed notice precedes that click, and (for geo) whether it shows based on the visitor's region.
		var consentMode = ROOT.getAttribute('data-consent') || 'off';
		var consentText = ROOT.getAttribute('data-consent-text') || '';
		var consentBtnLabel = ROOT.getAttribute('data-consent-btn') || 'Load video';
		var consentDeclineLabel = ROOT.getAttribute('data-consent-decline') || 'No thanks';
		var privacyUrl = ROOT.getAttribute('data-privacy') || '';
		function consentRequiredNow(){
			if(consentMode === 'off') return false;
			if(consentMode === 'strict') return true;
			return ROOT.dataset.consentRequired !== '0'; // geo: unknown/'1' => required (fail-safe)
		}
		if(consentMode === 'geo'){
			var rurl = ROOT.getAttribute('data-region-url'), cached = null;
			try { cached = sessionStorage.getItem('xrvConsentRequired'); } catch(e){}
			if(cached !== null){ ROOT.dataset.consentRequired = cached; }
			else if(rurl){
				ROOT.dataset.consentRequired = '1';
				fetch(rurl, {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
					var v = (d && d.consent_required) ? '1' : '0';
					try { sessionStorage.setItem('xrvConsentRequired', v); } catch(e){}
					ROOT.dataset.consentRequired = v;
				}).catch(function(){});
			}
		}
		function closeOverlay(card){ var ov = card.querySelector('.xrv-consent'); if(ov && ov.parentNode){ ov.parentNode.removeChild(ov); } }
		function showConsentOverlay(card){
			var frame = card.querySelector('.xrv-frame'); if(!frame || frame.querySelector('.xrv-consent')) return;
			var ov = document.createElement('div'); ov.className = 'xrv-consent';
			var x = document.createElement('button'); x.type = 'button'; x.className = 'xrv-consent-x'; x.setAttribute('aria-label', 'Decline and close'); x.textContent = '×';
			var pv = card.getAttribute('data-provider') || 'youtube';
			var hostName = ({ youtube:'YouTube', vimeo:'Vimeo', wistia:'Wistia', loom:'Loom', dailymotion:'Dailymotion', file:'this site' })[pv] || 'a third party';
			var msg = document.createElement('p'); msg.className = 'xrv-consent-msg'; msg.textContent = consentText || ('This video is hosted by ' + hostName + ' and may set cookies.');
			var actions = document.createElement('div'); actions.className = 'xrv-consent-actions';
			var go = document.createElement('button'); go.type = 'button'; go.className = 'xrv-consent-go'; go.textContent = consentBtnLabel;
			var no = document.createElement('button'); no.type = 'button'; no.className = 'xrv-consent-decline'; no.textContent = consentDeclineLabel;
			actions.appendChild(go); actions.appendChild(no);
			ov.appendChild(x); ov.appendChild(msg); ov.appendChild(actions);
			if(privacyUrl){ var a = document.createElement('a'); a.className = 'xrv-consent-link'; a.href = privacyUrl; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Privacy policy'; ov.appendChild(a); }
			frame.appendChild(ov);
		}

		function playCard(card, btn){
			var id = card.getAttribute('data-vid'); if(!id) return;
			var provider = card.getAttribute('data-provider') || 'youtube';
			var hash = card.getAttribute('data-hash') || '';
			var titleEl = card.querySelector('.xrv-title');
			var title = titleEl ? titleEl.textContent.trim() : 'Video player';
			var short = !!card.dataset.short;
			var titleLink = card.querySelector('.xrv-title-link');
			var info = { title:title, isShort:short, when:(card.getAttribute('data-when') || ''), desc:(card.getAttribute('data-desc') || ''), details:lbDetails, descMode:lbDesc, pageUrl:((lbLink && titleLink) ? titleLink.href : ''), i18n:i18n };
			var node = xrvEmbedNode(provider, id, hash, title, short);
			if(effectivePlayback() === 'inline'){
				var frame = (btn && btn.closest) ? (btn.closest('.xrv-frame') || btn.parentNode) : card.querySelector('.xrv-frame');
				if(btn && btn.replaceWith){ btn.replaceWith(node); } else if(frame){ frame.appendChild(node); }
				if(frame && frame.style){ frame.style.lineHeight = '0'; }
			} else {
				xrvOpenModal(node, info); /* shorts pop in a 9:16 portrait modal (no caption) */
			}
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push({ event:'video_play', video_provider:provider, video_id:id, video_title:title, video_series:(card.getAttribute('data-series') || '').split(' ')[0] });
		}

		// THE FACADE. Delegated on the whole root (covers grid AND carousel). Until a click fires, the page
		// has made ZERO requests to any Google domain. With strict/geo consent, the first click shows an
		// informed overlay; the user's confirmation is the consent that loads the embed.
		ROOT.addEventListener('click', function(e){
			// Decline: the × or "No thanks" closes the prompt and loads NOTHING (refusing must be as easy as accepting).
			var no = e.target && e.target.closest ? e.target.closest('.xrv-consent-decline, .xrv-consent-x') : null;
			if(no){ var nc = no.closest('.xrv-card'); if(nc){ closeOverlay(nc); } return; }
			var go = e.target && e.target.closest ? e.target.closest('.xrv-consent-go') : null;
			if(go){
				var gc = go.closest('.xrv-card'); if(!gc) return;
				gc.dataset.xrvConsented = '1';
				closeOverlay(gc);
				playCard(gc, gc.querySelector('.xrv-facade'));
				return;
			}
			var btn = e.target && e.target.closest ? e.target.closest('.xrv-facade') : null;
			if(!btn) return;
			// thumb_link = watch: the poster is a real link. A plain primary click plays (the navigation is
			// cancelled here, before the consent check); Ctrl/Cmd/Shift/Alt-click and the middle button
			// keep the browser's own link behaviour (new tab, new window).
			if(btn.tagName === 'A'){
				if(e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
				e.preventDefault();
			}
			var card = btn.closest('.xrv-card'); if(!card) return;
			if(consentRequiredNow() && card.dataset.xrvConsented !== '1'){ showConsentOverlay(card); return; }
			playCard(card, btn);
		});
		// Space plays a linked poster too (a link only answers Enter natively); Enter fires a plain click.
		ROOT.addEventListener('keydown', function(e){
			if(e.key !== ' ' && e.key !== 'Spacebar') return;
			var a = e.target && e.target.closest ? e.target.closest('a.xrv-facade') : null;
			if(!a) return;
			e.preventDefault();
			a.click();
		});

		// Optional warm-up (2.11.0: OFF unless the root says data-preconnect="1"). A preconnect opens a DNS +
		// TLS connection to the video host, which is contact with that host before any click, so the hover /
		// focus listeners are not even bound by default, never under Strict consent, and the handler re-checks
		// the geo answer so a consent-gated visitor's browser makes ZERO contact until they accept. One <link>
		// per host per page, however many cards or galleries there are.
		if(ROOT.getAttribute('data-preconnect') === '1' && consentMode !== 'strict'){
			var preconnect = function(card){
				if(consentRequiredNow()) return;
				var provider = card.getAttribute('data-provider') || 'youtube';
				var hosts = { youtube:'https://www.youtube-nocookie.com', vimeo:'https://player.vimeo.com', wistia:'https://fast.wistia.net', loom:'https://www.loom.com', dailymotion:'https://www.dailymotion.com', tiktok:'https://www.tiktok.com' };
				var host = hosts[provider];
				if(!host || xrvPreconnected[host]) return; // self-hosted files: nothing third-party to warm up
				xrvPreconnected[host] = 1;
				var l = document.createElement('link'); l.rel = 'preconnect'; l.href = host;
				document.head.appendChild(l);
			};
			ROOT.addEventListener('mouseover', function(e){ var c = e.target && e.target.closest ? e.target.closest('.xrv-card') : null; if(c) preconnect(c); });
			ROOT.addEventListener('focusin', function(e){ var c = e.target && e.target.closest ? e.target.closest('.xrv-card') : null; if(c) preconnect(c); });
		}

		// ---- Featured carousel (paged, 3/2/1 per view, arrows + dots) ----
		// try/catch (2.11.0): an error here must never stop the browse-grid init below, which is what
		// reveals the cards the server printed with `hidden`.
		var caro = ROOT.querySelector('.xrv-carousel');
		if(caro){ try {
			var track = caro.querySelector('.xrv-caro-track');
			var ccards = track ? Array.prototype.slice.call(track.children) : [];
			var cprev = caro.querySelector('.xrv-caro-prev');
			var cnext = caro.querySelector('.xrv-caro-next');
			var dotsWrap = caro.querySelector('.xrv-caro-dots');
			var page = 0;
			var colsAt = function(){ var w = caro.clientWidth; if(w < 560) return 1; if(w < 880) return 2; return parseInt(caro.getAttribute('data-cols'),10) || 3; };
			var pageCount = function(){ return Math.max(1, Math.ceil(ccards.length / colsAt())); };
			var renderCaro = function(){
				if(page > pageCount()-1) page = pageCount()-1; if(page < 0) page = 0;
				if(track) track.style.transform = 'translateX(' + (-page * 100) + '%)';
				if(dotsWrap){
					dotsWrap.innerHTML = '';
					for(var i=0;i<pageCount();i++){ (function(i){ var d=document.createElement('button'); d.type='button'; d.className='xrv-caro-dot'+(i===page?' is-active':''); d.setAttribute('aria-label','Page '+(i+1)); d.addEventListener('click',function(){ page=i; renderCaro(); }); dotsWrap.appendChild(d); })(i); }
				}
				if(cprev) cprev.disabled = (page <= 0);
				if(cnext) cnext.disabled = (page >= pageCount()-1);
			};
			if(cprev) cprev.addEventListener('click', function(){ if(page>0){ page--; renderCaro(); } });
			if(cnext) cnext.addEventListener('click', function(){ if(page<pageCount()-1){ page++; renderCaro(); } });
			var crt; window.addEventListener('resize', function(){ clearTimeout(crt); crt = setTimeout(renderCaro, 150); });
			renderCaro();
		} catch(err){ if(window.console && console.error) console.error('XRV carousel', err); } }

		// ---- Browse grid: filter / search / sort (only when the controls + grid are present) ----
		var grid = ROOT.querySelector('.xrv-grid');
		if(grid){
			// The server prints cards past per_page with `hidden` (a no-JS / delayed-JS fallback); from here
			// the script owns visibility, so clear it before the first apply().
			Array.prototype.forEach.call(grid.querySelectorAll('.xrv-card[hidden]'), function(c){ c.removeAttribute('hidden'); });
			var cards = Array.prototype.slice.call(grid.querySelectorAll('.xrv-card'));
			var shownEl = ROOT.querySelector('.xrv-shown');
			var totalEl = ROOT.querySelector('.xrv-total');
			var emptyEl = ROOT.querySelector('.xrv-empty');
			var qEl = ROOT.querySelector('.xrv-q');
			var sortEl = ROOT.querySelector('.xrv-sort');
			var loadMoreBtn = ROOT.querySelector('.xrv-loadmore');
			var pageSize = parseInt(grid.getAttribute('data-perpage'), 10) || 9;
			var loadStep = parseInt(grid.getAttribute('data-loadstep'), 10) || 3;
			var visibleLimit = pageSize;
			var origOrder = cards.slice();
			// The gallery's own order (orderby) is the starting sort; the DOM already holds it exactly (with the
			// server's publish-time and ID tie-breaks), and Reset returns to it.
			var seed = ROOT.getAttribute('data-sort') || 'curated';
			var state = { q:'', series:[], audience:[], topic:[], sort:seed };
			var num = function(el, a){ return +(el.getAttribute(a) || 0); };

			var groupVals = function(card, g){ return (card.getAttribute('data-'+g) || '').split(' ').filter(Boolean); };
			var matchGroup = function(g, card){ if(state[g].length === 0) return true; var vals = groupVals(card, g); return state[g].some(function(v){ return vals.indexOf(v) > -1; }); };
			var matchSearch = function(card){ if(!state.q) return true; return (card.getAttribute('data-search') || '').indexOf(state.q) > -1; };
			// Ties break on the server position (data-pos), so equal dates keep the curated order instead of
			// whatever the browser's sort leaves; "curated" uses the curated rank (data-cpos).
			var cmp = function(a, b){
				var s = state.sort, r = 0;
				if(s==='newest') r = num(b,'data-date') - num(a,'data-date');
				else if(s==='oldest') r = num(a,'data-date') - num(b,'data-date');
				else if(s==='title') r = (a.getAttribute('data-title') || '').localeCompare(b.getAttribute('data-title') || '');
				else if(s==='short') r = num(a,'data-seconds') - num(b,'data-seconds');
				else if(s==='long') r = num(b,'data-seconds') - num(a,'data-seconds');
				else if(s==='curated') r = num(a, a.hasAttribute('data-cpos') ? 'data-cpos' : 'data-pos') - num(b, b.hasAttribute('data-cpos') ? 'data-cpos' : 'data-pos');
				return r || (num(a,'data-pos') - num(b,'data-pos'));
			};
			var apply = function(){
				var ordered = (state.sort === seed) ? origOrder.slice() : cards.slice().sort(cmp);
				ordered.forEach(function(c){ grid.appendChild(c); });
				var matched = 0, visible = 0;
				ordered.forEach(function(c){
					var ok = matchGroup('series', c) && matchGroup('audience', c) && matchGroup('topic', c) && matchSearch(c);
					if(ok){
						matched++;
						if(matched <= visibleLimit){ c.style.display = ''; visible++; }
						else { c.style.display = 'none'; }
					} else {
						c.style.display = 'none';
					}
				});
				if(shownEl) shownEl.textContent = visible;
				if(totalEl) totalEl.textContent = matched;
				if(emptyEl) emptyEl.style.display = matched ? 'none' : 'block';
				if(loadMoreBtn) loadMoreBtn.style.display = (matched > visibleLimit) ? '' : 'none';
			};
			var resetVisible = function(){ visibleLimit = pageSize; };

			if(qEl) qEl.addEventListener('input', function(){ state.q = this.value.trim().toLowerCase(); resetVisible(); apply(); });
			if(sortEl) sortEl.addEventListener('change', function(){ state.sort = this.value; resetVisible(); apply(); });
			ROOT.querySelectorAll('.xrv-chip').forEach(function(chip){
				chip.addEventListener('click', function(){
					this.setAttribute('aria-pressed', this.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
					var g = this.getAttribute('data-group');
					state[g] = Array.prototype.slice.call(ROOT.querySelectorAll('.xrv-chip[data-group="' + g + '"][aria-pressed="true"]')).map(function(x){ return x.value; });
					resetVisible(); apply();
				});
			});
			ROOT.querySelectorAll('.xrv-fsel').forEach(function(sel){
				sel.addEventListener('change', function(){
					var g = this.getAttribute('data-group');
					state[g] = this.value ? [this.value] : [];
					this.setAttribute('data-active', this.value ? '1' : '0');
					resetVisible(); apply();
				});
			});
			if(loadMoreBtn) loadMoreBtn.addEventListener('click', function(){ visibleLimit += loadStep; apply(); });
			var resetBtn = ROOT.querySelector('.xrv-reset');
			if(resetBtn) resetBtn.addEventListener('click', function(){
				state = { q:'', series:[], audience:[], topic:[], sort:seed };
				if(qEl) qEl.value = '';
				if(sortEl) sortEl.value = seed;
				ROOT.querySelectorAll('.xrv-chip').forEach(function(x){ x.setAttribute('aria-pressed','false'); });
				ROOT.querySelectorAll('.xrv-fsel').forEach(function(x){ x.value=''; x.setAttribute('data-active','0'); });
				resetVisible(); apply();
			});
			apply();
		}
	}

	function init(){ Array.prototype.forEach.call(document.querySelectorAll('.xrv'), initRoot); }
	if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
JS;
}

/* =================================================================================================
 * 8. SHORTCODE + BLOCK REGISTRATION  (collision-proof xroad namespace)
 *    [xroad-videos] never clashes with the prior social-feed plugin's shortcodes. The block points at the
 *    same render callback (PHP-only dynamic block, no JS build step), so editor preview and front end
 *    share one code path.
 * ================================================================================================= */

add_shortcode( 'xroad-videos', 'xrv_render' );

/* Single-video embed: [xroad-video id="123"] drops ONE video inline (same privacy-first facade +
 * VideoObject schema as its dedicated page) by reusing xrv_render_single() — for editorial placements
 * like /about/ that sit a specific video in the page flow. Provider-agnostic via the 2.0 sources. */
add_shortcode( 'xroad-video', 'xrv_shortcode_single' );
function xrv_shortcode_single( $atts ) {
	$a  = shortcode_atts( array( 'id' => 0, 'url' => '', 'poster' => '', 'title' => '', 'playback' => 'inline' ), $atts, 'xroad-video' );
	$id = (int) $a['id'];
	// A published CPT video id renders the curated (schema-rich) single embed.
	if ( $id && 'xroad_video' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
		return xrv_render_single( $id, $a['playback'] ); // playback="lightbox" pops a modal; default plays in place
	}
	// Otherwise a pasted url renders an ad-hoc facade for ANY supported host, no CPT entry needed.
	if ( '' !== trim( (string) $a['url'] ) ) {
		return xrv_render_single_url( trim( (string) $a['url'] ), $a['playback'], (string) $a['poster'], (string) $a['title'] );
	}
	// Neither given: a visible hint for editors (the singular tag is easy to confuse with the gallery).
	return current_user_can( 'edit_posts' )
		? '<span style="display:inline-block;padding:8px 12px;border:1px dashed #f3d199;background:#fff8ef;border-radius:6px;font-size:13px;color:#7a4f00"><code>[xroad-video]</code> needs a published video <code>id</code> (<code>[xroad-video id="123"]</code>) or a <code>url</code> (<code>[xroad-video url="https://youtu.be/&hellip;"]</code>). For a gallery, use <code>[xroad-videos]</code> (plural).</span>'
		: '';
}

add_action( 'init', 'xrv_register_block' );
function xrv_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	// No-build editor UI: a dependency-only handle (false src) carries the inline registerBlockType call,
	// loaded in the editor as the block's editor_script (mirrors the xrv-admin inline pattern).
	wp_register_script( 'xrv-block', false, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), XRV_VERSION, true );
	wp_add_inline_script( 'xrv-block', xrv_block_editor_js() );
	$str = array( 'type' => 'string' );
	register_block_type( 'xroad/videos', array(
		'render_callback' => function( $attributes ) { return xrv_render( (array) $attributes ); },
		'editor_script'   => 'xrv-block',
		'attributes'      => array(
			'layout' => $str, 'columns' => $str, 'playback' => $str, 'lightbox_details' => $str, 'controls' => $str, 'filter_ui' => $str, 'card_meta' => $str, 'shorts' => $str,
			'per_page' => $str, 'load_more' => $str, 'featured_limit' => $str, 'heading' => $str,
			'subscribe_url' => $str, 'subscribe_label' => $str, 'consent_notice' => $str, 'consent_text' => $str,
			'consent_button' => $str, 'consent_decline' => $str, 'privacy_url' => $str, 'series' => $str, 'audience' => $str, 'topic' => $str, 'limit' => $str,
			'collection' => $str, 'ids' => $str, // 2.9.0: render a saved Collection by slug, or an explicit ordered id list
			// 2.11.0 display options ('' = site default; booleans are 'true' / 'false').
			'orderby' => $str, 'hover_style' => $str, 'card_align' => $str, 'card_date' => $str, 'desc_chars' => $str,
			'show_duration' => $str, 'subscribe_icon' => $str, 'lightbox_desc' => $str, 'lightbox_page_link' => $str,
			'thumb_link' => $str, 'preconnect' => $str,
		),
	) );
}
/* Inline Gutenberg editor UI (vanilla wp.* — no JSX/build). Empty values inherit the site Settings defaults. */
function xrv_block_editor_js() {
	return <<<'JS'
( function( blocks, element, blockEditor, components ){
	if(!blocks || !element || !blockEditor || !components) return;
	var el = element.createElement, Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody, SelectControl = components.SelectControl, TextControl = components.TextControl, RangeControl = components.RangeControl, ToggleControl = components.ToggleControl;
	blocks.registerBlockType('xroad/videos', {
		apiVersion: 2,
		title: 'XRV Video',
		description: 'Privacy-first YouTube gallery (click-to-load facade).',
		icon: 'video-alt3',
		category: 'media',
		example: {},
		edit: function(props){
			var a = props.attributes, set = props.setAttributes;
			var f = function(k){ return function(v){ var o={}; o[k]=v; set(o); }; };
			// 0 on a range control means "site default": store '' (2.10.0 stored '0', which rendered one card per page).
			var num = function(k){ return function(v){ var o={}; o[k] = (v && v > 0) ? String(v) : ''; set(o); }; };
			// Every 2.11.0 control is a select whose first option, '', inherits the site setting.
			var DEF = {label:'Site default', value:''};
			var pick = function(k, label, opts, help){ return el(SelectControl, { label:label, help:help, value:a[k]||'', options:[DEF].concat(opts), onChange:f(k) }); };
			var onOff = function(k, label, onLabel, offLabel, help){ return pick(k, label, [ {label:onLabel, value:'true'}, {label:offLabel, value:'false'} ], help); };
			var controlsOn = (a.controls !== 'false');
			return el(Fragment, {},
				el(InspectorControls, {},
					el(PanelBody, { title:'Collection (optional)', initialOpen:false },
						el(TextControl, { label:'Collection slug', help:'Render a saved Collection (XRV → Collections) by its slug. When set it supplies the base layout and videos; the fields below still override it.', value:a.collection||'', onChange:f('collection') })
					),
					el(PanelBody, { title:'Layout', initialOpen:true },
						el(SelectControl, { label:'Layout', value:a.layout||'grid', options:[
							{label:'Grid', value:'grid'}, {label:'Library (featured + grid)', value:'library'}, {label:'Carousel (featured row)', value:'carousel'} ], onChange:f('layout') }),
						el(SelectControl, { label:'Playback', value:a.playback||'', help:'Where the video plays on click. The desktop/mobile split is decided in the browser, so the page stays cacheable.', options:[
							{label:'Site default', value:''},
							{label:'Lightbox — all devices', value:'lightbox'},
							{label:'Lightbox on desktop, inline on mobile', value:'lightbox-desktop'},
							{label:'Lightbox on mobile, inline on desktop', value:'lightbox-mobile'},
							{label:'Inline — all devices', value:'inline'} ], onChange:f('playback') }),
						onOff('lightbox_details', 'Lightbox details', 'Show title, date & description', 'Player only', 'Shown below the player (lightbox only).'),
						pick('lightbox_desc', 'Lightbox description', [ {label:'Four lines + Show more', value:'collapsed'}, {label:'Whole description', value:'full'} ]),
						onOff('lightbox_page_link', 'Lightbox "Open video page" link', 'Show', 'Hide'),
						el(TextControl, { label:'Fixed columns (blank = responsive)', value:a.columns||'', onChange:f('columns') }),
						el(TextControl, { label:'Heading (optional)', value:a.heading||'', onChange:f('heading') }),
						el(ToggleControl, { label:'Show search / sort / filter bar', checked:controlsOn, onChange:function(v){ set({controls: v?'true':'false'}); } })
					),
					el(PanelBody, { title:'Browse', initialOpen:false },
						el(SelectControl, { label:'YouTube Shorts (blank = site default)', value:a.shorts||'', options:[
							{label:'Site default', value:''}, {label:'Show alongside regular', value:'all'}, {label:'Only Shorts (swipe shelf)', value:'only'}, {label:'Hide Shorts', value:'hide'} ], onChange:f('shorts') }),
						el(RangeControl, { label:'Show before “Load more” (0 = site default)', min:0, max:60, value: parseInt(a.per_page,10)||0, onChange:num('per_page') }),
						el(RangeControl, { label:'“Load more” step (0 = site default)', min:0, max:24, value: parseInt(a.load_more,10)||0, onChange:num('load_more') }),
						pick('orderby', 'Order', [ {label:'Curated', value:'curated'}, {label:'Newest first', value:'newest'}, {label:'Oldest first', value:'oldest'}, {label:'Title (A to Z)', value:'title'} ], 'The Sort menu starts here; Reset returns here.'),
						pick('thumb_link', 'Poster click', [ {label:'Plays the video', value:'none'}, {label:'Plays, and links to the video page', value:'watch'} ]),
						el(TextControl, { label:'Subscribe URL', value:a.subscribe_url||'', onChange:f('subscribe_url') }),
						el('p', { style:{ fontSize:'11px', color:'#787c82', margin:'4px 0 0' } }, 'Consent and privacy follow the site defaults in XRV → Settings.')
					),
					el(PanelBody, { title:'Card look', initialOpen:false },
						pick('hover_style', 'Hover effect', [ {label:'Zoom', value:'zoom'}, {label:'Dim', value:'dim'}, {label:'None', value:'none'} ]),
						pick('card_align', 'Text alignment', [ {label:'Automatic (grid left, carousel centred)', value:'auto'}, {label:'Left', value:'left'}, {label:'Centred', value:'center'} ]),
						onOff('card_date', 'Upload date on cards', 'Show', 'Hide'),
						onOff('show_duration', 'Duration badge', 'Show', 'Hide'),
						el(TextControl, { label:'Trim descriptions to N characters', help:'Blank = site default, 0 = no trim. The lightbox keeps the full text.', type:'number', min:0, value:(a.desc_chars===undefined?'':a.desc_chars), onChange:f('desc_chars') }),
						pick('subscribe_icon', 'Subscribe icon', [ {label:'YouTube red', value:'brand'}, {label:'Button text colour', value:'mono'} ]),
						onOff('preconnect', 'Warm-up on hover', 'On (contacts the host on hover)', 'Off', 'Off keeps zero contact with the video host before a click.')
					),
					el(PanelBody, { title:'Pre-filter to terms (optional)', initialOpen:false },
						el(TextControl, { label:'Series slugs (comma-separated)', value:a.series||'', onChange:f('series') }),
						el(TextControl, { label:'Audience slugs', value:a.audience||'', onChange:f('audience') }),
						el(TextControl, { label:'Topic slugs', value:a.topic||'', onChange:f('topic') })
					)
				),
				el('div', useBlockProps ? useBlockProps() : {},
					el('div', { style:{ border:'1px dashed var(--xrv-border,#c4ccd6)', borderRadius:'6px', padding:'18px', textAlign:'center', color:'#50575e', background:'#f6f7f7' } },
						el('strong', { style:{ color:'var(--xrv-primary,#013C60)' } }, '▶ XRV'),
						el('div', { style:{ fontSize:'12px', marginTop:'5px' } },
							(a.collection ? ('collection: '+a.collection+' · ') : '') + (a.layout||'grid') + ' · ' + (controlsOn ? 'controls on' : 'controls off') + (a.shorts ? (' · shorts: '+a.shorts) : '')),
						el('div', { style:{ fontSize:'11px', marginTop:'3px', color:'#787c82' } }, 'Rendered live on the front end.')
					)
				)
			);
		},
		save: function(){ return null; }
	});
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );
JS;
}

/* =================================================================================================
 * 8a. SINGLE-VIDEO FRONT-END  (so a single xroad_video URL is never an empty page)
 *     The CPT is public, so WordPress serves a single-post URL per video. The facade only renders via
 *     the shortcode/block, so without this the single view would show just the (empty) post body. Two
 *     behaviours, in priority order:
 *       1. If the video has a Dedicated Page URL (_xrv_dedicated_url), redirect the single to it. This is
 *          for sites whose canonical per-video pages live elsewhere; the curated entry points there
 *          instead of creating a thin or competing page. Status is filterable (default 301).
 *       2. Otherwise render a self-contained single-video facade (poster + click-to-load) plus the
 *          VideoObject JSON-LD, prepended to the post content, so the page actually shows the video.
 * ================================================================================================= */

add_action( 'template_redirect', 'xrv_single_redirect' );
function xrv_single_redirect() {
	if ( ! is_singular( 'xroad_video' ) || is_embed() ) {
		return; // 2.11.0: an oEmbed iframe always renders the video itself, never a redirect.
	}
	$pid = get_queried_object_id();
	// 2.11.0: an editor's preview renders here too. WP_Query flags ANY ?preview= request as a preview, so
	// the bypass also needs the right to edit this video; a public ?preview=true still redirects.
	if ( is_preview() && current_user_can( 'edit_post', $pid ) ) {
		return;
	}
	$dest = xrv_dedicated_target( $pid );
	// A dedicated URL that is this very page (its own permalink, or the address just requested) is ignored:
	// redirecting to it would loop. That happens once the video base moves to where the dedicated pages lived.
	if ( '' !== $dest && ! xrv_is_same_url( $dest, xrv_current_request_url() ) && ! xrv_is_same_url( $dest, (string) get_permalink( $pid ) ) ) {
		$s        = xrv_get_settings();
		$fallback = ( 302 === (int) $s['dedicated_status'] ) ? 302 : 301; // Settings > Dedicated URL redirect (2.10.0: always 301)
		$status   = xrv_redirect_status( apply_filters( 'xrv_dedicated_redirect_status', $fallback, $pid ), $fallback );
		if ( xrv_send_redirect( $dest, $status ) ) {
			exit;
		}
	}
	// Watch page turned off (explicit '0'): there is no standalone page for this video, so a direct hit
	// goes home rather than serving a thin orphan. Default (absent/'1') renders the watch page as normal.
	// ponytail: 302 to home is the lazy "no page"; return 404 (or 410) from xrv_watch_page_off_status to
	// de-index hard instead: that serves the theme's 404 template with that status, no redirect.
	if ( '0' === (string) get_post_meta( $pid, '_xrv_watch_page', true ) ) {
		$to     = esc_url_raw( (string) apply_filters( 'xrv_watch_page_off_url', home_url( '/' ), $pid ) );
		$status = apply_filters( 'xrv_watch_page_off_status', 302, $pid );
		if ( in_array( (int) $status, array( 404, 410 ), true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( (int) $status );
			nocache_headers();
			return;
		}
		if ( xrv_send_redirect( '' !== $to ? $to : home_url( '/' ), xrv_redirect_status( $status, 302 ) ) ) {
			exit;
		}
	}
}

/* 2.11.0: a video's dedicated URL as a redirect target ('' when none). A site-relative value ("/path/") is
 * resolved with home_url(); a protocol-relative "//host/path" is left alone. */
function xrv_dedicated_target( $pid ) {
	$raw = trim( (string) get_post_meta( $pid, '_xrv_dedicated_url', true ) );
	if ( '' === $raw ) {
		return '';
	}
	if ( '/' === $raw[0] && '/' !== substr( $raw, 1, 1 ) ) {
		$raw = home_url( $raw );
	}
	return esc_url_raw( $raw );
}

/* 2.11.0: keep a (filtered) redirect status to a real redirect code: 301, 302, 307 or 308. Anything else
 * (a 200, a string, a 404 from an old filter) falls back to $fallback. */
function xrv_redirect_status( $status, $fallback ) {
	$status = is_numeric( $status ) ? (int) $status : 0;
	return in_array( $status, array( 301, 302, 307, 308 ), true ) ? $status : (int) $fallback;
}

/* 2.11.0: send an XRV redirect. Temporary codes (302 / 307) also send no-cache headers so neither browsers
 * nor page caches keep them; X-Redirect-By names XRV (wp_redirect's third argument, WP 5.1+; older WP
 * ignores the extra argument). Returns false when wp_redirect() refused (empty or filtered-out target). */
function xrv_send_redirect( $url, $status ) {
	if ( 302 === $status || 307 === $status ) {
		nocache_headers();
	}
	return (bool) wp_redirect( $url, $status, 'XRV' );
}

/* 2.11.0: the address of the current request, for comparison only (never output or redirected to). */
function xrv_current_request_url() {
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- compared against stored URLs only.
	$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	// phpcs:enable
	return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
}

/* 2.11.0: do two URLs point at the same page? Compared without the scheme, with the host lowercased,
 * default ports (80 / 443) dropped, the path decoded, lowercased and given one trailing slash, the query
 * arguments in sorted order, and the fragment ignored. A host-less URL is read against home_url(). The
 * path is compared case-insensitively on purpose: WordPress answers a mixed-case slug and then
 * canonical-redirects it, so a case-only difference must count as "same" or the redirect would loop. */
function xrv_is_same_url( $a, $b ) {
	$na = xrv_normalize_url( $a );
	return '' !== $na && xrv_normalize_url( $b ) === $na;
}
function xrv_normalize_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$p = wp_parse_url( $url );
	if ( ! is_array( $p ) ) {
		return '';
	}
	if ( empty( $p['host'] ) ) {
		$home = wp_parse_url( home_url() );
		$p['host'] = isset( $home['host'] ) ? $home['host'] : '';
		$p['port'] = isset( $home['port'] ) ? $home['port'] : 0;
	}
	$port  = isset( $p['port'] ) ? (int) $p['port'] : 0;
	$host  = strtolower( $p['host'] ) . ( ( $port && 80 !== $port && 443 !== $port ) ? ':' . $port : '' );
	$path  = strtolower( rawurldecode( isset( $p['path'] ) ? (string) $p['path'] : '' ) );
	$path  = '' === trim( $path, '/' ) ? '/' : '/' . trim( $path, '/' ) . '/';
	$query = array();
	if ( isset( $p['query'] ) ) {
		foreach ( explode( '&', (string) $p['query'] ) as $pair ) {
			if ( '' !== $pair ) {
				$query[] = rawurldecode( str_replace( '+', ' ', $pair ) );
			}
		}
		sort( $query, SORT_STRING );
	}
	return $host . $path . ( $query ? '?' . implode( '&', $query ) : '' );
}

/* -------------------------------------------------------------------------------------------------
 * 8a-ii. ADDRESS HANDOVER  (2.11.1)
 *     When the video base matches an address other content already uses (base "videos" on a site whose
 *     posts live at /blog/videos/<postname>/), XRV's rewrite rule would claim every one of those
 *     addresses. XRV now claims an address only when a PUBLISHED video with that slug exists AND has been
 *     handed the address. Otherwise WordPress resolves the request as if XRV's rules were not there, so
 *     the page that lives there today (an old post, the category's paged archive or feed) keeps serving.
 *
 *     "Not handed over yet" = the video's dedicated URL is its own address: the old page still owns it.
 *     Removing the dedicated URL (wp xrv handover) hands the address to XRV, one video, ten videos or the
 *     whole library at a time; putting it back hands the address back. With nothing else at the address,
 *     XRV serves it regardless, so this can never produce a 404 or a redirect loop.
 * ------------------------------------------------------------------------------------------------- */

/** The ID of the published video with this slug, or 0. */
function xrv_published_video_id( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'        => 'xroad_video',
		'name'             => $slug,
		'post_status'      => 'publish',
		'numberposts'      => 1,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true,
	) );
	return $ids ? (int) $ids[0] : 0;
}

/** True while a video has not been handed its own address: its dedicated URL IS that address. */
function xrv_video_defers( $pid ) {
	$dest = xrv_dedicated_target( $pid );
	return '' !== $dest && xrv_is_same_url( $dest, (string) get_permalink( $pid ) );
}

/**
 * The rules an extra permastruct generates, built the way WP_Rewrite::rewrite_rules() builds them. That
 * method sets $matches = 'matches' first; without it the queries carry "$1" instead of "$matches[1]".
 */
function xrv_permastruct_rules( $ps ) {
	global $wp_rewrite;
	if ( ! is_array( $ps ) || ( ! isset( $ps['struct'] ) && ! ( 2 === count( $ps ) && isset( $ps[0] ) ) ) ) {
		return array();
	}
	$saved               = $wp_rewrite->matches;
	$wp_rewrite->matches = 'matches';
	if ( isset( $ps['struct'] ) ) {
		$rules = $wp_rewrite->generate_rewrite_rules( $ps['struct'], $ps['ep_mask'], $ps['paged'], $ps['feed'], $ps['forcomments'], $ps['walk_dirs'], $ps['endpoints'] );
	} else {
		$rules = $wp_rewrite->generate_rewrite_rules( $ps[0], $ps[1] );
	}
	$wp_rewrite->matches = $saved;
	return (array) $rules;
}

/**
 * 2.11.2: rules XRV's own rules overwrote. Rewrite rules are keyed by their regex, so when another post type
 * uses the same base as XRV (an old video plugin at /blog/videos/%its_slug%/), the two generate identical
 * keys and XRV's query replaces the other's in the stored rules: the old page's rule no longer exists to fall
 * back to. Regenerate every other permastruct and keep the rules whose key XRV also generates.
 */
function xrv_shadowed_rules() {
	global $wp_rewrite;
	static $memo = array();
	if ( ! ( $wp_rewrite instanceof WP_Rewrite ) || empty( $wp_rewrite->extra_permastructs['xroad_video'] ) ) {
		return array();
	}
	// Keyed by the permastructs, so a base change inside one process (an apply, then a handover) recomputes.
	$key = md5( wp_json_encode( $wp_rewrite->extra_permastructs ) );
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}
	$cache = array();
	$mine = xrv_permastruct_rules( $wp_rewrite->extra_permastructs['xroad_video'] );
	foreach ( $wp_rewrite->extra_permastructs as $name => $ps ) {
		if ( 'xroad_video' === $name ) {
			continue;
		}
		foreach ( xrv_permastruct_rules( $ps ) as $match => $query ) {
			if ( isset( $mine[ $match ] ) && ! isset( $cache[ $match ] ) && ! preg_match( '/(^|[?&])xroad_video=/', (string) $query ) ) {
				$cache[ $match ] = $query;
			}
		}
	}
	$memo[ $key ] = $cache;
	return $cache;
}

/**
 * The query vars WordPress would have produced for this request if XRV's own rewrite rules did not exist,
 * or null when no other rule matches. A careful mirror of WP::parse_request(): the same rule list in the
 * same order, the same verbose page-rule check, the same public query vars with $_POST / $_GET precedence,
 * post-type query vars mapped to post_type + name, and the same post_type and numeric-slug clean-ups.
 */
function xrv_fallback_query_vars( $wp ) {
	global $wp_rewrite;
	if ( ! ( $wp instanceof WP ) || ! ( $wp_rewrite instanceof WP_Rewrite ) || ! $wp_rewrite->using_permalinks() ) {
		return null;
	}
	$rules = $wp_rewrite->wp_rewrite_rules();
	$path  = (string) $wp->request;
	if ( '' === $path || empty( $rules ) ) {
		return null;
	}
	// Every rule generated from XRV's permastruct starts with its literal prefix ("blog/videos/"): the
	// single-video rule and its attachment / embed / trackback / comment-page siblings. Step past all of them.
	$prefix = '';
	if ( isset( $wp_rewrite->extra_permastructs['xroad_video']['struct'] ) ) {
		$struct = ltrim( (string) $wp_rewrite->extra_permastructs['xroad_video']['struct'], '/' );
		$cut    = strpos( $struct, '%xroad_video%' );
		$prefix = ( false === $cut ) ? '' : substr( $struct, 0, $cut );
	}
	// Rules XRV overwrote come first: they held these addresses before XRV's identical rules replaced them.
	$cands = array();
	foreach ( xrv_shadowed_rules() as $match => $query ) {
		$cands[] = array( $match, $query, true );
	}
	foreach ( (array) $rules as $match => $query ) {
		$cands[] = array( $match, $query, false );
	}
	$perma = null;
	foreach ( $cands as $cand ) {
		list( $match, $query, $shadowed ) = $cand;
		if ( ! $shadowed && '' !== $prefix && 0 === strpos( (string) $match, $prefix ) ) {
			continue;
		}
		if ( ! preg_match( "#^$match#", $path, $m ) && ! preg_match( "#^$match#", urldecode( $path ), $m ) ) {
			continue;
		}
		$q = preg_replace( '!^.+\?!', '', (string) $query );
		if ( preg_match( '/(^|&)xroad_video=/', $q ) ) {
			continue; // one of XRV's own rules: the ones we are stepping past
		}
		if ( $wp_rewrite->use_verbose_page_rules && preg_match( '/pagename=\$matches\[([0-9]+)\]/', $q, $vm ) ) {
			$page = get_page_by_path( $m[ $vm[1] ] );
			if ( ! $page ) {
				continue;
			}
			$st = get_post_status_object( $page->post_status );
			if ( $st && ! $st->public && ! $st->protected && ! $st->private && $st->exclude_from_search ) {
				continue;
			}
		}
		parse_str( addslashes( WP_MatchesMapRegex::apply( $q, $m ) ), $perma );
		break;
	}
	if ( null === $perma ) {
		return null;
	}
	$pt_vars = array();
	foreach ( get_post_types( array(), 'objects' ) as $pt => $t ) {
		if ( is_post_type_viewable( $t ) && $t->query_var ) {
			$pt_vars[ $t->query_var ] = $pt;
		}
	}
	$out = array();
	// phpcs:disable WordPress.Security.NonceVerification -- mirrors core's own query-var parsing; read-only.
	foreach ( (array) $wp->public_query_vars as $var ) {
		if ( isset( $wp->extra_query_vars[ $var ] ) ) {
			$out[ $var ] = $wp->extra_query_vars[ $var ];
		} elseif ( isset( $_POST[ $var ] ) ) {
			$out[ $var ] = $_POST[ $var ];
		} elseif ( isset( $_GET[ $var ] ) ) {
			$out[ $var ] = $_GET[ $var ];
		} elseif ( isset( $perma[ $var ] ) ) {
			$out[ $var ] = $perma[ $var ];
		}
		if ( ! empty( $out[ $var ] ) ) {
			$out[ $var ] = is_array( $out[ $var ] ) ? array_map( 'strval', $out[ $var ] ) : (string) $out[ $var ];
			if ( isset( $pt_vars[ $var ] ) ) {
				$out['post_type'] = $pt_vars[ $var ];
				$out['name']      = $out[ $var ];
			}
		}
	}
	// phpcs:enable
	foreach ( get_taxonomies( array(), 'objects' ) as $t ) {
		if ( $t->query_var && isset( $out[ $t->query_var ] ) && is_string( $out[ $t->query_var ] ) ) {
			$out[ $t->query_var ] = str_replace( ' ', '+', $out[ $t->query_var ] );
		}
	}
	if ( isset( $out['post_type'] ) ) {
		$queryable = get_post_types( array( 'publicly_queryable' => true ) );
		if ( ! is_array( $out['post_type'] ) ) {
			if ( ! in_array( $out['post_type'], $queryable, true ) ) {
				unset( $out['post_type'] );
			}
		} else {
			$out['post_type'] = array_intersect( $out['post_type'], $queryable );
		}
	}
	foreach ( (array) $wp->private_query_vars as $var ) {
		if ( isset( $wp->extra_query_vars[ $var ] ) ) {
			$out[ $var ] = $wp->extra_query_vars[ $var ];
		}
	}
	return function_exists( 'wp_resolve_numeric_slug_conflicts' ) ? wp_resolve_numeric_slug_conflicts( $out ) : $out;
}

/** Does a set of query vars find anything (the page that lives at the address today)? */
function xrv_query_has_content( $vars ) {
	$q = new WP_Query( array_merge( (array) $vars, array( 'fields' => 'ids', 'no_found_rows' => true ) ) );
	return $q->have_posts();
}

/**
 * The step-aside itself, on the main request: when XRV's rule matched an address whose video is not
 * published, or not handed over yet, and other content lives there, WordPress serves that content.
 * `?preview=true` always shows the XRV page, so editors can check a video before handing it over.
 * The xrv_handover_query_vars filter can veto per request.
 */
add_filter( 'request', 'xrv_request_handover', 1 );
function xrv_request_handover( $qv ) {
	global $wp;
	if ( is_admin() || empty( $qv['xroad_video'] ) || ! empty( $qv['preview'] ) ) {
		return $qv;
	}
	if ( ! ( $wp instanceof WP ) || false === strpos( (string) $wp->matched_query, 'xroad_video=' ) ) {
		return $qv; // only addresses XRV's rewrite rules claimed (not ?xroad_video= query strings)
	}
	$pid = xrv_published_video_id( is_array( $qv['xroad_video'] ) ? '' : $qv['xroad_video'] );
	if ( $pid && ! xrv_video_defers( $pid ) ) {
		return $qv; // handed over: XRV owns this address
	}
	$alt = xrv_fallback_query_vars( $wp );
	if ( null === $alt || ! xrv_query_has_content( $alt ) ) {
		return $qv; // nothing else lives here: XRV (or its 404 for an unpublished video) stands
	}
	return (array) apply_filters( 'xrv_handover_query_vars', $alt, $qv, $pid );
}

/**
 * Who serves a video's own address right now, for the editor, Settings and `wp xrv list`:
 * array( 'state' => xrv | old | redirect | home | draft, 'url' => its own address, 'to' => redirect target ).
 */
function xrv_address_state( $pid ) {
	$post = get_post( $pid );
	$own  = (string) get_permalink( $pid );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return array( 'state' => 'draft', 'url' => $own, 'to' => '' );
	}
	$dest = xrv_dedicated_target( $pid );
	if ( '' !== $dest && ! xrv_is_same_url( $dest, $own ) ) {
		return array( 'state' => 'redirect', 'url' => $own, 'to' => $dest );
	}
	if ( '' !== $dest && xrv_address_has_other_content( $own ) ) {
		return array( 'state' => 'old', 'url' => $own, 'to' => '' );
	}
	if ( '0' === (string) get_post_meta( $pid, '_xrv_watch_page', true ) ) {
		return array( 'state' => 'home', 'url' => $own, 'to' => home_url( '/' ) );
	}
	return array( 'state' => 'xrv', 'url' => $own, 'to' => '' );
}

/** Would anything other than XRV answer at this site-internal address? (Matches it against the rewrite rules.) */
function xrv_address_has_other_content( $url ) {
	$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	if ( '' !== $home && 0 === strpos( $path . '/', $home . '/' ) ) {
		$path = trim( substr( $path, strlen( $home ) ), '/' );
	}
	if ( '' === $path ) {
		return false;
	}
	$probe                   = new WP();
	$probe->request          = $path;
	$probe->public_query_vars = isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof WP ? $GLOBALS['wp']->public_query_vars : $probe->public_query_vars;
	$probe->extra_query_vars = array();
	$saved_get  = $_GET;  // phpcs:ignore WordPress.Security.NonceVerification
	$saved_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
	$_GET  = array();
	$_POST = array();
	$alt   = xrv_fallback_query_vars( $probe );
	$_GET  = $saved_get;
	$_POST = $saved_post;
	return null !== $alt && xrv_query_has_content( $alt );
}

/* 2.11.0: priority 20, AFTER do_shortcode (11). At the default priority the facade ran first and
 * do_shortcode then executed any [video src=…] typed into a description: it could load a third party
 * before the click and corrupted the JSON-LD. The watch page passes its own display settings
 * (watch_meta / watch_desc); [xroad-video] embeds keep the plain defaults. */
add_filter( 'the_content', 'xrv_single_content', 20 );
function xrv_single_content( $content ) {
	if ( ! is_singular( 'xroad_video' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$s = xrv_get_settings();
	return xrv_render_single( get_the_ID(), 'inline', array( 'meta' => $s['watch_meta'], 'desc' => $s['watch_desc'], 'watch' => true ) ) . $content;
}

/**
 * Render one video as a self-contained facade block (reusing the grid's assets, card, and schema).
 * 2.11.0: $args['meta'] (full | compact | title) and $args['desc'] (plain | rich) are passed by the watch
 * page from the watch_meta / watch_desc settings; $args['watch'] loads its poster eagerly (it is the page's
 * LCP image). [xroad-video] embeds call this without $args and keep the 2.10.0 output. The root now carries
 * the site's consent, preconnect and display settings, like a gallery root.
 */
function xrv_render_single( $post_id, $playback = 'inline', $args = array() ) {
	$args     = array_merge( array( 'meta' => 'full', 'desc' => 'plain', 'watch' => false ), (array) $args );
	$playback = ( 'lightbox' === $playback ) ? 'lightbox' : 'inline';
	$provider = (string) get_post_meta( $post_id, '_xrv_provider', true );
	$provider = $provider !== '' ? $provider : 'youtube';
	$vid      = (string) get_post_meta( $post_id, '_xrv_video_id', true );
	if ( $vid === '' ) {
		return ''; // nothing to render; leave the post body as-is.
	}

	$o = xrv_display_opts( array_merge( xrv_get_settings(), array( 'playback' => $playback ) ) );
	$o['desc_chars'] = 0; // a video's own page (or an embed) always shows the whole description
	$o['desc_mode']  = ( 'rich' === $args['desc'] ) ? 'rich' : 'plain';
	$meta = in_array( $args['meta'], array( 'full', 'compact', 'title' ), true ) ? $args['meta'] : 'full';

	$dur_iso  = (string) get_post_meta( $post_id, '_xrv_duration_iso', true );
	$upload   = xrv_video_ymd( $post_id );
	$thumb_id = (int) get_post_meta( $post_id, '_xrv_local_thumb_id', true );
	$poster_ss = xrv_poster_srcset( xrv_effective_thumb_id( $post_id, $thumb_id ) );

	$series   = wp_get_post_terms( $post_id, 'xrv_series', array( 'fields' => 'slugs' ) );
	$audience = wp_get_post_terms( $post_id, 'xrv_audience', array( 'fields' => 'slugs' ) );
	$topic    = wp_get_post_terms( $post_id, 'xrv_topic', array( 'fields' => 'slugs' ) );

	$r = array(
		'id'         => $post_id,
		'title'      => get_the_title( $post_id ),
		'provider'   => $provider,
		'vid'        => $vid,
		'hash'       => (string) get_post_meta( $post_id, '_xrv_video_hash', true ),
		'source_url' => (string) get_post_meta( $post_id, '_xrv_source_url', true ),
		'desc'       => (string) get_post_meta( $post_id, '_xrv_description', true ),
		'dedicated'  => '', // on its own page there is nowhere else to link out to.
		'dur_iso'    => $dur_iso,
		'dur_clock'  => xrv_iso_to_clock( $dur_iso ),
		'upload'     => $upload,
		'ymd'        => $upload,
		'poster'     => xrv_local_poster_url( $post_id, $thumb_id ),
		'poster_mobile' => xrv_mobile_poster_url( $post_id ),
		'poster_srcset' => $poster_ss['srcset'],
		'poster_sizes'  => $poster_ss['sizes'],
		'series'     => is_wp_error( $series ) ? array() : $series,
		'audience'   => is_wp_error( $audience ) ? array() : $audience,
		'topic'      => is_wp_error( $topic ) ? array() : $topic,
		'date_key'   => $upload !== '' ? (int) str_replace( '-', '', $upload ) : 0,
		'search'     => '',
		'is_short'   => xrv_is_short( $post_id ),
	);

	ob_start();
	?>
<div<?php echo xrv_root_attrs( $o, array( 'xrv--single' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in xrv_root_attrs ?>>
	<?php echo xrv_head_assets_once(); ?>
	<div class="xrv-grid" style="column-count:1">
		<?php echo xrv_render_card( $r, $meta, ! empty( $args['watch'] ), $o ); ?>
	</div>
	<?php echo xrv_footer_js_once(); ?>
</div>
	<?php
	echo xrv_single_video_schema( $post_id ); // standalone rich VideoObject (not the CollectionPage wrapper)
	return ob_get_clean();
}

/**
 * Ad-hoc poster for a pasted-URL embed that has no CPT entry. We must NOT point the <img> at a remote
 * host (that would fire a third-party request before the click and break the facade promise), so the
 * poster is sideloaded into the media library ONCE per provider:id and the attachment id cached in a
 * single option. A short transient lock stops a traffic burst sideloading the same URL twice. Returns 0
 * when no poster could be fetched yet (the card then shows the neutral placeholder).
 * ponytail: sideload lazily on first render, locked + cached forever; move to a cron/async resolver only
 * if a tenant embeds high-traffic ad-hoc URLs.
 */
function xrv_adhoc_thumb_id( $provider, $id, $source_url ) {
	$key = $provider . ':' . $id;
	$map = (array) get_option( 'xrv_adhoc_thumbs', array() );
	if ( ! empty( $map[ $key ] ) && wp_get_attachment_image_url( (int) $map[ $key ], 'large' ) ) {
		return (int) $map[ $key ];
	}
	$lock = 'xrv_adhoc_' . md5( $key );
	if ( get_transient( $lock ) ) { return 0; } // a sideload is already in flight; placeholder this render
	set_transient( $lock, 1, MINUTE_IN_SECONDS );

	if ( 'youtube' === $provider ) {
		$cands = xrv_thumb_candidates( $id, 'youtube', false !== strpos( (string) $source_url, '/shorts/' ) );
	} else {
		$oembed = xrv_fetch_oembed( $source_url, $provider ); // every non-YouTube host's poster = its oEmbed thumbnail_url
		$cands  = ! empty( $oembed['thumbnail_url'] ) ? array( $oembed['thumbnail_url'] ) : array();
	}
	$att = $cands ? xrv_sideload_thumbnail( 0, $id, $provider, $cands ) : new WP_Error( 'xrv', 'no candidate' );
	delete_transient( $lock );
	if ( is_wp_error( $att ) ) { return 0; }
	$map[ $key ] = (int) $att;
	update_option( 'xrv_adhoc_thumbs', $map, false );
	return (int) $att;
}

/**
 * Render a click-to-load facade for ANY supported video URL with no CPT entry — powers
 * [xroad-video url="..."] so an editor can drop a privacy-safe player into any post or page. Reuses the
 * whole provider switch, the card, and the shared assets. Poster: an explicit poster attr wins, else the
 * sideloaded-and-cached local image, else the neutral placeholder. No JSON-LD (the host page owns its own
 * schema); use a curated CPT video for an SEO-first page.
 */
function xrv_render_single_url( $url, $playback = 'inline', $poster_attr = '', $title = '' ) {
	$provider = xrv_detect_provider( $url );
	if ( '' === $provider ) { $provider = 'youtube'; }
	$id   = xrv_extract_video_id( $url, $provider );
	$hash = xrv_extract_video_hash( $url, $provider );
	if ( '' === $id ) {
		return current_user_can( 'edit_posts' )
			? '<span style="display:inline-block;padding:8px 12px;border:1px dashed #f3d199;background:#fff8ef;border-radius:6px;font-size:13px;color:#7a4f00"><code>[xroad-video]</code> could not read a video ID from that <code>url</code>. Paste a full watch/share link (for TikTok use the <code>@user/video/&hellip;</code> link, not a <code>vm.tiktok.com</code> short link).</span>'
			: '';
	}

	// Poster stays LOCAL only (an explicit external URL is ignored, never rendered) to keep the
	// no-third-party-before-click guarantee. attachment_url_to_postid() resolves a local media URL to its ID.
	$thumb_id = 0;
	if ( '' !== $poster_attr ) {
		$thumb_id = is_numeric( $poster_attr ) ? (int) $poster_attr : (int) attachment_url_to_postid( $poster_attr );
	}
	if ( ! $thumb_id ) {
		$thumb_id = xrv_adhoc_thumb_id( $provider, $id, $url );
	}
	$poster_ss = xrv_poster_srcset( $thumb_id );

	$r = array(
		'id'         => 0,
		'title'      => (string) $title,
		'provider'   => $provider,
		'vid'        => $id,
		'hash'       => $hash,
		'source_url' => $url,
		'desc'       => '',
		'dedicated'  => '',
		'dur_iso'    => '',
		'dur_clock'  => '',
		'upload'     => '',
		'poster'     => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'large' ) : '',
		'poster_mobile' => '',
		'poster_srcset' => $poster_ss['srcset'],
		'poster_sizes'  => $poster_ss['sizes'],
		'series'     => array(),
		'audience'   => array(),
		'topic'      => array(),
		'date_key'   => 0,
		'search'     => '',
		'is_short'   => ( 'tiktok' === $provider ) || ( false !== strpos( $url, '/shorts/' ) ),
	);

	// 2.11.0: the same root attributes as a gallery (consent mode, preconnect, display settings).
	$o = xrv_display_opts( array_merge( xrv_get_settings(), array( 'playback' => ( 'lightbox' === $playback ? 'lightbox' : 'inline' ) ) ) );

	ob_start();
	?>
<div<?php echo xrv_root_attrs( $o, array( 'xrv--single' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in xrv_root_attrs ?>>
	<?php echo xrv_head_assets_once(); ?>
	<div class="xrv-grid" style="column-count:1">
		<?php echo xrv_render_card( $r, 'full', false, $o ); ?>
	</div>
	<?php echo xrv_footer_js_once(); ?>
</div>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------------------------------
 * 8b. Single-page chrome cleanup. On a single xroad_video page the theme renders its own byline and a
 *     featured image above our facade. We never want either on these curated video pages, so hide them
 *     with a small scoped style (only on single xroad_video; harmless everywhere else). Filterable.
 * ------------------------------------------------------------------------------------------------- */
add_action( 'wp_head', 'xrv_single_chrome_css' );
function xrv_single_chrome_css() {
	if ( ! is_singular( 'xroad_video' ) ) {
		return;
	}
	$css = 'body.single-xroad_video .post-meta{display:none!important}'
		. 'body.single-xroad_video .et_post_meta_wrapper img,body.single-xroad_video .entry-content > .wp-post-image,body.single-xroad_video .post-thumbnail{display:none!important}';
	$css = apply_filters( 'xrv_single_chrome_css', $css );
	echo '<style id="xrv-single-chrome">' . $css . '</style>'; // phpcs:ignore -- static, controlled CSS
}

/* =================================================================================================
 * 9. META BOX  ("Video Details") + SAVE  (paste a URL; the ID, thumbnail, and metadata derive themselves)
 *    A single native meta box. The editor pastes a YouTube URL; on save the routine extracts the 11-char
 *    ID, sideloads a LOCAL thumbnail (the no-Google-call hardening), and prefills the title/description
 *    from oEmbed on first save. Duration and upload date can be entered by hand or read from oEmbed where
 *    available; if unavailable they fall back to editor-entered values so schema is never blank.
 * ================================================================================================= */

/* =================================================================================================
 * 9h. COLLECTION EDITOR  (2.9.0 — build a hand-picked, placeable gallery)
 *     The admin UI for an xrv_collection: pick and order videos, choose a layout, copy the shortcode.
 *     Dependency-free vanilla JS over a JSON list of the library — no wp.media, no build step.
 * ================================================================================================= */

add_action( 'add_meta_boxes_xrv_collection', 'xrvc_add_meta_boxes' );
function xrvc_add_meta_boxes() {
	add_meta_box( 'xrvc_build', 'Build this collection', 'xrvc_render_build_box', 'xrv_collection', 'normal', 'high' );
}

/* The library the picker draws from: every video (any status) as {id,title,thumb}. Capped so a very large
 * library can't bloat the editor page; the cap is surfaced in the UI when hit. */
function xrvc_library_for_picker() {
	$q = new WP_Query( array(
		'post_type'      => 'xroad_video',
		'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'posts_per_page' => 500,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	) );
	$out = array();
	foreach ( $q->posts as $p ) {
		$tid   = xrv_effective_thumb_id( $p->ID );
		$thumb = $tid ? wp_get_attachment_image_url( $tid, 'thumbnail' ) : '';
		$out[] = array( 'id' => $p->ID, 'title' => get_the_title( $p->ID ), 'thumb' => $thumb ? $thumb : '' );
	}
	return $out;
}

function xrvc_render_build_box( $post ) {
	wp_nonce_field( 'xrvc_save_meta', 'xrvc_meta_nonce' );
	echo xrv_admin_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- shared admin brand CSS (prints once/request)

	$ids    = (string) get_post_meta( $post->ID, '_xrvc_video_ids', true );
	$layout = (string) get_post_meta( $post->ID, '_xrvc_layout', true );
	$orderby = (string) get_post_meta( $post->ID, '_xrvc_orderby', true ); // 2.11.0
	$lib    = xrvc_library_for_picker();
	$capped = count( $lib ) >= 500;
	$slug   = (string) $post->post_name;

	echo '<div class="xrv-admin xrv-metabox xrvc-build">';
	echo '<input type="hidden" id="_xrvc_video_ids" name="_xrvc_video_ids" value="' . esc_attr( $ids ) . '">';

	if ( ! $lib ) {
		echo '<p class="xrvc-empty">No videos in the library yet. Add videos under <strong>XRV Video &rarr; Add</strong>, then build a collection.</p>';
	} else {
		echo '<div class="xrvc-cols">';
		echo '<div class="xrvc-col"><div class="xrvc-col-h">In this collection <span id="xrvc-count" class="xrvc-pill">0</span></div><div id="xrvc-selected" class="xrvc-list xrvc-selected"></div><p class="xrvc-hint">Use the &uarr; &darr; buttons to set the curated order. The gallery shows this order when the collection&rsquo;s Order is Curated (below).</p></div>';
		echo '<div class="xrvc-col"><div class="xrvc-col-h">Add from library</div><input type="search" id="xrvc-search" class="widefat xrvc-search" placeholder="Search videos&hellip;"><div id="xrvc-lib" class="xrvc-list xrvc-lib"></div>' . ( $capped ? '<p class="xrvc-hint">Showing the first 500 videos.</p>' : '' ) . '</div>';
		echo '</div>';
	}

	// Layout — the one display choice intrinsic to a collection; everything else is set on the shortcode/block at the placement site.
	echo '<p style="margin:14px 0 0"><label for="_xrvc_layout" style="font-weight:600;display:block;margin-bottom:4px">Layout</label><select id="_xrvc_layout" name="_xrvc_layout">';
	foreach ( array( '' => 'Site default (grid)', 'grid' => 'Grid', 'carousel' => 'Carousel (featured row)', 'library' => 'Library (featured + grid)' ) as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $layout, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select></p>';

	// 2.11.0: Order. Curated = the hand-picked order above; Newest / Oldest / Title re-sort the same videos.
	echo '<p style="margin:14px 0 0"><label for="_xrvc_orderby" style="font-weight:600;display:block;margin-bottom:4px">' . esc_html__( 'Order', 'xroad-videos' ) . '</label><select id="_xrvc_orderby" name="_xrvc_orderby">';
	foreach ( array( '' => __( 'Site default', 'xroad-videos' ), 'curated' => __( 'Curated (the order above)', 'xroad-videos' ), 'newest' => __( 'Newest first', 'xroad-videos' ), 'oldest' => __( 'Oldest first', 'xroad-videos' ), 'title' => __( 'Title (A to Z)', 'xroad-videos' ) ) as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $orderby, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select></p>';

	// Shortcode hint (replaces the separate usage box).
	if ( '' !== $slug ) {
		echo '<p class="xrvc-hint" style="margin:12px 0 0">Drop it anywhere with <code>[xroad-videos collection="' . esc_html( $slug ) . '"]</code> or the XRV Video block. Set columns / heading / etc. on the shortcode at each placement.</p>';
	} else {
		echo '<p class="xrvc-hint" style="margin:12px 0 0">Save to generate the slug, then a <code>[xroad-videos collection="&hellip;"]</code> shortcode appears here.</p>';
	}

	echo '</div>'; // .xrvc-build

	echo xrvc_build_styles(); // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS
	echo '<script>window.XRVC_LIB=' . wp_json_encode( $lib, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_json_encode w/ HEX flags is script-safe
	echo xrvc_build_js(); // phpcs:ignore WordPress.Security.EscapeOutput -- static NOWDOC script
}

function xrvc_build_styles() {
	return <<<'CSS'
<style id="xrvc-build-css">
.xrvc-build .xrvc-col-h{font-weight:700;color:var(--xr-deep);margin:0 0 6px;font-size:13px}
.xrvc-build .xrvc-pill{display:inline-block;min-width:20px;text-align:center;background:var(--xr-light);color:var(--xr-purple);border-radius:10px;padding:0 7px;font-size:12px;margin-left:4px}
.xrvc-build .xrvc-cols{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media (max-width:782px){.xrvc-build .xrvc-cols{grid-template-columns:1fr}}
.xrvc-build .xrvc-list{border:1px solid var(--xr-line);border-radius:10px;background:#fbfbfd;min-height:90px;max-height:340px;overflow:auto;padding:6px}
.xrvc-build .xrvc-row{display:flex;align-items:center;gap:8px;padding:6px;border-radius:8px;background:#fff;border:1px solid var(--xr-line);margin-bottom:6px}
.xrvc-build .xrvc-row img,.xrvc-build .xrvc-row .xrvc-noimg{width:56px;height:32px;object-fit:cover;border-radius:4px;flex:none;background:var(--xr-light);display:block}
.xrvc-build .xrvc-row .xrvc-t{flex:1;font-size:13px;line-height:1.3;overflow:hidden}
.xrvc-build .xrvc-row button{flex:none;border:0;background:transparent;cursor:pointer;color:var(--xr-charcoal);font-size:14px;line-height:1;padding:4px 6px;border-radius:6px}
.xrvc-build .xrvc-row button:hover{background:var(--xr-light)}
.xrvc-build .xrvc-row .xrvc-rm:hover{background:#fdecea;color:#b32d2e}
.xrvc-build .xrvc-row .xrvc-add{margin-left:auto;font-weight:600;color:var(--xr-purple);font-size:13px}
.xrvc-build .xrvc-empty,.xrvc-build .xrvc-hint{color:#787c82;font-size:12px;margin:6px 0 0}
.xrvc-build .xrvc-search{margin:0 0 8px}
.xrvc-build .xrvc-lib .xrvc-row{cursor:default}
</style>
CSS;
}

function xrvc_build_js() {
	return <<<'JS'
<script>
(function(){
	var LIB = window.XRVC_LIB || [];
	var byId = {}; LIB.forEach(function(v){ byId[v.id] = v; });
	function el(id){ return document.getElementById(id); }
	var hidden = el('_xrvc_video_ids');
	var selWrap = el('xrvc-selected'), libWrap = el('xrvc-lib'), search = el('xrvc-search'), countEl = el('xrvc-count');
	var selected = (hidden && hidden.value) ? hidden.value.split(',').map(Number).filter(function(n){ return n && byId[n]; }) : [];
	function esc(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
	function thumb(v){ return v.thumb ? '<img src="' + esc(v.thumb) + '" alt="">' : '<span class="xrvc-noimg"></span>'; }
	function renderSelected(){
		if(!selWrap) return;
		if(!selected.length){ selWrap.innerHTML = '<p class="xrvc-empty">No videos chosen yet. Add some from the library on the right.</p>'; return; }
		selWrap.innerHTML = '';
		selected.forEach(function(id){
			var v = byId[id]; if(!v) return;
			var row = document.createElement('div');
			row.className = 'xrvc-row'; row.dataset.id = id;
			row.innerHTML = thumb(v) + '<span class="xrvc-t">' + esc(v.title) + '</span>'
				+ '<button type="button" class="xrvc-up" title="Move up" aria-label="Move up">&uarr;</button>'
				+ '<button type="button" class="xrvc-down" title="Move down" aria-label="Move down">&darr;</button>'
				+ '<button type="button" class="xrvc-rm" title="Remove" aria-label="Remove">&#10005;</button>';
			selWrap.appendChild(row);
		});
	}
	function renderLib(){
		if(!libWrap) return;
		var q = (search && search.value ? search.value : '').trim().toLowerCase();
		var avail = LIB.filter(function(v){ return selected.indexOf(v.id) < 0 && (!q || String(v.title).toLowerCase().indexOf(q) > -1); });
		if(!avail.length){ libWrap.innerHTML = '<p class="xrvc-empty">' + (q ? 'No matches.' : 'Every video is already in this collection.') + '</p>'; return; }
		libWrap.innerHTML = '';
		avail.forEach(function(v){
			var row = document.createElement('div');
			row.className = 'xrvc-row'; row.dataset.id = v.id;
			row.innerHTML = thumb(v) + '<span class="xrvc-t">' + esc(v.title) + '</span><button type="button" class="xrvc-add" title="Add to collection">+ Add</button>';
			libWrap.appendChild(row);
		});
	}
	function sync(){ if(hidden) hidden.value = selected.join(','); if(countEl) countEl.textContent = selected.length; renderSelected(); renderLib(); }
	function move(id, dir){ var i = selected.indexOf(id), j = i + dir; if(i < 0 || j < 0 || j >= selected.length) return; var t = selected[i]; selected[i] = selected[j]; selected[j] = t; sync(); }
	if(selWrap){
		selWrap.addEventListener('click', function(e){
			var row = e.target.closest('.xrvc-row'); if(!row) return; var id = Number(row.dataset.id);
			if(e.target.closest('.xrvc-rm')){ selected = selected.filter(function(x){ return x !== id; }); sync(); }
			else if(e.target.closest('.xrvc-up')){ move(id, -1); }
			else if(e.target.closest('.xrvc-down')){ move(id, 1); }
		});
	}
	if(libWrap){
		libWrap.addEventListener('click', function(e){
			if(!e.target.closest('.xrvc-add')) return;
			var row = e.target.closest('.xrvc-row'); if(!row) return; var id = Number(row.dataset.id);
			if(selected.indexOf(id) < 0){ selected.push(id); sync(); }
		});
	}
	if(search){ search.addEventListener('input', renderLib); }
	sync();
})();
</script>
JS;
}

add_action( 'save_post_xrv_collection', 'xrvc_save_meta', 10, 2 );
function xrvc_save_meta( $post_id, $post ) {
	if ( ! isset( $_POST['xrvc_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['xrvc_meta_nonce'] ) ), 'xrvc_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$ids_raw = isset( $_POST['_xrvc_video_ids'] ) ? (string) wp_unslash( $_POST['_xrvc_video_ids'] ) : '';
	$ids     = array_values( array_unique( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $ids_raw ) ) ) ) );
	update_post_meta( $post_id, '_xrvc_video_ids', implode( ',', $ids ) );

	$layout = isset( $_POST['_xrvc_layout'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['_xrvc_layout'] ) ) ) : '';
	update_post_meta( $post_id, '_xrvc_layout', in_array( $layout, array( 'grid', 'carousel', 'library' ), true ) ? $layout : '' );

	$orderby = isset( $_POST['_xrvc_orderby'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['_xrvc_orderby'] ) ) ) : '';
	update_post_meta( $post_id, '_xrvc_orderby', in_array( $orderby, array( 'curated', 'newest', 'oldest', 'title' ), true ) ? $orderby : '' );
}

add_action( 'add_meta_boxes', 'xrv_add_meta_box' );
function xrv_add_meta_box() {
	add_meta_box( 'xrv_details', 'Video Details', 'xrv_render_meta_box', 'xroad_video', 'normal', 'high' );
}

function xrv_render_meta_box( $post ) {
	wp_nonce_field( 'xrv_save_meta', 'xrv_meta_nonce' );

	$provider  = (string) get_post_meta( $post->ID, '_xrv_provider', true );
	$provider  = $provider !== '' ? $provider : 'auto';
	$src_url    = (string) get_post_meta( $post->ID, '_xrv_source_url', true );
	$vid       = (string) get_post_meta( $post->ID, '_xrv_video_id', true );
	$dedicated = (string) get_post_meta( $post->ID, '_xrv_dedicated_url', true );
	$dur       = (string) get_post_meta( $post->ID, '_xrv_duration_iso', true );
	$upload    = (string) get_post_meta( $post->ID, '_xrv_upload_date', true );
	$desc      = (string) get_post_meta( $post->ID, '_xrv_description', true );
	$thumb_id  = (int) get_post_meta( $post->ID, '_xrv_local_thumb_id', true );
	$transcript = (string) get_post_meta( $post->ID, '_xrv_transcript', true );
	$chapters   = (string) get_post_meta( $post->ID, '_xrv_chapters', true );

	$row = function( $label, $name, $value, $placeholder = '', $type = 'text' ) {
		printf(
			'<p style="margin:0 0 14px"><label for="%1$s" style="display:block;font-weight:600;margin-bottom:4px">%2$s</label>'
			. '<input type="%5$s" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" class="widefat"></p>',
			esc_attr( $name ), esc_html( $label ), esc_attr( $value ), esc_attr( $placeholder ), esc_attr( $type )
		);
	};

	echo xrv_admin_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- brand the editor to match Settings & Import (prints once)
	echo '<div class="xrv-admin xrv-metabox" style="max-width:760px">';

	$provider_opts = array(
		'auto'        => 'Auto-detect from URL (recommended)',
		'youtube'     => 'YouTube',
		'vimeo'       => 'Vimeo',
		'wistia'      => 'Wistia',
		'loom'        => 'Loom',
		'dailymotion' => 'Dailymotion',
		'tiktok'      => 'TikTok',
		'file'        => 'Self-hosted file (MP4 / WebM)',
	);
	echo '<p style="margin:0 0 4px"><label for="_xrv_provider" style="display:block;font-weight:600;margin-bottom:4px">Provider</label>'
		. '<select id="_xrv_provider" name="_xrv_provider" class="widefat">';
	foreach ( $provider_opts as $pv => $plabel ) {
		echo '<option value="' . esc_attr( $pv ) . '"' . selected( $provider, $pv, false ) . '>' . esc_html( $plabel ) . '</option>';
	}
	echo '</select></p>';
	echo '<p style="margin:0 0 14px;color:#666;font-size:12px">Leave this on <strong>Auto-detect</strong> and just paste the video URL below. Supported hosts: YouTube, Vimeo, Wistia, Loom, Dailymotion, TikTok. For a <strong>self-hosted file</strong>, choose that option and paste a direct MP4 or WebM URL, then upload a poster image below.</p>';

	$row( 'Video URL (paste the watch link; the ID is extracted automatically)', '_xrv_source_url', $src_url, 'e.g. https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'url' );

	echo '<p style="margin:-6px 0 14px"><button type="button" class="button xrv-file-pick">Choose a video file from the Media Library</button> <span style="color:#666;font-size:12px">for a self-hosted MP4 / WebM; this fills the URL above and sets Provider to Self-hosted file.</span></p>';

	echo '<p style="margin:0 0 14px;color:#666;font-size:12px">Detected video ID: <code>' . ( $vid !== '' ? esc_html( $vid ) : '— (saved after you add a URL)' ) . '</code></p>';

	// Poster images: a primary (desktop/grid) poster — custom upload OR auto-fetched host thumbnail — plus an
	// OPTIONAL separate mobile poster for art-directed phones. Both pickers wired in xrv_media_picker_js().
	$is_custom   = '1' === (string) get_post_meta( $post->ID, '_xrv_thumb_custom', true );
	$eff_id      = xrv_effective_thumb_id( $post->ID, $thumb_id );
	$preview_url = $eff_id ? wp_get_attachment_image_url( $eff_id, 'medium' ) : '';
	$src_label   = $is_custom ? 'custom upload' : ( $thumb_id ? 'auto-fetched from the video host' : ( $eff_id ? 'site default' : 'none yet' ) );
	$settings_link = esc_url( admin_url( 'edit.php?post_type=xroad_video&page=xrv-settings' ) );
	$mobile_id   = (int) get_post_meta( $post->ID, '_xrv_mobile_thumb_id', true );
	$mobile_url  = $mobile_id ? wp_get_attachment_image_url( $mobile_id, 'medium' ) : '';

	echo '<label style="display:block;font-weight:600;margin:0 0 8px">Poster images</label>';

	// Primary / desktop poster.
	echo '<div class="xrv-media-field" style="margin:0 0 10px;padding:12px;border:1px solid var(--xr-line);border-radius:10px;background:#fbfbfd">'
		. '<div style="font-weight:600;font-size:13px;margin-bottom:6px;color:var(--xr-charcoal)">Primary poster <span style="font-weight:400;color:#787c82">&middot; ' . esc_html( $src_label ) . ' &middot; grids &amp; wide screens (16:9)</span></div>'
		. '<div class="xrv-media-preview" style="margin-bottom:8px">' . ( $preview_url ? '<img src="' . esc_url( $preview_url ) . '" alt="" style="max-width:220px;height:auto;border-radius:6px;display:block">' : '<span style="color:#a05a00;font-size:12px">No poster yet &mdash; one is downloaded from the video host automatically on save (self-hosted files need a manual upload).</span>' ) . '</div>'
		. '<input type="hidden" class="xrv-media-id" name="_xrv_local_thumb_id" value="' . (int) $thumb_id . '">'
		. '<input type="hidden" class="xrv-media-custom" name="_xrv_thumb_custom" value="' . ( $is_custom ? '1' : '0' ) . '">'
		. '<button type="button" class="button xrv-media-pick">Upload / choose image</button>'
		. ' <button type="button" class="button-link xrv-media-clear" style="color:#b32d2e;margin-left:8px;' . ( $thumb_id ? '' : 'display:none' ) . '">Reset to automatic</button>'
		. '</div>';

	// Optional mobile poster (opt-in via checkbox; revealed by the meta-box script below).
	echo '<label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;margin:0 0 8px;cursor:pointer">'
		. '<input type="checkbox" id="xrv-mob-toggle" style="margin-top:2px"' . checked( $mobile_id > 0, true, false ) . '>'
		. '<span><strong>Use a different image on phones</strong> <span style="color:#787c82">&mdash; optional. A portrait / square crop shown at &le;600px. Leave off and phones use a smaller rendition of the primary poster automatically.</span></span></label>';
	echo '<div class="xrv-media-field" id="xrv-mob-field" style="margin:0 0 6px;padding:12px;border:1px solid var(--xr-line);border-radius:10px;background:#fbfbfd;' . ( $mobile_id ? '' : 'display:none' ) . '">'
		. '<div style="font-weight:600;font-size:13px;margin-bottom:6px;color:var(--xr-charcoal)">Mobile poster <span style="font-weight:400;color:#787c82">&middot; recommended 3:4 or 1:1</span></div>'
		. '<div class="xrv-media-preview" style="margin-bottom:8px">' . ( $mobile_url ? '<img src="' . esc_url( $mobile_url ) . '" alt="" style="max-width:150px;height:auto;border-radius:6px;display:block">' : '<span style="color:#787c82;font-size:12px">No mobile image chosen yet.</span>' ) . '</div>'
		. '<input type="hidden" class="xrv-media-id" name="_xrv_mobile_thumb_id" value="' . (int) $mobile_id . '">'
		. '<button type="button" class="button xrv-media-pick">Upload / choose mobile image</button>'
		. ' <button type="button" class="button-link xrv-media-clear" style="color:#b32d2e;margin-left:8px;' . ( $mobile_id ? '' : 'display:none' ) . '">Remove</button>'
		. '</div>';

	echo xrv_help( 'Recommended poster specs', '<p><strong>Primary poster</strong> &mdash; 16:9 landscape, ideally <strong>1280&times;720</strong>. JPG or WebP, a clear focal subject, under ~300&nbsp;KB. WordPress auto-generates desktop and mobile renditions on upload and the grid serves the right size per screen.</p><p><strong>Mobile poster</strong> (optional) &mdash; a 3:4 portrait or 1:1 square framing for phones, where a wide 16:9 image can read small. Turn on the checkbox above to add one; otherwise phones use a smaller version of the primary poster.</p><p>If a video has no poster at all, the <a href="' . $settings_link . '">site default poster</a> set in Settings is used.</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

	echo '<p style="margin:0 0 14px"><label for="_xrv_description" style="display:block;font-weight:600;margin-bottom:4px">Plain-language description (one or two sentences)</label>'
		. '<textarea id="_xrv_description" name="_xrv_description" rows="3" class="widefat" placeholder="A short, plain-language summary of the video.">'
		. esc_textarea( $desc ) . '</textarea></p>';

	// Watch page — a per-video standalone page (this video's own permalink) with the embedded player and
	// VideoObject schema. On by default: the card title links to it, and the card still plays in the modal.
	$watch_on = '0' !== (string) get_post_meta( $post->ID, '_xrv_watch_page', true ); // default on
	echo '<label style="display:flex;align-items:flex-start;gap:8px;font-weight:600;margin:0 0 10px;cursor:pointer">'
		. '<input type="checkbox" id="xrv-watch-toggle" name="_xrv_watch_page" value="1" style="margin-top:3px"' . checked( $watch_on, true, false ) . '>'
		. '<span>Give this video its own watch page <span style="font-weight:400;color:#787c82">&mdash; a standalone page at this video&rsquo;s own URL with the embedded player and VideoObject schema, for SEO and sharing. The card <strong>title</strong> links to it; the card still plays in place. Untick to keep this video gallery-only (its title won&rsquo;t link and its URL won&rsquo;t serve a page).</span></span></label>';

	// The watch page's URL slug, editable here and revealed only when the checkbox is on (gallery-only videos
	// serve no page, so the slug is moot). Reuses the opt-in reveal pattern + the toggle() helper below.
	// 2.11.0: the prefix is the full address, permalink front included (/blog/videos/ on an /blog/ site).
	$pl_base = xrv_permalinks()['single'];
	if ( '' === $pl_base ) { $pl_base = 'video'; }
	$pl_tail = ( '/' === substr( user_trailingslashit( 'x' ), -1 ) ) ? '/' : '';
	echo '<div id="xrv-watch-slug" class="xrv-media-field" style="margin:0 0 16px;padding:12px;border:1px solid var(--xr-line);border-radius:10px;background:#fbfbfd;' . ( $watch_on ? '' : 'display:none' ) . '">'
		. '<label for="xrv_slug" style="display:block;font-weight:600;font-size:13px;margin-bottom:6px">Watch page URL slug</label>'
		. '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">'
		. '<span style="color:#787c82;font-size:12px">' . esc_html( trailingslashit( xrv_video_base_url( $pl_base ) ) ) . '</span>'
		. '<input type="text" id="xrv_slug" name="xrv_slug" value="' . esc_attr( $post->post_name ) . '" style="flex:1;min-width:200px" placeholder="' . esc_attr( sanitize_title( get_the_title( $post ) ) ) . '">'
		. ( '' !== $pl_tail ? '<span style="color:#787c82;font-size:12px">/</span>' : '' )
		. '</div>'
		. '<p style="margin:6px 0 0;color:#787c82;font-size:12px">The address of this video&rsquo;s standalone page. Lowercase letters, numbers, and hyphens; leave blank to auto-generate from the title on save.</p>'
		. '</div>';

	// 2.11.0: a dedicated URL has no field on this screen (it comes from an import or WP-CLI), yet it outranks
	// the watch page checkbox and the slug above. Show it read-only so editors know what the address does.
	$ded_target = xrv_dedicated_target( $post->ID );
	if ( '' !== $ded_target ) {
		$ded_set  = xrv_get_settings();
		$ded_code = ( 302 === (int) $ded_set['dedicated_status'] ) ? 302 : 301;
		if ( xrv_is_same_url( $ded_target, (string) get_permalink( $post->ID ) ) ) {
			$ded_what = esc_html__( 'This is the video\'s own address, so the address has not been handed over yet: the page that lives there today keeps serving it and XRV steps aside (XRV serves it only when nothing else lives there). Hand it over with wp xrv handover.', 'xroad-videos' );
		} else {
			/* translators: %d: HTTP redirect status code, 301 or 302. */
			$ded_what = esc_html( sprintf( __( 'This video\'s own address redirects here with a %d (Settings, Dedicated URL redirect; the xrv_dedicated_redirect_status filter can change it per video), and the gallery card title links here. It takes priority over the watch page checkbox and the slug above.', 'xroad-videos' ), $ded_code ) );
		}
		echo '<div class="xrv-media-field" style="margin:0 0 16px;padding:12px;border:1px solid #f3d199;border-left:4px solid var(--xr-orange);border-radius:10px;background:#fff8ef">'
			. '<div style="font-weight:600;font-size:13px;margin-bottom:6px">' . esc_html__( 'Dedicated URL (read-only)', 'xroad-videos' ) . '</div>'
			. '<p style="margin:0 0 6px;word-break:break-all"><a href="' . esc_url( $ded_target ) . '" target="_blank" rel="noopener">' . esc_html( $ded_target ) . '</a></p>'
			. '<p style="margin:0 0 6px;font-size:12px">' . $ded_what . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
			. '<p style="margin:0;color:#787c82;font-size:12px">' . esc_html__( 'Set by an import or WP-CLI (meta key _xrv_dedicated_url). There is no field for it here.', 'xroad-videos' ) . '</p>'
			. '</div>';
	}

	// Duration — keep the schema-accurate ISO field, but add a friendly minutes:seconds converter + help.
	$dur_human = xrv_iso_to_clock( $dur );
	echo '<p style="margin:0 0 4px"><label for="_xrv_duration_iso" style="display:block;font-weight:600;margin-bottom:4px">Duration <span style="font-weight:400;color:#787c82">(optional &mdash; filled automatically where the host provides it)</span></label>'
		. '<span style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">'
		. '<input type="text" id="xrv-dur-human" value="' . esc_attr( $dur_human ) . '" placeholder="12:30" style="width:120px" inputmode="numeric" aria-label="Duration as minutes and seconds">'
		. '<span style="color:#787c82;font-size:12px">min:sec &rarr;</span>'
		. '<input type="text" id="_xrv_duration_iso" name="_xrv_duration_iso" value="' . esc_attr( $dur ) . '" placeholder="PT12M30S" style="width:150px" aria-label="Duration in ISO 8601 format">'
		. '<span style="color:#787c82;font-size:12px">auto-fills as you type</span>'
		. '</span></p>';
	echo xrv_help( 'What is the ISO 8601 duration, and do I need it?', '<p>Usually you don\'t touch it &mdash; for YouTube, Vimeo and friends it fills in automatically. When you do need it, type the running time as <code>minutes:seconds</code> (or <code>h:mm:ss</code>) in the left box and the ISO value fills itself.</p><p><strong>ISO 8601</strong> is the machine format search engines read for the duration rich result: <code>PT12M30S</code> = 12 min 30 sec, <code>PT1H2M</code> = 1 hr 2 min. Prefer a tool? <a href="https://www.google.com/search?q=time+to+ISO+8601+duration+converter" target="_blank" rel="noopener">Open a converter &rarr;</a></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

	// Upload date: a native date picker beats hand-typing the YYYY-MM-DD format. Printed normalised (an
	// unreadable stored value shows blank instead of breaking the picker) and capped at the SITE-local today.
	echo '<p style="margin:14px 0 14px"><label for="_xrv_upload_date" style="display:block;font-weight:600;margin-bottom:4px">Upload date <span style="font-weight:400;color:#787c82">(optional &mdash; auto where available)</span></label>'
		. '<input type="date" id="_xrv_upload_date" name="_xrv_upload_date" value="' . esc_attr( xrv_normalize_ymd( $upload ) ) . '" max="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></p>';

	echo '<details class="xrv-help xrv-help--form" style="margin:16px 0 4px">'
		. '<summary>Rich video schema <span style="font-weight:400;color:#787c82">(optional &mdash; transcript &amp; key moments for richer Google results / AI citations)</span></summary>'
		. '<div class="xrv-help-body">';

	echo '<p style="margin:0 0 14px"><label for="_xrv_transcript" style="display:block;font-weight:600;margin-bottom:4px">Transcript</label>'
		. '<textarea id="_xrv_transcript" name="_xrv_transcript" rows="6" class="widefat" placeholder="Paste the full transcript. Powers VideoObject.transcript — strong signal for accessibility, Google, and AI answer engines.">'
		. esc_textarea( $transcript ) . '</textarea></p>';

	echo '<p style="margin:0 0 6px"><label for="_xrv_chapters" style="display:block;font-weight:600;margin-bottom:4px">Key moments / chapters</label>'
		. '<textarea id="_xrv_chapters" name="_xrv_chapters" rows="5" class="widefat" placeholder="One per line:&#10;0:00 Introduction&#10;2:15 The diagnosis&#10;9:40 Treatment options">'
		. esc_textarea( $chapters ) . '</textarea></p>';
	echo '<p style="margin:0 0 6px;color:#646970;font-size:12px">One per line as <code>M:SS Label</code> (or <code>H:MM:SS Label</code>). Emits <code>Clip</code> markup so the video can show "key moments" in Google search.</p>';
	echo '</div></details>';

	echo '<p class="xrv-hint" style="margin:14px 0 2px;color:#646970;font-size:12px">Series, Audience, and Topic are set in the taxonomy boxes in the sidebar. The keyword search index is built automatically.</p>';
	echo xrv_help( 'How do I control the order videos appear in galleries?', '<p>Set the <em>Order</em> number under <em>Post Attributes</em> on each video: lower numbers come first, and equal numbers fall back to the publish date, then the post ID. A gallery can instead sort by newest, oldest or title (Settings &rsaquo; Browse defaults &rsaquo; Default order, or <code>orderby</code> on the shortcode). A collection keeps its own order, set with the up and down buttons on its Collections screen.</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

	// Editor sugar: the minutes:seconds -> ISO converter and the two opt-in field reveals. Pure progressive
	// enhancement — with JS off, the ISO field is still typeable and revealed fields default open when set.
	echo <<<'MBJS'
<script>
(function(){
	function toIso(v){
		v=(v||'').trim(); if(!v) return '';
		var p=v.split(':'); for(var i=0;i<p.length;i++){ if(p[i]===''||isNaN(+p[i])) return ''; p[i]=parseInt(p[i],10); }
		var h=0,m=0,s=0;
		if(p.length===3){ h=p[0]; m=p[1]; s=p[2]; }
		else if(p.length===2){ m=p[0]; s=p[1]; }
		else { m=p[0]; }
		m+=Math.floor(s/60); s=s%60; h+=Math.floor(m/60); m=m%60;
		var o='PT'; if(h)o+=h+'H'; if(m)o+=m+'M'; if(s||(!h&&!m))o+=s+'S'; return o;
	}
	var human=document.getElementById('xrv-dur-human'), iso=document.getElementById('_xrv_duration_iso');
	if(human&&iso){ human.addEventListener('input',function(){ var v=toIso(human.value); if(v) iso.value=v; }); }
	function toggle(cbId, fieldId){ var cb=document.getElementById(cbId), f=document.getElementById(fieldId); if(cb&&f){ cb.addEventListener('change',function(){ f.style.display=cb.checked?'':'none'; }); } }
	toggle('xrv-mob-toggle','xrv-mob-field');
	toggle('xrv-watch-toggle','xrv-watch-slug');
})();
</script>
MBJS;
	echo '</div>'; // close .xrv-metabox wrapper
}

add_action( 'save_post_xroad_video', 'xrv_save_meta', 10, 2 );
function xrv_save_meta( $post_id, $post ) {
	if ( ! isset( $_POST['xrv_meta_nonce'] ) || ! wp_verify_nonce( $_POST['xrv_meta_nonce'], 'xrv_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// URLs (read first so provider auto-detection can key off the source URL).
	$source_url = isset( $_POST['_xrv_source_url'] ) ? esc_url_raw( wp_unslash( $_POST['_xrv_source_url'] ) ) : '';
	update_post_meta( $post_id, '_xrv_source_url', $source_url );
	// Watch page: store '0' only when explicitly unticked; absent meta = on. A legacy _xrv_dedicated_url is
	// left untouched (no field posts it anymore) and still wins as the title link where one was set.
	update_post_meta( $post_id, '_xrv_watch_page', isset( $_POST['_xrv_watch_page'] ) ? '1' : '0' );

	// Watch page slug: editable in the meta box. Direct slug-only write (kept unique by WP) so we don't
	// re-enter save_post via wp_update_post. Empty input leaves the existing/auto slug untouched.
	if ( isset( $_POST['xrv_slug'] ) ) {
		$slug = sanitize_title( wp_unslash( $_POST['xrv_slug'] ) );
		if ( '' !== $slug && $slug !== $post->post_name ) {
			$slug = wp_unique_post_slug( $slug, $post_id, $post->post_status, $post->post_type, $post->post_parent );
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		}
	}

	// Flag YouTube Shorts (vertical) from the /shorts/ URL so galleries can render/filter them. Delete when
	// not a short, so the EXISTS / NOT EXISTS meta queries stay clean.
	if ( false !== strpos( $source_url, '/shorts/' ) ) { update_post_meta( $post_id, '_xrv_short', '1' ); }
	else { delete_post_meta( $post_id, '_xrv_short' ); }

	// Provider: an explicit selector choice wins; "auto" (or any unknown value) is detected from the URL.
	$provider = isset( $_POST['_xrv_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['_xrv_provider'] ) ) : 'auto';
	if ( ! in_array( $provider, xrv_providers(), true ) ) {
		$provider = xrv_detect_provider( $source_url );
		if ( '' === $provider ) { $provider = 'youtube'; }
	}
	update_post_meta( $post_id, '_xrv_provider', $provider );

	// Description + manual metadata. Percent-preserving: "50%" and "%20" in a URL survive the save (2.11.0).
	if ( isset( $_POST['_xrv_description'] ) ) {
		update_post_meta( $post_id, '_xrv_description', xrv_sanitize_multiline( wp_unslash( $_POST['_xrv_description'] ) ) );
	}
	if ( isset( $_POST['_xrv_transcript'] ) ) {
		update_post_meta( $post_id, '_xrv_transcript', xrv_sanitize_multiline( wp_unslash( $_POST['_xrv_transcript'] ) ) );
	}
	if ( isset( $_POST['_xrv_chapters'] ) ) {
		update_post_meta( $post_id, '_xrv_chapters', xrv_sanitize_multiline( wp_unslash( $_POST['_xrv_chapters'] ) ) );
	}
	$dur    = isset( $_POST['_xrv_duration_iso'] ) ? sanitize_text_field( wp_unslash( $_POST['_xrv_duration_iso'] ) ) : '';
	// Stored normalised (site-local YYYY-MM-DD, or '' for anything that is not a real date).
	$upload = isset( $_POST['_xrv_upload_date'] ) ? xrv_normalize_ymd( sanitize_text_field( wp_unslash( $_POST['_xrv_upload_date'] ) ) ) : '';

	// Derive the platform ID from the pasted URL. Editors never hand-type IDs. For self-hosted files the
	// media URL itself is the identifier.
	$old_id = (string) get_post_meta( $post_id, '_xrv_video_id', true );
	$new_id = xrv_extract_video_id( $source_url, $provider );
	if ( $new_id !== '' ) {
		update_post_meta( $post_id, '_xrv_video_id', $new_id );
	}
	$id = $new_id !== '' ? $new_id : $old_id;

	// Privacy hash (Vimeo unlisted / domain-private videos: vimeo.com/{id}/{hash}). Stored so the embed
	// can be reconstructed on click; empty for public videos and every other provider.
	$hash = xrv_extract_video_hash( $source_url, $provider );
	if ( '' !== $hash ) {
		update_post_meta( $post_id, '_xrv_video_hash', $hash );
	} elseif ( $new_id !== '' && $new_id !== $old_id ) {
		delete_post_meta( $post_id, '_xrv_video_hash' );
	}

	// No-key oEmbed metadata. Every provider except self-hosted files returns a title + thumbnail with no
	// API key (and, for all but YouTube, duration + description too). Fetched once and reused below to
	// prefill blank fields and to source the local poster, never overwriting an editor-entered value.
	// YouTube is queried only when the title or description is still blank (preserves prior behavior); the
	// other hosts are queried whenever an ID is present, because oEmbed is their poster + duration source.
	$oembed      = array();
	$title_blank = ( $post->post_title === '' || $post->post_title === 'Auto Draft' );
	$desc_blank  = ( ! isset( $_POST['_xrv_description'] ) && get_post_meta( $post_id, '_xrv_description', true ) === '' );
	if ( $id !== '' && 'file' !== $provider && ( 'youtube' !== $provider || $title_blank || $desc_blank ) ) {
		$watch  = $source_url !== '' ? $source_url : xrv_watch_url( $id, $provider );
		$oembed = xrv_fetch_oembed( $watch, $provider );
	}
	if ( ! empty( $oembed['title'] ) && $title_blank ) {
		// Unhook to avoid recursion, update the title, re-hook.
		remove_action( 'save_post_xroad_video', 'xrv_save_meta', 10 );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $oembed['title'] ) ) );
		add_action( 'save_post_xroad_video', 'xrv_save_meta', 10, 2 );
	}
	if ( $desc_blank ) {
		$od = ! empty( $oembed['description'] ) ? $oembed['description'] : ( ! empty( $oembed['title'] ) ? $oembed['title'] : '' );
		if ( '' !== $od ) {
			update_post_meta( $post_id, '_xrv_description', xrv_sanitize_multiline( mb_substr( $od, 0, 5000 ) ) );
		}
	}

	// Duration: an editor-entered ISO value wins; otherwise derive it from the oEmbed duration (seconds)
	// normalized to ISO 8601 so the existing clock + duration sort keep working unchanged.
	if ( '' === $dur && isset( $oembed['duration'] ) && (float) $oembed['duration'] > 0 ) {
		$dur = xrv_seconds_to_iso( (int) round( (float) $oembed['duration'] ) );
	}
	update_post_meta( $post_id, '_xrv_duration_iso', $dur );
	update_post_meta( $post_id, '_xrv_upload_date', $upload );

	// Poster image. The editor may upload/choose a CUSTOM poster (media picker in the meta box); a custom
	// choice is stored verbatim and never overwritten by the auto-sideload. Otherwise a LOCAL thumbnail is
	// sideloaded from the provider when none is stored yet or the video ID changed — the no-Google-call
	// hardening that keeps the rendered grid pointing at /wp-content/uploads/ and fires ZERO requests to
	// i.ytimg.com before a click.
	$is_custom    = isset( $_POST['_xrv_thumb_custom'] ) && '1' === $_POST['_xrv_thumb_custom'];
	$posted_thumb = isset( $_POST['_xrv_local_thumb_id'] ) ? max( 0, (int) wp_unslash( $_POST['_xrv_local_thumb_id'] ) ) : null;

	if ( $is_custom && $posted_thumb ) {
		update_post_meta( $post_id, '_xrv_local_thumb_id', $posted_thumb );
		update_post_meta( $post_id, '_xrv_thumb_custom', '1' );
		set_post_thumbnail( $post_id, $posted_thumb );
	} else {
		delete_post_meta( $post_id, '_xrv_thumb_custom' );
		if ( 0 === $posted_thumb ) {
			// "Reset to automatic": drop the stored poster so it is re-fetched below.
			delete_post_meta( $post_id, '_xrv_local_thumb_id' );
		}
		$thumb_id    = (int) get_post_meta( $post_id, '_xrv_local_thumb_id', true );
		$needs_thumb = $id !== '' && 'file' !== $provider && ( $thumb_id === 0 || $new_id !== $old_id );
		if ( $needs_thumb ) {
			// YouTube has predictable ID-based poster URLs; the other hosts return a thumbnail_url via
			// oEmbed (no third-party thumbnail relay). Self-hosted files have no remote poster, so the
			// editor's uploaded poster (or the site default) stands in.
			$cands  = ( 'youtube' === $provider )
				? xrv_thumb_candidates( $id, 'youtube', false !== strpos( $source_url, '/shorts/' ) )
				: ( ! empty( $oembed['thumbnail_url'] ) ? array( $oembed['thumbnail_url'] ) : array() );
			$attach = xrv_sideload_thumbnail( $post_id, $id, $provider, $cands );
			if ( ! is_wp_error( $attach ) ) {
				update_post_meta( $post_id, '_xrv_local_thumb_id', (int) $attach );
				set_post_thumbnail( $post_id, (int) $attach );
			}
			// On WP_Error the editor's manually uploaded featured image (if any) remains the poster fallback.
		}
	}

	// Optional separate mobile poster (manual only; never auto-fetched). Stored when set, cleared when removed.
	$mobile_thumb = isset( $_POST['_xrv_mobile_thumb_id'] ) ? max( 0, (int) wp_unslash( $_POST['_xrv_mobile_thumb_id'] ) ) : 0;
	if ( $mobile_thumb > 0 ) {
		update_post_meta( $post_id, '_xrv_mobile_thumb_id', $mobile_thumb );
	} else {
		delete_post_meta( $post_id, '_xrv_mobile_thumb_id' );
	}
}

/* -------------------------------------------------------------------------------------------------
 * 9a. EDITOR AUTO-TITLE  (so pasting a URL is enough to save)
 *     The block editor refuses to save a post with an empty title AND empty body, which would leave a
 *     URL-only video as an unsaveable auto-draft and prevent the server-side oEmbed title/thumbnail step
 *     from ever running. This admin script watches the Video URL field and, while the title is still
 *     empty, fetches the same-origin WordPress oEmbed proxy and fills the title — making the post
 *     saveable and giving the editor instant feedback. It NEVER overwrites a title the editor has typed,
 *     and works in both the block editor (wp.data) and the classic editor (#title input).
 * ------------------------------------------------------------------------------------------------- */
add_action( 'admin_enqueue_scripts', 'xrv_admin_autotitle_assets' );
function xrv_admin_autotitle_assets( $hook ) {
	$screen     = get_current_screen();
	$is_edit    = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && 'xroad_video' === $screen->post_type;
	$is_settings = isset( $GLOBALS['xrv_settings_hook'] ) && $hook === $GLOBALS['xrv_settings_hook'];
	if ( ! $is_edit && ! $is_settings ) {
		return;
	}

	if ( $is_edit ) {
		// Dependency-only handle (false src) so we can attach inline JS that runs after these cores load.
		wp_register_script( 'xrv-admin', false, array( 'wp-api-fetch', 'wp-dom-ready', 'wp-data' ), XRV_VERSION, true );
		wp_enqueue_script( 'xrv-admin' );
		wp_add_inline_script( 'xrv-admin', xrv_admin_autotitle_js() );
	}

	// Poster media picker (per-video meta box AND the Settings default). Attaches to core's media-editor
	// handle so wp.media is guaranteed loaded; the script no-ops if it somehow is not.
	wp_enqueue_media();
	wp_add_inline_script( 'media-editor', xrv_media_picker_js() );
}

/* Reusable WordPress media-library picker for any `.xrv-media-field` (hidden `.xrv-media-id` + optional
 * `.xrv-media-custom` flag + `.xrv-media-preview` + pick/clear buttons). Used by the video meta box and
 * the Settings default-poster control. Admin-only; never enqueued on the front end. */
function xrv_media_picker_js() {
	return <<<'JS'
(function(){
	if(!window.wp || !wp.media) return;
	function previewImg(wrap, url){
		var prev = wrap.querySelector('.xrv-media-preview'); if(!prev) return;
		prev.textContent = '';
		if(!url) return;
		var img = document.createElement('img');
		img.src = url; img.alt = '';
		img.style.maxWidth = '220px'; img.style.height = 'auto'; img.style.borderRadius = '4px'; img.style.display = 'block';
		prev.appendChild(img);
	}
	function bind(root){
		Array.prototype.forEach.call(root.querySelectorAll('.xrv-media-pick'), function(btn){
			if(btn.dataset.xrvBound) return; btn.dataset.xrvBound = '1';
			btn.addEventListener('click', function(e){
				e.preventDefault();
				var wrap = btn.closest('.xrv-media-field'); if(!wrap) return;
				var frame = wp.media({ title:'Select or upload a poster image', button:{ text:'Use this image' }, multiple:false, library:{ type:'image' } });
				frame.on('select', function(){
					var a = frame.state().get('selection').first().toJSON();
					var idEl = wrap.querySelector('.xrv-media-id'); if(idEl) idEl.value = a.id;
					var customEl = wrap.querySelector('.xrv-media-custom'); if(customEl) customEl.value = '1';
					var url = (a.sizes && (a.sizes.medium || a.sizes.thumbnail)) ? (a.sizes.medium || a.sizes.thumbnail).url : a.url;
					previewImg(wrap, url);
					var clr = wrap.querySelector('.xrv-media-clear'); if(clr) clr.style.display = '';
				});
				frame.open();
			});
		});
		Array.prototype.forEach.call(root.querySelectorAll('.xrv-file-pick'), function(btn){
			if(btn.dataset.xrvBound) return; btn.dataset.xrvBound = '1';
			btn.addEventListener('click', function(e){
				e.preventDefault();
				var frame = wp.media({ title:'Choose a video file', button:{ text:'Use this video' }, multiple:false, library:{ type:'video' } }); // self-hosted MP4/WebM: one file at a time (multiple:false) — one video per post
				frame.on('select', function(){
					var a = frame.state().get('selection').first().toJSON();
					var u = document.getElementById('_xrv_source_url'); if(u){ u.value = a.url; }
					var p = document.getElementById('_xrv_provider'); if(p){ p.value = 'file'; }
				});
				frame.open();
			});
		});
		Array.prototype.forEach.call(root.querySelectorAll('.xrv-media-clear'), function(btn){
			if(btn.dataset.xrvBound) return; btn.dataset.xrvBound = '1';
			btn.addEventListener('click', function(e){
				e.preventDefault();
				var wrap = btn.closest('.xrv-media-field'); if(!wrap) return;
				var idEl = wrap.querySelector('.xrv-media-id'); if(idEl) idEl.value = '0';
				var customEl = wrap.querySelector('.xrv-media-custom'); if(customEl) customEl.value = '0';
				previewImg(wrap, '');
				btn.style.display = 'none';
			});
		});
	}
	if(document.readyState !== 'loading'){ bind(document); } else { document.addEventListener('DOMContentLoaded', function(){ bind(document); }); }
})();
JS;
}

function xrv_admin_autotitle_js() {
	return <<<'JS'
(function(){
	function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
	ready(function(){
		var input = document.getElementById('_xrv_source_url');
		if(!input) return;
		var busy = false, lastUrl = '';

		function titleIsEmpty(){
			if(window.wp && wp.data && wp.data.select('core/editor')){
				var t = wp.data.select('core/editor').getEditedPostAttribute('title');
				return !t || !t.trim();
			}
			var el = document.getElementById('title');
			return el ? !el.value.trim() : true;
		}
		function setTitle(title){
			if(window.wp && wp.data && wp.data.dispatch('core/editor')){
				wp.data.dispatch('core/editor').editPost({ title: title });
			} else {
				var el = document.getElementById('title');
				if(el){
					el.value = title;
					var wrap = document.getElementById('titlewrap'); if(wrap){ wrap.className = wrap.className.replace('hidden',''); }
					var prompt = document.getElementById('title-prompt-text'); if(prompt){ prompt.style.display = 'none'; }
				}
			}
		}
		function maybeFill(){
			var url = (input.value || '').trim();
			if(!url || url === lastUrl || busy) return;
			if(!titleIsEmpty()) return;                 // never overwrite an editor-entered title
			if(!(window.wp && wp.apiFetch)) return;
			busy = true; lastUrl = url;
			wp.apiFetch({ path: '/oembed/1.0/proxy?url=' + encodeURIComponent(url) + '&format=json' })
				.then(function(o){ if(o && o.title && titleIsEmpty()) setTitle(o.title); })
				.catch(function(){})
				.then(function(){ busy = false; });
		}
		input.addEventListener('change', maybeFill);
		input.addEventListener('blur', maybeFill);
		input.addEventListener('paste', function(){ setTimeout(maybeFill, 60); });
	});
})();
JS;
}

/* =================================================================================================
 * 9c. BULK IMPORTER  (native, zero-dependency — paste URLs / a file / a channel or playlist)
 *     A first-class admin tool under "XRV > Import". Two tiers:
 *       Tier 1 (no setup): paste/upload YouTube video URLs. Title via oEmbed, thumbnail sideloaded.
 *       Tier 2 (optional free YouTube Data API key): pull an entire channel or playlist by URL AND
 *               fill rich metadata (duration, upload date, description) for full VideoObject schema.
 *     Flow: Source -> dry-run Preview (new vs. already-in-library) -> skip/overwrite confirmation ->
 *     batched AJAX import with a progress bar. Reuses xrv_extract_video_id / xrv_fetch_oembed /
 *     xrv_sideload_thumbnail. No WPCode, no SSH, no external dependency.
 * ================================================================================================= */

add_action( 'admin_menu', 'xrv_register_import_page' );
function xrv_register_import_page() {
	add_submenu_page( 'edit.php?post_type=xroad_video', 'Import Videos', 'Import', 'edit_others_posts', 'xrv-import', 'xrv_render_import_page' );
}

/* =================================================================================================
 * 9d. SETTINGS  (Videos -> Settings): site-wide DEFAULTS for every gallery + the YouTube API key.
 *     Shortcode/block attributes ALWAYS override these (see xrv_render). Lets a non-technical admin set
 *     compliance (consent) and browse defaults once, instead of remembering per-shortcode attributes.
 * ================================================================================================= */

function xrv_settings_defaults() {
	return array(
		'consent_notice'  => 'off',
		'consent_text'    => 'This video is hosted by YouTube. Playing it may set cookies on your device.',
		'consent_button'  => 'Load video',
		'consent_decline' => 'No thanks',
		'privacy_url'     => '',
		'playback'        => 'lightbox', // lightbox (all) | lightbox-desktop | lightbox-mobile | inline — see xrv_render
		'lightbox_details' => 1,         // show title + relative date + collapsible description inside the lightbox
		'filter_ui'       => 'select',
		'card_meta'       => 'full',
		'per_page'        => 9,
		'load_more'       => 3,
		'subscribe_url'   => '',
		'subscribe_label' => 'Subscribe to our YouTube channel',
		'shorts_default'  => 'all',
		'default_thumb_id' => 0,
		'icon_color'      => '',
		'icon_hover'      => '',
		'sync_url'        => '',
		'sync_freq'       => 'off',
		'sync_status'     => 'publish',
		'sync_max'        => 25,
		// 2.11.0. Every default reproduces 2.10.0 behaviour except preconnect (a DEFAULT CHANGE: off).
		'hover_style'        => 'zoom',      // zoom | dim | none: the poster's hover / focus treatment
		'card_align'         => 'auto',      // auto (grid left, carousel centred) | left | center
		'card_date'          => 0,           // show the upload date on each card
		'desc_chars'         => 0,           // trim the visible card description to N characters (0 = no trim)
		'show_duration'      => 1,           // duration badge on the poster
		'subscribe_icon'     => 'brand',     // brand (red YouTube mark) | mono (button text colour, triangle cut out)
		'lightbox_desc'      => 'collapsed', // collapsed (4 lines + Show more) | full
		'lightbox_page_link' => 0,           // an "Open video page" link in the lightbox caption
		'thumb_link'         => 'none',      // none (the poster is a play button) | watch (the poster is also a link to the video's page)
		'orderby'            => 'curated',   // curated | newest | oldest | title
		'preconnect'         => 0,           // warm up the video host on hover / focus (opt-in since 2.11.0)
		'dedicated_status'   => 301,         // 301 | 302 for a video's dedicated-URL redirect
		'watch_meta'         => 'full',      // watch-page card text: full | compact | title
		'watch_desc'         => 'plain',     // watch-page description: plain | rich (line breaks + links)
		'sync_since'         => '',          // YYYY-MM-DD: auto-sync skips videos published before this day
	);
}
function xrv_get_settings() {
	return wp_parse_args( (array) get_option( 'xrv_settings', array() ), xrv_settings_defaults() );
}
/* 2.11.0: scalar-safe readers for the settings sanitizer. A missing key falls back to its default, and a
 * non-scalar (an array posted by a crafted form or a manifest) counts as missing, so the sanitizer never
 * throws a PHP 8 TypeError and running it twice gives the same result. */
function xrv_setting_str( $in, $key, $default ) {
	return ( isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ) ? trim( (string) $in[ $key ] ) : (string) $default;
}
function xrv_setting_enum( $in, $key, $allowed, $default ) {
	$v = strtolower( xrv_setting_str( $in, $key, $default ) );
	return in_array( $v, $allowed, true ) ? $v : $default;
}
/* Boolean switch values: '', '0', 'false', 'no', 'off' (any case) and false are off; anything else is on. */
function xrv_is_off( $v ) {
	if ( is_bool( $v ) ) { return ! $v; }
	return ! is_scalar( $v ) || in_array( strtolower( trim( (string) $v ) ), array( '', '0', 'false', 'no', 'off' ), true );
}
function xrv_setting_bool( $in, $key, $default ) {
	if ( ! isset( $in[ $key ] ) || ! ( is_scalar( $in[ $key ] ) ) ) { return $default ? 1 : 0; }
	return xrv_is_off( $in[ $key ] ) ? 0 : 1;
}
function xrv_sanitize_settings( $in ) {
	$d = xrv_settings_defaults(); $in = is_array( $in ) ? $in : array(); $out = array();
	$out['consent_notice']  = xrv_setting_enum( $in, 'consent_notice', array( 'off', 'strict', 'geo' ), $d['consent_notice'] );
	$out['consent_text']    = sanitize_text_field( xrv_setting_str( $in, 'consent_text', $d['consent_text'] ) );
	$out['consent_button']  = sanitize_text_field( xrv_setting_str( $in, 'consent_button', $d['consent_button'] ) );
	$out['consent_decline'] = sanitize_text_field( xrv_setting_str( $in, 'consent_decline', $d['consent_decline'] ) );
	$out['privacy_url']     = esc_url_raw( xrv_setting_str( $in, 'privacy_url', '' ) );
	$out['playback']        = xrv_setting_enum( $in, 'playback', array( 'lightbox', 'lightbox-desktop', 'lightbox-mobile', 'inline' ), $d['playback'] );
	$out['lightbox_details'] = xrv_setting_bool( $in, 'lightbox_details', $d['lightbox_details'] );
	$out['filter_ui']       = xrv_setting_enum( $in, 'filter_ui', array( 'select', 'chips' ), $d['filter_ui'] );
	$out['card_meta']       = xrv_setting_enum( $in, 'card_meta', array( 'full', 'compact', 'title' ), $d['card_meta'] );
	$out['per_page']        = max( 1, (int) xrv_setting_str( $in, 'per_page', $d['per_page'] ) );
	$out['load_more']       = max( 1, (int) xrv_setting_str( $in, 'load_more', $d['load_more'] ) );
	$out['subscribe_url']   = esc_url_raw( xrv_setting_str( $in, 'subscribe_url', '' ) );
	$out['subscribe_label'] = sanitize_text_field( xrv_setting_str( $in, 'subscribe_label', $d['subscribe_label'] ) );
	$out['shorts_default']  = xrv_setting_enum( $in, 'shorts_default', array( 'all', 'only', 'hide' ), $d['shorts_default'] );
	$out['default_thumb_id'] = max( 0, (int) xrv_setting_str( $in, 'default_thumb_id', 0 ) );
	// Play-button color combo: only kept while the override is on, so an unrelated save never pins a color
	// and clobbers a theme's --xrv-primary/--xrv-action brand tokens. The form always posts icon_override
	// (hidden 0 + checkbox 1) and the flag is now stored; input WITHOUT the key (a 2.10.0 stored value, a
	// partial array) keeps whatever colors it carries, so a second pass never wipes them.
	$ic = (string) sanitize_hex_color( xrv_setting_str( $in, 'icon_color', '' ) );
	$ih = (string) sanitize_hex_color( xrv_setting_str( $in, 'icon_hover', '' ) );
	$override = isset( $in['icon_override'] ) ? ! xrv_is_off( $in['icon_override'] ) : ( '' !== $ic || '' !== $ih );
	$out['icon_override']   = $override ? 1 : 0;
	$out['icon_color']      = $override ? $ic : '';
	$out['icon_hover']      = $override ? $ih : '';
	$out['sync_url']        = esc_url_raw( xrv_setting_str( $in, 'sync_url', '' ) );
	$out['sync_freq']       = xrv_setting_enum( $in, 'sync_freq', array( 'off', 'hourly', 'daily', 'weekly', 'monthly' ), $d['sync_freq'] );
	$out['sync_status']     = xrv_setting_enum( $in, 'sync_status', array( 'publish', 'draft' ), $d['sync_status'] );
	$out['sync_max']        = min( 50, max( 1, (int) xrv_setting_str( $in, 'sync_max', $d['sync_max'] ) ) );
	// 2.11.0 keys.
	$out['hover_style']        = xrv_setting_enum( $in, 'hover_style', array( 'zoom', 'dim', 'none' ), $d['hover_style'] );
	$out['card_align']         = xrv_setting_enum( $in, 'card_align', array( 'auto', 'left', 'center' ), $d['card_align'] );
	$out['card_date']          = xrv_setting_bool( $in, 'card_date', $d['card_date'] );
	$out['desc_chars']         = min( 2000, max( 0, (int) xrv_setting_str( $in, 'desc_chars', $d['desc_chars'] ) ) );
	$out['show_duration']      = xrv_setting_bool( $in, 'show_duration', $d['show_duration'] );
	$out['subscribe_icon']     = xrv_setting_enum( $in, 'subscribe_icon', array( 'brand', 'mono' ), $d['subscribe_icon'] );
	$out['lightbox_desc']      = xrv_setting_enum( $in, 'lightbox_desc', array( 'collapsed', 'full' ), $d['lightbox_desc'] );
	$out['lightbox_page_link'] = xrv_setting_bool( $in, 'lightbox_page_link', $d['lightbox_page_link'] );
	$out['thumb_link']         = xrv_setting_enum( $in, 'thumb_link', array( 'none', 'watch' ), $d['thumb_link'] );
	$out['orderby']            = xrv_setting_enum( $in, 'orderby', array( 'curated', 'newest', 'oldest', 'title' ), $d['orderby'] );
	$out['preconnect']         = xrv_setting_bool( $in, 'preconnect', $d['preconnect'] );
	$out['dedicated_status']   = (int) xrv_setting_enum( $in, 'dedicated_status', array( '301', '302' ), (string) $d['dedicated_status'] );
	$out['watch_meta']         = xrv_setting_enum( $in, 'watch_meta', array( 'full', 'compact', 'title' ), $d['watch_meta'] );
	$out['watch_desc']         = xrv_setting_enum( $in, 'watch_desc', array( 'plain', 'rich' ), $d['watch_desc'] );
	$since = xrv_setting_str( $in, 'sync_since', '' );
	$out['sync_since']         = ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) && '' !== xrv_normalize_ymd( $since ) ) ? $since : '';
	return $out;
}
/* 2.11.0: the one way to write a PARTIAL settings array (the WP-CLI apply / rollback path, a migration, a
 * theme). Merges onto the stored settings, then sanitizes the whole set, so keys the caller did not name
 * keep their current values instead of falling back to defaults. */
function xrv_settings_prepare( $partial ) {
	return xrv_sanitize_settings( array_merge( xrv_get_settings(), is_array( $partial ) ? $partial : array() ) );
}
add_action( 'admin_init', 'xrv_register_settings' );
function xrv_register_settings() {
	register_setting( 'xrv_settings_group', 'xrv_settings', array( 'type' => 'array', 'sanitize_callback' => 'xrv_sanitize_settings' ) );
	register_setting( 'xrv_settings_group', 'xrv_yt_api_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
}
add_action( 'admin_menu', 'xrv_register_settings_page' );
function xrv_register_settings_page() {
	$GLOBALS['xrv_settings_hook'] = add_submenu_page( 'edit.php?post_type=xroad_video', 'XRV Settings', 'Settings', 'manage_options', 'xrv-settings', 'xrv_render_settings_page' );
}

/* =================================================================================================
 * 9e. CHANNEL AUTO-SYNC  (poll a channel/playlist for new uploads — on a schedule or on demand)
 *     Reuses the importer's YouTube Data API helpers. Only ADDS videos whose ID is not already in the
 *     library (dedup on _xrv_video_id), so a run is always idempotent and safe. Requires the API key
 *     (the only supported way to enumerate a channel's latest uploads). WP-Cron drives the schedule;
 *     "Sync now" runs it on demand. New videos publish or stay draft per the Settings choice.
 * ================================================================================================= */

/* -------------------------------------------------------------------------------------------------
 * 2.11.0 LIBRARY WRITE LOCK. One writer at a time across channel sync and every WP-CLI run. The lock is
 * one row in the options table, taken with INSERT IGNORE on the unique option_name, so two processes can
 * never both take a free lock (add_option() is not atomic: it upserts). The value names the owner (a CLI
 * run id or a sync token), the command, and a heartbeat the owner refreshes per record. A lock whose
 * heartbeat is older than the stale window (15 minutes, filter xrv_lock_stale_after) can be taken over,
 * so a killed SSH session never wedges sync or the next run. Read straight from the database (never the
 * object cache) so a persistent cache cannot hide a live lock.
 * ------------------------------------------------------------------------------------------------- */
function xrv_lock_read() {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'xrv_lock' ) );
	if ( null === $raw ) {
		return null;
	}
	$val = json_decode( (string) $raw, true );
	return is_array( $val ) ? $val : array( 'owner' => '', 'cmd' => '', 'started' => 0, 'heartbeat' => 0, 'raw' => (string) $raw );
}

/* Take the lock. Returns true (taken, or already ours: a resumed run keeps its run id) or the holder's
 * array {owner, cmd, started, heartbeat} when someone else holds a live lock. */
function xrv_lock_acquire( $owner, $cmd = '' ) {
	global $wpdb;
	$owner = (string) $owner;
	$now   = time();
	$val   = wp_json_encode( array( 'owner' => $owner, 'cmd' => (string) $cmd, 'started' => $now, 'heartbeat' => $now ) );
	$sql   = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
	if ( 1 === (int) $wpdb->query( $wpdb->prepare( $sql, 'xrv_lock', $val ) ) ) {
		return true;
	}
	$cur = xrv_lock_read();
	if ( null === $cur ) { // released between our INSERT and the read: try once more
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, 'xrv_lock', $val ) ) ? true : (array) xrv_lock_read();
	}
	if ( $owner === (string) $cur['owner'] ) {
		xrv_lock_heartbeat( $owner );
		return true;
	}
	$stale = (int) apply_filters( 'xrv_lock_stale_after', 15 * MINUTE_IN_SECONDS );
	if ( ( $now - (int) $cur['heartbeat'] ) < $stale ) {
		return $cur;
	}
	// Stale: delete only the exact row we judged stale (a live owner's heartbeat changes it), then insert.
	$old = isset( $cur['raw'] ) ? $cur['raw'] : (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'xrv_lock' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'xrv_lock', $old ) );
	return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, 'xrv_lock', $val ) ) ? true : (array) xrv_lock_read();
}

/* Refresh the heartbeat. Returns false when the caller no longer owns the lock (it was taken over). */
function xrv_lock_heartbeat( $owner ) {
	global $wpdb;
	$cur = xrv_lock_read();
	if ( ! is_array( $cur ) || (string) $owner !== (string) $cur['owner'] ) {
		return false;
	}
	unset( $cur['raw'] );
	$cur['heartbeat'] = time();
	$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $cur ) ), array( 'option_name' => 'xrv_lock' ) );
	return true;
}

/* Release the lock, only if the caller owns it. */
function xrv_lock_release( $owner ) {
	global $wpdb;
	$cur = xrv_lock_read();
	if ( ! is_array( $cur ) || (string) $owner !== (string) $cur['owner'] ) {
		return false;
	}
	$wpdb->delete( $wpdb->options, array( 'option_name' => 'xrv_lock' ) );
	return true;
}

/* Public entry point (cron and "Sync now"). 2.11.0: a run takes the shared library write lock above under
 * its own owner token, so a cron tick, a "Sync now" click and a WP-CLI run can never insert at the same
 * time. The run heartbeats the lock per inserted video and releases it in a finally block; a run that dies
 * mid-way leaves a lock the next run takes over once it is stale. When another writer holds the lock the
 * run does nothing and returns the last stored result untouched: a busy result is never written to
 * xrv_sync_last. Pass $report_busy = true (Sync now) to get a WP_Error naming the holder instead. Cron
 * passes '' here, which is not true, so a scheduled run always gets the stored result. */
function xrv_sync_run( $report_busy = false ) {
	$owner = 'sync-' . time() . '-' . wp_generate_password( 6, false );
	$got   = xrv_lock_acquire( $owner, 'sync' );
	if ( true !== $got ) {
		if ( true === $report_busy ) {
			return new WP_Error( 'xrv_sync_busy', __( 'Another sync or WP-CLI run is changing the library right now.', 'xroad-videos' ), is_array( $got ) ? $got : array() );
		}
		$last = get_option( 'xrv_sync_last', array() );
		return is_array( $last ) ? $last : array();
	}
	try {
		$res = xrv_sync_perform( $owner );
	} finally {
		xrv_lock_release( $owner );
	}
	return $res;
}

/* The sync engine. Returns (and stores in option xrv_sync_last) a small result array. */
/* Shared YouTube provider-meta write for the importer and channel sync (both key duration/upload/desc
 * identically). ponytail: was a 6-line copy in each; one field set, one place to change. */
function xrv_apply_youtube_meta( $pid, $id, $f ) {
	update_post_meta( $pid, '_xrv_provider', 'youtube' );
	update_post_meta( $pid, '_xrv_video_id', $id );
	// A Short keeps a /shorts/ source URL: the editor's save re-derives the Short flag from the URL, so a
	// watch?v= URL here would silently turn the Short back into a 16:9 card on the next manual save.
	$short = ! empty( $f['is_short'] ) || '1' === (string) get_post_meta( $pid, '_xrv_short', true );
	update_post_meta( $pid, '_xrv_source_url', xrv_youtube_source_url( $id, $short ) );
	if ( ! empty( $f['duration'] ) ) { update_post_meta( $pid, '_xrv_duration_iso', sanitize_text_field( $f['duration'] ) ); }
	$ymd = ! empty( $f['upload'] ) ? xrv_normalize_ymd( sanitize_text_field( $f['upload'] ) ) : '';
	if ( '' !== $ymd )               { update_post_meta( $pid, '_xrv_upload_date', $ymd ); }
	if ( ! empty( $f['desc'] ) )     { update_post_meta( $pid, '_xrv_description', xrv_sanitize_multiline( $f['desc'] ) ); }
	// Watch page: written only when the import record specified it ('0'/'1'); absent = leave as-is.
	$wp = isset( $f['watch_page'] ) ? (string) $f['watch_page'] : '';
	if ( '0' === $wp || '1' === $wp ) { update_post_meta( $pid, '_xrv_watch_page', $wp ); }
}

/** 2.11.0: the stored source URL for a YouTube video: /shorts/{id} for a Short, else watch?v={id}. */
function xrv_youtube_source_url( $id, $is_short = false ) {
	return $is_short ? 'https://www.youtube.com/shorts/' . $id : 'https://www.youtube.com/watch?v=' . $id;
}

/** 2.11.0: flag a video as a Short AND give it the /shorts/ source URL the editor derives the flag from. */
function xrv_mark_short( $pid, $id ) {
	update_post_meta( $pid, '_xrv_short', '1' );
	update_post_meta( $pid, '_xrv_source_url', xrv_youtube_source_url( $id, true ) );
}

/** Detect a Short. The Data API exposes no aspect ratio, so probe the canonical /shorts/ URL: YouTube
 *  303-redirects a non-Short to /watch but serves a Short in place. One HEAD per NEW video, admin-side only.
 *  ponytail: HEAD probe; replace with an API signal if YouTube ever ships one. */
function xrv_yt_is_short( $id ) {
	$r = wp_remote_head( 'https://www.youtube.com/shorts/' . rawurlencode( $id ), array( 'redirection' => 0, 'timeout' => 4 ) );
	return ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
}

/* 2.11.0 gates, per new video (the details call asks for snippet, contentDetails and status):
 *   skipped  = private, unlisted, an upload that failed / was rejected / was deleted, or published before
 *              the optional "since" day. Counted, never inserted.
 *   deferred = not ready yet: still processing (uploadStatus uploaded), live now or upcoming, an unknown
 *              privacy value, or no usable details (the details call failed, or the video is missing from
 *              its response). Nothing is inserted, so the video stays "new" and the next run retries it.
 * An inserted video is dated from publishedAt in the site timezone (post_date) and UTC (post_date_gmt),
 * both clamped to now so a publish never turns into a scheduled post. $owner is the lock token from
 * xrv_sync_run(); the lock is heartbeated per inserted video and the run stops if it was taken over. */
function xrv_sync_perform( $owner = '' ) {
	$s   = xrv_get_settings();
	$key = trim( (string) get_option( 'xrv_yt_api_key', '' ) );
	$url = isset( $s['sync_url'] ) ? trim( (string) $s['sync_url'] ) : '';
	// Every stored result carries every key, success or not, so readers need no isset() guards.
	$res = array( 'time' => current_time( 'mysql' ), 'ok' => false, 'checked' => 0, 'added' => 0, 'titles' => array(), 'msg' => '', 'skipped' => 0, 'deferred' => 0, 'api_msg' => '' );

	if ( '' === $key || '' === $url ) {
		$res['msg'] = __( 'Needs a YouTube Data API key and a channel/playlist URL.', 'xroad-videos' );
		update_option( 'xrv_sync_last', $res ); return $res;
	}

	// Resolve the source to a playlist of uploads (a ?list= URL is already a playlist), then list it.
	if ( preg_match( '#[?&]list=([A-Za-z0-9_-]+)#', $url, $m ) ) { $playlist = $m[1]; }
	else { $playlist = xrv_yt_uploads_playlist( $url, $key ); }
	$max = min( 50, max( 1, (int) ( isset( $s['sync_max'] ) ? $s['sync_max'] : 25 ) ) );
	$ids = is_wp_error( $playlist ) ? $playlist : xrv_yt_playlist_ids( $playlist, $key, $max );
	if ( is_wp_error( $ids ) ) {
		$res['msg']     = $ids->get_error_message();
		$res['api_msg'] = $res['msg'];
		update_option( 'xrv_sync_last', $res ); return $res;
	}
	$ids            = array_slice( array_values( array_unique( $ids ) ), 0, $max );
	$res['checked'] = count( $ids );

	// Which of these are not already in the library?
	global $wpdb;
	$existing = array_flip( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_xrv_video_id'" ) );
	$new      = array();
	foreach ( $ids as $id ) { if ( ! isset( $existing[ $id ] ) ) { $new[] = $id; } }

	$inserted = array();
	if ( $new ) {
		$meta = xrv_yt_videos_meta( $new, $key );
		if ( is_wp_error( $meta ) ) {
			// Never guess: without details a video used to land titled with its raw ID. Insert nothing.
			$res['deferred'] = count( $new );
			$res['api_msg']  = $meta->get_error_message();
			/* translators: %s: the error message returned by the YouTube Data API. */
			$res['msg']      = sprintf( __( 'Could not read video details from YouTube (%s). Nothing was added; the new videos will be retried on the next run.', 'xroad-videos' ), $res['api_msg'] );
			update_option( 'xrv_sync_last', $res ); return $res;
		}
		$status    = 'draft' === strtolower( trim( (string) $s['sync_status'] ) ) ? 'draft' : 'publish';
		$since     = ( is_string( $s['sync_since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s['sync_since'] ) ) ? xrv_normalize_ymd( $s['sync_since'] ) : '';
		$missing   = array();
		$max_order = (int) $wpdb->get_var( "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = 'xroad_video'" );

		foreach ( $new as $i => $id ) {
			$mv  = isset( $meta[ $id ] ) ? $meta[ $id ] : null;
			$day = $mv ? xrv_normalize_ymd( (string) $mv['published_at'] ) : ''; // site-local upload day
			if ( '' === $day ) { $missing[] = $id; $res['deferred']++; continue; }
			$privacy = strtolower( (string) $mv['privacy'] );
			$upload  = strtolower( (string) $mv['upload_status'] );
			$live    = strtolower( (string) $mv['live'] );
			if ( 'private' === $privacy || 'unlisted' === $privacy || in_array( $upload, array( 'failed', 'rejected', 'deleted' ), true ) ) {
				$res['skipped']++; continue;
			}
			if ( 'public' !== $privacy || 'uploaded' === $upload || 'live' === $live || 'upcoming' === $live ) {
				$res['deferred']++; continue;
			}
			// After the deferrals, so a stream is judged on the publishedAt it has once it is over.
			if ( '' !== $since && $day < $since ) { $res['skipped']++; continue; }

			$pts   = (int) strtotime( (string) $mv['published_at'] );
			$when  = min( $pts, time() );
			$title = sanitize_text_field( (string) $mv['title'] );
			$title = '' !== $title ? $title : $id;
			$max_order++;
			$pid = wp_insert_post( array(
				'post_type'     => 'xroad_video',
				'post_status'   => $status,
				'post_title'    => $title,
				'menu_order'    => $max_order,
				'post_date'     => xrv_local_date( 'Y-m-d H:i:s', $when ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
			), true );
			if ( is_wp_error( $pid ) || ! $pid ) { $res['deferred']++; continue; }

			xrv_apply_youtube_meta( $pid, $id, array( 'desc' => $mv['desc'], 'duration' => $mv['duration'], 'upload' => $day ) );
			update_post_meta( $pid, '_xrv_published_at', gmdate( 'Y-m-d\TH:i:s\Z', $pts ) );

			$short = xrv_yt_is_short( $id );
			if ( $short ) { xrv_mark_short( $pid, $id ); }
			$att = xrv_sideload_thumbnail( $pid, $id, 'youtube', $short ? xrv_thumb_candidates( $id, 'youtube', true ) : null );
			if ( ! is_wp_error( $att ) ) { update_post_meta( $pid, '_xrv_local_thumb_id', (int) $att ); set_post_thumbnail( $pid, (int) $att ); }

			$res['added']++;
			$inserted[] = (int) $pid;
			if ( count( $res['titles'] ) < 10 ) { $res['titles'][] = $title; }
			// Still ours? A stale-lock takeover means another writer now owns the library: stop, retry the rest.
			if ( '' !== (string) $owner && ! xrv_lock_heartbeat( $owner ) ) {
				$res['deferred'] += count( $new ) - $i - 1;
				$res['msg']       = __( 'Stopped early: another run took over the library lock. The remaining videos will be retried on the next run.', 'xroad-videos' );
				break;
			}
		}
		if ( $missing ) {
			/* translators: %s: comma-separated YouTube video IDs. */
			$res['api_msg'] = sprintf( __( 'YouTube returned no usable details for %s; retrying on the next run.', 'xroad-videos' ), implode( ', ', array_slice( $missing, 0, 10 ) ) );
		}
	}

	$res['ok'] = true;
	update_option( 'xrv_sync_last', $res );
	if ( $inserted ) {
		/** 2.11.0: the library gained videos (post IDs). */
		do_action( 'xrv_library_changed', $inserted );
	}
	return $res;
}

/* Custom "weekly" cron interval (WP ships only hourly / twicedaily / daily). */
add_filter( 'cron_schedules', 'xrv_cron_schedules' );
function xrv_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Once weekly' );
	}
	if ( ! isset( $schedules['monthly'] ) ) {
		$schedules['monthly'] = array( 'interval' => MONTH_IN_SECONDS, 'display' => 'Once monthly' );
	}
	return $schedules;
}

/* The scheduled event handler. */
add_action( 'xrv_sync_event', 'xrv_sync_run' );

/* (Re)schedule whenever the settings are saved: clear any existing event, then schedule if enabled + configured. */
add_action( 'update_option_xrv_settings', 'xrv_sync_reschedule' );
add_action( 'add_option_xrv_settings', 'xrv_sync_reschedule' );
add_action( 'update_option_xrv_yt_api_key', 'xrv_sync_reschedule' );
// 2.11.0: the first save of a key (add) can enable sync, and removing it (delete fires after the row and
// its cache entry are gone) must stop it.
add_action( 'add_option_xrv_yt_api_key', 'xrv_sync_reschedule' );
add_action( 'delete_option_xrv_yt_api_key', 'xrv_sync_reschedule' );
function xrv_sync_reschedule() {
	$ts = wp_next_scheduled( 'xrv_sync_event' );
	if ( $ts ) { wp_unschedule_event( $ts, 'xrv_sync_event' ); }

	$s     = xrv_get_settings();
	$freqs = array( 'hourly', 'daily', 'weekly', 'monthly' );
	$freq  = isset( $s['sync_freq'] ) ? $s['sync_freq'] : 'off';
	$ready = in_array( $freq, $freqs, true )
		&& '' !== trim( (string) ( isset( $s['sync_url'] ) ? $s['sync_url'] : '' ) )
		&& '' !== (string) get_option( 'xrv_yt_api_key', '' );

	if ( $ready ) { wp_schedule_event( time() + 60, $freq, 'xrv_sync_event' ); }
}

/* On-demand "Sync now" (admin-post action; not a settings save). */
add_action( 'admin_post_xrv_sync_now', 'xrv_sync_now_handler' );
function xrv_sync_now_handler() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
	check_admin_referer( 'xrv_sync_now' );
	// A busy lock is reported on this redirect only; it is never stored in xrv_sync_last.
	$res = xrv_sync_run( true );
	wp_safe_redirect( add_query_arg( 'xrv_synced', is_wp_error( $res ) ? 'busy' : '1', admin_url( 'edit.php?post_type=xroad_video&page=xrv-settings' ) ) );
	exit;
}

/* Clear the scheduled event on deactivation (in addition to the rewrite flush). */
register_deactivation_hook( __FILE__, 'xrv_sync_clear_cron' );
function xrv_sync_clear_cron() {
	$ts = wp_next_scheduled( 'xrv_sync_event' );
	if ( $ts ) { wp_unschedule_event( $ts, 'xrv_sync_event' ); }
	wp_clear_scheduled_hook( 'xrv_sync_event' );
}
/* Crossroad Media horizontal logo, inline so it needs no asset request. Fills are inlined and the
 * gradient IDs are namespaced (xrvlg*) so the markup can't collide with other inline SVGs on the page.
 * Admin chrome only. */
function xrv_brand_logo( $h = 30 ) {
	$h = (int) $h;
	return '<svg viewBox="0 0 413.92 74.44" role="img" aria-label="Crossroad Media" style="height:' . $h . 'px;width:auto;flex:none;display:block" xmlns="http://www.w3.org/2000/svg">'
		. '<defs>'
		. '<linearGradient id="xrvlg1" x1="57.47" y1="118.81" x2="57.47" y2="81.59" gradientTransform="translate(0 118.81) scale(1 -1)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#f7941d"/><stop offset="1" stop-color="#f15a29"/></linearGradient>'
		. '<linearGradient id="xrvlg2" x1="11.79" y1="118.81" x2="11.79" y2="81.57" gradientTransform="translate(0 118.81) scale(1 -1)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#f7941d"/><stop offset="1" stop-color="#f15a29"/></linearGradient>'
		. '</defs>'
		. '<g fill="#424142">'
		. '<path d="M126.15,50.11c-4.36,4.97-9.55,7.49-15.46,7.49s-10.39-1.92-14.17-5.7-5.61-8.66-5.61-14.67,1.89-10.94,5.61-14.67c3.78-3.78,8.55-5.7,14.17-5.7s10.37,2.23,14.02,6.61l.11.15,4.99-4.84-.1-.13c-4.84-5.61-11.25-8.45-19.01-8.45s-14.13,2.63-19.21,7.81c-5.08,5.08-7.66,11.55-7.66,19.21s2.58,14.17,7.66,19.3c5.13,5.13,11.6,7.73,19.21,7.73,4.07,0,7.94-.81,11.49-2.42,3.55-1.61,6.58-3.9,9.03-6.82l.1-.11-5.05-4.9-.13.11Z"/>'
		. '<path d="M155.64,26.57c-2.03,0-4.13.68-6.23,2-2.07,1.31-3.47,2.9-4.16,4.74v-5.74h-6.66v35.53h6.94v-19.55c0-2.63.92-4.95,2.73-6.92s3.94-2.95,6.37-2.95c1.74,0,3.02.16,3.81.48l.18.06,2.11-6.71-.15-.06c-1.34-.58-3-.87-4.94-.87Z"/>'
		. '<path d="M180.55,26.43c-5.24,0-9.65,1.81-13.12,5.37-3.42,3.57-5.15,8.12-5.15,13.54s1.73,9.99,5.15,13.55c3.47,3.57,7.87,5.36,13.12,5.36s9.63-1.81,13.04-5.36c3.47-3.5,5.21-8.07,5.21-13.55s-1.76-9.99-5.21-13.54c-3.4-3.57-7.79-5.37-13.04-5.37ZM191.88,45.33c0,3.73-1.1,6.78-3.26,9.07-2.16,2.29-4.87,3.47-8.07,3.47s-5.9-1.16-8.07-3.47-3.26-5.36-3.26-9.07,1.1-6.7,3.26-9c2.21-2.34,4.92-3.53,8.07-3.53s5.86,1.19,8.07,3.53c2.16,2.31,3.26,5.32,3.26,9Z"/>'
		. '<path d="M222.59,42.45l-5.32-1.37c-3.68-.84-5.53-2.23-5.53-4.15,0-1.18.66-2.19,1.97-3.02,1.34-.84,2.86-1.26,4.55-1.26,1.82,0,3.5.42,4.99,1.24,1.47.82,2.55,1.97,3.19,3.4l.06.15,6.2-2.57-.06-.16c-1.02-2.52-2.82-4.55-5.34-6.03-2.53-1.5-5.39-2.24-8.52-2.24-4.1,0-7.49.98-10.12,2.94-2.65,1.97-3.98,4.6-3.98,7.86,0,4.95,3.5,8.34,10.39,10.07l6.02,1.5c3.36,1.02,5.05,2.63,5.05,4.79,0,1.18-.69,2.21-2.03,3.08-1.37.89-3.11,1.34-5.18,1.34-1.92,0-3.71-.58-5.32-1.73-1.61-1.15-2.87-2.77-3.71-4.84l-.06-.16-6.2,2.65.06.15c1.16,3.03,3.11,5.52,5.81,7.37,2.69,1.86,5.87,2.79,9.44,2.79,4.08,0,7.53-1.08,10.23-3.19,2.71-2.13,4.08-4.78,4.08-7.89-.03-5.37-3.6-8.99-10.65-10.71Z"/>'
		. '<path d="M257.05,42.45l-5.32-1.37c-3.68-.84-5.53-2.23-5.53-4.15,0-1.18.66-2.19,1.97-3.02,1.34-.84,2.86-1.26,4.53-1.26,1.82,0,3.5.42,4.99,1.24,1.47.82,2.55,1.97,3.19,3.4l.06.15,6.2-2.57-.06-.16c-1.02-2.52-2.82-4.55-5.34-6.03-2.53-1.5-5.39-2.24-8.52-2.24-4.08,0-7.49.98-10.12,2.94-2.65,1.97-3.98,4.6-3.98,7.86,0,4.95,3.5,8.34,10.39,10.07l6.02,1.5c3.36,1.02,5.05,2.63,5.05,4.79,0,1.18-.69,2.21-2.03,3.08-1.37.89-3.11,1.34-5.18,1.34-1.92,0-3.71-.58-5.32-1.73-1.61-1.15-2.87-2.77-3.71-4.84l-.06-.16-6.2,2.65.06.15c1.16,3.03,3.11,5.52,5.81,7.37,2.69,1.86,5.87,2.79,9.42,2.79,4.08,0,7.53-1.08,10.23-3.19,2.71-2.13,4.08-4.78,4.08-7.89,0-5.37-3.57-8.99-10.62-10.71Z"/>'
		. '<path d="M291.55,26.57c-2.03,0-4.13.68-6.23,2-2.07,1.31-3.45,2.9-4.15,4.74v-5.74h-6.66v35.53h6.94v-19.55c0-2.63.92-4.95,2.73-6.92s3.94-2.95,6.37-2.95c1.74,0,3.02.16,3.81.48l.18.06,2.11-6.71-.15-.06c-1.36-.58-3.02-.87-4.95-.87Z"/>'
		. '<path d="M316.46,26.43c-5.24,0-9.65,1.81-13.12,5.37-3.42,3.57-5.15,8.12-5.15,13.54s1.73,9.99,5.15,13.55c3.47,3.57,7.87,5.36,13.12,5.36s9.63-1.81,13.04-5.36c3.47-3.5,5.21-8.07,5.21-13.55s-1.76-9.99-5.21-13.54c-3.4-3.57-7.79-5.37-13.04-5.37ZM327.78,45.33c0,3.73-1.1,6.78-3.26,9.07-2.16,2.29-4.87,3.47-8.07,3.47s-5.9-1.16-8.07-3.47-3.26-5.36-3.26-9.07,1.1-6.7,3.26-9c2.21-2.34,4.92-3.53,8.07-3.53s5.86,1.19,8.07,3.53c2.16,2.31,3.26,5.32,3.26,9Z"/>'
		. '<path d="M355.73,26.43c-6.31,0-11.13,2.34-14.36,6.97l-.1.15,6.1,3.84.1-.13c2.11-3.05,5.02-4.6,8.62-4.6,2.39,0,4.5.79,6.28,2.36s2.68,3.48,2.68,5.73v1.23c-2.52-1.36-5.71-2.03-9.52-2.03-4.61,0-8.36,1.1-11.13,3.26-2.77,2.18-4.19,5.15-4.19,8.83,0,3.48,1.34,6.42,3.97,8.74,2.63,2.31,5.94,3.48,9.84,3.48,4.57,0,8.26-2.03,11-6.03h.03v4.89h6.66v-21.84c0-4.57-1.45-8.23-4.29-10.86-2.84-2.63-6.78-3.97-11.68-3.97ZM365.04,47.93c-.02,2.68-1.1,5.05-3.21,7.05-2.13,2.02-4.6,3.03-7.31,3.03-1.92,0-3.61-.56-5.03-1.69-1.42-1.11-2.13-2.52-2.13-4.18,0-1.86.89-3.42,2.61-4.68,1.76-1.26,3.98-1.9,6.61-1.9,3.6.02,6.44.81,8.45,2.37Z"/>'
		. '<path d="M406.97,11.36v16.41l.27,4.69h-.02c-1.16-1.79-2.82-3.26-4.94-4.36-2.15-1.11-4.55-1.68-7.15-1.68-4.61,0-8.65,1.86-11.97,5.52-3.28,3.69-4.92,8.21-4.92,13.39s1.66,9.7,4.92,13.39c3.32,3.66,7.34,5.52,11.97,5.52,2.6,0,5-.56,7.15-1.68,2.11-1.1,3.78-2.57,4.94-4.36h.03v4.89h6.66V11.36h-6.95ZM407.26,45.33c0,3.73-1.06,6.78-3.19,9.08-2.02,2.29-4.65,3.47-7.84,3.47s-5.73-1.19-7.84-3.53c-2.11-2.31-3.18-5.32-3.18-9s1.06-6.65,3.19-9c2.11-2.34,4.74-3.53,7.84-3.53s5.78,1.19,7.84,3.53c2.11,2.34,3.18,5.36,3.18,8.99Z"/>'
		. '</g>'
		. '<g>'
		. '<polygon fill="url(#xrvlg1)" points="69.21 0 48.42 0 45.72 7.65 56.11 37.22 69.21 0"/>'
		. '<polygon fill="#6873b7" points="69.21 74.44 56.11 37.22 45.63 67.05 48.17 74.42 69.21 74.44"/>'
		. '<polygon fill="#342669" points="45.72 7.65 42.09 17.91 35.28 37.22 45.01 65.29 45.63 67.05 56.11 37.22 45.72 7.65"/>'
		. '<polygon fill="#6873b7" points="0 74.44 20.8 74.44 23.49 66.79 13.1 37.24 0 74.44"/>'
		. '<polygon fill="url(#xrvlg2)" points="0 0 13.1 37.24 23.59 7.39 21.04 .02 0 0"/>'
		. '<polygon fill="#342669" points="23.49 66.79 27.12 56.53 33.93 37.24 24.2 9.15 23.59 7.39 13.1 37.24 23.49 66.79"/>'
		. '</g></svg>';
}

/* =================================================================================================
 * 9f. ADMIN UI KIT  (shared Crossroad-brand chrome for every XRV admin screen)
 *     One palette, one card system, one bold-carat Q&A accordion — printed on Settings, Import, and the
 *     per-video editor so the whole plugin reads as one finished product. Admin-only: the front-end
 *     gallery keeps its own per-tenant tokens and never loads any of this. Each admin request renders at
 *     most one of these screens, so printing the stylesheet inline (vs. enqueuing) stays cheap and keeps
 *     every page self-contained.
 * ================================================================================================= */

/* Inline line-icon set (~17px, inherits color). Gives each section header a glanceable anchor. */
function xrv_admin_icon( $name ) {
	$paths = array(
		'shield'  => '<path d="M12 2l8 3v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V5l8-3z"/><path d="M9 12l2 2 4-4"/>',
		'sliders' => '<line x1="4" y1="8" x2="20" y2="8"/><circle cx="9" cy="8" r="2.3"/><line x1="4" y1="16" x2="20" y2="16"/><circle cx="15" cy="16" r="2.3"/>',
		'key'     => '<circle cx="7.6" cy="15.4" r="4"/><path d="M10.4 12.6L20 3M16 3h4v4"/>',
		'image'   => '<rect x="3" y="4.5" width="18" height="15" rx="2.5"/><circle cx="8.4" cy="9.6" r="1.7"/><path d="M21 15.5l-5-5L5 21"/>',
		'sync'    => '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/>',
		'link'    => '<path d="M10 14a4 4 0 0 0 5.66 0l2.83-2.83a4 4 0 1 0-5.66-5.66L11.5 7"/><path d="M14 10a4 4 0 0 0-5.66 0L5.5 12.83a4 4 0 1 0 5.66 5.66L12.5 17"/>',
		'upload'  => '<path d="M12 15V4M7.5 8.5L12 4l4.5 4.5"/><path d="M5 20h14"/>',
		'help'    => '<circle cx="12" cy="12" r="9.2"/><path d="M9.4 9.3a2.6 2.6 0 1 1 3.7 2.4c-1 .5-1.6 1-1.6 2.1"/><circle cx="11.6" cy="16.7" r=".7" fill="currentColor" stroke="none"/>',
		'play'    => '<circle cx="12" cy="12" r="9.2"/><path d="M10 8.4l6 3.6-6 3.6z" fill="currentColor" stroke="none"/>',
	);
	$d = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['help'];
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/* Card section header: icon chip + title, with an optional one-line subtitle below it. */
function xrv_card_head( $id, $icon, $title, $sub = '' ) {
	$h = '<h2 class="title" id="' . esc_attr( $id ) . '"><span class="xrv-ico">' . xrv_admin_icon( $icon ) . '</span>' . esc_html( $title ) . '</h2>';
	if ( '' !== $sub ) { $h .= '<p class="xrv-card-sub">' . wp_kses_post( $sub ) . '</p>'; }
	return $h;
}

/* A bold-carat Q&A accordion. $answer is trusted, admin-authored HTML. */
function xrv_help( $question, $answer, $open = false ) {
	return '<details class="xrv-help"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $question )
		. '</summary><div class="xrv-help-body">' . $answer . '</div></details>';
}

/* Render a whole "Questions & answers" card from a [question => answer-html] map. */
function xrv_faq_card( $id, $title, array $items ) {
	echo '<div class="xrv-card xrv-faq">' . xrv_card_head( $id, 'help', $title, 'Click a question to expand the answer.' );
	foreach ( $items as $q => $a ) { echo xrv_help( $q, $a ); } // phpcs:ignore WordPress.Security.EscapeOutput -- helper escapes the question; answers are static admin copy.
	echo '</div>';
}

/* The premium page lockup: logo + eyebrow + title + subtitle + version chip. */
function xrv_admin_header( $eyebrow, $title_html, $subtitle ) {
	echo '<div class="xrv-hero"><div class="xrv-hero-logo">' . xrv_brand_logo( 34 ) . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput
		. '<div class="xrv-hero-txt"><span class="xrv-eyebrow">' . esc_html( $eyebrow ) . '</span>'
		. '<h1>' . wp_kses_post( $title_html ) . '</h1>'
		. '<p>' . wp_kses_post( $subtitle ) . '</p></div>'
		. '<span class="xrv-badge">v' . esc_html( XRV_VERSION ) . '</span>'
		. '</div>'
		. '<hr class="wp-header-end">'; // WP relocates admin notices to just after this marker.
}

/* The one stylesheet that brands every XRV admin screen. Scoped to .xrv-admin so it can never touch the
 * rest of wp-admin or the front-end gallery (which reuses some of the same class names under .xrv). */
function xrv_admin_css() {
	static $printed = false;
	if ( $printed ) { return ''; }
	$printed = true;
	return <<<'CSS'
<style id="xrv-admin-css">
/* ---- Tokens (Crossroad Media palette) ---- */
.xrv-admin{--xr-purple:#342669;--xr-deep:#2A1F4F;--xr-blue:#6873B7;--xr-light:#E8E3F3;--xr-charcoal:#414042;--xr-warm:#F7F7F7;--xr-line:#e6e3ef;--xr-orange:#F7941D;--xr-orange2:#F15A29;--xr-green:#1a9d57;font-family:'DM Sans',ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
.xrv-admin{max-width:1040px}
.xrv-admin ::selection{background:var(--xr-light)}
/* ---- Hero lockup ---- */
.xrv-admin .xrv-hero{display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin:8px 0 2px;padding:20px 24px;background:linear-gradient(120deg,#fbfaff 0%,#f4f1fb 58%,#efeaf8 100%);border:1px solid var(--xr-line);border-radius:18px;box-shadow:0 1px 2px rgba(42,31,79,.05),0 18px 40px -28px rgba(42,31,79,.45)}
.xrv-admin .xrv-hero-logo{flex:none}
.xrv-admin .xrv-hero-txt{flex:1 1 280px;min-width:0}
.xrv-admin .xrv-eyebrow{display:block;font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--xr-blue);margin:0 0 1px}
.xrv-admin .xrv-hero h1{margin:0;padding:0;font-size:24px;line-height:1.12;font-weight:700;letter-spacing:-.02em;color:var(--xr-deep)}
.xrv-admin .xrv-hero h1 b{color:var(--xr-blue);font-weight:600}
.xrv-admin .xrv-hero p{margin:7px 0 0;max-width:680px;color:#50575e;font-size:13.5px;line-height:1.5}
.xrv-admin .xrv-badge{flex:none;align-self:flex-start;font-size:11px;font-weight:700;letter-spacing:.04em;color:var(--xr-purple);background:#fff;border:1px solid var(--xr-line);border-radius:999px;padding:5px 12px}
/* ---- Sticky pill nav ---- */
.xrv-admin .xrv-nav{position:sticky;top:42px;z-index:9;display:flex;flex-wrap:wrap;gap:8px;margin:16px 0 6px;padding:9px 10px;background:rgba(247,247,250,.85);-webkit-backdrop-filter:saturate(1.5) blur(7px);backdrop-filter:saturate(1.5) blur(7px);border:1px solid var(--xr-line);border-radius:14px}
.xrv-admin .xrv-nav a{text-decoration:none;font-size:12px;font-weight:600;letter-spacing:.01em;color:var(--xr-purple);background:#fff;border:1px solid var(--xr-line);border-radius:999px;padding:6px 13px;display:inline-flex;align-items:center;gap:6px;transition:background .14s ease,color .14s ease,border-color .14s ease,transform .14s ease}
.xrv-admin .xrv-nav a:hover,.xrv-admin .xrv-nav a:focus{background:var(--xr-purple);color:#fff;border-color:var(--xr-purple);transform:translateY(-1px)}
.xrv-admin .xrv-nav a.is-active{background:var(--xr-purple);color:#fff;border-color:var(--xr-purple)}
.xrv-admin .xrv-nav svg{width:13px;height:13px;flex:none}
/* ---- Card system ---- */
.xrv-admin .xrv-card{position:relative;background:#fff;border:1px solid var(--xr-line);border-radius:16px;margin:18px 0;box-shadow:0 1px 2px rgba(42,31,79,.05),0 12px 28px -18px rgba(42,31,79,.22)}
.xrv-admin .xrv-card>*{margin:0;padding-left:26px;padding-right:26px}
.xrv-admin .xrv-card>h2.title{display:flex;align-items:center;gap:11px;padding-top:18px;padding-bottom:14px;margin:0;border:0;border-bottom:1px solid var(--xr-light);border-radius:0;background:transparent;font-size:15px;font-weight:700;letter-spacing:-.01em;color:var(--xr-deep);scroll-margin-top:66px}
.xrv-admin .xrv-card>h2.title .xrv-ico{flex:none;display:inline-flex;width:30px;height:30px;align-items:center;justify-content:center;border-radius:9px;background:var(--xr-light);color:var(--xr-purple)}
.xrv-admin .xrv-card>h2.title .xrv-ico svg{width:17px;height:17px}
.xrv-admin .xrv-card>.xrv-card-sub{padding-top:13px;padding-bottom:0;color:#50575e;font-size:13px;line-height:1.55;max-width:780px}
.xrv-admin .xrv-card>.form-table{padding:8px 0 16px;border:0;background:transparent}
.xrv-admin .xrv-card>p,.xrv-admin .xrv-card>form{padding-top:8px;padding-bottom:14px}
.xrv-admin .xrv-card>.xrv-warn{margin:14px 26px 8px;padding:13px 16px;background:#fff8ef;border:1px solid #f3d199;border-left:4px solid var(--xr-orange);border-radius:10px;font-size:13px;line-height:1.5;color:#5a4a2a}
.xrv-admin .xrv-warn code{background:#fdeccc;color:#7a4f00}
.xrv-admin .xrv-warn ol{padding-left:0}
/* ---- Forms ---- */
.xrv-admin .form-table th{width:220px;color:var(--xr-charcoal);font-weight:600;padding-left:26px}
.xrv-admin .form-table td{padding-right:26px}
.xrv-admin .form-table td:first-child{padding-left:26px}
.xrv-admin .form-table td .description,.xrv-admin .description{color:#646970}
.xrv-admin h2.title{margin:24px 0 6px;font-size:15px;font-weight:700;color:var(--xr-deep)}
.xrv-admin input[type=text],.xrv-admin input[type=url],.xrv-admin input[type=number],.xrv-admin select,.xrv-admin textarea{border-radius:7px}
.xrv-admin input[type=text]:focus,.xrv-admin input[type=url]:focus,.xrv-admin input[type=number]:focus,.xrv-admin select:focus,.xrv-admin textarea:focus{border-color:var(--xr-blue);box-shadow:0 0 0 1px var(--xr-blue)}
.xrv-admin .button,.xrv-admin .button-secondary{border-radius:7px}
.xrv-admin .button-primary{background:var(--xr-purple);border-color:var(--xr-deep);box-shadow:none;text-shadow:none;border-radius:7px;font-weight:600}
.xrv-admin .button-primary:hover,.xrv-admin .button-primary:focus{background:var(--xr-deep);border-color:var(--xr-deep);color:#fff}
.xrv-admin a{color:var(--xr-blue)}
.xrv-admin a:hover{color:var(--xr-purple)}
.xrv-admin .submit{padding-left:26px}
/* ---- Bold-carat Q&A accordion ---- */
.xrv-admin details.xrv-help{margin:12px 0 0;max-width:820px;border:1px solid var(--xr-line);border-radius:12px;background:#fff;transition:border-color .15s ease,box-shadow .15s ease}
.xrv-admin details.xrv-help+details.xrv-help{margin-top:8px}
.xrv-admin details.xrv-help>summary{cursor:pointer;list-style:none;display:flex;align-items:flex-start;gap:11px;padding:12px 16px;font-weight:600;font-size:13px;color:var(--xr-deep);user-select:none}
.xrv-admin details.xrv-help>summary::-webkit-details-marker{display:none}
.xrv-admin details.xrv-help>summary::before{content:"\25BA";font-size:11px;line-height:1.5;color:var(--xr-purple);transition:transform .18s ease;flex:none}
.xrv-admin details.xrv-help[open]>summary::before{transform:rotate(90deg)}
.xrv-admin details.xrv-help>summary:hover{color:var(--xr-purple)}
.xrv-admin details.xrv-help[open]{border-color:var(--xr-blue);box-shadow:0 1px 2px rgba(42,31,79,.05),0 16px 30px -22px rgba(42,31,79,.40)}
.xrv-admin details.xrv-help[open]>summary{border-bottom:1px solid var(--xr-light);color:var(--xr-purple)}
.xrv-admin details.xrv-help>summary~*{padding-left:38px;padding-right:18px;font-size:13px;line-height:1.6;color:#3c4257}
.xrv-admin details.xrv-help>summary+*{padding-top:13px}
.xrv-admin details.xrv-help>*:last-child{padding-bottom:15px}
.xrv-admin .xrv-help-body p{margin:0 0 9px}
.xrv-admin .xrv-help-body p:last-child{margin-bottom:0}
.xrv-admin .xrv-help-body ul{margin:0 0 9px;list-style:disc;padding-left:18px}
.xrv-admin .xrv-help-body code{background:var(--xr-light);color:var(--xr-purple);border-radius:4px;padding:1px 5px;font-size:12px}
.xrv-admin .xrv-faq>details.xrv-help{max-width:none;margin-left:24px;margin-right:24px;padding-left:0;padding-right:0}
.xrv-admin .xrv-faq>details.xrv-help:first-of-type{margin-top:6px}
.xrv-admin .xrv-faq>details.xrv-help:last-of-type{margin-bottom:20px}
.xrv-admin details.xrv-help.xrv-help--form>summary~*{padding-left:16px;padding-right:16px;color:inherit;font-size:13px}
.xrv-admin details.xrv-help.xrv-help--form .description{color:#646970}
/* ---- Edge-check diagnostic ---- */
.xrv-admin #xrv-edge-out{margin-top:12px}
.xrv-admin .xrv-edge-result{border:1px solid var(--xr-line);border-radius:12px;padding:14px 16px;background:#fff;max-width:640px}
.xrv-admin .xrv-edge-flag{font-size:28px;line-height:1;vertical-align:-3px}
.xrv-admin .xrv-edge-ms{font-size:30px;font-weight:700;color:var(--xr-purple);font-variant-numeric:tabular-nums}
.xrv-admin .xrv-edge-duel{margin:12px 0 4px;display:grid;gap:8px;max-width:600px}
.xrv-admin .xrv-edge-row{display:grid;grid-template-columns:140px 1fr;align-items:center;gap:10px;font-size:12px;color:var(--xr-charcoal)}
.xrv-admin .xrv-edge-bar{display:inline-block;height:13px;border-radius:7px;vertical-align:-2px;margin-right:7px}
.xrv-admin .xrv-edge-bar--xrv{background:var(--xr-green);width:8px}
.xrv-admin .xrv-edge-bar--them{background:#d98324;width:300px;max-width:60%}
.xrv-admin .xrv-edge-chips{margin:10px 0 0;display:flex;flex-wrap:wrap;gap:6px}
.xrv-admin .xrv-edge-chip{font-size:11px;font-weight:600;color:#0a6b3c;background:#e7f6ee;border:1px solid #b6e3c8;border-radius:999px;padding:3px 10px}
.xrv-admin .xrv-edge-chip--warn{color:#7a4f00;background:#fff8ef;border-color:#f3d199}
.xrv-admin .xrv-edge-guess button{margin:0 6px 6px 0}
/* ---- Import workflow ---- */
.xrv-admin .xrv-steps{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none}
.xrv-admin .xrv-steps li{display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:600;color:#646970;background:#fff;border:1px solid var(--xr-line);border-radius:999px;padding:6px 14px 6px 7px}
.xrv-admin .xrv-steps li b{display:inline-flex;width:21px;height:21px;align-items:center;justify-content:center;border-radius:50%;background:var(--xr-light);color:var(--xr-purple);font-size:11px;font-weight:700}
.xrv-admin #xrv-imp-results table{max-width:none;border-radius:10px;overflow:hidden}
.xrv-admin .xrv-badge-new{color:#0a6b3c;font-weight:700}
.xrv-admin .xrv-badge-exists{color:#8a6a2a;font-weight:700}
.xrv-admin fieldset{border:1px solid var(--xr-line);border-radius:10px;padding:12px 16px;max-width:none;background:#fbfbfd}
.xrv-admin .xrv-bar-wrap{max-width:none;background:var(--xr-light);border-radius:8px;height:14px;overflow:hidden}
.xrv-admin .xrv-bar{height:100%;width:0;background:linear-gradient(90deg,var(--xr-purple),var(--xr-blue));transition:width .3s}
.xrv-admin .xrv-tag{display:inline-block;margin:2px 4px 2px 0;padding:1px 8px;border-radius:10px;font-size:11px;line-height:1.7;white-space:nowrap}
.xrv-admin .xrv-tag.is-series{background:#e6f4ee;color:#0a6b48}
.xrv-admin .xrv-tag.is-aud{background:#eef1fb;color:#3a4a8c}
.xrv-admin .xrv-tag.is-topic{background:#f3eefb;color:#6b3a8c}
/* ---- Per-video editor (meta box) ---- */
.xrv-admin.xrv-metabox{max-width:760px}
.xrv-admin.xrv-metabox .xrv-media-field{border:1px solid var(--xr-line);border-radius:10px;background:#fbfbfd}
.xrv-admin.xrv-metabox label[for]{color:var(--xr-charcoal)}
@media (max-width:782px){.xrv-admin .xrv-hero{padding:18px}.xrv-admin .form-table th{width:auto}.xrv-admin .form-table td{padding-left:26px}}
</style>
CSS;
}

function xrv_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$s       = xrv_get_settings();
	$key     = (string) get_option( 'xrv_yt_api_key', '' );
	$wp_priv = get_privacy_policy_url();
	?>
	<div class="wrap xrv-admin xrv-settings">
		<?php echo xrv_admin_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static, controlled CSS ?>
		<?php xrv_admin_header( 'Privacy-first video gallery', 'XRV <b>Settings</b>', 'Site-wide <strong>defaults</strong> for every gallery. Anything set directly on a <code>[xroad-videos]</code> shortcode or the block overrides what you choose here.' ); ?>
		<nav class="xrv-nav" aria-label="Settings sections">
			<a href="#xrv-sec-privacy"><?php echo xrv_admin_icon( 'shield' ); ?> Privacy &amp; consent</a>
			<a href="#xrv-sec-browse"><?php echo xrv_admin_icon( 'sliders' ); ?> Browse</a>
			<a href="#xrv-sec-api"><?php echo xrv_admin_icon( 'key' ); ?> API key</a>
			<a href="#xrv-sec-appearance"><?php echo xrv_admin_icon( 'image' ); ?> Appearance</a>
			<a href="#xrv-sec-sync"><?php echo xrv_admin_icon( 'sync' ); ?> Auto-sync</a>
			<a href="#xrv-sec-urls"><?php echo xrv_admin_icon( 'link' ); ?> URLs</a>
			<a href="#xrv-sec-watch"><?php echo xrv_admin_icon( 'play' ); ?> Watch pages</a>
			<a href="#xrv-sec-faq"><?php echo xrv_admin_icon( 'help' ); ?> Help</a>
		</nav>
		<script>
		/* Scroll-spy: highlight the nav pill for the section currently in view. */
		(function(){
			var nav=document.querySelector('.xrv-settings .xrv-nav'); if(!nav||!('IntersectionObserver' in window)) return;
			var links={}, secs=[];
			nav.querySelectorAll('a[href^="#"]').forEach(function(a){ var id=a.getAttribute('href').slice(1); links[id]=a; var s=document.getElementById(id); if(s) secs.push(s); });
			var io=new IntersectionObserver(function(es){ es.forEach(function(e){ if(e.isIntersecting){ Object.keys(links).forEach(function(k){ links[k].classList.toggle('is-active', k===e.target.id); }); } }); }, { rootMargin:'-55% 0px -42% 0px', threshold:0 });
			secs.forEach(function(s){ io.observe(s); });
		})();
		</script>
		<form method="post" action="options.php">
			<?php settings_fields( 'xrv_settings_group' ); ?>

			<div class="xrv-card"><h2 class="title" id="xrv-sec-privacy"><span class="xrv-ico"><?php echo xrv_admin_icon( 'shield' ); ?></span>Privacy &amp; consent</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Edge check</th>
					<td>
						<div id="xrv-edge" data-url="<?php echo esc_url( rest_url( 'xrv/v1/region' ) ); ?>">
							<p class="description" style="margin:0 0 8px;max-width:640px">XRV reads each visitor's region from an edge header that is <strong>already on the request</strong> &mdash; zero extra network calls, zero cookies, the page stays fully cached. Run a live check to watch it work.</p>
							<button type="button" class="button button-secondary" id="xrv-edge-go">&#9889; Run edge check</button>
							<div id="xrv-edge-out" hidden></div>
						</div>
						<script>
						(function(){
							var root = document.getElementById('xrv-edge'); if(!root) return;
							var go = document.getElementById('xrv-edge-go'), out = document.getElementById('xrv-edge-out'), url = root.getAttribute('data-url');
							function flag(cc){ try{ return cc.replace(/./g, function(c){ return String.fromCodePoint(127397 + c.toUpperCase().charCodeAt(0)); }); }catch(e){ return '🌐'; } }
							function rname(cc){ try{ return new Intl.DisplayNames(['en'],{type:'region'}).of(cc) || cc; }catch(e){ return cc; } }
							function bucket(ms){ return ms < 50 ? 'fast' : (ms <= 150 ? 'mid' : 'slow'); }
							var LBL = { fast:'under 50 ms', mid:'50–150 ms', slow:'over 150 ms' };
							function countUp(el, to){ var s=null; (function step(t){ if(s===null)s=t; var p=Math.min(1,(t-s)/600); el.textContent=Math.round(p*to); if(p<1) requestAnimationFrame(step); })(performance.now()); }
							function guessUI(){
								out.hidden = false;
								out.innerHTML = '<div class="xrv-edge-guess"><p style="margin:0 0 6px"><strong>Guess:</strong> how fast will the edge answer?</p>'
									+ '<button type="button" class="button" data-g="fast">Under 50 ms</button>'
									+ '<button type="button" class="button" data-g="mid">50&ndash;150 ms</button>'
									+ '<button type="button" class="button" data-g="slow">Over 150 ms</button></div>';
								Array.prototype.forEach.call(out.querySelectorAll('[data-g]'), function(b){ b.addEventListener('click', function(){ run(b.getAttribute('data-g')); }); });
							}
							function run(guess){
								out.innerHTML = '<p class="description">Pinging the edge&hellip;</p>';
								var t0 = performance.now();
								fetch(url, { credentials:'same-origin', cache:'no-store' }).then(function(r){ return r.json(); }).then(function(d){
									reveal(d || {}, Math.max(1, Math.round(performance.now() - t0)), guess);
								}).catch(function(){ out.innerHTML = '<p style="color:#b32d2e">Could not reach the region endpoint &mdash; check that the REST API is reachable.</p>'; });
							}
							function reveal(d, ms, guess){
								var cc = d.country || '', hit = (guess === bucket(ms));
								var who = cc ? (flag(cc) + ' <strong>' + rname(cc) + '</strong>') : '<strong>no region header on this server yet</strong>';
								out.innerHTML =
									'<div class="xrv-edge-result">'
									+ '<p style="margin:0 0 4px"><span class="xrv-edge-flag">' + (cc?flag(cc):'🌐') + '</span> &nbsp; live edge check answered in <span class="xrv-edge-ms" id="xrv-edge-n">0</span> ms</p>'
									+ '<p class="description" style="margin:0 0 10px">You guessed ' + LBL[guess] + '. ' + (hit?'Nailed it.':'Not quite.') + ' Detected: ' + who + '. <em>This pinged the endpoint once to prove it is live; in production XRV adds no calls at all.</em></p>'
									+ '<div class="xrv-edge-duel">'
									+   '<div class="xrv-edge-row"><span><strong>XRV</strong></span><span><span class="xrv-edge-bar xrv-edge-bar--xrv"></span>0 extra calls/view &middot; 0 cookies &middot; cache intact</span></div>'
									+   '<div class="xrv-edge-row"><span>Typical geo-IP plugin</span><span><span class="xrv-edge-bar xrv-edge-bar--them"></span>1 third-party call/view &middot; sets a cookie &middot; ~150 ms &middot; breaks cache</span></div>'
									+ '</div>'
									+ '<div class="xrv-edge-chips">'
									+   (cc ? '<span class="xrv-edge-chip">0 third-party calls</span><span class="xrv-edge-chip">0 cookies set</span><span class="xrv-edge-chip">page still cached</span>'
										   : '<span class="xrv-edge-chip xrv-edge-chip--warn">No region header &mdash; turn on Cloudflare &rarr; Managed Transforms &rarr; visitor location headers (one toggle)</span>')
									+ '</div>'
									+ '<p style="margin:10px 0 0"><button type="button" class="button-link" id="xrv-edge-again" style="color:var(--xr-purple)">Check again &#8635;</button></p>'
									+ '</div>';
								countUp(document.getElementById('xrv-edge-n'), ms);
								document.getElementById('xrv-edge-again').addEventListener('click', guessUI);
							}
							go.addEventListener('click', guessUI);
						})();
						</script>
					</td>
				</tr>
				<?php if ( 'geo' === $s['consent_notice'] ) : // region diagnostic is only relevant in Global mode ?>
				<tr>
					<th scope="row">Geo source</th>
					<td>
						<?php
						$g = xrv_geo_country();
						if ( '' !== $g['country'] ) {
							$cf_icon = ( 'HTTP_CF_IPCOUNTRY' === $g['header'] )
								? '<svg width="18" height="18" viewBox="0 0 24 24" style="vertical-align:-4px;margin-right:3px" aria-hidden="true"><path fill="#F6821F" d="M19.35 10.04A7.49 7.49 0 0 0 12 4 7.5 7.5 0 0 0 5.04 8.73 6 6 0 0 0 6 20h13a5 5 0 0 0 .35-9.96z"/></svg>'
								: '';
							echo '<p style="margin:.2em 0"><span style="color:#007a53;font-weight:600">&#10003; Detected: ' . esc_html( $g['country'] ) . '</span> via ' . $cf_icon . '<strong>' . esc_html( $g['source'] ) . '</strong> <code>' . esc_html( $g['header'] ) . '</code></p>';
							echo '<p class="description">This request resolved to <strong>' . esc_html( $g['country'] ) . '</strong>, so in <strong>Global</strong> mode a visitor here ' . ( xrv_is_consent_region( $g['country'] ) ? 'would see the opt-in prompt' : 'would play in one click' ) . '. Geolocation is read per-visitor at runtime, so the page stays fully cacheable.</p>';
						} else {
							echo '<p style="margin:.2em 0"><span style="color:#b32d2e;font-weight:600">&#9888; No visitor-country header detected on this server.</span></p>';
							echo '<p class="description">In <strong>Global</strong> mode the EU/UK/EEA prompt safely shows to <em>everyone</em> (fail-safe) when region is unknown. To enable region targeting, turn on a visitor-country header from one of: '
								. '<strong>Cloudflare</strong> &rarr; Rules &rarr; Managed Transforms &rarr; &ldquo;Add visitor location headers&rdquo; (free, one toggle); '
								. '<strong>WP Engine</strong> &rarr; GeoTarget; an <strong>AWS CloudFront</strong> viewer-country header; or an nginx/Apache GeoIP2 module. '
								. 'Or wire your own logic via the <code>xrv_consent_required</code> filter. <em>Strict GDPR needs no geo header; it prompts everyone.</em></p>';
						}
						?>
						<p class="description"><a href="<?php echo esc_url( rest_url( 'xrv/v1/region' ) ); ?>" target="_blank" rel="noopener">Test the live region endpoint &rarr;</a></p>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="xrv-consent">Consent mode</label></th>
					<td>
						<select id="xrv-consent" name="xrv_settings[consent_notice]">
							<option value="geo"    <?php selected( $s['consent_notice'], 'geo' ); ?>>Global (respects regional privacy laws including GDPR and CCPA) - recommended</option>
							<option value="strict" <?php selected( $s['consent_notice'], 'strict' ); ?>>Strict GDPR (opt-in prompt for every visitor)</option>
							<option value="off"    <?php selected( $s['consent_notice'], 'off' ); ?>>Facade only (no prompt)</option>
						</select>
						<p class="description" style="max-width:760px">
							<?php echo wp_kses_post( __( '<strong>All three modes are cookie-free until the click.</strong> With the default settings the player makes no request, cookie, or connection to YouTube until a visitor presses play. (The opt-in <em>Warm-up on hover</em> below is the one exception: it opens a connection, with no cookies, when a visitor hovers or tabs to a video.) This setting only adds an extra consent prompt on top of that, so &ldquo;Facade only&rdquo; is still privacy-safe; it just skips the prompt.', 'xroad-videos' ) ); ?>
						</p>
						<details class="xrv-help">
							<summary>How the modes differ (GDPR / CCPA)</summary>
						<ul class="description" style="max-width:760px;list-style:disc;margin-left:1.4em">
							<li><?php echo wp_kses_post( __( '<strong>Global</strong> (recommended): adapts to the visitor\'s region. Visitors in the EU, UK, EEA, or Switzerland get a dismissible opt-in "Load video" prompt before anything loads (GDPR / ePrivacy), and their browser makes <strong>zero</strong> contact with any Google domain until they accept (<em>Warm-up on hover</em> is suppressed for them even when it is on). Everyone else, including US / California visitors, plays in one click; because the facade shares no data with YouTube until that click, this meets the US notice-and-opt-out model (CCPA / CPRA) without adding friction. Region is detected from an edge country header (see <strong>Geo source</strong> above).', 'xroad-videos' ) ); ?></li>
							<li><strong>Strict GDPR</strong>: the dismissible opt-in prompt and zero-contact guarantee for <strong>every</strong> visitor worldwide, regardless of region. The most defensible posture; slightly slower first play.</li>
							<li><?php echo wp_kses_post( __( '<strong>Facade only</strong>: no prompt or notice. The click-to-load facade still applies (no YouTube contact until a click, or until a hover if <em>Warm-up on hover</em> is on), but the plugin adds no consent layer. Use only where you handle consent elsewhere or do not serve regulated regions.', 'xroad-videos' ) ); ?></li>
						</ul>
						<p class="description" style="max-width:760px">
							The opt-in prompt is declinable (× or "No thanks"), so refusing is as easy as accepting, and the click is the consent that loads the embed. Global mode reads a visitor-country header from your edge/CDN; if none is present it fails safe by prompting everyone, and the <code>xrv_consent_required</code> filter can override the region logic.<br>
							<em>Informational only, not legal advice. Cookies and tags from your analytics, ads, and consent manager are governed by those tools, not this plugin.</em>
						</p>
						</details>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Warm-up on hover', 'xroad-videos' ); ?></th>
					<td>
						<input type="hidden" name="xrv_settings[preconnect]" value="0">
						<label><input type="checkbox" name="xrv_settings[preconnect]" value="1" <?php checked( ! empty( $s['preconnect'] ) ); ?>> <?php esc_html_e( 'Open a connection to the video host when a visitor hovers or tabs to a video', 'xroad-videos' ); ?></label>
						<p class="description" style="max-width:760px"><?php esc_html_e( 'Off by default (2.11.0). When on, hovering a card, or moving keyboard focus onto it, makes the browser open a DNS and TLS connection to the video host (for example youtube-nocookie.com) a moment before the click, so the player starts slightly faster. No cookies are sent, but the host does see the visitor\'s IP address before any click. It is never made while a consent prompt is required (Strict, or Global for EU / UK / EEA / Swiss visitors). Leave it off for a strict zero-contact-before-click posture.', 'xroad-videos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-ctext">Notice text</label></th>
					<td><input type="text" id="xrv-ctext" name="xrv_settings[consent_text]" value="<?php echo esc_attr( $s['consent_text'] ); ?>" class="large-text">
					<p class="description">Shown in the consent prompt.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-cbtn">Accept button label</label></th>
					<td><input type="text" id="xrv-cbtn" name="xrv_settings[consent_button]" value="<?php echo esc_attr( $s['consent_button'] ); ?>" class="regular-text"> <span class="description">used by the consent prompt</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-cdecline">Decline button label</label></th>
					<td><input type="text" id="xrv-cdecline" name="xrv_settings[consent_decline]" value="<?php echo esc_attr( $s['consent_decline'] ); ?>" class="regular-text"> <span class="description">the “No thanks” option on the prompt</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-priv">Privacy policy URL</label></th>
					<td><input type="url" id="xrv-priv" name="xrv_settings[privacy_url]" value="<?php echo esc_attr( $s['privacy_url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $wp_priv ? $wp_priv : 'https://example.org/privacy-policy/' ); ?>">
					<p class="description"><?php echo $wp_priv ? 'Leave blank to use your WordPress privacy page: ' . esc_html( $wp_priv ) : 'Leave blank to use your WordPress privacy page (none set yet).'; ?></p></td>
				</tr>
			</table>

			</div>
				<div class="xrv-card"><h2 class="title" id="xrv-sec-browse"><span class="xrv-ico"><?php echo xrv_admin_icon( 'sliders' ); ?></span>Browse defaults</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="xrv-playback">Play videos in</label></th>
					<td>
						<select id="xrv-playback" name="xrv_settings[playback]">
							<option value="lightbox"         <?php selected( $s['playback'], 'lightbox' ); ?>>A pop-out lightbox — all devices</option>
							<option value="lightbox-desktop" <?php selected( $s['playback'], 'lightbox-desktop' ); ?>>Lightbox on desktop, inline on mobile</option>
							<option value="lightbox-mobile"  <?php selected( $s['playback'], 'lightbox-mobile' ); ?>>Lightbox on mobile, inline on desktop</option>
							<option value="inline"           <?php selected( $s['playback'], 'inline' ); ?>>Inline, in the card — all devices</option>
						</select>
						<p class="description" style="max-width:760px">A <strong>lightbox</strong> pops the player into a centered overlay over a dimmed page; <strong>inline</strong> swaps the player into the card in place. The desktop / mobile split is decided in the visitor's browser by screen width (under 768&nbsp;px is treated as mobile), so the page stays fully cached. A <code>[xroad-videos]</code> shortcode or block can override this per gallery.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Lightbox details</th>
					<td>
						<input type="hidden" name="xrv_settings[lightbox_details]" value="0">
						<label><input type="checkbox" name="xrv_settings[lightbox_details]" value="1" <?php checked( ! empty( $s['lightbox_details'] ) ); ?>> Show the title, date &amp; a collapsible description below the player in the lightbox</label>
						<p class="description" style="max-width:760px"><?php echo wp_kses_post( __( 'Mirrors the watch-page caption (title, &ldquo;2 months ago&rdquo;, and the description). Applies to lightbox playback only; inline playback already shows this beneath the card.', 'xroad-videos' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-lbdesc"><?php esc_html_e( 'Lightbox description', 'xroad-videos' ); ?></label></th>
					<td>
						<select id="xrv-lbdesc" name="xrv_settings[lightbox_desc]">
							<option value="collapsed" <?php selected( $s['lightbox_desc'], 'collapsed' ); ?>><?php esc_html_e( 'Four lines, with Show more / Show less', 'xroad-videos' ); ?></option>
							<option value="full"      <?php selected( $s['lightbox_desc'], 'full' ); ?>><?php esc_html_e( 'The whole description', 'xroad-videos' ); ?></option>
						</select>
						<p style="margin:10px 0 0"><input type="hidden" name="xrv_settings[lightbox_page_link]" value="0"><label><input type="checkbox" name="xrv_settings[lightbox_page_link]" value="1" <?php checked( ! empty( $s['lightbox_page_link'] ) ); ?>> <?php esc_html_e( 'Add an "Open video page" link to the lightbox caption', 'xroad-videos' ); ?></label></p>
						<p class="description" style="max-width:760px"><?php esc_html_e( 'The link goes where the card title goes: the video\'s watch page, or its dedicated URL. Videos with neither show no link.', 'xroad-videos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-orderby"><?php esc_html_e( 'Default order', 'xroad-videos' ); ?></label></th>
					<td>
						<select id="xrv-orderby" name="xrv_settings[orderby]">
							<option value="curated" <?php selected( $s['orderby'], 'curated' ); ?>><?php esc_html_e( 'Curated (each video\'s Order number, or the collection\'s order)', 'xroad-videos' ); ?></option>
							<option value="newest"  <?php selected( $s['orderby'], 'newest' ); ?>><?php esc_html_e( 'Newest first', 'xroad-videos' ); ?></option>
							<option value="oldest"  <?php selected( $s['orderby'], 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'xroad-videos' ); ?></option>
							<option value="title"   <?php selected( $s['orderby'], 'title' ); ?>><?php esc_html_e( 'Title (A to Z)', 'xroad-videos' ); ?></option>
						</select>
						<p class="description" style="max-width:760px"><?php esc_html_e( 'The order a gallery is printed in, before a visitor touches the Sort menu. Newest and oldest use each video\'s upload date (or its post date when none is set), then its exact publish time. The Sort menu starts on this choice and Reset returns to it. Override per gallery with orderby="newest" or a collection\'s own order.', 'xroad-videos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-thumblink"><?php esc_html_e( 'Poster click', 'xroad-videos' ); ?></label></th>
					<td>
						<select id="xrv-thumblink" name="xrv_settings[thumb_link]">
							<option value="none"  <?php selected( $s['thumb_link'], 'none' ); ?>><?php esc_html_e( 'Plays the video', 'xroad-videos' ); ?></option>
							<option value="watch" <?php selected( $s['thumb_link'], 'watch' ); ?>><?php esc_html_e( 'Plays the video, and is also a link to the video\'s page', 'xroad-videos' ); ?></option>
						</select>
						<p class="description" style="max-width:760px"><?php esc_html_e( 'With the link option the poster points to the same page as the title. A plain click still plays the video; Ctrl / Cmd-click, a middle click, or "Open in new tab" opens the page. Videos with no page to link to keep a plain play button.', 'xroad-videos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Filter style</th>
					<td>
						<label style="margin-right:18px"><input type="radio" name="xrv_settings[filter_ui]" value="select" <?php checked( $s['filter_ui'], 'select' ); ?>> Dropdown selects</label>
						<label><input type="radio" name="xrv_settings[filter_ui]" value="chips" <?php checked( $s['filter_ui'], 'chips' ); ?>> Clickable chips</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-cardmeta">Card text</label></th>
					<td>
						<select id="xrv-cardmeta" name="xrv_settings[card_meta]">
							<option value="full"    <?php selected( $s['card_meta'], 'full' ); ?>>Full — title + description + tags</option>
							<option value="compact" <?php selected( $s['card_meta'], 'compact' ); ?>>Compact — title + description</option>
							<option value="title"   <?php selected( $s['card_meta'], 'title' ); ?>>Title only — thumbnail + title (matches a stock YouTube grid)</option>
						</select>
						<p class="description">What shows beneath each video thumbnail. “Title only” gives the cleanest, feed-style grid.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Card extras', 'xroad-videos' ); ?></th>
					<td>
						<input type="hidden" name="xrv_settings[card_date]" value="0">
						<label style="display:block;margin-bottom:6px"><input type="checkbox" name="xrv_settings[card_date]" value="1" <?php checked( ! empty( $s['card_date'] ) ); ?>> <?php esc_html_e( 'Show the upload date under each title', 'xroad-videos' ); ?></label>
						<input type="hidden" name="xrv_settings[show_duration]" value="0">
						<label style="display:block"><input type="checkbox" name="xrv_settings[show_duration]" value="1" <?php checked( ! empty( $s['show_duration'] ) ); ?>> <?php esc_html_e( 'Show the running time on each poster', 'xroad-videos' ); ?></label>
						<p class="description" style="max-width:760px"><?php esc_html_e( 'The date uses the video\'s upload date, or its post date when none is set, and shows with any Card text choice. Hiding the running time keeps the Shortest / Longest sort working.', 'xroad-videos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-descchars"><?php esc_html_e( 'Trim card descriptions', 'xroad-videos' ); ?></label></th>
					<td><input type="number" min="0" max="2000" id="xrv-descchars" name="xrv_settings[desc_chars]" value="<?php echo (int) $s['desc_chars']; ?>" class="small-text"> <?php esc_html_e( 'characters (0 = no trim)', 'xroad-videos' ); ?>
					<p class="description" style="max-width:760px"><?php esc_html_e( 'Cuts the description shown on each card at a word boundary and adds "…". The lightbox and the watch page still show the full text. Screen-reader users also hear only the trimmed text on the card, so keep it generous, or leave it at 0 if the description carries something visitors need.', 'xroad-videos' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-pp">Show before “Load more”</label></th>
					<td><input type="number" min="1" id="xrv-pp" name="xrv_settings[per_page]" value="<?php echo (int) $s['per_page']; ?>" class="small-text"> videos</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-lm">“Load more” reveals</label></th>
					<td><input type="number" min="1" id="xrv-lm" name="xrv_settings[load_more]" value="<?php echo (int) $s['load_more']; ?>" class="small-text"> more per click</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-sub">Subscribe button URL</label></th>
					<td><input type="url" id="xrv-sub" name="xrv_settings[subscribe_url]" value="<?php echo esc_attr( $s['subscribe_url'] ); ?>" class="regular-text" placeholder="https://youtube.com/@yourchannel">
					<p class="description">When set, a Subscribe button appears under the grid.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-sublabel">Subscribe button label</label></th>
					<td><input type="text" id="xrv-sublabel" name="xrv_settings[subscribe_label]" value="<?php echo esc_attr( $s['subscribe_label'] ); ?>" class="regular-text"></td>
				</tr>
			</table>

			</div>
				<div class="xrv-card"><h2 class="title" id="xrv-sec-api"><span class="xrv-ico"><?php echo xrv_admin_icon( 'key' ); ?></span>YouTube Data API key <span style="font-weight:500;color:#787c82;letter-spacing:0">(optional)</span></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="xrv-key">API key</label></th>
					<td><input type="text" id="xrv-key" name="xrv_yt_api_key" value="<?php echo esc_attr( $key ); ?>" class="regular-text" autocomplete="off">
					<p class="description">Only needed to import an entire channel/playlist with durations &amp; descriptions, and for <strong>auto-sync</strong> below. Pasting URLs or a JSON file needs no key. Used by <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=xroad_video&page=xrv-import' ) ); ?>">Import</a>.</p></td>
				</tr>
			</table>

			</div>
				<div class="xrv-card"><h2 class="title" id="xrv-sec-appearance"><span class="xrv-ico"><?php echo xrv_admin_icon( 'image' ); ?></span>Appearance</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Default poster</th>
					<td>
						<?php
						$def_id  = (int) $s['default_thumb_id'];
						$def_url = $def_id ? wp_get_attachment_image_url( $def_id, 'medium' ) : '';
						?>
						<div class="xrv-media-field">
							<div class="xrv-media-preview" style="margin-bottom:8px"><?php if ( $def_url ) { echo '<img src="' . esc_url( $def_url ) . '" alt="" style="max-width:220px;height:auto;border-radius:4px;display:block">'; } ?></div>
							<input type="hidden" class="xrv-media-id" name="xrv_settings[default_thumb_id]" value="<?php echo (int) $def_id; ?>">
							<button type="button" class="button xrv-media-pick">Upload / choose image</button>
							<button type="button" class="button-link xrv-media-clear" style="color:#b32d2e;margin-left:8px;<?php echo $def_id ? '' : 'display:none'; ?>">Remove</button>
							<p class="description">Shown for any video that has no YouTube thumbnail and no custom poster &mdash; for example a video added before its thumbnail finished downloading. A neutral, branded placeholder works best.</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">Play button color</th>
					<td>
						<?php
						$icon_on = ( '' !== $s['icon_color'] || '' !== $s['icon_hover'] );
						$ic = '' !== $s['icon_color'] ? $s['icon_color'] : '#013C60';
						$ih = '' !== $s['icon_hover'] ? $s['icon_hover'] : '#007A53';
						?>
						<input type="hidden" name="xrv_settings[icon_override]" value="0">
						<label style="display:block;margin-bottom:10px"><input type="checkbox" name="xrv_settings[icon_override]" value="1" <?php checked( $icon_on ); ?>> Override the play-button color</label>
						<label style="display:inline-flex;align-items:center;gap:8px;margin-right:24px">Idle <input type="color" name="xrv_settings[icon_color]" value="<?php echo esc_attr( $ic ); ?>"></label>
						<label style="display:inline-flex;align-items:center;gap:8px">Hover <input type="color" name="xrv_settings[icon_hover]" value="<?php echo esc_attr( $ih ); ?>"></label>
						<p class="description"><?php echo wp_kses_post( __( 'Colors the click-to-load play button (the YouTube icon) for its idle and hover states. Leave the box unticked to inherit your theme\'s brand colors (defaults: navy <code>#013C60</code> / green <code>#007A53</code>). The white play triangle is unchanged. Applies to every gallery and single embed on the site. The hover color is used by the <strong>Zoom</strong> hover effect only; <strong>Dim</strong> and <strong>None</strong> keep the idle color.', 'xroad-videos' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-hover"><?php esc_html_e( 'Hover effect', 'xroad-videos' ); ?></label></th>
					<td>
						<select id="xrv-hover" name="xrv_settings[hover_style]">
							<option value="zoom" <?php selected( $s['hover_style'], 'zoom' ); ?>><?php esc_html_e( 'Zoom the poster and the play button', 'xroad-videos' ); ?></option>
							<option value="dim"  <?php selected( $s['hover_style'], 'dim' ); ?>><?php esc_html_e( 'Dim the poster', 'xroad-videos' ); ?></option>
							<option value="none" <?php selected( $s['hover_style'], 'none' ); ?>><?php esc_html_e( 'None', 'xroad-videos' ); ?></option>
						</select>
						<p class="description" style="max-width:760px"><?php esc_html_e( 'Used on mouse hover and on keyboard focus. Visitors whose system asks for reduced motion get no zoom and no fade.', 'xroad-videos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-align"><?php esc_html_e( 'Card text alignment', 'xroad-videos' ); ?></label></th>
					<td>
						<select id="xrv-align" name="xrv_settings[card_align]">
							<option value="auto"   <?php selected( $s['card_align'], 'auto' ); ?>><?php esc_html_e( 'Automatic: left in grids, centred in carousels', 'xroad-videos' ); ?></option>
							<option value="left"   <?php selected( $s['card_align'], 'left' ); ?>><?php esc_html_e( 'Left everywhere', 'xroad-videos' ); ?></option>
							<option value="center" <?php selected( $s['card_align'], 'center' ); ?>><?php esc_html_e( 'Centred everywhere', 'xroad-videos' ); ?></option>
						</select>
						<p class="description" style="max-width:760px"><?php esc_html_e( 'Aligns the title, date, description and tags under each poster.', 'xroad-videos' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-subicon"><?php esc_html_e( 'Subscribe button icon', 'xroad-videos' ); ?></label></th>
					<td>
						<select id="xrv-subicon" name="xrv_settings[subscribe_icon]">
							<option value="brand" <?php selected( $s['subscribe_icon'], 'brand' ); ?>><?php esc_html_e( 'YouTube red', 'xroad-videos' ); ?></option>
							<option value="mono"  <?php selected( $s['subscribe_icon'], 'mono' ); ?>><?php esc_html_e( 'Match the button text color (play triangle cut out)', 'xroad-videos' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
							<th scope="row"><label for="xrv-shorts">Shorts in galleries</label></th>
							<td>
								<select id="xrv-shorts" name="xrv_settings[shorts_default]">
									<option value="all"  <?php selected( $s['shorts_default'], 'all' ); ?>>Show alongside regular videos</option>
									<option value="only" <?php selected( $s['shorts_default'], 'only' ); ?>>Shorts only (vertical shelf)</option>
									<option value="hide" <?php selected( $s['shorts_default'], 'hide' ); ?>>Hide Shorts</option>
								</select>
								<p class="description">Default for a bare <code>[xroad-videos]</code>. A YouTube <strong>Short</strong> is auto-detected from its <code>/shorts/</code> URL, rendered vertical (9:16), and plays inline in its card. Override per gallery with <code>[xroad-videos shorts="only"]</code>, <code>"hide"</code>, or <code>"all"</code>.</p>
							</td>
						</tr>
					</table>
				</div>
				<div class="xrv-card"><?php echo xrv_card_head( 'xrv-sec-watchdisplay', 'play', __( 'Watch page display', 'xroad-videos' ), esc_html__( 'How each video\'s own page looks, and how a video with a dedicated URL redirects. Galleries use the Browse defaults above.', 'xroad-videos' ) ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xrv-watchmeta"><?php esc_html_e( 'Text under the player', 'xroad-videos' ); ?></label></th>
						<td>
							<select id="xrv-watchmeta" name="xrv_settings[watch_meta]">
								<option value="full"    <?php selected( $s['watch_meta'], 'full' ); ?>><?php esc_html_e( 'Full: title, description and tags', 'xroad-videos' ); ?></option>
								<option value="compact" <?php selected( $s['watch_meta'], 'compact' ); ?>><?php esc_html_e( 'Compact: title and description', 'xroad-videos' ); ?></option>
								<option value="title"   <?php selected( $s['watch_meta'], 'title' ); ?>><?php esc_html_e( 'Title only', 'xroad-videos' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xrv-watchdesc"><?php esc_html_e( 'Description', 'xroad-videos' ); ?></label></th>
						<td>
							<select id="xrv-watchdesc" name="xrv_settings[watch_desc]">
								<option value="plain" <?php selected( $s['watch_desc'], 'plain' ); ?>><?php esc_html_e( 'Plain text', 'xroad-videos' ); ?></option>
								<option value="rich"  <?php selected( $s['watch_desc'], 'rich' ); ?>><?php esc_html_e( 'Rich: keep line breaks and make web addresses clickable', 'xroad-videos' ); ?></option>
							</select>
							<p class="description" style="max-width:760px"><?php esc_html_e( 'Rich links to other sites get rel="nofollow noopener". Shortcodes typed into a description never run, in either mode.', 'xroad-videos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xrv-dedstatus"><?php esc_html_e( 'Dedicated URL redirect', 'xroad-videos' ); ?></label></th>
						<td>
							<select id="xrv-dedstatus" name="xrv_settings[dedicated_status]">
								<option value="301" <?php selected( (int) $s['dedicated_status'], 301 ); ?>><?php esc_html_e( '301 Permanent', 'xroad-videos' ); ?></option>
								<option value="302" <?php selected( (int) $s['dedicated_status'], 302 ); ?>><?php esc_html_e( '302 Temporary', 'xroad-videos' ); ?></option>
							</select>
							<p class="description" style="max-width:760px"><?php esc_html_e( 'When a video has a dedicated URL, the video\'s own address redirects there. Use 302 while a migration is in progress (browsers and search engines do not cache it), and 301 once the URLs are final. The xrv_dedicated_redirect_status filter can still override it per video.', 'xroad-videos' ); ?></p>
						</td>
					</tr>
				</table>
				</div>
				<div class="xrv-card"><h2 class="title" id="xrv-sec-sync"><span class="xrv-ico"><?php echo xrv_admin_icon( 'sync' ); ?></span>Automatic channel sync</h2>
			<p class="description" style="max-width:780px">Poll a YouTube channel or playlist and add any <strong>new</strong> uploads to the library automatically. Videos already in the library (matched by video ID) are skipped, so it is always safe to run. Needs the YouTube Data API key above.<?php if ( '' === $key ) { echo ' <strong style="color:#b32d2e">Add an API key to enable it.</strong>'; } ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="xrv-sync-url">Channel or playlist URL</label></th>
					<td><input type="url" id="xrv-sync-url" name="xrv_settings[sync_url]" value="<?php echo esc_attr( $s['sync_url'] ); ?>" class="regular-text" placeholder="https://www.youtube.com/@yourchannel">
					<p class="description">A channel URL (<code>/@handle</code>, <code>/channel/UC…</code>, <code>/user/…</code>) or a <code>?list=…</code> playlist URL.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-sync-freq">Check frequency</label></th>
					<td><select id="xrv-sync-freq" name="xrv_settings[sync_freq]">
						<?php foreach ( array( 'off' => 'On demand only (recommended)', 'daily' => 'Daily (most frequent we recommend)', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'hourly' => 'Hourly (not recommended)' ) as $val => $lbl ) {
							echo '<option value="' . esc_attr( $val ) . '" ' . selected( $s['sync_freq'], $val, false ) . '>' . esc_html( $lbl ) . '</option>';
						} ?>
					</select>
					<p class="description"><strong>On demand only</strong> is the default and what we recommend: nothing is scheduled and the library updates only when you click <a href="#xrv-sec-syncnow"><em>Sync now</em></a> below. If you do automate, keep it to <strong>daily at most</strong> &mdash; a video library rarely changes hour to hour, so hourly checks just burn YouTube API quota with nothing new to show.
					<?php
					$next = wp_next_scheduled( 'xrv_sync_event' );
					if ( $next ) {
						$tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string' );
						echo ' Next automatic check: <strong>' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'M j, Y g:i a' ) ) . '</strong>' . ( '' !== $tz ? ' (' . esc_html( $tz ) . ')' : ' (site time)' ) . '.';
					}
					?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-sync-status">Add new videos as</label></th>
					<td><select id="xrv-sync-status" name="xrv_settings[sync_status]">
						<option value="publish" <?php selected( $s['sync_status'], 'publish' ); ?>>Published (live immediately)</option>
						<option value="draft" <?php selected( $s['sync_status'], 'draft' ); ?>>Draft (review before publishing)</option>
					</select>
					<p class="description">Choose <strong>Draft</strong> if you want to review titles/taxonomy before each new video goes live.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-sync-max">Videos to check</label></th>
					<td><input type="number" id="xrv-sync-max" name="xrv_settings[sync_max]" value="<?php echo esc_attr( (int) $s['sync_max'] ); ?>" min="1" max="50" class="small-text">
					<p class="description">How many of the channel's most recent uploads to scan each run (1&ndash;50).</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="xrv-sync-since"><?php esc_html_e( 'Only videos published since', 'xroad-videos' ); ?></label></th>
					<td><input type="date" id="xrv-sync-since" name="xrv_settings[sync_since]" value="<?php echo esc_attr( $s['sync_since'] ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. Sync skips any video YouTube says was published before this day (site time). Leave blank to consider every upload the scan finds.', 'xroad-videos' ); ?></p></td>
				</tr>
			</table>
			</div>

			<?php submit_button(); ?>
		</form>

		<?php
		// Video URLs — write-once permalink structure (its own form; not a Settings-API save).
		$pl = xrv_permalinks();
		// 2.11.0: every example is the FULL address with the permalink front (video URLs are built with_front,
		// so /blog/%category%/%postname%/ puts them at /blog/{base}/{slug}/). The result notice is
		// picked from fixed text by a whitelisted code; nothing from the query string is ever printed.
		$urls_front = xrv_permalink_front();
		$urls_root  = xrv_video_base_url( '' ); // home plus the front, e.g. https://example.org/blog/
		$urls_tail  = ( '/' === substr( user_trailingslashit( 'x' ), -1 ) ) ? '/' : '';
		$urls_state = isset( $_GET['xrv_urls'] ) ? sanitize_key( wp_unslash( $_GET['xrv_urls'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
		$urls_code  = isset( $_GET['xrv_code'] ) ? sanitize_key( wp_unslash( $_GET['xrv_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
		$urls_errors = array(
			/* translators: 1: the permalink front, e.g. "blog". 2: an example full video URL. */
			'base_has_front' => sprintf( __( 'Not saved. Enter the base without the permalink front: "%1$s" comes from Settings > Permalinks and is added automatically, so "videos" gives %2$s', 'xroad-videos' ), $urls_front, xrv_video_base_url( 'videos', 'example-video' ) ),
			'invalid'        => __( 'Not saved. That URL base could not be used, so nothing was changed.', 'xroad-videos' ),
		);
		$urls_notice = '';
		if ( '1' === $urls_state ) {
			$urls_notice = '<div class="notice notice-success inline" style="margin:0"><p>' . esc_html__( 'Video URLs saved. Rewrite rules flushed.', 'xroad-videos' ) . '</p></div>';
		} elseif ( 'err' === $urls_state ) {
			$urls_notice = '<div class="notice notice-error inline" style="margin:0"><p>' . esc_html( isset( $urls_errors[ $urls_code ] ) ? $urls_errors[ $urls_code ] : $urls_errors['invalid'] ) . '</p></div>';
		} elseif ( '' !== $urls_state ) {
			$urls_notice = '<div class="notice notice-warning inline" style="margin:0"><p>' . esc_html__( 'Nothing changed. Tick the confirmation box to apply a new URL structure.', 'xroad-videos' ) . '</p></div>';
		}
		?>
		<div class="xrv-card">
			<h2 class="title" id="xrv-sec-urls"><span class="xrv-ico"><?php echo xrv_admin_icon( 'link' ); ?></span>Video URLs</h2>
			<?php if ( '' !== $urls_notice ) { echo '<div style="padding-top:14px">' . $urls_notice . '</div>'; } // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped fixed text above ?>
			<?php $pub_count = (int) wp_count_posts( 'xroad_video' )->publish; ?>
			<p style="margin:.2em 0 0"><?php esc_html_e( 'Active now:', 'xroad-videos' ); ?> <code><?php echo esc_html( xrv_video_base_url( $pl['single'], '{slug}' ) ); ?></code><?php if ( '' !== $pl['archive'] ) { echo ' &nbsp;&middot;&nbsp; ' . esc_html__( 'Collection', 'xroad-videos' ) . ' <code>' . esc_html( xrv_video_base_url( $pl['archive'] ) ) . '</code>'; } ?> &nbsp;&middot;&nbsp; <strong><?php echo (int) $pub_count; ?></strong> published video<?php echo 1 === $pub_count ? '' : 's'; ?> using it.</p>
			<?php if ( '' !== $urls_front ) : ?>
			<p class="description" style="margin:0;padding-bottom:4px"><?php
				printf(
					/* translators: 1: the permalink front, e.g. "blog". 2: link to Settings > Permalinks. */
					esc_html__( 'The /%1$s/ part of every video URL comes from %2$s (your post permalink structure) and is added automatically. Type only the base below, never "%1$s/videos".', 'xroad-videos' ),
					esc_html( $urls_front ),
					'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings > Permalinks', 'xroad-videos' ) . '</a>'
				); // phpcs:ignore WordPress.Security.EscapeOutput -- format and arguments escaped individually
			?></p>
			<?php endif; ?>

			<div class="xrv-warn">
				<?php if ( $pub_count > 0 ) : ?><p style="margin:0 0 .5em"><strong>&#9888; <?php echo (int) $pub_count; ?> published video URL<?php echo 1 === $pub_count ? ' is' : 's are'; ?> already live under <code><?php echo esc_html( xrv_video_base_url( $pl['single'] ) ); ?></code>.</strong> Run the two-part test below before you change the base.</p><?php endif; ?>
				<p style="margin:0 0 .5em"><strong>Changing a URL that is already live is a one-way SEO risk.</strong> The default <code><?php echo esc_html( xrv_video_base_url( 'video' ) ); ?></code> is safe for a fresh setup. Once a video URL has been published, changing its base breaks every existing link to it and can drop its search rankings. (Also pick a base that is not already a page slug on this site, or they will collide.)</p>
				<p style="margin:0 0 .35em"><strong>Run this two-part test before changing a base that is in use. If EITHER is true, do not change it. It is a showstopper:</strong></p>
				<ol style="margin:0 0 .5em 1.4em">
					<li><strong>Organic traffic.</strong> Is any URL under this base getting search or organic visits? Check Search Console or analytics, filtered to the path.</li>
					<li><strong>Inbound links.</strong> Does any external site, or an internal link, point to a URL under this base? Check your backlink tool, or a site search for the path.</li>
				</ol>
				<p style="margin:0">If both come back clean, changing is low-risk. If not, treat it as a migration: put 301 redirects from every old URL to the new one in place first, then change the base here.</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="xrv_lock_urls">
				<?php wp_nonce_field( 'xrv_lock_urls' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xrv-single">Single video base</label></th>
						<td><code><?php echo esc_html( $urls_root ); ?></code> <input type="text" id="xrv-single" name="xrv_single" value="<?php echo esc_attr( $pl['single'] ); ?>" style="width:200px"> <code><?php echo esc_html( '/{slug}' . $urls_tail ); ?></code>
						<p class="description"><?php esc_html_e( 'A single video\'s own page, e.g.', 'xroad-videos' ); ?> <code>video</code> &rarr; <code><?php echo esc_html( xrv_video_base_url( 'video', 'my-clip' ) ); ?></code></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="xrv-archive">Collection base</label></th>
						<td><code><?php echo esc_html( $urls_root ); ?></code> <input type="text" id="xrv-archive" name="xrv_archive" value="<?php echo esc_attr( $pl['archive'] ); ?>" style="width:200px" placeholder="(none)"> <code>/</code>
						<p class="description"><?php esc_html_e( 'Optional archive listing every video, e.g.', 'xroad-videos' ); ?> <code>videos</code> &rarr; <code><?php echo esc_html( xrv_video_base_url( 'videos' ) ); ?></code>. <?php esc_html_e( 'Blank = videos via shortcode/block only.', 'xroad-videos' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row">Confirm</th>
						<td><label><input type="checkbox" name="xrv_confirm" value="1"> I have run the two-part test (organic traffic + inbound links) for any base already in use, or this is a fresh setup with no published video URLs yet.</label></td>
					</tr>
				</table>
				<p><?php submit_button( 'Save video URLs', 'primary', 'submit', false ); ?></p>
			</form>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xrv-card">
			<?php
			// Watch Page Controls — the standalone-page switch for the whole library, grouped beside Video URLs
			// (watch pages ARE the per-video URLs). Its OWN form (posts to admin-post.php), a sibling of the
			// settings form above. Per-video + selective bulk control live in the editor and the All Videos list.
			echo xrv_card_head( 'xrv-sec-watch', 'play', 'Watch Page Controls', 'Turn the standalone watch page on or off for every video at once. Per-video control is in each video&rsquo;s editor; selective bulk control is on the All Videos list.' );
			wp_nonce_field( 'xrv_watch_all' );
			?>
			<input type="hidden" name="action" value="xrv_watch_all">
			<p style="padding-bottom:6px;margin-top:4px">
				<button type="submit" name="state" value="0" class="button button-primary">Turn off all watch pages</button>
				<button type="submit" name="state" value="1" class="button">Turn all back on</button>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=xroad_video' ) ); ?>" class="button-link" style="margin-left:10px">Review on All Videos &rarr;</a>
			</p>
		</form>

		<?php
		// On-demand sync (separate form: it performs an action, not a settings save).
		if ( isset( $_GET['xrv_synced'] ) && 'busy' === sanitize_key( wp_unslash( $_GET['xrv_synced'] ) ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Another sync or WP-CLI run is changing the library right now, so nothing was synced. Try again in a few minutes.', 'xroad-videos' ) . '</p></div>';
		} elseif ( isset( $_GET['xrv_synced'] ) ) {
			$last = get_option( 'xrv_sync_last', array() );
			if ( ! empty( $last['ok'] ) ) {
				$msg = sprintf( 'Sync complete: checked %d, added %d new video%s.', (int) $last['checked'], (int) $last['added'], 1 === (int) $last['added'] ? '' : 's' );
				if ( ! empty( $last['titles'] ) ) { $msg .= ' (' . esc_html( implode( ', ', array_map( 'sanitize_text_field', $last['titles'] ) ) ) . ')'; }
				/* translators: 1: videos skipped (private, unlisted, failed uploads, before the since day), 2: videos deferred to the next run. */
				$msg .= ' ' . esc_html( sprintf( __( 'Skipped %1$d, deferred %2$d.', 'xroad-videos' ), (int) ( $last['skipped'] ?? 0 ), (int) ( $last['deferred'] ?? 0 ) ) );
				foreach ( array( 'msg', 'api_msg' ) as $k ) { if ( ! empty( $last[ $k ] ) ) { $msg .= ' ' . esc_html( $last[ $k ] ); } }
				echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
			} elseif ( ! empty( $last['msg'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>Sync could not run: ' . esc_html( $last['msg'] ) . '</p></div>';
			}
		}
		?>
		<div class="xrv-card">
			<?php echo xrv_card_head( 'xrv-sec-syncnow', 'play', 'Run a one-time sync now', 'A manual, on-demand check of the channel using the settings above &mdash; nothing is scheduled, it runs once. This is the recommended way to use sync. Save your changes first if you just edited them.' ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="xrv_sync_now">
				<?php wp_nonce_field( 'xrv_sync_now' ); ?>
				<?php submit_button( 'Sync now', 'primary', 'submit', false, '' === $key || empty( $s['sync_url'] ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
				<?php if ( '' === $key || empty( $s['sync_url'] ) ) { echo '<span class="description" style="margin-left:10px">Add an API key and a channel URL above to enable this.</span>'; } ?>
			</form>
			<?php
			$last = get_option( 'xrv_sync_last', array() );
			if ( ! empty( $last['time'] ) ) {
				echo '<p class="description" style="margin:0;padding-bottom:16px">Last run: <strong>' . esc_html( get_date_from_gmt( get_gmt_from_date( $last['time'] ), 'M j, Y g:i a' ) ) . '</strong> &mdash; ' . ( ! empty( $last['ok'] ) ? esc_html( sprintf( /* translators: 1: videos checked, 2: added, 3: skipped, 4: deferred to the next run. */ __( 'checked %1$d, added %2$d, skipped %3$d, deferred %4$d.', 'xroad-videos' ), (int) $last['checked'], (int) $last['added'], (int) ( $last['skipped'] ?? 0 ), (int) ( $last['deferred'] ?? 0 ) ) ) : esc_html( $last['msg'] ) ) . ( ! empty( $last['api_msg'] ) && ! empty( $last['ok'] ) ? ' ' . esc_html( $last['api_msg'] ) : '' ) . '</p>';
			}
			?>
		</div>

		<?php
		// Help / FAQ — the same bold-carat accordion used throughout, gathered as a quick-reference card.
		xrv_faq_card( 'xrv-sec-faq', 'Questions & answers', array(
			'Which consent mode should I choose?' =>
				wp_kses_post( __( '<p><strong>Global</strong> fits almost everyone: EU / UK / EEA / Swiss visitors get a one-time opt-in prompt, everyone else plays in one click, and no visitor\'s browser contacts YouTube before they click play. (If you turn on <em>Warm-up on hover</em>, visitors who need no prompt open a cookie-free connection when they hover a video.) Pick <strong>Strict GDPR</strong> to prompt every visitor worldwide (the most defensible posture), or <strong>Facade only</strong> to drop the prompt entirely: still cookie-free until the click, just with no extra notice.</p>', 'xroad-videos' ) ),
			'Do I need the YouTube Data API key?' =>
				'<p>No, for everyday use. Pasting video links or uploading a list works with no key (you get the title and thumbnail). You only need a free key to pull a <strong>whole channel or playlist</strong> with durations and descriptions, and to run <strong>automatic sync</strong>.</p>',
			'Will changing a video URL hurt my SEO?' =>
				'<p>It can, once URLs are live. Changing the base breaks every existing link and can drop rankings. The default <code>/video/</code> is safe for a fresh site. If videos are already published, run the two-part test in the <em>Video URLs</em> card first and add 301 redirects before changing anything.</p>',
			'What if a video has no thumbnail?' =>
				'<p>Hosted videos (YouTube, Vimeo, etc.) download their poster automatically when you save the video. <strong>Self-hosted files</strong> have no poster to fetch, so upload one in the video editor. Until a poster exists, the <em>Default poster</em> set under Appearance is shown.</p>',
			'How do I show only Shorts, or hide them?' =>
				'<p>Set the site default under <em>Appearance &rsaquo; Shorts in galleries</em>. To override a single gallery, add an attribute: <code>[xroad-videos shorts="only"]</code>, <code>shorts="hide"</code>, or <code>shorts="all"</code>.</p>',
			'Do shortcode or block settings override these?' =>
				'<p>Yes. Everything here is a <strong>site-wide default</strong>. Any attribute set directly on a <code>[xroad-videos]</code> shortcode or on the block always wins for that one gallery.</p>',
		) );
		?>
	</div>
	<?php
}

/* ---- Normalize a taxonomy field from an import record: array OR comma/pipe/semicolon string -> clean name list ---- */
function xrv_import_term_list( $val ) {
	if ( is_array( $val ) ) { $parts = $val; }
	elseif ( is_string( $val ) && '' !== trim( $val ) ) { $parts = preg_split( '/[|;,]+/', $val ); }
	else { return array(); }
	$out = array();
	foreach ( $parts as $p ) { $p = sanitize_text_field( trim( (string) $p ) ); if ( '' !== $p && ! in_array( $p, $out, true ) ) { $out[] = $p; } }
	return $out;
}

/* Normalize an import record's optional `watch_page` field to the meta convention: '' = not specified
 * (leave the video's existing/default state), '0' = no standalone watch page, '1' = on. Accepts string,
 * number, or JSON boolean — `false`/`"0"`/`"no"`/`"off"`/`"none"` all map to off, so a hand-authored
 * file can turn the watch page off without knowing the exact stored value. */
function xrv_norm_watch_page( $val ) {
	if ( ! isset( $val ) || null === $val || '' === $val ) { return ''; }
	if ( is_bool( $val ) ) { return $val ? '1' : '0'; }
	$s = strtolower( trim( (string) $val ) );
	return in_array( $s, array( '0', 'false', 'no', 'off', 'none' ), true ) ? '0' : '1';
}

/* ---- YouTube Data API helpers (only used when an API key is supplied) ---- */
function xrv_yt_get( $path, $params, $key ) {
	$params['key'] = $key;
	$res = wp_remote_get( 'https://www.googleapis.com/youtube/v3/' . $path . '?' . http_build_query( $params ), array( 'timeout' => 15 ) );
	if ( is_wp_error( $res ) ) { return new WP_Error( 'xrv_yt', $res->get_error_message() ); }
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'xrv_yt', isset( $body['error']['message'] ) ? $body['error']['message'] : 'YouTube API error.' );
	}
	return $body;
}
function xrv_yt_uploads_playlist( $url, $key ) {
	$cid = '';
	if ( preg_match( '#youtube\.com/channel/(UC[\w-]+)#i', $url, $m ) ) {
		$cid = $m[1];
	} elseif ( preg_match( '#youtube\.com/@([\w.\-]+)#i', $url, $m ) ) {
		$r = xrv_yt_get( 'channels', array( 'part' => 'contentDetails', 'forHandle' => '@' . $m[1] ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		return $r['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? new WP_Error( 'xrv_yt', 'Channel not found for that handle.' );
	} elseif ( preg_match( '#youtube\.com/user/([\w-]+)#i', $url, $m ) ) {
		$r = xrv_yt_get( 'channels', array( 'part' => 'contentDetails', 'forUsername' => $m[1] ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		return $r['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? new WP_Error( 'xrv_yt', 'Channel not found for that user.' );
	} elseif ( preg_match( '#youtube\.com/c/([\w-]+)#i', $url, $m ) ) {
		$r = xrv_yt_get( 'search', array( 'part' => 'snippet', 'type' => 'channel', 'q' => $m[1], 'maxResults' => 1 ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		$cid = $r['items'][0]['id']['channelId'] ?? '';
	}
	if ( '' === $cid ) { return new WP_Error( 'xrv_yt', 'Could not resolve a channel from that URL.' ); }
	$r = xrv_yt_get( 'channels', array( 'part' => 'contentDetails', 'id' => $cid ), $key );
	if ( is_wp_error( $r ) ) { return $r; }
	return $r['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? new WP_Error( 'xrv_yt', 'Could not find the uploads playlist.' );
}
function xrv_yt_playlist_ids( $playlist_id, $key, $max = 500 ) {
	$ids = array(); $page = '';
	do {
		$r = xrv_yt_get( 'playlistItems', array( 'part' => 'contentDetails', 'playlistId' => $playlist_id, 'maxResults' => 50, 'pageToken' => $page ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		foreach ( (array) ( $r['items'] ?? array() ) as $it ) {
			$vid = $it['contentDetails']['videoId'] ?? '';
			if ( $vid ) { $ids[] = $vid; }
		}
		$page = $r['nextPageToken'] ?? '';
	} while ( $page && count( $ids ) < $max );
	return $ids;
}
/* Video details keyed by video ID. 'upload' stays the UTC day of publishedAt (what the importer has always
 * stored); 2.11.0 adds the fields channel sync gates on: privacy (status.privacyStatus), upload_status
 * (status.uploadStatus), live (snippet.liveBroadcastContent) and published_at (the full publishedAt).
 * An ID the API does not return (deleted, private, or a partial response) is simply absent. */
function xrv_yt_videos_meta( $ids, $key ) {
	$out = array();
	foreach ( array_chunk( $ids, 50 ) as $chunk ) {
		$r = xrv_yt_get( 'videos', array( 'part' => 'snippet,contentDetails,status', 'id' => implode( ',', $chunk ), 'maxResults' => 50 ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		foreach ( (array) ( $r['items'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) || empty( $it['id'] ) || ! is_string( $it['id'] ) ) { continue; }
			$pub = isset( $it['snippet']['publishedAt'] ) ? (string) $it['snippet']['publishedAt'] : '';
			$out[ $it['id'] ] = array(
				'title'         => $it['snippet']['title'] ?? '',
				'desc'          => $it['snippet']['description'] ?? '',
				'upload'        => '' !== $pub ? substr( $pub, 0, 10 ) : '',
				'duration'      => $it['contentDetails']['duration'] ?? '',
				'privacy'       => (string) ( $it['status']['privacyStatus'] ?? '' ),
				'upload_status' => (string) ( $it['status']['uploadStatus'] ?? '' ),
				'live'          => (string) ( $it['snippet']['liveBroadcastContent'] ?? '' ),
				'published_at'  => $pub,
			);
		}
	}
	return $out;
}

/* ---- Resolve a channel URL / @handle / name to a channel ID, then list its PUBLIC playlists. ---- */
function xrv_yt_channel_id( $url, $key ) {
	$url = trim( (string) $url );
	if ( preg_match( '#youtube\.com/channel/(UC[\w-]+)#i', $url, $m ) ) { return $m[1]; }
	if ( preg_match( '#^UC[\w-]{20,}$#', $url ) ) { return $url; }
	if ( preg_match( '#youtube\.com/@([\w.\-]+)#i', $url, $m ) || preg_match( '#^@([\w.\-]+)$#', $url, $m ) ) {
		$r = xrv_yt_get( 'channels', array( 'part' => 'id', 'forHandle' => '@' . $m[1] ), $key );
	} elseif ( preg_match( '#youtube\.com/user/([\w-]+)#i', $url, $m ) ) {
		$r = xrv_yt_get( 'channels', array( 'part' => 'id', 'forUsername' => $m[1] ), $key );
	} else {
		// A /c/ vanity URL or a bare channel name: search for the channel.
		$q = preg_match( '#youtube\.com/c/([\w-]+)#i', $url, $m ) ? $m[1] : $url;
		if ( '' === $q ) { return new WP_Error( 'xrv_yt', 'Enter a channel URL, @handle, or name.' ); }
		$r = xrv_yt_get( 'search', array( 'part' => 'snippet', 'type' => 'channel', 'q' => $q, 'maxResults' => 1 ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		return $r['items'][0]['id']['channelId'] ?? new WP_Error( 'xrv_yt', 'No channel found for that name.' );
	}
	if ( is_wp_error( $r ) ) { return $r; }
	return $r['items'][0]['id'] ?? new WP_Error( 'xrv_yt', 'Channel not found.' );
}
function xrv_yt_channel_playlists( $url, $key ) {
	$cid = xrv_yt_channel_id( $url, $key );
	if ( is_wp_error( $cid ) ) { return $cid; }
	$out = array(); $page = '';
	do {
		$r = xrv_yt_get( 'playlists', array( 'part' => 'snippet,contentDetails', 'channelId' => $cid, 'maxResults' => 50, 'pageToken' => $page ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		foreach ( (array) ( $r['items'] ?? array() ) as $it ) {
			$pid = $it['id'] ?? '';
			if ( '' === $pid ) { continue; }
			$out[] = array(
				'id'    => $pid,
				'title' => $it['snippet']['title'] ?? '(untitled playlist)',
				'count' => (int) ( $it['contentDetails']['itemCount'] ?? 0 ),
			);
		}
		$page = $r['nextPageToken'] ?? '';
	} while ( $page && count( $out ) < 200 );
	return $out;
}

/* ---- AJAX: list a channel's playlists for the Import picker (reads nothing into the library). ---- */
add_action( 'wp_ajax_xrv_yt_playlists', 'xrv_ajax_yt_playlists' );
function xrv_ajax_yt_playlists() {
	check_ajax_referer( 'xrv_import', 'nonce' );
	if ( ! current_user_can( 'edit_others_posts' ) ) { wp_send_json_error( array( 'msg' => 'forbidden' ) ); }
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	if ( '' === $key ) { $key = (string) get_option( 'xrv_yt_api_key', '' ); }
	if ( '' === $key ) { wp_send_json_error( array( 'msg' => 'A YouTube Data API key is required to list playlists. Add one above or in Settings.' ) ); }
	$channel = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : '';
	if ( '' === trim( $channel ) ) { wp_send_json_error( array( 'msg' => 'Enter a channel URL, @handle, or name.' ) ); }
	$lists = xrv_yt_channel_playlists( $channel, $key );
	if ( is_wp_error( $lists ) ) { wp_send_json_error( array( 'msg' => $lists->get_error_message() ) ); }
	if ( empty( $lists ) ) { wp_send_json_error( array( 'msg' => 'No public playlists found on that channel.' ) ); }
	wp_send_json_success( array( 'playlists' => $lists ) );
}

/* ---- AJAX: dry-run preview (resolve sources -> list with new/exists status; writes nothing) ---- */
add_action( 'wp_ajax_xrv_import_preview', 'xrv_ajax_import_preview' );
function xrv_ajax_import_preview() {
	check_ajax_referer( 'xrv_import', 'nonce' );
	if ( ! current_user_can( 'edit_others_posts' ) ) { wp_send_json_error( array( 'msg' => 'You do not have permission to import.' ) ); }

	$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
	if ( '' !== $key ) { update_option( 'xrv_yt_api_key', $key ); } else { $key = (string) get_option( 'xrv_yt_api_key', '' ); }

	$raw      = isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '';
	$raw_trim = ltrim( (string) $raw );
	$ids = array(); $errors = array(); $json_meta = array();

	if ( '' !== $raw_trim && '[' === $raw_trim[0] ) {
		// A JSON array of records: { "id"|"url", "title"?, "duration"?, "upload"?, "desc"? }. Lets a
		// prepared metadata file import rich VideoObject data with NO API key (the "upload a file" path).
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) { wp_send_json_error( array( 'msg' => 'That looks like JSON but could not be parsed.' ) ); }
		foreach ( $decoded as $rec ) {
			if ( ! is_array( $rec ) ) { continue; }
			$rid = ! empty( $rec['id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $rec['id'] ) : ( ! empty( $rec['url'] ) ? xrv_extract_video_id( (string) $rec['url'], 'youtube' ) : '' );
			if ( '' === $rid ) { continue; }
			$ids[] = $rid;
			$json_meta[ $rid ] = array(
				'title'      => isset( $rec['title'] ) ? (string) $rec['title'] : '',
				'desc'       => isset( $rec['desc'] ) ? (string) $rec['desc'] : '',
				'upload'     => isset( $rec['upload'] ) ? (string) $rec['upload'] : '',
				'duration'   => isset( $rec['duration'] ) ? (string) $rec['duration'] : '',
				'series'     => xrv_import_term_list( isset( $rec['series'] ) ? $rec['series'] : '' ),
				'audience'   => xrv_import_term_list( isset( $rec['audience'] ) ? $rec['audience'] : '' ),
				'topic'      => xrv_import_term_list( isset( $rec['topic'] ) ? $rec['topic'] : '' ),
				'watch_page' => xrv_norm_watch_page( isset( $rec['watch_page'] ) ? $rec['watch_page'] : null ),
			);
		}
	} else {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) ) );
		foreach ( $lines as $line ) {
			if ( preg_match( '#[?&]list=([A-Za-z0-9_-]+)#', $line, $m ) ) {
				if ( '' === $key ) { $errors[] = 'A playlist URL needs an API key.'; continue; }
				$r = xrv_yt_playlist_ids( $m[1], $key );
				if ( is_wp_error( $r ) ) { $errors[] = $r->get_error_message(); continue; }
				$ids = array_merge( $ids, $r );
			} elseif ( preg_match( '#youtube\.com/(channel/|@|c/|user/)#i', $line ) && ! preg_match( '#[?&]v=|/(watch|embed|shorts|live)#i', $line ) ) {
				if ( '' === $key ) { $errors[] = 'A channel URL needs an API key (or paste individual video URLs).'; continue; }
				$pl = xrv_yt_uploads_playlist( $line, $key );
				if ( is_wp_error( $pl ) ) { $errors[] = $pl->get_error_message(); continue; }
				$r = xrv_yt_playlist_ids( $pl, $key );
				if ( is_wp_error( $r ) ) { $errors[] = $r->get_error_message(); continue; }
				$ids = array_merge( $ids, $r );
			} else {
				$vid = xrv_extract_video_id( $line, 'youtube' );
				if ( $vid ) { $ids[] = $vid; } elseif ( '' !== $line ) { $errors[] = 'Could not read: ' . esc_html( mb_substr( $line, 0, 40 ) ); }
			}
		}
	}
	$ids = array_values( array_unique( $ids ) );
	if ( empty( $ids ) ) { wp_send_json_error( array( 'msg' => $errors ? implode( ' ', $errors ) : 'No YouTube videos found in the input.' ) ); }
	// Cap each import run to 50 videos so a pasted playlist/channel of thousands can't fire thousands of
	// metadata + HEAD (Shorts probe) + thumbnail requests at once. Run the import again to add the rest.
	if ( count( $ids ) > 50 ) {
		$errors[] = sprintf( '%d more found — capped to 50 per import. Run the import again to add the rest.', count( $ids ) - 50 );
		$ids = array_slice( $ids, 0, 50 );
	}

	// Metadata: start from any JSON-provided fields, then fill gaps from the API when a key is present.
	$meta = $json_meta;
	if ( '' !== $key ) {
		$m = xrv_yt_videos_meta( $ids, $key );
		if ( ! is_wp_error( $m ) ) {
			foreach ( $m as $mid => $mv ) {
				if ( empty( $meta[ $mid ] ) ) { $meta[ $mid ] = $mv; continue; }
				foreach ( $mv as $k => $val ) { if ( empty( $meta[ $mid ][ $k ] ) ) { $meta[ $mid ][ $k ] = $val; } }
			}
		}
	}
	$rich = ( '' !== $key ) || ! empty( $json_meta );

	global $wpdb;
	$existing = array_flip( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_xrv_video_id'" ) );

	$videos = array(); $new = 0;
	foreach ( $ids as $id ) {
		$title = $meta[ $id ]['title'] ?? '';
		if ( '' === $title ) { $o = xrv_fetch_oembed( 'https://www.youtube.com/watch?v=' . $id, 'youtube' ); $title = $o['title'] ?? $id; }
		$is_existing = isset( $existing[ $id ] );
		if ( ! $is_existing ) { $new++; }
		$videos[] = array(
			'id'       => $id,
			'title'    => $title,
			'duration' => $meta[ $id ]['duration'] ?? '',
			'upload'   => $meta[ $id ]['upload'] ?? '',
			'desc'     => isset( $meta[ $id ]['desc'] ) ? mb_substr( $meta[ $id ]['desc'], 0, 5000 ) : '',
			'series'     => isset( $meta[ $id ]['series'] ) ? (array) $meta[ $id ]['series'] : array(),
			'audience'   => isset( $meta[ $id ]['audience'] ) ? (array) $meta[ $id ]['audience'] : array(),
			'topic'      => isset( $meta[ $id ]['topic'] ) ? (array) $meta[ $id ]['topic'] : array(),
			'watch_page' => isset( $meta[ $id ]['watch_page'] ) ? (string) $meta[ $id ]['watch_page'] : '',
			'exists'     => $is_existing,
			'thumb'      => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
		);
	}
	wp_send_json_success( array( 'videos' => $videos, 'total' => count( $videos ), 'new' => $new, 'exists' => count( $videos ) - $new, 'errors' => $errors, 'rich' => $rich ) );
}

/* ---- AJAX: import a batch (create/update + sideload thumbnail). Called repeatedly by the JS. ---- */
add_action( 'wp_ajax_xrv_import_run', 'xrv_ajax_import_run' );
function xrv_ajax_import_run() {
	check_ajax_referer( 'xrv_import', 'nonce' );
	if ( ! current_user_can( 'edit_others_posts' ) ) { wp_send_json_error( array( 'msg' => 'forbidden' ) ); }

	$overwrite = isset( $_POST['overwrite'] ) && '1' === $_POST['overwrite'];
	$items     = json_decode( isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]', true );
	if ( ! is_array( $items ) ) { wp_send_json_error( array( 'msg' => 'Bad payload.' ) ); }
	$items = array_slice( $items, 0, 50 ); // hard server-side cap: 50 per run, bounding metadata/HEAD/thumbnail requests

	global $wpdb;
	$max_order = (int) $wpdb->get_var( "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = 'xroad_video'" );

	$results = array();
	foreach ( $items as $v ) {
		$id = isset( $v['id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', $v['id'] ) : '';
		if ( '' === $id ) { $results[] = array( 'id' => '', 'status' => 'failed' ); continue; }

		$ex = get_posts( array( 'post_type' => 'xroad_video', 'post_status' => 'any', 'meta_key' => '_xrv_video_id', 'meta_value' => $id, 'fields' => 'ids', 'posts_per_page' => 1 ) );
		if ( $ex && ! $overwrite ) { $results[] = array( 'id' => $id, 'status' => 'skipped' ); continue; }

		$title = isset( $v['title'] ) && '' !== $v['title'] ? sanitize_text_field( $v['title'] ) : $id;
		if ( $ex ) {
			$pid = $ex[0];
			wp_update_post( array( 'ID' => $pid, 'post_title' => $title ) );
			$status = 'updated';
		} else {
			$max_order++;
			$pid = wp_insert_post( array( 'post_type' => 'xroad_video', 'post_status' => 'publish', 'post_title' => $title, 'menu_order' => $max_order ) );
			if ( is_wp_error( $pid ) ) { $results[] = array( 'id' => $id, 'status' => 'failed' ); continue; }
			$status = 'created';
		}

		xrv_apply_youtube_meta( $pid, $id, $v );

		// Taxonomies (Series / Audience / Topic): assign by NAME, creating any term that doesn't exist yet.
		foreach ( array( 'series' => 'xrv_series', 'audience' => 'xrv_audience', 'topic' => 'xrv_topic' ) as $field => $tax ) {
			$names = xrv_import_term_list( isset( $v[ $field ] ) ? $v[ $field ] : '' );
			if ( empty( $names ) || ! taxonomy_exists( $tax ) ) { continue; }
			$term_ids = array();
			foreach ( $names as $name ) {
				$term = get_term_by( 'name', $name, $tax );
				if ( ! $term ) { $ins = wp_insert_term( $name, $tax ); if ( ! is_wp_error( $ins ) ) { $term_ids[] = (int) $ins['term_id']; } }
				else { $term_ids[] = (int) $term->term_id; }
			}
			if ( $term_ids ) { wp_set_object_terms( $pid, $term_ids, $tax, false ); }
		}

		$short = xrv_yt_is_short( $id );
		if ( $short ) { xrv_mark_short( $pid, $id ); }
		if ( ! (int) get_post_meta( $pid, '_xrv_local_thumb_id', true ) ) {
			$att = xrv_sideload_thumbnail( $pid, $id, 'youtube', $short ? xrv_thumb_candidates( $id, 'youtube', true ) : null );
			if ( ! is_wp_error( $att ) ) { update_post_meta( $pid, '_xrv_local_thumb_id', (int) $att ); set_post_thumbnail( $pid, (int) $att ); }
		}
		$results[] = array( 'id' => $id, 'status' => $status );
	}
	wp_send_json_success( array( 'results' => $results ) );
}

/* ---- The Import admin screen ---- */
function xrv_render_import_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) { return; }
	$key = (string) get_option( 'xrv_yt_api_key', '' );
	?>
	<div class="wrap xrv-admin xrv-import">
		<?php echo xrv_admin_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static, controlled CSS ?>
		<?php xrv_admin_header( 'Bulk add to your library', 'Import <b>Videos</b>', 'Paste video links (one per line) or upload a list. With an optional free <a href="https://developers.google.com/youtube/v3/getting-started" target="_blank" rel="noopener">YouTube Data API key</a> you can pull a whole <strong>channel</strong> or <strong>playlist</strong> with durations, dates, and descriptions for richer schema.' ); ?>
		<ol class="xrv-steps" aria-label="How importing works">
			<li><b>1</b> Add links or a file</li>
			<li><b>2</b> Preview &amp; pick</li>
			<li><b>3</b> Import to library</li>
		</ol>
		<div class="xrv-card">
			<?php echo xrv_card_head( 'xrv-imp-add', 'upload', 'Add videos', 'Paste links, list a channel&rsquo;s playlists, or choose a file. Nothing is imported until you preview and confirm.' ); ?>
			<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><label for="xrv-imp-key">YouTube Data API key</label><br><span style="font-weight:400;color:#787c82;font-size:12px">optional</span></th>
				<td><input type="text" id="xrv-imp-key" class="regular-text" value="<?php echo esc_attr( $key ); ?>" placeholder="Leave blank to import by URL (title + thumbnail only)" autocomplete="off"></td></tr>
<tr><th scope="row"><label for="xrv-imp-pl-ch">Pick a playlist</label><br><span style="font-weight:400;color:#787c82;font-size:12px">optional &middot; needs API key</span></th>
				<td>
					<input type="text" id="xrv-imp-pl-ch" class="regular-text" placeholder="Channel URL, @handle, or name">
					<button type="button" id="xrv-imp-pl-load" class="button">List playlists</button>
					<span id="xrv-imp-pl-status" style="margin-left:6px;color:#787c82"></span>
					<p style="margin:8px 0 0"><select id="xrv-imp-pl" class="regular-text" style="display:none"><option value="">Select a playlist&hellip;</option></select></p>
					<p class="description">List the public playlists on a channel and pick one; it loads into the <strong>Videos</strong> box below, then click <strong>Preview import</strong>.</p>
				</td></tr>
			<tr><th scope="row"><label for="xrv-imp-src">Videos</label></th>
				<td>
					<textarea id="xrv-imp-src" rows="7" class="large-text code" placeholder="https://www.youtube.com/watch?v=...&#10;https://youtu.be/...&#10;https://www.youtube.com/@channel   (needs API key)&#10;https://www.youtube.com/playlist?list=...   (needs API key)"></textarea>
					<p><label class="button">Choose file&hellip;<input type="file" id="xrv-imp-file" accept=".txt,.csv,.json" style="display:none"></label> <span style="color:#787c82">.txt / .csv of URLs, or a .json metadata file (id, title, duration, upload, desc, watch_page) for rich import with no API key</span></p>
				</td></tr>
		</tbody></table>

			<p style="padding-bottom:18px"><button id="xrv-imp-preview" class="button button-primary button-hero">Preview import</button> <span id="xrv-imp-status" style="margin-left:10px"></span></p>
			<div id="xrv-imp-results"></div>
			<div id="xrv-imp-progress"></div>
		</div>

		<?php
		xrv_faq_card( 'xrv-imp-faq', 'Questions & answers', array(
			'What can I paste in the Videos box?' =>
				'<p>One link per line. YouTube <code>watch</code> or <code>youtu.be</code> links work with no key. With an API key you can also paste a <strong>channel</strong> URL (<code>/@handle</code>, <code>/channel/UC…</code>) or a <code>playlist?list=…</code> URL and the whole list resolves.</p>',
			'Do I need the API key to import?' =>
				'<p>No. Without a key you import by URL and get the title and thumbnail. A free key adds duration, upload date, and description, and unlocks channel / playlist imports and the playlist picker above.</p>',
			'What file types can I upload?' =>
				'<p>A <code>.txt</code> or <code>.csv</code> of URLs (one per line), or a <code>.json</code> metadata file with <code>id, title, duration, upload, desc</code> fields for a rich import with no API key. Add <code>"watch_page": "0"</code> to a record to import that video with <strong>no standalone watch page</strong> (<code>"1"</code> forces it on); omit it to leave the video&rsquo;s current setting. Combine with <strong>Overwrite</strong> to flip videos already in your library.</p>',
			'What happens to videos already in my library?' =>
				'<p>The preview flags each as <span class="xrv-badge-new">NEW</span> or <span class="xrv-badge-exists">EXISTS</span>. Before importing you choose whether existing videos are <strong>skipped</strong> (the default) or <strong>overwritten</strong> with a refreshed title, metadata, and thumbnail.</p>',
			'Will importing publish the videos right away?' =>
				'<p>Yes &mdash; imported videos are added as published and appear in your galleries immediately. Reorder them under <strong>All Videos</strong>, or open any one to fine-tune its details.</p>',
		) );
		?>
	</div>
	<script>window.XRV_IMP = { ajax: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce: <?php echo wp_json_encode( wp_create_nonce( 'xrv_import' ) ); ?> };</script>
	<?php
	echo xrv_import_inline_js();
}

function xrv_import_inline_js() {
	return <<<'JS'
<script>
(function(){
	var C = window.XRV_IMP || {};
	var $ = function(s){ return document.querySelector(s); };
	var src=$('#xrv-imp-src'), keyEl=$('#xrv-imp-key'), fileEl=$('#xrv-imp-file');
	var statusEl=$('#xrv-imp-status'), resultsEl=$('#xrv-imp-results'), progEl=$('#xrv-imp-progress');
	var videos=[];
	function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':s); return d.innerHTML; }
	function post(action,data){ data.action=action; data.nonce=C.nonce; var b=new URLSearchParams(data); return fetch(C.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b.toString()}).then(function(r){return r.json();}); }

	if(fileEl) fileEl.addEventListener('change', function(){ var f=fileEl.files[0]; if(!f) return; var r=new FileReader(); r.onload=function(){ src.value=(src.value?src.value+'\n':'')+r.result; }; r.readAsText(f); });

	// Playlist picker: list a channel's public playlists, then load the chosen one into the Videos box.
	var plCh=$('#xrv-imp-pl-ch'), plBtn=$('#xrv-imp-pl-load'), plSel=$('#xrv-imp-pl'), plStatus=$('#xrv-imp-pl-status');
	if(plBtn) plBtn.addEventListener('click', function(){
		var ch=plCh?plCh.value.trim():''; if(!ch){ plStatus.textContent='Enter a channel first.'; return; }
		plStatus.textContent='Loading…'; plBtn.disabled=true;
		post('xrv_yt_playlists',{ channel:ch, key:(keyEl?keyEl.value:'') }).then(function(res){
			plBtn.disabled=false;
			if(!res || !res.success){ plStatus.innerHTML='<span style="color:#b32d2e">'+esc((res&&res.data&&res.data.msg)||'Could not load playlists.')+'</span>'; return; }
			var pls=res.data.playlists||[];
			plSel.innerHTML='<option value="">Select a playlist…</option>'+pls.map(function(p){ return '<option value="'+esc(p.id)+'">'+esc(p.title)+' ('+p.count+')</option>'; }).join('');
			plSel.style.display=''; plStatus.textContent=pls.length+' playlist'+(pls.length===1?'':'s')+' found.';
		}).catch(function(e){ plBtn.disabled=false; plStatus.innerHTML='<span style="color:#b32d2e">Error: '+esc(String(e))+'</span>'; });
	});
	if(plSel) plSel.addEventListener('change', function(){
		if(!plSel.value){ return; }
		src.value='https://www.youtube.com/playlist?list='+plSel.value;
		plStatus.textContent='Loaded into the Videos box below — click Preview import.';
	});

	$('#xrv-imp-preview').addEventListener('click', function(){
		statusEl.textContent='Resolving…'; resultsEl.innerHTML=''; progEl.innerHTML='';
		post('xrv_import_preview',{ source:src.value, api_key:(keyEl?keyEl.value:'') }).then(function(res){
			if(!res || !res.success){ statusEl.innerHTML='<span style="color:#b32d2e">'+esc((res&&res.data&&res.data.msg)||'Preview failed.')+'</span>'; return; }
			videos=res.data.videos; render(res.data);
		}).catch(function(e){ statusEl.innerHTML='<span style="color:#b32d2e">Error: '+esc(String(e))+'</span>'; });
	});

	function render(d){
		statusEl.innerHTML='<strong>'+d.total+'</strong> found · <strong>'+d.new+'</strong> new · <strong>'+d.exists+'</strong> already in library · '+(d.rich?'rich metadata ✓':'titles only (add an API key for duration/date/description)');
		if(d.errors && d.errors.length){ statusEl.innerHTML += '<br><span style="color:#b32d2e">'+d.errors.map(esc).join('<br>')+'</span>'; }
		var anyTax = videos.some(function(v){ return (v.series&&v.series.length)||(v.audience&&v.audience.length)||(v.topic&&v.topic.length); });
		function tags(arr,cls){ return (arr||[]).map(function(t){ return '<span class="xrv-tag '+cls+'">'+esc(t)+'</span>'; }).join(''); }
		var rows=videos.map(function(v,i){
			return '<tr><td><input type="checkbox" class="xrv-cb" data-i="'+i+'" checked></td>'+
				'<td><img src="'+esc(v.thumb)+'" width="80" height="45" style="border-radius:3px;object-fit:cover"></td>'+
				'<td>'+esc(v.title)+'</td>'+
				'<td>'+(v.duration?esc(v.duration):'—')+'</td>'+
				(anyTax?'<td>'+(tags(v.series,'is-series')+tags(v.audience,'is-aud')+tags(v.topic,'is-topic')||'—')+'</td>':'')+
				'<td>'+(v.exists?'<span class="xrv-badge-exists">EXISTS</span>':'<span class="xrv-badge-new">NEW</span>')+'</td></tr>';
		}).join('');
		resultsEl.innerHTML =
			'<table class="widefat striped" style="margin-top:12px"><thead><tr><th style="width:32px"><input type="checkbox" id="xrv-all" checked></th><th>Thumbnail</th><th>Title</th><th>Duration</th>'+(anyTax?'<th>Suggested terms <span style="font-weight:400;color:#787c82">(Series · Audience · Topic)</span></th>':'')+'<th>Status</th></tr></thead><tbody>'+rows+'</tbody></table>'+
			'<fieldset style="margin:14px 0"><legend><strong>If a video is already in the library:</strong></legend>'+
			'<label style="margin-right:18px"><input type="radio" name="xrv-conf" value="skip" checked> Skip it (keep what is there)</label>'+
			'<label><input type="radio" name="xrv-conf" value="overwrite"> Overwrite — refresh title, metadata &amp; thumbnail</label></fieldset>'+
			'<button id="xrv-run" class="button button-primary button-hero">Import selected</button>';
		$('#xrv-all').addEventListener('change', function(){ var c=this.checked; resultsEl.querySelectorAll('.xrv-cb').forEach(function(x){x.checked=c;}); });
		$('#xrv-run').addEventListener('click', run);
	}

	function run(){
		var overwrite = (resultsEl.querySelector('input[name=xrv-conf]:checked')||{}).value==='overwrite';
		var sel=[]; resultsEl.querySelectorAll('.xrv-cb:checked').forEach(function(x){ sel.push(videos[+x.getAttribute('data-i')]); });
		if(!sel.length){ progEl.innerHTML='<p>Nothing selected.</p>'; return; }
		var runBtn=$('#xrv-run'); runBtn.disabled=true;
		var total=sel.length, done=0, tally={created:0,updated:0,skipped:0,failed:0};
		progEl.innerHTML='<div style="margin:14px 0"><div class="xrv-bar-wrap"><div class="xrv-bar" id="xrv-bar"></div></div><p id="xrv-msg" style="margin-top:8px">Starting…</p></div>';
		var SIZE=5, idx=0;
		function next(){
			if(idx>=total){ $('#xrv-msg').innerHTML='<strong>Done.</strong> Created '+tally.created+' · Updated '+tally.updated+' · Skipped '+tally.skipped+(tally.failed?' · Failed '+tally.failed:'')+'. <a href="edit.php?post_type=xroad_video">View all videos &rarr;</a>'; runBtn.disabled=false; return; }
			var batch=sel.slice(idx,idx+SIZE); idx+=SIZE;
			var payload=batch.map(function(v){ return {id:v.id,title:v.title,duration:v.duration,upload:v.upload,desc:v.desc,series:v.series||[],audience:v.audience||[],topic:v.topic||[],watch_page:v.watch_page||''}; });
			post('xrv_import_run',{ items:JSON.stringify(payload), overwrite:overwrite?'1':'0' }).then(function(res){
				if(res && res.success && res.data && res.data.results){ res.data.results.forEach(function(r){ if(tally[r.status]!=null) tally[r.status]++; }); }
				else { tally.failed+=batch.length; }
				done+=batch.length; var pct=Math.round(done/total*100);
				$('#xrv-bar').style.width=pct+'%'; $('#xrv-msg').textContent='Imported '+done+' of '+total+'…';
				next();
			}).catch(function(){ tally.failed+=batch.length; done+=batch.length; next(); });
		}
		next();
	}
})();
</script>
JS;
}

/* =================================================================================================
 * 9g. BULK CONTROLS  (native list-table bulk actions + an at-a-glance column + a one-click "all" handler)
 *     The XRV differentiators Smash Balloon has no equivalent for, exposed in bulk: per-video SEO WATCH
 *     PAGES and LOCAL-POSTER integrity. All native WordPress list-table hooks, no custom screen — the Bulk
 *     Actions dropdown on All Videos acts on a selected subset; the Settings buttons (9d) act on the whole
 *     library; a status column shows each video's state at a glance.
 * ================================================================================================= */

/* Bulk Actions dropdown items on the All Videos list. */
add_filter( 'bulk_actions-edit-xroad_video', 'xrv_bulk_actions' );
function xrv_bulk_actions( $actions ) {
	$actions['xrv_watch_off']       = 'Watch page: turn off';
	$actions['xrv_watch_on']        = 'Watch page: turn on';
	$actions['xrv_rebuild_posters'] = 'Rebuild local posters';
	return $actions;
}

/* Handle a selected-set bulk action; append a result count for the notice. WP verifies the bulk nonce
 * before this filter runs. */
add_filter( 'handle_bulk_actions-edit-xroad_video', 'xrv_handle_bulk_actions', 10, 3 );
function xrv_handle_bulk_actions( $redirect, $action, $ids ) {
	if ( ! in_array( $action, array( 'xrv_watch_off', 'xrv_watch_on', 'xrv_rebuild_posters' ), true ) ) { return $redirect; }
	if ( ! current_user_can( 'edit_others_posts' ) ) { return $redirect; }
	$ids = array_filter( array_map( 'intval', (array) $ids ), function( $id ) { return 'xroad_video' === get_post_type( $id ); } );
	$n = 0;
	if ( 'xrv_rebuild_posters' === $action ) {
		// ponytail: synchronous re-pull (one HEAD + download per video). If a large selection ever trips
		// PHP max_execution_time, move it to a chunked AJAX run like the importer (9c).
		foreach ( $ids as $id ) { if ( xrv_rebuild_poster( $id ) ) { $n++; } }
	} else {
		$val = ( 'xrv_watch_on' === $action ) ? '1' : '0';
		foreach ( $ids as $id ) { update_post_meta( $id, '_xrv_watch_page', $val ); $n++; }
	}
	return add_query_arg( array( 'xrv_bulk' => $action, 'xrv_n' => $n ), $redirect );
}

/* One-click "whole library" watch-page handler behind the Settings buttons (9d). */
add_action( 'admin_post_xrv_watch_all', 'xrv_handle_watch_all' );
function xrv_handle_watch_all() {
	if ( ! current_user_can( 'edit_others_posts' ) ) { wp_die( 'You do not have permission to do that.' ); }
	check_admin_referer( 'xrv_watch_all' );
	$val = ( isset( $_POST['state'] ) && '1' === (string) $_POST['state'] ) ? '1' : '0';
	$ids = get_posts( array( 'post_type' => 'xroad_video', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) { update_post_meta( $id, '_xrv_watch_page', $val ); }
	wp_safe_redirect( add_query_arg(
		array( 'post_type' => 'xroad_video', 'page' => 'xrv-settings', 'xrv_bulk' => ( '1' === $val ? 'xrv_watch_on' : 'xrv_watch_off' ), 'xrv_n' => count( $ids ) ),
		admin_url( 'edit.php' )
	) );
	exit;
}

/* Re-pull a video's local poster from its provider, swap the pointer, drop the stale attachment. The
 * local-poster guarantee is XRV's privacy headline, so a "repair" control belongs in bulk. */
function xrv_rebuild_poster( $id ) {
	$vid = (string) get_post_meta( $id, '_xrv_video_id', true );
	if ( '' === $vid ) { return false; }
	$provider = (string) get_post_meta( $id, '_xrv_provider', true );
	if ( '' === $provider ) { $provider = 'youtube'; }
	$short = '1' === (string) get_post_meta( $id, '_xrv_short', true );
	$cands = ( $short && 'youtube' === $provider ) ? xrv_thumb_candidates( $vid, $provider, true ) : null;
	$att   = xrv_sideload_thumbnail( $id, $vid, $provider, $cands );
	if ( is_wp_error( $att ) || ! $att ) { return false; }
	$old = (int) get_post_meta( $id, '_xrv_local_thumb_id', true );
	update_post_meta( $id, '_xrv_local_thumb_id', (int) $att );
	set_post_thumbnail( $id, (int) $att );
	// Replace the old plugin-generated poster only after the new one is in place, and only when it is this
	// video's OWN image: never the site default, an imported/reused library image parented elsewhere, or
	// an attachment another post still uses (2.11.0: xrv_attachment_is_exclusive).
	if ( $old && $old !== (int) $att && xrv_attachment_is_exclusive( $old, $id ) ) { wp_delete_attachment( $old, true ); }
	return true;
}

/* Success notice for both the list-table actions and the Settings buttons (scoped to XRV screens). */
add_action( 'admin_notices', 'xrv_bulk_notice' );
function xrv_bulk_notice() {
	if ( empty( $_GET['xrv_bulk'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change
	$screen = get_current_screen();
	if ( ! $screen || 'xroad_video' !== $screen->post_type ) { return; }
	$labels = array(
		'xrv_watch_off'       => 'Turned the watch page off for',
		'xrv_watch_on'        => 'Turned the watch page on for',
		'xrv_rebuild_posters' => 'Rebuilt local posters for',
	);
	$action = sanitize_key( wp_unslash( $_GET['xrv_bulk'] ) );
	if ( ! isset( $labels[ $action ] ) ) { return; }
	$n = isset( $_GET['xrv_n'] ) ? max( 0, (int) $_GET['xrv_n'] ) : 0;
	printf( '<div class="notice notice-success is-dismissible"><p><strong>XRV:</strong> %s %d video%s.</p></div>',
		esc_html( $labels[ $action ] ), (int) $n, 1 === $n ? '' : 's' );
}

/* At-a-glance "Watch page" column on the All Videos list, so the bulk state is visible without opening each. */
add_filter( 'manage_xroad_video_posts_columns', 'xrv_admin_columns' );
function xrv_admin_columns( $cols ) {
	$out = array();
	foreach ( $cols as $k => $v ) {
		if ( 'date' === $k ) { $out['xrv_watch'] = 'Watch page'; }
		$out[ $k ] = $v;
	}
	if ( ! isset( $out['xrv_watch'] ) ) { $out['xrv_watch'] = 'Watch page'; } // fallback if a theme/plugin removed the Date column
	return $out;
}
add_action( 'manage_xroad_video_posts_custom_column', 'xrv_admin_column', 10, 2 );
function xrv_admin_column( $col, $id ) {
	if ( 'xrv_watch' !== $col ) { return; }
	$on = '0' !== (string) get_post_meta( $id, '_xrv_watch_page', true );
	echo $on
		? '<span style="color:#1a9d57;font-weight:600">On</span>'
		: '<span style="color:#8a8d91">Off</span>';
	// 2.11.1: who serves the video's own address while a migration hands addresses over.
	if ( '' !== (string) get_post_meta( $id, '_xrv_dedicated_url', true ) ) {
		$st = xrv_address_state( $id );
		if ( 'old' === $st['state'] ) {
			echo '<br><span style="color:#8a6a2a;font-size:12px">' . esc_html__( 'Old page serves its address (not handed over)', 'xroad-videos' ) . '</span>';
		} elseif ( 'redirect' === $st['state'] ) {
			echo '<br><span style="color:#8a6a2a;font-size:12px">' . esc_html__( 'Redirects to its dedicated URL', 'xroad-videos' ) . '</span>';
		}
	}
}

/* =================================================================================================
 * 10. ADMIN BRANDING  (Crossroad attribution in the Plugins screen; admin-only)
 * ================================================================================================= */

add_filter( 'plugin_row_meta', 'xrv_plugin_row_meta', 10, 2 );
function xrv_plugin_row_meta( $links, $file ) {
	if ( $file === plugin_basename( __FILE__ ) ) {
		$links[] = '<a href="https://crossroad.us" target="_blank" rel="noopener">Crossroad Media</a>';
	}
	return $links;
}

add_action( 'after_plugin_row_' . plugin_basename( __FILE__ ), 'xrv_plugin_branding_row', 10, 0 );
function xrv_plugin_branding_row() {
	echo '<tr class="plugin-update-tr"><td colspan="4" class="plugin-update colspanchange" style="box-shadow:none;padding:0">'
		. '<div style="margin:0;border-left:4px solid #342669;background:#f7f6fb;padding:8px 12px;font-size:12px;color:#414042">'
		. '<strong style="color:#342669">Crossroad Media</strong> &nbsp;·&nbsp; Privacy-first, click-to-load video gallery &nbsp;·&nbsp; '
		. '<a href="https://crossroad.us" target="_blank" rel="noopener" style="color:#6873B7;text-decoration:none">crossroad.us</a>'
		. '</div></td></tr>';
}

/* =================================================================================================
 * 11. UNINSTALL  (opt-in destructive cleanup)
 *     A static helper guarded by an option, so reinstalling never destroys curated content unexpectedly.
 *     By default uninstall removes only the plugin's bookkeeping options; it leaves the videos, terms, and
 *     sideloaded thumbnails in place. Set the 'xrv_delete_data_on_uninstall' option to a truthy value to
 *     opt into full removal (CPT posts, taxonomy terms, and — only if also opted in — the thumbnails).
 * ================================================================================================= */

register_uninstall_hook( __FILE__, 'xrv_uninstall_cleanup' );
function xrv_uninstall_cleanup() {
	$delete_data   = (bool) get_option( 'xrv_delete_data_on_uninstall', false );
	$delete_thumbs = (bool) get_option( 'xrv_delete_thumbs_on_uninstall', false );

	if ( $delete_data ) {
		$posts = get_posts( array( 'post_type' => 'xroad_video', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( $posts as $pid ) {
			if ( $delete_thumbs ) {
				// Only the video's own poster: a shared image, or one parented elsewhere (a reused featured
				// image from a migration), stays in the Media Library.
				$tid = (int) get_post_meta( $pid, '_xrv_local_thumb_id', true );
				if ( $tid && xrv_attachment_is_exclusive( $tid, $pid ) ) {
					wp_delete_attachment( $tid, true );
				}
			}
			wp_delete_post( $pid, true );
		}
		foreach ( array( 'xrv_series', 'xrv_audience', 'xrv_topic' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $tid ) {
					wp_delete_term( $tid, $tax );
				}
			}
		}
	}

	wp_clear_scheduled_hook( 'xrv_sync_event' );

	delete_option( 'xrv_version' );
	delete_option( 'xrv_yt_api_key' );
	delete_option( 'xrv_settings' );
	delete_option( 'xrv_permalinks' );
	delete_option( 'xrv_sync_last' );
	delete_option( 'xrv_lock' );          // 2.11.0 shared write lock
	delete_transient( 'xrv_sync_lock' );   // pre-2.11.0 sync lock
	delete_option( 'xrv_delete_data_on_uninstall' );
	delete_option( 'xrv_delete_thumbs_on_uninstall' );
}

/* =================================================================================================
 * 12. WP-CLI  (2.11.0)
 *     wp xrv import | rollback | collection set | apply | export. Every write command takes the shared
 *     library lock (owner = run id, heartbeat per record), supports --dry-run (per-field diffs, no
 *     writes) and keeps a JSON run log in wp-content/xrv-runs/ (rewritten atomically after each record)
 *     holding the before-image of every changed field, so `wp xrv rollback <log>` can undo the run.
 *     Chunked and resumable for hosts that drop idle SSH sessions (--max-seconds, --limit, --resume).
 *     Import never fetches a URL: no oEmbed, no API, no remote thumbnail. Run every command with the
 *     global --user=<admin> flag (capability checks and kses filtering need a real administrator).
 * ================================================================================================= */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/* ---- 12a. Small shared helpers ---- */

	/** UTC timestamp for run logs. */
	function xrv_cli_now() {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/** Strip anything that could carry the YouTube API key before a log or manifest is written. */
	function xrv_cli_scrub( $v, $key = null ) {
		if ( null === $key ) {
			$key = (string) get_option( 'xrv_yt_api_key', '' );
		}
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $item ) {
				if ( is_string( $k ) && false !== stripos( $k, 'api_key' ) ) {
					continue;
				}
				$out[ $k ] = xrv_cli_scrub( $item, $key );
			}
			return $out;
		}
		return ( is_string( $v ) && '' !== $key && false !== strpos( $v, $key ) ) ? '[redacted]' : $v;
	}

	/** One value for a diff line: quoted, single-line, truncated. */
	function xrv_cli_fmt( $v ) {
		if ( null === $v ) {
			return '(none)';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( is_array( $v ) ) {
			return (string) wp_json_encode( $v );
		}
		$s = str_replace( array( "\r\n", "\n", "\r" ), '\n', (string) $v );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > 70 ) {
			$s = mb_substr( $s, 0, 67 ) . '...';
		} elseif ( strlen( $s ) > 70 ) {
			$s = substr( $s, 0, 67 ) . '...';
		}
		return '"' . $s . '"';
	}

	/** Print per-field diffs ({field, before, after}) under a record line; term IDs print as names. */
	function xrv_cli_print_diffs( $diffs, $indent = '      ' ) {
		foreach ( (array) $diffs as $d ) {
			$b = $d['before'];
			$a = $d['after'];
			if ( 0 === strpos( (string) $d['field'], 'terms.' ) ) {
				$tax = substr( (string) $d['field'], 6 );
				$b   = xrv_cli_term_label( $tax, $b );
				$a   = xrv_cli_term_label( $tax, $a );
			}
			WP_CLI::log( $indent . $d['field'] . ': ' . xrv_cli_fmt( $b ) . ' -> ' . xrv_cli_fmt( $a ) );
		}
	}

	/** Term IDs as names for output; a dry-run placeholder "new:Name" prints as "Name (new)". */
	function xrv_cli_term_label( $tax, $ids ) {
		if ( null === $ids ) {
			return null;
		}
		$out = array();
		foreach ( (array) $ids as $id ) {
			if ( is_string( $id ) && 0 === strpos( $id, 'new:' ) ) {
				$out[] = substr( $id, 4 ) . ' (new)';
				continue;
			}
			$t     = get_term( (int) $id, $tax );
			$out[] = ( $t && ! is_wp_error( $t ) ) ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : '#' . (int) $id;
		}
		return $out;
	}

	/** Writes need a real administrator: capability-gated helpers and kses both key off the current user. */
	function xrv_cli_require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run this command as an administrator: add the global flag --user=<admin-login>.' );
		}
	}

	/** Take the shared library lock for this run, or print the holder and exit non-zero. */
	function xrv_cli_lock( $run_id, $cmd ) {
		$got = xrv_lock_acquire( $run_id, $cmd );
		if ( true !== $got ) {
			$h = (array) $got;
			WP_CLI::error( sprintf(
				'The XRV library is locked by another writer: owner %s, command "%s", started %s, last heartbeat %d seconds ago. Wait for it to finish; a lock idle for 15 minutes can be taken over.',
				isset( $h['owner'] ) ? $h['owner'] : '?',
				isset( $h['cmd'] ) ? $h['cmd'] : '?',
				! empty( $h['started'] ) ? gmdate( 'Y-m-d H:i:s', (int) $h['started'] ) . ' UTC' : '?',
				max( 0, time() - ( isset( $h['heartbeat'] ) ? (int) $h['heartbeat'] : 0 ) )
			) );
		}
		// Released on every exit path, including WP_CLI::error() and fatals.
		register_shutdown_function( 'xrv_lock_release', (string) $run_id );
	}

	/** True when $id looks like a YouTube video ID. */
	function xrv_cli_valid_yt_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/', $id );
	}

	/** Parse "provider:id" (or a bare ID, meaning youtube). Returns array( provider, id ) or WP_Error. */
	function xrv_cli_parse_ref( $ref ) {
		$ref = trim( (string) $ref );
		$pos = strpos( $ref, ':' );
		$provider = ( false === $pos ) ? 'youtube' : strtolower( trim( substr( $ref, 0, $pos ) ) );
		$id       = ( false === $pos ) ? $ref : trim( substr( $ref, $pos + 1 ) );
		if ( 'youtube' !== $provider ) {
			return new WP_Error( 'xrv_provider', sprintf( '"%s": provider "%s" is not supported (youtube only in 2.11)', $ref, $provider ) );
		}
		if ( ! xrv_cli_valid_yt_id( $id ) ) {
			return new WP_Error( 'xrv_id', sprintf( '"%s" is not a valid YouTube video ID', $ref ) );
		}
		return array( $provider, $id );
	}

	/**
	 * Every xroad_video with this provider + ID, in ANY status (trash included), lowest ID first. A missing
	 * _xrv_provider counts as youtube. IDs compare case-sensitively in PHP (MySQL collations do not).
	 */
	function xrv_cli_find_videos( $provider, $id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, m.meta_value AS vid, pr.meta_value AS prov FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_xrv_video_id' LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_xrv_provider' WHERE p.post_type = 'xroad_video' AND m.meta_value = %s ORDER BY p.ID ASC",
			(string) $id
		) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$prov = ( null === $r->prov || '' === (string) $r->prov ) ? 'youtube' : (string) $r->prov;
			if ( (string) $r->vid === (string) $id && $prov === $provider && ! in_array( (int) $r->ID, $out, true ) ) {
				$out[] = (int) $r->ID;
			}
		}
		return $out;
	}

	/** "provider:id" for a video post ('' when it has no video ID). */
	function xrv_cli_video_ref( $post_id ) {
		$id = (string) get_post_meta( $post_id, '_xrv_video_id', true );
		if ( '' === $id ) {
			return '';
		}
		$prov = (string) get_post_meta( $post_id, '_xrv_provider', true );
		return ( '' === $prov ? 'youtube' : $prov ) . ':' . $id;
	}

	/** The URL a video with this slug has (or would have) under the given single base: home + front + base + slug. */
	function xrv_cli_video_url( $slug, $base = null ) {
		if ( null === $base ) {
			$pl   = xrv_permalinks();
			$base = $pl['single'];
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'xroad_video', $slug, home_url( '/' ) );
		}
		$front = xrv_permalink_front();
		$path  = trim( ( '' !== $front ? $front . '/' : '' ) . $base . '/' . $slug, '/' );
		return home_url( user_trailingslashit( '/' . $path ) );
	}

	/** A dedicated URL from a file: '/path' resolves against home_url(), absolute http(s) is kept, else WP_Error. */
	function xrv_cli_resolve_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( '/' === $raw[0] && ( 1 === strlen( $raw ) || '/' !== $raw[1] ) ) {
			$url = home_url( $raw );
		} elseif ( preg_match( '#^https?://[^/\s]+#i', $raw ) ) {
			$url = $raw;
		} else {
			return new WP_Error( 'xrv_url', sprintf( 'dedicated_url "%s" must be site-relative ("/path/") or an absolute http(s) URL', $raw ) );
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		return '' === $clean ? new WP_Error( 'xrv_url', sprintf( 'dedicated_url "%s" is not a valid URL', $raw ) ) : $clean;
	}

	/** An on-site absolute URL back to its site-relative form (export); off-site URLs are returned unchanged. */
	function xrv_cli_relative_url( $url ) {
		$url  = (string) $url;
		$home = untrailingslashit( home_url() );
		if ( '' !== $url && 0 === strpos( $url, $home . '/' ) ) {
			return substr( $url, strlen( $home ) );
		}
		return $url;
	}

	/* ---- 12b. Run log: one JSON file per run, rewritten atomically (temp file + rename) ---- */
	class XRV_CLI_Log {
		public $path = '';
		public $data = array();
		private $pos = array(); // record ref => position in data['records']

		/** The default log directory, created with an index.php and a deny-all .htaccess. */
		public static function default_dir() {
			$dir = trailingslashit( WP_CONTENT_DIR ) . 'xrv-runs';
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				WP_CLI::error( 'Cannot create the run-log directory ' . $dir );
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			}
			return $dir;
		}

		/** A fresh run id: 16 random hex characters (also the lock owner and the _xrv_run_id stamp). */
		public static function new_id() {
			return bin2hex( random_bytes( 8 ) );
		}

		/** Start a new run log, write it, and print its path. Take the lock with $run_id BEFORE calling this. */
		public static function start( $kind, $command, $args, $assoc, $dry_run, $override = '', $run_id = '' ) {
			$log      = new self();
			$run_id   = '' !== (string) $run_id ? (string) $run_id : self::new_id();
			$name     = 'xrv-' . sanitize_file_name( str_replace( ' ', '-', $command ) ) . '-' . gmdate( 'Ymd-His' ) . '-' . $run_id . '.json';
			$override = (string) $override;
			if ( '' === $override ) {
				$log->path = self::default_dir() . '/' . $name;
			} else {
				$dir_like  = is_dir( $override ) || in_array( substr( $override, -1 ), array( '/', '\\' ), true );
				$log->path = $dir_like ? trailingslashit( $override ) . $name : $override;
				if ( ! is_dir( dirname( $log->path ) ) && ! wp_mkdir_p( dirname( $log->path ) ) ) {
					WP_CLI::error( 'Cannot create the directory for --log=' . $override );
				}
			}
			$log->data = array(
				'kind'           => $kind,
				'run_id'         => $run_id,
				'command'        => $command,
				'args'           => array( 'positional' => array_values( (array) $args ), 'assoc' => (array) $assoc ),
				'home_url'       => home_url(),
				'plugin_version' => defined( 'XRV_VERSION' ) ? XRV_VERSION : '',
				'user'           => wp_get_current_user()->user_login,
				'dry_run'        => (bool) $dry_run,
				'started'        => xrv_cli_now(),
				'finished'       => null,
				'state'          => 'running',
				'records'        => array(),
			);
			$log->save();
			WP_CLI::log( ( $dry_run ? 'Dry run (nothing will be written). ' : '' ) . 'Run log: ' . $log->path );
			return $log;
		}

		/** Open an existing run log (resume / rollback). */
		public static function open( $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				WP_CLI::error( 'Run log not found: ' . $path );
			}
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $data ) || empty( $data['kind'] ) || empty( $data['run_id'] ) ) {
				WP_CLI::error( 'Not an XRV run log: ' . $path );
			}
			$log       = new self();
			$log->path = $path;
			$log->data = $data;
			$log->data['records'] = ( isset( $data['records'] ) && is_array( $data['records'] ) ) ? array_values( $data['records'] ) : array();
			foreach ( $log->data['records'] as $i => $r ) {
				if ( isset( $r['ref'] ) ) {
					$log->pos[ (string) $r['ref'] ] = $i;
				}
			}
			return $log;
		}

		/** A record by ref, or null. */
		public function get( $ref ) {
			$ref = (string) $ref;
			return isset( $this->pos[ $ref ] ) ? $this->data['records'][ $this->pos[ $ref ] ] : null;
		}

		/** Insert or replace a record, then rewrite the log. */
		public function put( $ref, $entry ) {
			$ref   = (string) $ref;
			$entry = array_merge( array( 'ref' => $ref ), (array) $entry );
			if ( isset( $this->pos[ $ref ] ) ) {
				$this->data['records'][ $this->pos[ $ref ] ] = $entry;
			} else {
				$this->pos[ $ref ]       = count( $this->data['records'] );
				$this->data['records'][] = $entry;
			}
			$this->save();
		}

		/** Close the run: state = complete | stopped | failed | refused. */
		public function finish( $state, $extra = array() ) {
			$this->data          = array_merge( $this->data, (array) $extra );
			$this->data['state'] = $state;
			$this->data['finished'] = xrv_cli_now();
			$this->save();
		}

		/** Atomic rewrite: encode (API key scrubbed), write a temp file beside the log, rename over it. */
		public function save() {
			$json = wp_json_encode( xrv_cli_scrub( $this->data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
			if ( false === $json ) {
				WP_CLI::error( 'Could not encode the run log ' . $this->path );
			}
			$tmp = $this->path . '.tmp' . getmypid();
			if ( false === file_put_contents( $tmp, $json . "\n" ) ) {
				WP_CLI::error( 'Could not write the run log ' . $this->path );
			}
			if ( ! @rename( $tmp, $this->path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( ! @rename( $tmp, $this->path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					WP_CLI::error( 'Could not replace the run log ' . $this->path );
				}
			}
		}
	}

	/* ---- 12c. Field images. A field is "post.<column>", "meta.<key>" (null = absent) or "terms.<taxonomy>"
	 *      (sorted term IDs). Diffs, before-images, post-apply images and restores all use these keys. ---- */

	function xrv_cli_field_get( $post_id, $field ) {
		$parts = explode( '.', (string) $field, 2 );
		$name  = isset( $parts[1] ) ? $parts[1] : '';
		if ( 'post' === $parts[0] ) {
			$p = get_post( $post_id );
			if ( ! $p ) {
				return null;
			}
			return in_array( $name, array( 'menu_order', 'post_parent' ), true ) ? (int) $p->$name : (string) $p->$name;
		}
		if ( 'meta' === $parts[0] ) {
			if ( ! metadata_exists( 'post', $post_id, $name ) ) {
				return null;
			}
			$v = get_post_meta( $post_id, $name, true );
			return is_scalar( $v ) ? (string) $v : maybe_serialize( $v );
		}
		if ( 'terms' === $parts[0] ) {
			$ids = wp_get_object_terms( $post_id, $name, array( 'fields' => 'ids' ) );
			$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids );
			return $ids;
		}
		return null;
	}

	/** Field values equal? Arrays compare as sorted lists; null (absent) only equals null. */
	function xrv_cli_same( $a, $b ) {
		if ( is_array( $a ) || is_array( $b ) ) {
			$a = array_map( 'strval', (array) $a );
			$b = array_map( 'strval', (array) $b );
			sort( $a );
			sort( $b );
			return $a === $b;
		}
		if ( null === $a || null === $b ) {
			return null === $a && null === $b;
		}
		return (string) $a === (string) $b;
	}

	/** Field-aware equality: _xrv_published_at compares as a UTC instant (a zone-less value counts as UTC). */
	function xrv_cli_same_field( $field, $a, $b ) {
		if ( 'meta._xrv_published_at' === $field && is_string( $a ) && is_string( $b ) && '' !== $a && '' !== $b ) {
			$zone = '/(Z|[+-]\d{2}:?\d{2})$/i';
			$ta   = strtotime( preg_match( $zone, $a ) ? $a : $a . ' UTC' );
			$tb   = strtotime( preg_match( $zone, $b ) ? $b : $b . ' UTC' );
			if ( false !== $ta && false !== $tb ) {
				return $ta === $tb;
			}
		}
		return xrv_cli_same( $a, $b );
	}

	/**
	 * Compare desired field values with a post (0 = not created yet). Returns the diffs {field, before,
	 * after}, the before-image of every changed field, and the set to write. post_date and post_date_gmt
	 * always travel together so a restore is exact.
	 */
	function xrv_cli_diff( $post_id, $desired ) {
		$res = array( 'diffs' => array(), 'before' => array(), 'set' => array() );
		foreach ( (array) $desired as $f => $v ) {
			$cur = $post_id ? xrv_cli_field_get( $post_id, $f ) : null;
			if ( ! xrv_cli_same_field( $f, $cur, $v ) ) {
				$res['diffs'][]      = array( 'field' => $f, 'before' => $cur, 'after' => $v );
				$res['before'][ $f ] = $cur;
				$res['set'][ $f ]    = $v;
			}
		}
		$pairs = array( 'post.post_date' => 'post.post_date_gmt', 'post.post_date_gmt' => 'post.post_date' );
		foreach ( $pairs as $one => $other ) {
			if ( array_key_exists( $one, $res['set'] ) && ! array_key_exists( $other, $res['set'] ) && array_key_exists( $other, $desired ) ) {
				$res['set'][ $other ]    = $desired[ $other ];
				$res['before'][ $other ] = $post_id ? xrv_cli_field_get( $post_id, $other ) : null;
			}
		}
		return $res;
	}

	/** Write fields to a post: post columns in one wp_update_post, then meta, then terms. Returns error strings. */
	function xrv_cli_fields_set( $post_id, $fields ) {
		$errors = array();
		$post   = array();
		$meta   = array();
		$terms  = array();
		foreach ( (array) $fields as $f => $v ) {
			$parts = explode( '.', (string) $f, 2 );
			if ( 'post' === $parts[0] ) {
				$post[ $parts[1] ] = $v;
			} elseif ( 'meta' === $parts[0] ) {
				$meta[ $parts[1] ] = $v;
			} elseif ( 'terms' === $parts[0] ) {
				$terms[ $parts[1] ] = $v;
			}
		}
		if ( $post ) {
			$post['ID'] = (int) $post_id;
			if ( array_key_exists( 'post_date', $post ) || array_key_exists( 'post_date_gmt', $post ) ) {
				$post['edit_date'] = true;
			}
			$r = wp_update_post( wp_slash( $post ), true );
			if ( is_wp_error( $r ) ) {
				$errors[] = 'post fields: ' . $r->get_error_message();
			}
		}
		foreach ( $meta as $k => $v ) {
			if ( null === $v ) {
				delete_post_meta( $post_id, $k );
				continue;
			}
			$v = is_serialized( $v ) ? maybe_unserialize( $v ) : (string) $v;
			update_post_meta( $post_id, $k, wp_slash( $v ) );
		}
		foreach ( $terms as $tax => $ids ) {
			$keep = array();
			foreach ( (array) $ids as $tid ) {
				$t = get_term( (int) $tid, $tax );
				if ( $t && ! is_wp_error( $t ) ) {
					$keep[] = (int) $t->term_id;
				} else {
					$errors[] = sprintf( '%s term %s no longer exists', $tax, $tid );
				}
			}
			$r = wp_set_object_terms( $post_id, $keep, $tax, false );
			if ( is_wp_error( $r ) ) {
				$errors[] = $tax . ': ' . $r->get_error_message();
			}
		}
		clean_post_cache( $post_id );
		return $errors;
	}

	/** Read a JSON file into an array, or exit with a clear error. */
	function xrv_cli_read_json( $path, $what ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( '%s not found or not readable: %s', $what, $path ) );
		}
		$raw  = (string) file_get_contents( $path );
		$raw  = ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) ? substr( $raw, 3 ) : $raw; // tolerate a UTF-8 BOM
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( '%s is not valid JSON (%s): %s', $what, json_last_error_msg(), $path ) );
		}
		return $data;
	}

	/* ---- 12d. Import: validate and normalise ONE record (no database writes, no network) ---- */

	/**
	 * Returns array( 'errors' => [], 'warnings' => [], 'v' => [] ). v holds only the fields the record
	 * specifies (absent = leave as-is on update): provider, id, title, slug, post_date, menu_order,
	 * watch_page ('0'|'1'), dedicated_url ('' = remove), description, upload, published_at (UTC), duration,
	 * is_short, poster_id, poster_path, terms (taxonomy => names, [] = clear).
	 */
	function xrv_cli_validate_record( $rec, $base_dir = '' ) {
		$e = array();
		$w = array();
		$v = array();
		if ( ! is_array( $rec ) ) {
			return array( 'errors' => array( 'record is not a JSON object' ), 'warnings' => array(), 'v' => array( 'provider' => '', 'id' => '' ) );
		}
		$known = array( 'provider', 'id', 'url', 'title', 'slug', 'post_date', 'menu_order', 'watch_page', 'dedicated_url', 'description', 'desc', 'upload', 'published_at', 'duration', 'is_short', 'poster_id', 'poster_path', 'terms', 'series', 'audience', 'topic' );
		foreach ( array_keys( $rec ) as $k ) {
			if ( ! in_array( (string) $k, $known, true ) ) {
				$w[] = sprintf( 'unknown field "%s" ignored', $k );
			}
		}
		$str = function ( $k ) use ( $rec ) {
			return ( isset( $rec[ $k ] ) && is_scalar( $rec[ $k ] ) && ! is_bool( $rec[ $k ] ) ) ? trim( (string) $rec[ $k ] ) : '';
		};

		$v['provider'] = '' !== $str( 'provider' ) ? strtolower( $str( 'provider' ) ) : 'youtube';
		if ( 'youtube' !== $v['provider'] ) {
			$e[] = sprintf( 'provider "%s" is not supported (youtube only in 2.11)', $v['provider'] );
		}
		$v['id'] = $str( 'id' );
		if ( '' === $v['id'] && '' !== $str( 'url' ) ) {
			$v['id'] = (string) xrv_extract_video_id( $str( 'url' ), 'youtube' );
		}
		if ( 'youtube' === $v['provider'] && ! xrv_cli_valid_yt_id( $v['id'] ) ) {
			$e[] = '' === $v['id'] ? 'id is missing' : sprintf( 'id "%s" is not a valid YouTube video ID', $v['id'] );
		}
		if ( '' !== $str( 'title' ) ) {
			$v['title'] = sanitize_text_field( $str( 'title' ) );
		}
		if ( array_key_exists( 'slug', $rec ) && '' !== $str( 'slug' ) ) {
			$v['slug'] = sanitize_title( $str( 'slug' ) );
			if ( '' === $v['slug'] ) {
				$e[] = sprintf( 'slug "%s" is empty once sanitized', $str( 'slug' ) );
			} elseif ( $v['slug'] !== $str( 'slug' ) ) {
				$w[] = sprintf( 'slug "%s" normalised to "%s"', $str( 'slug' ), $v['slug'] );
			}
		}
		if ( '' !== $str( 'post_date' ) ) {
			$d = $str( 'post_date' );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60 ) {
				$v['post_date'] = $d;
			} else {
				$e[] = sprintf( 'post_date "%s" must be a real "YYYY-MM-DD HH:MM:SS" (site-local time)', $d );
			}
		}
		if ( array_key_exists( 'menu_order', $rec ) && null !== $rec['menu_order'] && '' !== $rec['menu_order'] ) {
			if ( is_int( $rec['menu_order'] ) || ( is_string( $rec['menu_order'] ) && preg_match( '/^-?\d+$/', trim( $rec['menu_order'] ) ) ) ) {
				$v['menu_order'] = (int) $rec['menu_order'];
			} else {
				$e[] = sprintf( 'menu_order "%s" must be an integer', is_scalar( $rec['menu_order'] ) ? $rec['menu_order'] : gettype( $rec['menu_order'] ) );
			}
		}
		if ( array_key_exists( 'watch_page', $rec ) ) {
			$wp = xrv_norm_watch_page( is_array( $rec['watch_page'] ) ? null : $rec['watch_page'] );
			if ( '' !== $wp ) {
				$v['watch_page'] = $wp;
			}
		}
		if ( array_key_exists( 'dedicated_url', $rec ) ) {
			$u = is_scalar( $rec['dedicated_url'] ) || null === $rec['dedicated_url'] ? xrv_cli_resolve_url( (string) $rec['dedicated_url'] ) : new WP_Error( 'xrv_url', 'dedicated_url must be a string' );
			if ( is_wp_error( $u ) ) {
				$e[] = $u->get_error_message();
			} else {
				$v['dedicated_url'] = $u;
			}
		}
		$desc_key = array_key_exists( 'description', $rec ) ? 'description' : ( array_key_exists( 'desc', $rec ) ? 'desc' : '' );
		if ( array_key_exists( 'description', $rec ) && array_key_exists( 'desc', $rec ) ) {
			$w[] = 'both description and legacy desc given: using description';
		}
		if ( '' !== $desc_key ) {
			if ( is_scalar( $rec[ $desc_key ] ) || null === $rec[ $desc_key ] ) {
				$v['description'] = xrv_sanitize_multiline( (string) $rec[ $desc_key ] );
			} else {
				$e[] = $desc_key . ' must be a string';
			}
		}
		if ( '' !== $str( 'upload' ) ) {
			$u = $str( 'upload' );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $u, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$v['upload'] = $u;
			} else {
				$e[] = sprintf( 'upload "%s" is not a real YYYY-MM-DD date', $u );
			}
		}
		if ( '' !== $str( 'published_at' ) ) {
			$p  = $str( 'published_at' );
			$ok = preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/i', $p, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60;
			$ts = $ok ? strtotime( $p ) : false;
			if ( false === $ts ) {
				$e[] = sprintf( 'published_at "%s" must be an ISO-8601 date-time with a zone, e.g. 2026-08-30T03:36:24Z', $p );
			} else {
				$v['published_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $ts );
			}
		}
		if ( '' !== $str( 'duration' ) ) {
			$d = strtoupper( $str( 'duration' ) );
			if ( preg_match( '/^P(?=\d|T\d)(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', $d ) ) {
				$v['duration'] = $d;
			} else {
				$e[] = sprintf( 'duration "%s" must be ISO-8601, e.g. PT58M46S', $str( 'duration' ) );
			}
		}
		if ( array_key_exists( 'is_short', $rec ) && null !== $rec['is_short'] ) {
			$v['is_short'] = ! xrv_is_off( $rec['is_short'] );
		}
		$v = xrv_cli_validate_poster( $rec, $base_dir, $v, $e );
		$v = xrv_cli_validate_terms( $rec, $v, $e, $w );
		return array( 'errors' => $e, 'warnings' => $w, 'v' => $v );
	}

	/** Poster: poster_id = an existing image attachment whose file exists; poster_path = a LOCAL image file. */
	function xrv_cli_validate_poster( $rec, $base_dir, $v, &$e ) {
		$has_id   = isset( $rec['poster_id'] ) && '' !== $rec['poster_id'] && 0 !== $rec['poster_id'] && '0' !== $rec['poster_id'];
		$has_path = isset( $rec['poster_path'] ) && '' !== $rec['poster_path'];
		if ( $has_id && $has_path ) {
			$e[] = 'give poster_id or poster_path, not both';
			return $v;
		}
		if ( $has_id ) {
			$raw = $rec['poster_id'];
			$aid = ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) ? (int) $raw : 0;
			$att = $aid ? get_post( $aid ) : null;
			if ( ! $att || 'attachment' !== $att->post_type ) {
				$e[] = sprintf( 'poster_id %s is not an existing attachment', is_scalar( $raw ) ? $raw : gettype( $raw ) );
			} elseif ( ! wp_attachment_is_image( $aid ) ) {
				$e[] = sprintf( 'poster_id %d is not an image attachment (%s)', $aid, $att->post_mime_type );
			} else {
				$file = get_attached_file( $aid );
				if ( ! $file || ! file_exists( $file ) ) {
					$e[] = sprintf( 'poster_id %d: its file is missing on disk (%s)', $aid, (string) $file );
				} else {
					$v['poster_id'] = $aid;
				}
			}
		}
		if ( $has_path ) {
			$p = is_string( $rec['poster_path'] ) ? trim( $rec['poster_path'] ) : '';
			if ( '' === $p || false !== strpos( $p, '://' ) ) {
				$e[] = 'poster_path must be a local file path (URLs are never fetched by the CLI import)';
				return $v;
			}
			if ( ! preg_match( '#^([A-Za-z]:[\\\\/]|/|\\\\)#', $p ) && '' !== $base_dir ) {
				$p = rtrim( $base_dir, '/\\' ) . '/' . $p;
			}
			$type = wp_check_filetype( basename( $p ) );
			if ( ! is_file( $p ) || ! is_readable( $p ) ) {
				$e[] = sprintf( 'poster_path "%s" is not a readable file', $p );
			} elseif ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) || false === @getimagesize( $p ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$e[] = sprintf( 'poster_path "%s" is not an image file', $p );
			} else {
				$v['poster_path'] = $p;
			}
		}
		return $v;
	}

	/** Terms by NAME per taxonomy: {"terms":{"xrv_series":[..]}} (or series/audience/topic keys inside terms, or legacy top-level keys). */
	function xrv_cli_validate_terms( $rec, $v, &$e, &$w ) {
		$map   = array( 'xrv_series' => 'series', 'xrv_audience' => 'audience', 'xrv_topic' => 'topic' );
		$terms = array();
		$in    = array();
		if ( array_key_exists( 'terms', $rec ) && null !== $rec['terms'] ) {
			if ( ! is_array( $rec['terms'] ) ) {
				$e[] = 'terms must be an object like {"xrv_series":["Name"]}';
				return $v;
			}
			$in = $rec['terms'];
			foreach ( array_keys( $in ) as $k ) {
				if ( ! isset( $map[ $k ] ) && ! in_array( $k, $map, true ) ) {
					$w[] = sprintf( 'unknown taxonomy "%s" in terms ignored', $k );
				}
			}
		}
		foreach ( $map as $tax => $short ) {
			$src = null;
			if ( array_key_exists( $tax, $in ) ) {
				$src = $in[ $tax ];
				if ( array_key_exists( $short, $in ) || array_key_exists( $short, $rec ) ) {
					$w[] = sprintf( 'terms.%s wins over the duplicate "%s" key', $tax, $short );
				}
			} elseif ( array_key_exists( $short, $in ) ) {
				$src = $in[ $short ];
			} elseif ( array_key_exists( $short, $rec ) ) {
				$src = $rec[ $short ];
			} else {
				continue;
			}
			if ( null !== $src && ! is_array( $src ) && ! is_string( $src ) ) {
				$e[] = sprintf( '%s must be a list of term names', $tax );
				continue;
			}
			$terms[ $tax ] = xrv_import_term_list( null === $src ? array() : $src );
		}
		if ( $terms ) {
			$v['terms'] = $terms;
		}
		return $v;
	}

	/** The xroad_video (other than $exclude) that already owns $slug, in any status (a trashed post keeps "slug__trashed"). */
	function xrv_cli_slug_owner( $slug, $exclude = 0 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'xroad_video' AND post_name IN (%s, %s) AND ID <> %d ORDER BY ID ASC LIMIT 1",
			$slug,
			$slug . '__trashed',
			(int) $exclude
		) );
	}

	/** Warnings for other content at the URL this video will have: url_to_postid(), same-slug published posts and terms. */
	function xrv_cli_path_warnings( $slug, $video_id ) {
		global $wpdb;
		$w   = array();
		$url = xrv_cli_video_url( $slug );
		$hit = (int) url_to_postid( $url );
		if ( $hit && $hit !== (int) $video_id ) {
			$w[] = sprintf( '%s already resolves to %s %d', $url, get_post_type( $hit ), $hit );
		}
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'xroad_video', 'attachment' ) );
		if ( $types ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' ORDER BY ID ASC LIMIT 5", $slug ) );
			foreach ( (array) $rows as $r ) {
				if ( in_array( $r->post_type, $types, true ) && (int) $r->ID !== $hit ) {
					$w[] = sprintf( 'published %s %d also uses slug "%s" (%s)', $r->post_type, $r->ID, $slug, get_permalink( (int) $r->ID ) );
				}
			}
		}
		$terms = get_terms( array( 'slug' => $slug, 'hide_empty' => false, 'taxonomy' => get_taxonomies( array( 'public' => true ) ) ) );
		foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $t ) {
			$w[] = sprintf( 'term %s "%s" also uses slug "%s"', $t->taxonomy, html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), $slug );
		}
		return $w;
	}

	/** Import a LOCAL image into the Media Library, parented to the video (copy to a temp file first). */
	function xrv_cli_sideload( $post_id, $path, $title ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( basename( $path ) );
		if ( ! $tmp || ! @copy( $path, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'xrv_copy', 'could not copy poster_path to a temp file' );
		}
		$att = media_handle_sideload( array( 'name' => basename( $path ), 'tmp_name' => $tmp ), (int) $post_id, $title );
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $att;
		}
		if ( ! wp_attachment_is_image( $att ) ) {
			wp_delete_attachment( $att, true );
			return new WP_Error( 'xrv_not_image', 'poster_path did not import as an image' );
		}
		return (int) $att;
	}

	/** Term names to IDs for one taxonomy. Missing names become "new:Name" placeholders (created at write time). */
	function xrv_cli_term_ids( $tax, $names ) {
		$ids = array();
		foreach ( (array) $names as $name ) {
			$t     = get_term_by( 'name', $name, $tax );
			$ids[] = $t ? (int) $t->term_id : 'new:' . $name;
		}
		return $ids;
	}

	/* ---- 12e. Import: the shared core that validates and writes ONE record ---- */

	/**
	 * Validate and write one import record. $ctx: dry_run, status (created posts only), on_existing
	 * (skip|update), run_id, base_dir (for a relative poster_path), dupes ("provider:id" => count, ids seen
	 * more than once in the file), file_slugs (slug => count in the file), intent (callable, called with
	 * the entry BEFORE an insert so a crash leaves a findable trace). Never fetches a URL.
	 *
	 * Returns the log entry: key, status (created|updated|skipped|failed, or would-create|would-update on a
	 * dry run), message, post_id, created_status, before / after (every changed field), diffs (dry run),
	 * attachments {sideloaded, reused}, terms_created, warnings.
	 */
	function xrv_import_record( $rec, $ctx = array() ) {
		$ctx = wp_parse_args( $ctx, array( 'dry_run' => false, 'status' => 'draft', 'on_existing' => 'skip', 'run_id' => '', 'base_dir' => '', 'dupes' => array(), 'file_slugs' => array(), 'intent' => null ) );
		$val = xrv_cli_validate_record( $rec, $ctx['base_dir'] );
		$v   = $val['v'];
		$key = $v['provider'] . ':' . $v['id'];
		$err = $val['errors'];
		$out = array( 'key' => $key, 'status' => 'failed', 'message' => '', 'post_id' => 0, 'warnings' => $val['warnings'], 'attachments' => array( 'sideloaded' => array(), 'reused' => array() ) );
		if ( ! empty( $ctx['dupes'][ $key ] ) ) {
			$err[] = sprintf( 'id %s appears %d times in this file (every duplicate is rejected)', $v['id'], (int) $ctx['dupes'][ $key ] );
		}
		if ( $err ) {
			$out['message'] = implode( '; ', $err );
			return $out;
		}

		$found = xrv_cli_find_videos( $v['provider'], $v['id'] );
		$pid   = $found ? (int) $found[0] : 0;
		if ( count( $found ) > 1 ) {
			$out['warnings'][] = sprintf( '%d videos share this ID (%s); using post %d', count( $found ), implode( ', ', $found ), $pid );
		}
		if ( $pid && 'update' !== $ctx['on_existing'] ) {
			$out['status']  = 'skipped';
			$out['post_id'] = $pid;
			$out['message'] = sprintf( 'exists as post %d (%s); --on-existing=skip', $pid, get_post_status( $pid ) );
			return $out;
		}

		// Slug: explicit, or (create only) from the title. Unique among xroad_video in every status and in the file.
		$slug = isset( $v['slug'] ) ? $v['slug'] : ( $pid ? '' : sanitize_title( isset( $v['title'] ) ? $v['title'] : $v['id'] ) );
		if ( '' !== $slug ) {
			$owner = xrv_cli_slug_owner( $slug, $pid );
			if ( $owner ) {
				$err[] = sprintf( 'slug "%s" is already used by video %d (%s, %s)', $slug, $owner, get_post_status( $owner ), xrv_cli_video_ref( $owner ) );
			} elseif ( ! empty( $ctx['file_slugs'][ $slug ] ) && $ctx['file_slugs'][ $slug ] > 1 ) {
				$err[] = sprintf( 'slug "%s" is used by %d records in this file', $slug, (int) $ctx['file_slugs'][ $slug ] );
			} else {
				$out['warnings'] = array_merge( $out['warnings'], xrv_cli_path_warnings( $slug, $pid ) );
			}
		}
		if ( isset( $v['post_date'] ) && $pid && 'publish' === get_post_status( $pid ) && strtotime( get_gmt_from_date( $v['post_date'] ) . ' UTC' ) > time() ) {
			$err[] = 'post_date is in the future: updating it would turn this published video into a scheduled one (updates never change status)';
		}
		if ( $err ) {
			$out['post_id'] = $pid;
			$out['message'] = implode( '; ', $err );
			return $out;
		}

		// Desired state, as field => value (null = remove the meta).
		$d = array();
		if ( isset( $v['title'] ) || ! $pid ) {
			$d['post.post_title'] = isset( $v['title'] ) ? $v['title'] : $v['id'];
		}
		if ( '' !== $slug ) {
			$d['post.post_name'] = $slug;
		}
		if ( isset( $v['post_date'] ) ) {
			$d['post.post_date']     = $v['post_date'];
			$d['post.post_date_gmt'] = get_gmt_from_date( $v['post_date'] );
		}
		if ( isset( $v['menu_order'] ) ) {
			$d['post.menu_order'] = $v['menu_order'];
		} elseif ( ! $pid ) {
			global $wpdb;
			static $dry_extra = 0;
			$d['post.menu_order'] = (int) $wpdb->get_var( "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = 'xroad_video'" ) + 1 + ( $ctx['dry_run'] ? $dry_extra++ : 0 );
		}
		if ( ! $pid || isset( $v['is_short'] ) ) {
			$short = ! empty( $v['is_short'] );
			$d['meta._xrv_source_url'] = xrv_youtube_source_url( $v['id'], $short );
			$d['meta._xrv_short']      = $short ? '1' : null;
		}
		$meta_map = array( 'duration' => '_xrv_duration_iso', 'upload' => '_xrv_upload_date', 'description' => '_xrv_description', 'published_at' => '_xrv_published_at' );
		foreach ( $meta_map as $f => $mk ) {
			if ( isset( $v[ $f ] ) ) {
				$d[ 'meta.' . $mk ] = $v[ $f ];
			}
		}
		if ( isset( $v['watch_page'] ) && ! ( '1' === $v['watch_page'] && $pid && null === xrv_cli_field_get( $pid, 'meta._xrv_watch_page' ) ) ) {
			$d['meta._xrv_watch_page'] = $v['watch_page']; // absent already means "on": no write for an explicit '1'
		}
		if ( array_key_exists( 'dedicated_url', $v ) ) {
			$d['meta._xrv_dedicated_url'] = '' === $v['dedicated_url'] ? null : $v['dedicated_url'];
			if ( '' !== $v['dedicated_url'] && '' !== $slug && untrailingslashit( $v['dedicated_url'] ) === untrailingslashit( xrv_cli_video_url( $slug ) ) ) {
				$out['warnings'][] = 'dedicated_url equals the video\'s own URL: the page that lives there keeps serving it until `wp xrv handover` hands it to XRV';
			}
		}
		if ( isset( $v['poster_id'] ) ) {
			$d['meta._xrv_local_thumb_id'] = (string) $v['poster_id'];
			$d['meta._thumbnail_id']       = (string) $v['poster_id'];
		}
		foreach ( isset( $v['terms'] ) ? $v['terms'] : array() as $tax => $names ) {
			$d[ 'terms.' . $tax ] = xrv_cli_term_ids( $tax, $names );
		}
		return xrv_cli_import_write( $pid, $v, $d, $slug, $out, $ctx );
	}

	/** Second half of xrv_import_record(): diff, then the dry-run report or the actual insert / update. */
	function xrv_cli_import_write( $pid, $v, $d, $slug, $out, $ctx ) {
		$created  = ! $pid;
		$diff     = xrv_cli_diff( $pid, $d );
		$sideload = '';
		if ( isset( $v['poster_path'] ) ) {
			$cur = $pid ? (int) get_post_meta( $pid, '_xrv_local_thumb_id', true ) : 0;
			if ( $cur && get_post( $cur ) ) {
				$out['warnings'][] = sprintf( 'poster_path ignored: the video already has poster %d', $cur );
			} else {
				$sideload        = $v['poster_path'];
				$diff['diffs'][] = array( 'field' => 'poster_path (sideload)', 'before' => null, 'after' => $sideload );
			}
		}
		$out['post_id'] = $pid;
		if ( $pid && ! $diff['diffs'] ) {
			$out['status']  = 'skipped';
			$out['message'] = 'unchanged';
			return $out;
		}
		if ( isset( $v['poster_id'] ) ) {
			$out['attachments']['reused'][] = (int) $v['poster_id'];
		}
		if ( $ctx['dry_run'] ) {
			$out['status']  = $pid ? 'would-update' : 'would-create';
			$out['message'] = $pid ? sprintf( 'post %d: %d field(s) would change', $pid, count( $diff['diffs'] ) ) : 'would create a ' . $ctx['status'] . ' video';
			$out['diffs']   = $diff['diffs'];
			return $out;
		}
		$set = $diff['set'];
		if ( $sideload && $pid ) { // the poster meta changes too: keep its before-image
			foreach ( array( 'meta._xrv_local_thumb_id', 'meta._thumbnail_id' ) as $f ) {
				if ( ! array_key_exists( $f, $diff['before'] ) ) {
					$diff['before'][ $f ] = xrv_cli_field_get( $pid, $f );
				}
			}
		}
		if ( $created ) {
			if ( is_callable( $ctx['intent'] ) ) {
				call_user_func( $ctx['intent'], array_merge( $out, array( 'status' => 'intent', 'message' => 'insert started' ) ) );
			}
			$arr = array(
				'post_type'   => 'xroad_video',
				'post_status' => $ctx['status'],
				'post_title'  => $d['post.post_title'],
				'post_name'   => $slug,
				'menu_order'  => $d['post.menu_order'],
				'meta_input'  => array( '_xrv_run_id' => $ctx['run_id'], '_xrv_provider' => $v['provider'], '_xrv_video_id' => $v['id'] ),
			);
			if ( isset( $d['post.post_date'] ) ) {
				$arr['post_date']     = $d['post.post_date'];
				$arr['post_date_gmt'] = $d['post.post_date_gmt'];
			}
			$new = wp_insert_post( wp_slash( $arr ), true );
			if ( is_wp_error( $new ) || ! $new ) {
				$out['message'] = 'insert failed: ' . ( is_wp_error( $new ) ? $new->get_error_message() : 'unknown error' );
				return $out;
			}
			$pid            = (int) $new;
			$out['post_id'] = $pid;
			foreach ( array_keys( $set ) as $f ) {
				if ( 0 === strpos( $f, 'post.' ) ) {
					unset( $set[ $f ] );
				}
			}
			if ( get_post_field( 'post_name', $pid ) !== $slug ) {
				$out['warnings'][] = sprintf( 'WordPress changed the slug to "%s"', get_post_field( 'post_name', $pid ) );
			}
		}
		// Create missing terms by NAME (like the admin importer), then write everything else.
		$out['terms_created'] = array();
		foreach ( $set as $f => $val ) {
			if ( 0 !== strpos( $f, 'terms.' ) ) {
				continue;
			}
			$tax = substr( $f, 6 );
			$ids = array();
			foreach ( (array) $val as $tid ) {
				if ( ! is_string( $tid ) || 0 !== strpos( $tid, 'new:' ) ) {
					$ids[] = (int) $tid;
					continue;
				}
				$ins = wp_insert_term( substr( $tid, 4 ), $tax );
				if ( is_wp_error( $ins ) ) {
					$dup = (int) $ins->get_error_data( 'term_exists' );
					if ( $dup ) {
						$ids[] = $dup;
					} else {
						$out['warnings'][] = sprintf( '%s term "%s": %s', $tax, substr( $tid, 4 ), $ins->get_error_message() );
					}
					continue;
				}
				$ids[]                  = (int) $ins['term_id'];
				$out['terms_created'][] = array( 'taxonomy' => $tax, 'term_id' => (int) $ins['term_id'] );
			}
			$set[ $f ] = $ids;
		}
		$short = array_key_exists( 'meta._xrv_short', $set ) && '1' === $set['meta._xrv_short'];
		if ( $short ) {
			unset( $set['meta._xrv_short'], $set['meta._xrv_source_url'] );
		}
		$errs = xrv_cli_fields_set( $pid, $set );
		if ( $short ) {
			xrv_mark_short( $pid, $v['id'] );
		}
		if ( $sideload ) {
			$att = xrv_cli_sideload( $pid, $sideload, isset( $v['title'] ) ? $v['title'] : get_the_title( $pid ) );
			if ( is_wp_error( $att ) ) {
				$errs[] = 'poster_path: ' . $att->get_error_message();
			} else {
				$out['attachments']['sideloaded'][] = $att;
				update_post_meta( $pid, '_xrv_local_thumb_id', $att );
				set_post_thumbnail( $pid, $att );
			}
		}
		$out['warnings'] = array_merge( $out['warnings'], $errs );
		if ( $created ) {
			$out['status']         = 'created';
			$out['created_status'] = get_post_status( $pid );
			$out['message']        = sprintf( 'post %d (%s)', $pid, $out['created_status'] );
		} else {
			$after = array();
			foreach ( array_keys( $diff['before'] ) as $f ) {
				$after[ $f ] = xrv_cli_field_get( $pid, $f );
			}
			$out['status']  = 'updated';
			$out['before']  = $diff['before'];
			$out['after']   = $after;
			$out['message'] = sprintf( 'post %d: %d field(s) changed', $pid, count( $diff['diffs'] ) );
		}
		return $out;
	}

	/* ---- 12f. Import run: pre-pass, chunked loop, resume, summary ---- */

	/** Count ids ("provider:id") and explicit slugs across the whole file, so every duplicate errors before any write. */
	function xrv_cli_import_prepass( $records ) {
		$ids   = array();
		$slugs = array();
		foreach ( $records as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$p = ( isset( $r['provider'] ) && is_scalar( $r['provider'] ) && '' !== trim( (string) $r['provider'] ) ) ? strtolower( trim( (string) $r['provider'] ) ) : 'youtube';
			$i = ( isset( $r['id'] ) && is_scalar( $r['id'] ) ) ? trim( (string) $r['id'] ) : '';
			if ( '' === $i && ! empty( $r['url'] ) && is_string( $r['url'] ) ) {
				$i = (string) xrv_extract_video_id( $r['url'], 'youtube' );
			}
			if ( '' !== $i ) {
				$ids[ $p . ':' . $i ] = isset( $ids[ $p . ':' . $i ] ) ? $ids[ $p . ':' . $i ] + 1 : 1;
			}
			if ( isset( $r['slug'] ) && is_scalar( $r['slug'] ) && '' !== sanitize_title( (string) $r['slug'] ) ) {
				$s           = sanitize_title( (string) $r['slug'] );
				$slugs[ $s ] = isset( $slugs[ $s ] ) ? $slugs[ $s ] + 1 : 1;
			}
		}
		$multi = function ( $c ) {
			return $c > 1;
		};
		return array( array_filter( $ids, $multi ), array_filter( $slugs, $multi ) );
	}

	/** The post an interrupted insert ('intent') produced, if it got far enough to carry its ID and run id. */
	function xrv_cli_intent_post( $key, $run_id ) {
		$ref = xrv_cli_parse_ref( $key );
		if ( is_wp_error( $ref ) ) {
			return 0;
		}
		foreach ( xrv_cli_find_videos( $ref[0], $ref[1] ) as $pid ) {
			if ( (string) get_post_meta( $pid, '_xrv_run_id', true ) === (string) $run_id ) {
				return (int) $pid;
			}
		}
		return 0;
	}

	/** One output line per record (+ warnings, + diffs on a dry run). */
	function xrv_cli_print_entry( $n, $total, $entry ) {
		$extra = '';
		if ( ! empty( $entry['attachments']['reused'] ) ) {
			$extra .= '  reused poster ' . implode( ',', $entry['attachments']['reused'] );
		}
		if ( ! empty( $entry['attachments']['sideloaded'] ) ) {
			$extra .= '  sideloaded poster ' . implode( ',', $entry['attachments']['sideloaded'] );
		}
		$w    = strlen( (string) $total );
		$line = sprintf( '[%' . $w . 'd/%d] %-22s %-12s %s%s', $n, $total, $entry['key'], $entry['status'], $entry['message'], $extra );
		if ( 'failed' === $entry['status'] ) {
			WP_CLI::warning( $line );
		} else {
			WP_CLI::log( $line );
		}
		foreach ( isset( $entry['warnings'] ) ? (array) $entry['warnings'] : array() as $msg ) {
			WP_CLI::log( '      warning: ' . $msg );
		}
		if ( ! empty( $entry['diffs'] ) ) {
			xrv_cli_print_diffs( $entry['diffs'] );
		}
	}

	/** Status counts over every record in a log. */
	function xrv_cli_log_counts( $log ) {
		$c = array();
		foreach ( $log->data['records'] as $r ) {
			$s       = isset( $r['status'] ) ? $r['status'] : '?';
			$c[ $s ] = isset( $c[ $s ] ) ? $c[ $s ] + 1 : 1;
		}
		ksort( $c );
		return $c;
	}

	/**
	 * The import loop. $o: records, total, limit, max_seconds, t0, dry_run, resume_cmd (callable: log => string).
	 * Returns array( processed, failed, stopped reason ('' | limit | time | lock), changed post IDs ).
	 */
	function xrv_cli_import_loop( $log, $ctx, $o ) {
		$run_id    = $log->data['run_id'];
		$processed = 0;
		$failed    = 0;
		$done      = 0;
		$stopped   = '';
		$changed   = array();
		foreach ( $o['records'] as $n => $rec ) {
			$ref  = '#' . ( $n + 1 );
			$prev = $log->get( $ref );
			if ( $prev && in_array( $prev['status'], array( 'created', 'updated', 'skipped' ), true ) ) {
				$done++;
				continue;
			}
			if ( $o['limit'] && $processed >= $o['limit'] ) {
				$stopped = 'limit';
				break;
			}
			if ( $o['max_seconds'] && ( microtime( true ) - $o['t0'] ) >= $o['max_seconds'] ) {
				$stopped = 'time';
				break;
			}
			if ( ! $o['dry_run'] && ! xrv_lock_heartbeat( $run_id ) ) {
				$stopped = 'lock';
				break;
			}
			$c           = $ctx;
			$c['intent'] = function ( $entry ) use ( $log, $ref, $n ) {
				$log->put( $ref, array_merge( array( 'index' => $n + 1 ), $entry ) );
			};
			$resumed = ( $prev && 'intent' === $prev['status'] ) ? xrv_cli_intent_post( $prev['key'], $run_id ) : 0;
			if ( $resumed ) { // the insert happened before the interruption: finish that post's fields, keep it "created"
				$c['on_existing'] = 'update';
			}
			$entry = xrv_import_record( $rec, $c );
			if ( $resumed && 'failed' !== $entry['status'] ) {
				$entry['status']         = 'created';
				$entry['post_id']        = $resumed;
				$entry['created_status'] = get_post_status( $resumed );
				$entry['message']        = sprintf( 'post %d (%s), insert completed before an interruption', $resumed, $entry['created_status'] );
				unset( $entry['before'], $entry['after'] );
			}
			$entry = array_merge( array( 'index' => $n + 1 ), $entry );
			$log->put( $ref, $entry );
			$processed++;
			if ( 'failed' === $entry['status'] ) {
				$failed++;
			} elseif ( in_array( $entry['status'], array( 'created', 'updated' ), true ) ) {
				$changed[] = (int) $entry['post_id'];
			}
			xrv_cli_print_entry( $n + 1, $o['total'], $entry );
		}
		if ( $done ) {
			WP_CLI::log( sprintf( '%d record(s) were already done in this run and were skipped.', $done ) );
		}
		return array( $processed, $failed, $stopped, $changed );
	}

	/** The exact command that continues an import run. */
	function xrv_cli_resume_cmd( $file, $log, $assoc ) {
		$cmd = 'wp xrv import ' . escapeshellarg( $file ) . ' --resume=' . escapeshellarg( $log->path );
		foreach ( array( 'limit', 'max-seconds' ) as $k ) {
			if ( isset( $assoc[ $k ] ) ) {
				$cmd .= ' --' . $k . '=' . (int) $assoc[ $k ];
			}
		}
		return $cmd . ' --user=' . escapeshellarg( wp_get_current_user()->user_login );
	}

	/* ---- 12g. Rollback of an import or collection run ---- */

	/** Undo one CREATED record: force-delete (never trash) the post and the attachments the run sideloaded for it. */
	function xrv_cli_rollback_created( $r, $pid, $run_id, $force, $dry, $res ) {
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post ) {
			$res['message'] = 'already gone';
			return $res;
		}
		if ( (string) get_post_meta( $pid, '_xrv_run_id', true ) !== (string) $run_id ) {
			$res['status']  = 'refused';
			$res['message'] = sprintf( 'post %d does not carry this run id; left alone', $pid );
			return $res;
		}
		$was = isset( $r['created_status'] ) ? (string) $r['created_status'] : '';
		if ( '' !== $was && $post->post_status !== $was && ! $force ) {
			$res['status']  = 'kept';
			$res['message'] = sprintf( 'post %d changed status since the run (%s -> %s); --force deletes it anyway', $pid, $was, $post->post_status );
			return $res;
		}
		$atts = isset( $r['attachments']['sideloaded'] ) ? array_map( 'intval', (array) $r['attachments']['sideloaded'] ) : array();
		if ( $dry ) {
			$res['status']  = 'would-delete';
			$res['message'] = sprintf( 'would force-delete %s %d%s', $post->post_type, $pid, $atts ? ' and sideloaded attachment(s) ' . implode( ',', $atts ) : '' );
			return $res;
		}
		$gone = array();
		foreach ( $atts as $aid ) {
			if ( xrv_attachment_is_exclusive( $aid, $pid ) ) { // checked BEFORE the post goes: it must still be this post's alone
				wp_delete_attachment( $aid, true );
				$gone[] = $aid;
			} else {
				$res['warnings'][] = sprintf( 'attachment %d kept: it is no longer used by this post alone', $aid );
			}
		}
		wp_delete_post( $pid, true );
		$res['status']  = 'deleted';
		$res['message'] = sprintf( 'force-deleted %s %d%s', $post->post_type, $pid, $gone ? ' and sideloaded attachment(s) ' . implode( ',', $gone ) : '' );
		return $res;
	}

	/** Undo one UPDATED record from its before-image. A field changed again since the run is kept unless --force. */
	function xrv_cli_rollback_updated( $r, $pid, $force, $dry, $res ) {
		if ( ! $pid || ! get_post( $pid ) ) {
			$res['status']  = 'failed';
			$res['message'] = sprintf( 'post %d no longer exists', $pid );
			return $res;
		}
		$before = isset( $r['before'] ) ? (array) $r['before'] : array();
		$after  = isset( $r['after'] ) ? (array) $r['after'] : array();
		$set    = array();
		$diffs  = array();
		$kept   = array();
		foreach ( $before as $f => $old ) {
			$cur = xrv_cli_field_get( $pid, $f );
			if ( array_key_exists( $f, $after ) && ! xrv_cli_same_field( $f, $cur, $after[ $f ] ) && ! $force ) {
				$kept[] = $f;
				continue;
			}
			if ( ! xrv_cli_same_field( $f, $cur, $old ) ) {
				$set[ $f ] = $old;
				$diffs[]   = array( 'field' => $f, 'before' => $cur, 'after' => $old );
			}
		}
		foreach ( array( 'post.post_date' => 'post.post_date_gmt', 'post.post_date_gmt' => 'post.post_date' ) as $one => $other ) {
			if ( array_key_exists( $one, $set ) && ! array_key_exists( $other, $set ) && array_key_exists( $other, $before ) ) {
				$set[ $other ] = $before[ $other ];
			}
		}
		if ( $kept ) {
			$res['warnings'][] = 'changed again since the run, kept (use --force): ' . implode( ', ', $kept );
		}
		$res['diffs'] = $diffs;
		if ( $dry ) {
			$res['status']  = 'would-restore';
			$res['message'] = sprintf( 'post %d: %d field(s) would be restored', $pid, count( $diffs ) );
			return $res;
		}
		$errs = $set ? xrv_cli_fields_set( $pid, $set ) : array();
		foreach ( isset( $r['attachments']['sideloaded'] ) ? array_map( 'intval', (array) $r['attachments']['sideloaded'] ) : array() as $aid ) {
			if ( xrv_attachment_is_exclusive( $aid, $pid ) && (int) get_post_meta( $pid, '_xrv_local_thumb_id', true ) !== $aid && (int) get_post_meta( $pid, '_thumbnail_id', true ) !== $aid ) {
				wp_delete_attachment( $aid, true );
				$res['message'] = sprintf( 'deleted sideloaded attachment %d; ', $aid );
			}
		}
		$res['status']   = 'restored';
		$res['message'] .= sprintf( 'post %d: %d field(s) restored', $pid, count( $diffs ) );
		$res['warnings'] = array_merge( isset( $res['warnings'] ) ? $res['warnings'] : array(), $errs );
		return $res;
	}

	/** Recount every term of the three video taxonomies, then delete terms the run created that nothing uses now. */
	function xrv_cli_recount_terms( $created, $dry ) {
		global $wpdb;
		foreach ( array( 'xrv_series', 'xrv_audience', 'xrv_topic' ) as $tax ) {
			$tt = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'tt_ids' ) );
			if ( ! is_wp_error( $tt ) && $tt && ! $dry ) {
				wp_update_term_count_now( array_map( 'intval', $tt ), $tax );
			}
		}
		$deleted = array();
		foreach ( (array) $created as $c ) {
			$t = get_term( (int) $c['term_id'], $c['taxonomy'] );
			if ( ! $t || is_wp_error( $t ) ) {
				continue;
			}
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $t->term_taxonomy_id ) );
			if ( 0 === $used ) {
				if ( ! $dry ) {
					wp_delete_term( $t->term_id, $t->taxonomy );
				}
				$deleted[] = $t->taxonomy . ':' . html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		return $deleted;
	}

	/** Roll back an import or collection run (newest record first). Returns array( changed post IDs, problem count ). */
	function xrv_cli_rollback_run( $src, $rb, $force, $dry ) {
		$run_id   = (string) $src->data['run_id'];
		$records  = array_reverse( $src->data['records'] );
		$total    = count( $records );
		$changed  = array();
		$created  = array();
		$problems = 0;
		foreach ( $records as $i => $r ) {
			$ref    = isset( $r['ref'] ) ? (string) $r['ref'] : '#' . ( $total - $i );
			$status = isset( $r['status'] ) ? (string) $r['status'] : '';
			$pid    = isset( $r['post_id'] ) ? (int) $r['post_id'] : 0;
			$res    = array( 'key' => isset( $r['key'] ) ? $r['key'] : $ref, 'status' => 'skipped', 'message' => '', 'post_id' => $pid, 'was' => $status, 'warnings' => array() );
			if ( 'intent' === $status ) { // interrupted insert: did the post get written?
				$pid    = 'collection' === $src->data['kind'] ? xrv_cli_collection_by_run( $r, $run_id ) : xrv_cli_intent_post( $r['key'], $run_id );
				$status = $pid ? 'created' : $status;
			}
			if ( 'created' === $status ) {
				$res = xrv_cli_rollback_created( $r, $pid, $run_id, $force, $dry, $res );
			} elseif ( 'updated' === $status ) {
				$res = xrv_cli_rollback_updated( $r, $pid, $force, $dry, $res );
			} else {
				$res['message'] = 'nothing to undo (' . ( '' === $status ? 'unknown' : $status ) . ')';
			}
			$res['post_id'] = $pid;
			if ( ! empty( $r['terms_created'] ) ) {
				$created = array_merge( $created, (array) $r['terms_created'] );
			}
			if ( in_array( $res['status'], array( 'deleted', 'restored' ), true ) ) {
				$changed[] = $pid;
			} elseif ( in_array( $res['status'], array( 'kept', 'refused', 'failed' ), true ) ) {
				$problems++;
			}
			$rb->put( $ref, $res );
			xrv_cli_print_entry( $i + 1, $total, $res );
		}
		$gone = xrv_cli_recount_terms( $created, $dry );
		WP_CLI::log( sprintf( 'Terms recounted. %s created by the run and now unused: %s', $dry ? 'Would delete terms' : 'Deleted terms', $gone ? implode( ', ', $gone ) : 'none' ) );
		$rb->data['terms_deleted'] = $gone;
		return array( $changed, $problems );
	}

	/* ---- 12h. Collections: validation and the idempotent upsert shared by `collection set` and `apply` ---- */

	/** The xrv_collection with exactly this post_name (any status but trash), lowest ID first, or 0. */
	function xrv_cli_collection_id( $slug ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'xrv_collection' AND post_name = %s AND post_status <> 'trash' ORDER BY ID ASC LIMIT 1", (string) $slug ) );
	}

	/** The collection an interrupted insert ('intent') produced, if it carries this run id. */
	function xrv_cli_collection_by_run( $r, $run_id ) {
		$slug = isset( $r['slug'] ) ? (string) $r['slug'] : preg_replace( '/^collection:/', '', (string) $r['key'] );
		$id   = xrv_cli_collection_id( $slug );
		return ( $id && (string) get_post_meta( $id, '_xrv_run_id', true ) === (string) $run_id ) ? $id : 0;
	}

	/**
	 * Validate one collection spec {slug, title?, layout?, orderby?, videos?: ["provider:id", ...]} without
	 * writing. Returns array( errors, warnings, c ) where c = slug, title|null, layout|null, orderby|null,
	 * ids (ordered video post IDs) | null; null = not given (leave as-is on update).
	 */
	function xrv_cli_collection_validate( $spec ) {
		$e    = array();
		$w    = array();
		$raw  = ( isset( $spec['slug'] ) && is_scalar( $spec['slug'] ) ) ? trim( (string) $spec['slug'] ) : '';
		$slug = sanitize_title( $raw );
		if ( '' === $slug ) {
			$e[] = 'slug is missing';
		} elseif ( ctype_digit( $slug ) ) {
			$e[] = sprintf( 'slug "%s" is numeric: [xroad-videos collection="%s"] would read it as a post ID', $slug, $slug );
		} elseif ( $slug !== $raw ) {
			$w[] = sprintf( 'slug "%s" normalised to "%s"', $raw, $slug );
		}
		$c       = array( 'slug' => $slug, 'title' => null, 'layout' => null, 'orderby' => null, 'ids' => null );
		$allowed = array(
			'layout'  => array( '', 'grid', 'carousel', 'library' ),
			'orderby' => array( '', 'curated', 'newest', 'oldest', 'title' ),
		);
		foreach ( $allowed as $k => $ok ) {
			if ( array_key_exists( $k, $spec ) ) {
				$val = null === $spec[ $k ] ? '' : ( is_scalar( $spec[ $k ] ) ? strtolower( trim( (string) $spec[ $k ] ) ) : '?' );
				if ( in_array( $val, $ok, true ) ) {
					$c[ $k ] = $val;
				} else {
					$e[] = sprintf( '%s must be one of: %s', $k, implode( ', ', array_map( function ( $x ) { return '' === $x ? '"" (site default)' : $x; }, $ok ) ) );
				}
			}
		}
		if ( isset( $spec['title'] ) && is_scalar( $spec['title'] ) && '' !== trim( (string) $spec['title'] ) ) {
			$c['title'] = sanitize_text_field( (string) $spec['title'] );
		}
		if ( array_key_exists( 'videos', $spec ) ) {
			$refs     = is_array( $spec['videos'] ) ? $spec['videos'] : preg_split( '/[\s,]+/', (string) $spec['videos'], -1, PREG_SPLIT_NO_EMPTY );
			$c['ids'] = array();
			foreach ( $refs as $ref ) {
				$p = xrv_cli_parse_ref( is_scalar( $ref ) ? (string) $ref : '' );
				if ( is_wp_error( $p ) ) {
					$e[] = $p->get_error_message();
					continue;
				}
				$found = xrv_cli_find_videos( $p[0], $p[1] );
				if ( ! $found ) {
					$e[] = sprintf( 'unknown video %s:%s (no xroad_video with that provider + ID)', $p[0], $p[1] );
					continue;
				}
				if ( in_array( $found[0], $c['ids'], true ) ) {
					$w[] = sprintf( '%s:%s listed twice; kept the first position', $p[0], $p[1] );
					continue;
				}
				if ( 'trash' === get_post_status( $found[0] ) ) {
					$w[] = sprintf( '%s:%s is in the trash (post %d)', $p[0], $p[1], $found[0] );
				}
				$c['ids'][] = (int) $found[0];
			}
		}
		return array( $e, $w, $c );
	}

	/**
	 * Idempotent upsert of one validated collection. Created as 'publish' (the type is non-public) with an
	 * explicit post_name; afterwards get_page_by_path( slug, OBJECT, 'xrv_collection' ) must resolve to it.
	 * $ctx: dry_run, run_id, intent (callable). Returns a log entry like xrv_import_record().
	 */
	function xrv_cli_collection_upsert( $c, $ctx ) {
		$cid = xrv_cli_collection_id( $c['slug'] );
		$out = array( 'key' => 'collection:' . $c['slug'], 'slug' => $c['slug'], 'status' => 'failed', 'message' => '', 'post_id' => $cid, 'warnings' => array() );
		$d   = array( 'post.post_status' => 'publish' );
		if ( ! $cid || null !== $c['title'] ) {
			$d['post.post_title'] = null !== $c['title'] ? $c['title'] : ucwords( str_replace( '-', ' ', $c['slug'] ) );
		}
		if ( null !== $c['ids'] || ! $cid ) {
			$d['meta._xrvc_video_ids'] = implode( ',', (array) $c['ids'] );
		}
		foreach ( array( 'layout' => 'meta._xrvc_layout', 'orderby' => 'meta._xrvc_orderby' ) as $k => $f ) {
			if ( null !== $c[ $k ] || ! $cid ) {
				$d[ $f ] = (string) $c[ $k ];
			}
		}
		$diff = xrv_cli_diff( $cid, $d );
		if ( $cid && ! $diff['diffs'] ) {
			$out['status']  = 'skipped';
			$out['message'] = sprintf( 'collection %d unchanged; nothing written', $cid );
			return $out;
		}
		if ( ! empty( $ctx['dry_run'] ) ) {
			$out['status']  = $cid ? 'would-update' : 'would-create';
			$out['message'] = $cid ? sprintf( 'collection %d: %d field(s) would change', $cid, count( $diff['diffs'] ) ) : 'would create a published collection';
			$out['diffs']   = $diff['diffs'];
			return $out;
		}
		if ( ! $cid ) {
			if ( ! empty( $ctx['intent'] ) && is_callable( $ctx['intent'] ) ) {
				call_user_func( $ctx['intent'], array_merge( $out, array( 'status' => 'intent', 'message' => 'insert started' ) ) );
			}
			$meta = array( '_xrv_run_id' => (string) $ctx['run_id'] );
			foreach ( $d as $f => $v ) {
				if ( 0 === strpos( $f, 'meta.' ) ) {
					$meta[ substr( $f, 5 ) ] = $v;
				}
			}
			$new = wp_insert_post( wp_slash( array( 'post_type' => 'xrv_collection', 'post_status' => 'publish', 'post_title' => $d['post.post_title'], 'post_name' => $c['slug'], 'meta_input' => $meta ) ), true );
			if ( is_wp_error( $new ) || ! $new ) {
				$out['message'] = 'insert failed: ' . ( is_wp_error( $new ) ? $new->get_error_message() : 'unknown error' );
				return $out;
			}
			$cid                   = (int) $new;
			$out['status']         = 'created';
			$out['created_status'] = get_post_status( $cid );
			$out['message']        = sprintf( 'collection %d created (%d video(s))', $cid, count( (array) $c['ids'] ) );
		} else {
			$out['warnings'] = xrv_cli_fields_set( $cid, $diff['set'] );
			$after           = array();
			foreach ( array_keys( $diff['before'] ) as $f ) {
				$after[ $f ] = xrv_cli_field_get( $cid, $f );
			}
			$out['status']  = 'updated';
			$out['before']  = $diff['before'];
			$out['after']   = $after;
			$out['message'] = sprintf( 'collection %d: %d field(s) changed', $cid, count( $diff['diffs'] ) );
			$out['diffs']   = $diff['diffs'];
		}
		$out['post_id'] = $cid;
		$hit            = get_page_by_path( $c['slug'], OBJECT, 'xrv_collection' );
		$out['resolves'] = ( $hit && (int) $hit->ID === $cid );
		if ( ! $out['resolves'] ) {
			$out['warnings'][] = sprintf( 'get_page_by_path("%s") resolves to %s, not collection %d', $c['slug'], $hit ? 'post ' . $hit->ID : 'nothing', $cid );
		}
		return $out;
	}

	/* ---- 12i. Apply: raw images (pre-apply / post-apply), restore helpers ---- */

	/** An option exactly as stored (read from the database, not through filters): exists, value, autoload. */
	function xrv_cli_option_raw( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( ! $row ) {
			return array( 'exists' => false, 'value' => null, 'autoload' => '' );
		}
		return array( 'exists' => true, 'value' => maybe_unserialize( $row->option_value ), 'autoload' => (string) $row->autoload );
	}

	/** Write an option back RAW: the sanitize filter is removed around the write; absent = delete_option(). */
	function xrv_cli_option_restore( $name, $img ) {
		global $wp_filter;
		$hook  = 'sanitize_option_' . $name;
		$saved = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
		remove_all_filters( $hook );
		if ( empty( $img['exists'] ) ) {
			delete_option( $name );
		} elseif ( ! xrv_cli_option_raw( $name )['exists'] ) {
			add_option( $name, $img['value'], '', ! in_array( (string) $img['autoload'], array( 'no', 'off', 'auto-off' ), true ) );
		} else {
			update_option( $name, $img['value'] );
		}
		if ( null !== $saved ) {
			$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	/** The channel-sync schedule: next timestamp and recurrence (false when none). */
	function xrv_cli_cron_image() {
		return array( 'timestamp' => wp_next_scheduled( 'xrv_sync_event' ), 'recurrence' => wp_get_schedule( 'xrv_sync_event' ) );
	}

	/** Put the sync schedule back exactly: clear, then schedule at the recorded timestamp / recurrence, or none. */
	function xrv_cli_cron_restore( $img ) {
		wp_clear_scheduled_hook( 'xrv_sync_event' );
		if ( ! empty( $img['timestamp'] ) ) {
			if ( ! empty( $img['recurrence'] ) ) {
				wp_schedule_event( (int) $img['timestamp'], (string) $img['recurrence'], 'xrv_sync_event' );
			} else {
				wp_schedule_single_event( (int) $img['timestamp'], 'xrv_sync_event' );
			}
		}
	}

	/** Field lists captured per touched object. */
	function xrv_cli_image_fields( $type ) {
		if ( 'collection' === $type ) {
			return array( 'post.post_title', 'post.post_status', 'post.post_name', 'meta._xrvc_video_ids', 'meta._xrvc_layout', 'meta._xrvc_orderby' );
		}
		return array( 'post.post_status', 'post.menu_order', 'post.post_date', 'post.post_date_gmt', 'meta._xrv_dedicated_url', 'meta._xrv_watch_page' );
	}

	/**
	 * Capture everything an apply may touch: raw xrv_settings and xrv_permalinks, the sync schedule, each
	 * touched collection (by slug) and video (by "provider:id"), plus a hash of the rewrite rules.
	 */
	function xrv_cli_apply_capture( $slugs, $refs ) {
		$img = array(
			'options'     => array( 'xrv_settings' => xrv_cli_option_raw( 'xrv_settings' ), 'xrv_permalinks' => xrv_cli_option_raw( 'xrv_permalinks' ) ),
			'cron'        => xrv_cli_cron_image(),
			'collections' => array(),
			'videos'      => array(),
			'rewrite'     => md5( maybe_serialize( get_option( 'rewrite_rules' ) ) ),
			'rewrite_xrv' => xrv_cli_rewrite_fingerprint(),
		);
		foreach ( (array) $slugs as $slug ) {
			$cid = xrv_cli_collection_id( $slug );
			$c   = array( 'exists' => (bool) $cid, 'post_id' => $cid, 'fields' => array() );
			foreach ( $cid ? xrv_cli_image_fields( 'collection' ) : array() as $f ) {
				$c['fields'][ $f ] = xrv_cli_field_get( $cid, $f );
			}
			$img['collections'][ $slug ] = $c;
		}
		foreach ( (array) $refs as $ref => $pid ) {
			$v = array( 'post_id' => (int) $pid, 'fields' => array() );
			foreach ( xrv_cli_image_fields( 'video' ) as $f ) {
				$v['fields'][ $f ] = xrv_cli_field_get( $pid, $f );
			}
			$img['videos'][ $ref ] = $v;
		}
		return $img;
	}

	/** Paths that differ between two images (JSON-equal comparison per object). */
	function xrv_cli_image_diff( $a, $b, $skip_rewrite = false ) {
		$out = array();
		foreach ( array( 'options', 'collections', 'videos' ) as $sec ) {
			$keys = array_unique( array_merge( array_keys( (array) $a[ $sec ] ), array_keys( (array) $b[ $sec ] ) ) );
			foreach ( $keys as $k ) {
				$x = isset( $a[ $sec ][ $k ] ) ? $a[ $sec ][ $k ] : null;
				$y = isset( $b[ $sec ][ $k ] ) ? $b[ $sec ][ $k ] : null;
				if ( wp_json_encode( $x ) !== wp_json_encode( $y ) ) {
					$out[] = $sec . '.' . $k;
				}
			}
		}
		if ( wp_json_encode( $a['cron'] ) !== wp_json_encode( $b['cron'] ) ) {
			$out[] = 'cron.xrv_sync_event';
		}
		// 2.11.2: compare XRV's own rules. The whole table also changes when other plugins' rules are rebuilt in
		// another context (a plugin update, a web-request flush), which made a correct restore report a difference.
		// Logs written before 2.11.2 have no rewrite_xrv and keep the whole-table comparison.
		$ka = isset( $a['rewrite_xrv'], $b['rewrite_xrv'] ) ? 'rewrite_xrv' : 'rewrite';
		if ( ! $skip_rewrite && (string) $a[ $ka ] !== (string) $b[ $ka ] ) {
			$out[] = 'rewrite_rules';
		}
		return $out;
	}

	/** Fingerprint of the stored rewrite rules that route to XRV (videos and collections): key, query and order. */
	function xrv_cli_rewrite_fingerprint() {
		$mine = array();
		foreach ( (array) get_option( 'rewrite_rules' ) as $match => $query ) {
			if ( preg_match( '/(^|[?&])(xroad_video|xrv_collection|xrv_series|xrv_audience|xrv_topic)=|post_type=(xroad_video|xrv_collection)\b/', (string) $query ) ) {
				$mine[] = $match . ' => ' . $query;
			}
		}
		return md5( implode( "\n", $mine ) );
	}

	/** Flush the object cache and, on WP Engine, its page / CDN caches (each method guarded). */
	function xrv_cli_purge_caches() {
		wp_cache_flush();
		if ( class_exists( 'WpeCommon' ) ) {
			foreach ( array( 'purge_memcached', 'clear_maxcdn_cache', 'purge_varnish_cache' ) as $m ) {
				if ( method_exists( 'WpeCommon', $m ) ) {
					call_user_func( array( 'WpeCommon', $m ) );
				}
			}
		}
	}

	/* ---- 12j. Apply: validate a manifest into a plan (no writes) ---- */

	/** One manifest video: patches only status, dedicated_url (null / "" deletes), watch_page, menu_order, post_date. */
	function xrv_cli_apply_video_spec( $vrec, &$e ) {
		$prov = ( isset( $vrec['provider'] ) && is_scalar( $vrec['provider'] ) && '' !== (string) $vrec['provider'] ) ? (string) $vrec['provider'] : 'youtube';
		$ref  = xrv_cli_parse_ref( $prov . ':' . ( isset( $vrec['id'] ) && is_scalar( $vrec['id'] ) ? (string) $vrec['id'] : '' ) );
		if ( is_wp_error( $ref ) ) {
			$e[] = 'videos: ' . $ref->get_error_message();
			return null;
		}
		$key   = $ref[0] . ':' . $ref[1];
		$found = xrv_cli_find_videos( $ref[0], $ref[1] );
		if ( ! $found ) {
			$e[] = sprintf( 'videos: unknown video %s (no xroad_video with that provider + ID)', $key );
			return null;
		}
		$pid  = (int) $found[0];
		$meta = ( isset( $vrec['meta'] ) && is_array( $vrec['meta'] ) ) ? $vrec['meta'] : array();
		$src  = array_merge( $meta, $vrec );
		$d    = array();
		if ( isset( $src['status'] ) && '' !== $src['status'] ) {
			if ( in_array( $src['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
				$d['post.post_status'] = $src['status'];
			} else {
				$e[] = sprintf( 'videos %s: status "%s" must be publish, draft, pending or private', $key, is_scalar( $src['status'] ) ? $src['status'] : '?' );
			}
		}
		if ( isset( $src['post_date'] ) && '' !== $src['post_date'] ) {
			$pd = is_scalar( $src['post_date'] ) ? (string) $src['post_date'] : '';
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $pd, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60 ) {
				$d['post.post_date']     = $pd;
				$d['post.post_date_gmt'] = get_gmt_from_date( $pd );
			} else {
				$e[] = sprintf( 'videos %s: post_date "%s" must be "YYYY-MM-DD HH:MM:SS" (site-local)', $key, $pd );
			}
		}
		if ( isset( $src['menu_order'] ) && '' !== $src['menu_order'] ) {
			if ( is_int( $src['menu_order'] ) || ( is_string( $src['menu_order'] ) && preg_match( '/^-?\d+$/', $src['menu_order'] ) ) ) {
				$d['post.menu_order'] = (int) $src['menu_order'];
			} else {
				$e[] = sprintf( 'videos %s: menu_order must be an integer', $key );
			}
		}
		if ( array_key_exists( 'watch_page', $src ) ) {
			$wp = xrv_norm_watch_page( is_array( $src['watch_page'] ) ? null : $src['watch_page'] );
			if ( '' !== $wp && ! ( '1' === $wp && null === xrv_cli_field_get( $pid, 'meta._xrv_watch_page' ) ) ) {
				$d['meta._xrv_watch_page'] = $wp;
			}
		}
		if ( array_key_exists( 'dedicated_url', $src ) ) {
			$u = ( null === $src['dedicated_url'] || is_scalar( $src['dedicated_url'] ) ) ? xrv_cli_resolve_url( (string) $src['dedicated_url'] ) : new WP_Error( 'xrv_url', 'dedicated_url must be a string' );
			if ( is_wp_error( $u ) ) {
				$e[] = sprintf( 'videos %s: %s', $key, $u->get_error_message() );
			} else {
				$d['meta._xrv_dedicated_url'] = '' === $u ? null : $u;
			}
		}
		return array( 'ref' => $key, 'post_id' => $pid, 'desired' => $d );
	}

	/** Validate the requested manifest sections. Returns array( errors, warnings, plan ). */
	function xrv_cli_apply_plan( $m, $sections, $include_sync ) {
		$e    = array();
		$w    = array();
		$plan = array( 'settings' => null, 'permalinks' => null, 'videos' => array(), 'collections' => array() );
		if ( in_array( 'settings', $sections, true ) && isset( $m['settings'] ) ) {
			$known   = array_merge( array_keys( xrv_settings_defaults() ), array( 'icon_override' ) );
			$partial = array();
			$sync    = array();
			foreach ( is_array( $m['settings'] ) ? $m['settings'] : array() as $k => $val ) {
				if ( false !== stripos( (string) $k, 'api_key' ) ) {
					$w[] = sprintf( 'settings.%s ignored: the API key is never applied or logged', $k );
				} elseif ( 0 === strpos( (string) $k, 'sync_' ) && ! $include_sync ) {
					$sync[] = $k;
				} elseif ( ! in_array( (string) $k, $known, true ) ) {
					$w[] = sprintf( 'settings.%s is not an XRV setting; ignored', $k );
				} else {
					$partial[ $k ] = $val;
				}
			}
			if ( $sync ) {
				$w[] = 'sync settings left untouched (add --include-sync to apply them): ' . implode( ', ', $sync );
			}
			$plan['settings'] = $partial;
		}
		if ( in_array( 'permalinks', $sections, true ) ) {
			if ( ! isset( $m['permalinks'] ) || ! is_array( $m['permalinks'] ) ) {
				$e[] = 'the permalinks section was requested but the manifest has no "permalinks" object';
			} else {
				$cur = xrv_permalinks();
				$s   = xrv_sanitize_permalink_base( array_key_exists( 'single', $m['permalinks'] ) ? $m['permalinks']['single'] : $cur['single'], 'video' );
				$a   = xrv_sanitize_permalink_base( array_key_exists( 'archive', $m['permalinks'] ) ? (string) $m['permalinks']['archive'] : $cur['archive'], '' );
				foreach ( array( $s, $a ) as $r ) {
					if ( is_wp_error( $r ) ) {
						$e[] = 'permalinks: ' . $r->get_error_message();
					}
				}
				if ( ! is_wp_error( $s ) && ! is_wp_error( $a ) ) {
					$plan['permalinks'] = array( 'single' => $s, 'archive' => $a );
				}
			}
		}
		if ( in_array( 'videos', $sections, true ) ) {
			foreach ( ( isset( $m['videos'] ) && is_array( $m['videos'] ) ) ? $m['videos'] : array() as $vrec ) {
				$spec = is_array( $vrec ) ? xrv_cli_apply_video_spec( $vrec, $e ) : null;
				if ( $spec ) {
					$plan['videos'][ $spec['ref'] ] = $spec;
				}
			}
		}
		if ( in_array( 'collections', $sections, true ) ) {
			foreach ( ( isset( $m['collections'] ) && is_array( $m['collections'] ) ) ? $m['collections'] : array() as $spec ) {
				list( $ce, $cw, $c ) = xrv_cli_collection_validate( is_array( $spec ) ? $spec : array() );
				foreach ( $ce as $x ) {
					$e[] = sprintf( 'collections[%s]: %s', $c['slug'], $x );
				}
				foreach ( $cw as $x ) {
					$w[] = sprintf( 'collections[%s]: %s', $c['slug'], $x );
				}
				if ( isset( $plan['collections'][ $c['slug'] ] ) ) {
					$e[] = sprintf( 'collections[%s]: listed twice', $c['slug'] );
				} elseif ( ! $ce ) {
					$plan['collections'][ $c['slug'] ] = $c;
				}
			}
		}
		return array( $e, $w, $plan );
	}

	/* ---- 12k. Apply: what a new video base would shadow, and dedicated URLs vs the new permalink ---- */

	/** Report for a base change. Returns array( lines, shadow (count), differ (count), equal (count) ). */
	function xrv_cli_permalink_report( $single, $archive ) {
		global $wpdb, $wp_rewrite;
		$lines  = array();
		$shadow = 0;
		$front  = xrv_permalink_front();
		$bases  = array( 'single' => $single );
		if ( '' !== (string) $archive ) {
			$bases['archive'] = $archive;
		}
		foreach ( $bases as $which => $base ) {
			$path    = trim( ( '' !== $front ? $front . '/' : '' ) . $base, '/' );
			$lines[] = sprintf( '%s base "%s": videos live under %s', $which, $base, home_url( '/' . $path . '/' ) );
			$hier    = get_post_types( array( 'public' => true, 'hierarchical' => true ) );
			$page    = $hier ? get_page_by_path( $path, OBJECT, array_values( $hier ) ) : null;
			if ( $page && 'publish' === $page->post_status ) {
				$shadow++;
				$lines[] = sprintf( '  SHADOW: %s %d "%s" lives at /%s/', $page->post_type, $page->ID, $page->post_title, $path );
			}
			$hit = (int) url_to_postid( home_url( '/' . $path . '/' ) );
			if ( $hit && 'xroad_video' !== get_post_type( $hit ) && ( ! $page || $hit !== (int) $page->ID ) ) {
				$shadow++;
				$lines[] = sprintf( '  SHADOW: /%s/ resolves to %s %d today', $path, get_post_type( $hit ), $hit );
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type NOT IN ('attachment','revision','nav_menu_item','xroad_video','xrv_collection') ORDER BY ID ASC LIMIT 10", $base ) );
			foreach ( (array) $rows as $r ) {
				$lines[] = sprintf( '  note: published %s %d uses the slug "%s" (%s)', $r->post_type, $r->ID, $base, get_permalink( (int) $r->ID ) );
			}
			$terms = get_terms( array( 'slug' => $base, 'hide_empty' => false, 'taxonomy' => array_values( get_taxonomies( array( 'public' => true ) ) ) ) );
			foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $t ) {
				if ( 'category' === $t->taxonomy && false !== strpos( (string) get_option( 'permalink_structure' ), '%category%' ) ) {
					$shadow++;
					$posts   = get_posts( array( 'category' => $t->term_id, 'numberposts' => 5, 'post_status' => 'publish', 'fields' => 'ids' ) );
					$lines[] = sprintf( '  SHADOW: category "%s" (%d post(s)): its posts live at /%s/<postname>/; XRV takes one of those addresses only for a published video that has been handed it (no dedicated URL at that address), and every other post keeps serving', html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), $t->count, $path );
					foreach ( $posts as $p ) {
						$lines[] = '          e.g. ' . get_permalink( $p );
					}
				} else {
					$lines[] = sprintf( '  note: term %s "%s" uses the slug "%s" (%s)', $t->taxonomy, html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), $base, get_term_link( $t ) );
				}
			}
			$probe = $path . '/xrv-probe-slug/';
			foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $regex => $query ) {
				// Skip the video's own rules and the page catch-all (pages were checked above by path).
				if ( false !== strpos( (string) $query, 'xroad_video' ) || false !== strpos( (string) $query, 'pagename=' ) ) {
					continue;
				}
				if ( @preg_match( '#^' . $regex . '#', $probe ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$shadow++;
					$lines[] = sprintf( '  SHADOW: rewrite rule %s => %s matches /%s/<slug>/ today', $regex, $query, $path );
					break;
				}
			}
		}
		$rows   = $wpdb->get_results( "SELECT p.ID, p.post_name, m.meta_value AS url FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_xrv_dedicated_url' WHERE p.post_type = 'xroad_video' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_value <> '' ORDER BY p.menu_order ASC, p.ID ASC" );
		$differ = array();
		$equal  = array();
		foreach ( (array) $rows as $r ) {
			$new = xrv_cli_video_url( $r->post_name, $single );
			$row = sprintf( '    post %d: %s  (new permalink %s)', $r->ID, $r->url, $new );
			if ( untrailingslashit( (string) $r->url ) === untrailingslashit( $new ) ) {
				$equal[] = $row;
			} else {
				$differ[] = $row;
			}
		}
		$lines[] = sprintf( 'Dedicated URLs that will NOT match the new permalink (those videos keep redirecting to them): %d', count( $differ ) );
		$lines   = array_merge( $lines, $differ );
		$lines[] = sprintf( 'Dedicated URLs that will EQUAL the new permalink (the page there keeps serving it until `wp xrv handover`; with nothing there, XRV serves it): %d', count( $equal ) );
		$lines   = array_merge( $lines, $equal );
		return array( 'lines' => $lines, 'shadow' => $shadow, 'differ' => count( $differ ), 'equal' => count( $equal ) );
	}

	/* ---- 12l. Apply: write the plan in the fixed order settings, permalinks, videos, collections ---- */

	/** Execute (or on a dry run only diff) an apply plan; one log record per step. Returns array( changed IDs, failures ). */
	function xrv_cli_apply_run( $plan, $log, $dry, $include_sync ) {
		$changed = array();
		$fail    = 0;
		$n       = 0;
		$total   = ( null !== $plan['settings'] ? 1 : 0 ) + ( null !== $plan['permalinks'] ? 1 : 0 ) + count( $plan['videos'] ) + count( $plan['collections'] );
		$emit    = function ( $ref, $entry ) use ( $log, &$n, $total, &$fail ) {
			$n++;
			$fail += ( 'failed' === $entry['status'] ) ? 1 : 0;
			$log->put( $ref, $entry );
			xrv_cli_print_entry( $n, $total, $entry );
		};
		if ( null !== $plan['settings'] ) {
			$raw   = xrv_cli_option_raw( 'xrv_settings' );
			$eff   = xrv_get_settings();
			$new   = xrv_settings_prepare( $plan['settings'] );
			$diffs = array();
			foreach ( $new as $k => $v ) {
				$b = array_key_exists( $k, $eff ) ? $eff[ $k ] : null;
				if ( null === $b || (string) $b !== (string) $v ) {
					$diffs[] = array( 'field' => 'settings.' . $k, 'before' => $b, 'after' => $v );
				}
			}
			$needs = wp_json_encode( $raw['value'] ) !== wp_json_encode( $new );
			$entry = array( 'key' => 'settings', 'status' => 'skipped', 'message' => 'unchanged', 'diffs' => $diffs );
			if ( $needs && $dry ) {
				$entry['status']  = 'would-update';
				$entry['message'] = sprintf( '%d setting(s) would change', count( $diffs ) );
			} elseif ( $needs ) {
				$cron = xrv_cli_cron_image();
				update_option( 'xrv_settings', $new );
				$entry['status']  = 'updated';
				$entry['message'] = $diffs ? sprintf( '%d setting(s) changed', count( $diffs ) ) : 'stored settings normalised (no effective change)';
				if ( ! $include_sync && wp_json_encode( xrv_cli_cron_image() ) !== wp_json_encode( $cron ) ) {
					xrv_cli_cron_restore( $cron ); // the settings hook re-armed sync; sync is not ours to touch
					$entry['message'] .= '; sync schedule kept as it was';
				}
			}
			$emit( 'settings', $entry );
		}
		if ( null !== $plan['permalinks'] ) {
			$cur   = xrv_permalinks();
			$diffs = array();
			foreach ( array( 'single', 'archive' ) as $k ) {
				if ( (string) $cur[ $k ] !== (string) $plan['permalinks'][ $k ] ) {
					$diffs[] = array( 'field' => 'permalinks.' . $k, 'before' => $cur[ $k ], 'after' => $plan['permalinks'][ $k ] );
				}
			}
			$entry = array( 'key' => 'permalinks', 'status' => $diffs ? ( $dry ? 'would-update' : 'updated' ) : 'skipped', 'message' => $diffs ? ( $dry ? 'video URL base would change' : 'video URL base changed; rewrite rules rebuilt' ) : 'unchanged', 'diffs' => $diffs );
			if ( $diffs && ! $dry ) {
				$r = xrv_set_permalinks( $plan['permalinks']['single'], $plan['permalinks']['archive'] );
				if ( is_wp_error( $r ) ) {
					$entry['status']  = 'failed';
					$entry['message'] = $r->get_error_message();
				}
			}
			$emit( 'permalinks', $entry );
		}
		foreach ( $plan['videos'] as $ref => $spec ) {
			$pid   = (int) $spec['post_id'];
			$diff  = xrv_cli_diff( $pid, $spec['desired'] );
			$entry = array( 'key' => $ref, 'status' => 'skipped', 'message' => 'unchanged', 'post_id' => $pid, 'diffs' => $diff['diffs'] );
			if ( $diff['diffs'] && $dry ) {
				$entry['status']  = 'would-update';
				$entry['message'] = sprintf( 'post %d: %d field(s) would change', $pid, count( $diff['diffs'] ) );
			} elseif ( $diff['diffs'] ) {
				$entry['warnings'] = xrv_cli_fields_set( $pid, $diff['set'] );
				$entry['before']   = $diff['before'];
				$entry['status']   = 'updated';
				$entry['message']  = sprintf( 'post %d: %d field(s) changed', $pid, count( $diff['diffs'] ) );
				$changed[]         = $pid;
			}
			$emit( 'video:' . $ref, $entry );
		}
		foreach ( $plan['collections'] as $slug => $c ) {
			$ref   = 'collection:' . $slug;
			$entry = xrv_cli_collection_upsert( $c, array(
				'dry_run' => $dry,
				'run_id'  => $log->data['run_id'],
				'intent'  => function ( $e ) use ( $log, $ref ) {
					$log->put( $ref, $e );
				},
			) );
			if ( in_array( $entry['status'], array( 'created', 'updated' ), true ) ) {
				$changed[] = (int) $entry['post_id'];
			}
			$emit( $ref, $entry );
		}
		if ( ! $dry ) {
			xrv_cli_purge_caches();
		}
		return array( $changed, $fail );
	}

	/* ---- 12m. Rollback of an apply: exact restore of the pre-image ---- */

	/** Restore an apply's pre-image. Returns array( changed post IDs, problem count ). */
	function xrv_cli_rollback_apply( $src, $rb, $force, $dry ) {
		$pre  = isset( $src->data['preimage'] ) && is_array( $src->data['preimage'] ) ? $src->data['preimage'] : null;
		$post = isset( $src->data['postimage'] ) && is_array( $src->data['postimage'] ) ? $src->data['postimage'] : null;
		if ( ! $pre ) {
			WP_CLI::error( 'That apply log has no pre-image.' );
		}
		$slugs = array_keys( (array) $pre['collections'] );
		$refs  = array();
		foreach ( (array) $pre['videos'] as $ref => $v ) {
			$refs[ $ref ] = (int) $v['post_id'];
		}
		$now = xrv_cli_apply_capture( $slugs, $refs );
		$since = $post ? xrv_cli_image_diff( $now, $post, true ) : array( 'post-apply image missing (the apply did not finish)' );
		if ( $since ) {
			WP_CLI::log( 'Changed since the apply: ' . implode( ', ', $since ) );
			if ( ! $force ) {
				$rb->put( 'check', array( 'key' => 'check', 'status' => 'refused', 'message' => 'changed since the apply: ' . implode( ', ', $since ) ) );
				$rb->finish( 'refused' );
				WP_CLI::error( 'Refusing to roll back: the values above changed after the apply. Re-run with --force to overwrite them with the pre-image.' );
			}
			WP_CLI::warning( 'Overwriting those changes because of --force.' );
		}
		$todo  = xrv_cli_image_diff( $now, $pre, true );
		$total = count( $todo );
		if ( $dry ) {
			foreach ( $todo as $i => $p ) {
				$e = array( 'key' => $p, 'status' => 'would-restore', 'message' => 'differs from the pre-image' );
				$rb->put( $p, $e );
				xrv_cli_print_entry( $i + 1, $total, $e );
			}
			return array( array(), 0 );
		}
		$changed = array();
		$n       = 0;
		$emit    = function ( $p, $e ) use ( $rb, &$n, $total ) {
			$n++;
			$rb->put( $p, $e );
			xrv_cli_print_entry( $n, max( $n, $total ), $e );
		};
		foreach ( array( 'xrv_settings', 'xrv_permalinks' ) as $name ) {
			if ( in_array( 'options.' . $name, $todo, true ) ) {
				xrv_cli_option_restore( $name, $pre['options'][ $name ] );
				$emit( 'options.' . $name, array( 'key' => 'options.' . $name, 'status' => 'restored', 'message' => empty( $pre['options'][ $name ]['exists'] ) ? 'deleted (absent before the apply)' : 'raw value restored' ) );
			}
		}
		foreach ( (array) $pre['videos'] as $ref => $v ) {
			if ( ! in_array( 'videos.' . $ref, $todo, true ) ) {
				continue;
			}
			$pid  = (int) $v['post_id'];
			$errs = get_post( $pid ) ? xrv_cli_fields_set( $pid, (array) $v['fields'] ) : array( 'post no longer exists' );
			$emit( 'videos.' . $ref, array( 'key' => $ref, 'status' => $errs ? 'failed' : 'restored', 'message' => $errs ? implode( '; ', $errs ) : sprintf( 'post %d fields restored', $pid ), 'post_id' => $pid ) );
			$changed[] = $pid;
		}
		foreach ( (array) $pre['collections'] as $slug => $c ) {
			if ( ! in_array( 'collections.' . $slug, $todo, true ) ) {
				continue;
			}
			$cid = xrv_cli_collection_id( $slug );
			if ( empty( $c['exists'] ) ) { // created by the apply: force-delete it, but only if it is the one the apply made
				$made = isset( $post['collections'][ $slug ]['post_id'] ) ? (int) $post['collections'][ $slug ]['post_id'] : 0;
				$ours = $cid && ( $cid === $made || (string) get_post_meta( $cid, '_xrv_run_id', true ) === (string) $src->data['run_id'] );
				if ( $ours ) {
					wp_delete_post( $cid, true );
				}
				$emit( 'collections.' . $slug, array( 'key' => 'collection:' . $slug, 'status' => $ours ? 'deleted' : 'kept', 'message' => $ours ? sprintf( 'force-deleted collection %d (created by the apply)', $cid ) : 'not created by this apply; left alone', 'post_id' => $cid ) );
			} else {
				$cid  = (int) $c['post_id'];
				$errs = get_post( $cid ) ? xrv_cli_fields_set( $cid, (array) $c['fields'] ) : array( 'collection no longer exists' );
				$emit( 'collections.' . $slug, array( 'key' => 'collection:' . $slug, 'status' => $errs ? 'failed' : 'restored', 'message' => $errs ? implode( '; ', $errs ) : sprintf( 'collection %d restored', $cid ), 'post_id' => $cid ) );
			}
			$changed[] = $cid;
		}
		if ( wp_json_encode( xrv_cli_cron_image() ) !== wp_json_encode( $pre['cron'] ) ) { // after the options: the settings hook may have re-armed it
			xrv_cli_cron_restore( $pre['cron'] );
			$emit( 'cron', array( 'key' => 'cron.xrv_sync_event', 'status' => 'restored', 'message' => $pre['cron']['timestamp'] ? sprintf( 'scheduled at %s UTC (%s)', gmdate( 'Y-m-d H:i:s', (int) $pre['cron']['timestamp'] ), $pre['cron']['recurrence'] ? $pre['cron']['recurrence'] : 'single' ) : 'no sync event (as before)' ) );
		}
		xrv_rebuild_video_rewrites();
		xrv_cli_purge_caches();
		$left = xrv_cli_image_diff( xrv_cli_apply_capture( $slugs, $refs ), $pre );
		$rb->data['verify'] = array( 'matches_preimage' => ! $left, 'differences' => $left );
		WP_CLI::log( $left ? 'Still different from the pre-image: ' . implode( ', ', $left ) : 'Verified: options, collections, videos, sync schedule and rewrite rules match the pre-image.' );
		return array( $changed, count( $left ) );
	}

	/* ---- 12n. Export: a manifest that `wp xrv apply` accepts ---- */

	/** Build the manifest array (never includes the API key; on-site dedicated URLs become site-relative). */
	function xrv_cli_export_manifest() {
		global $wpdb;
		$m = array(
			'xrv_manifest' => 1,
			'generator'    => 'XRV ' . ( defined( 'XRV_VERSION' ) ? XRV_VERSION : '' ),
			'exported'     => xrv_cli_now(),
			'home_url'     => home_url(),
			'settings'     => xrv_cli_scrub( xrv_get_settings() ),
			'permalinks'   => xrv_permalinks(),
			'collections'  => array(),
			'videos'       => array(),
		);
		// Published collections only: a manifest carries no status and apply creates collections as published,
		// so exporting a draft would publish it on the target site. (Only published collections render anyway.)
		$cols = get_posts( array( 'post_type' => 'xrv_collection', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ) ) );
		foreach ( $cols as $c ) {
			$refs = array();
			foreach ( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $c->ID, '_xrvc_video_ids', true ) ) ) ) as $vid ) {
				$r = xrv_cli_video_ref( $vid );
				if ( '' === $r ) {
					WP_CLI::warning( sprintf( 'collection "%s": post %d has no video ID; left out', $c->post_name, $vid ) );
					continue;
				}
				$refs[] = $r;
			}
			$m['collections'][] = array(
				'slug'    => $c->post_name,
				'title'   => html_entity_decode( $c->post_title, ENT_QUOTES, 'UTF-8' ),
				'layout'  => (string) get_post_meta( $c->ID, '_xrvc_layout', true ),
				'orderby' => (string) get_post_meta( $c->ID, '_xrvc_orderby', true ),
				'videos'  => $refs,
			);
		}
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'xroad_video' AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY menu_order ASC, ID ASC" );
		foreach ( (array) $ids as $pid ) {
			$pid = (int) $pid;
			$ref = xrv_cli_video_ref( $pid );
			if ( '' === $ref ) {
				continue;
			}
			$p     = get_post( $pid );
			$parts = explode( ':', $ref, 2 );
			$ded   = (string) get_post_meta( $pid, '_xrv_dedicated_url', true );
			$pub   = (string) get_post_meta( $pid, '_xrv_published_at', true );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $pub ) ) { // zone-less: stored as UTC
				$pub = str_replace( ' ', 'T', $pub ) . 'Z';
			}
			$m['videos'][] = array(
				'provider'   => $parts[0],
				'id'         => $parts[1],
				'slug'       => $p->post_name,
				'status'     => 'future' === $p->post_status ? 'publish' : $p->post_status,
				'post_date'  => $p->post_date,
				'menu_order' => (int) $p->menu_order,
				'meta'       => array(
					'watch_page'    => '0' === (string) get_post_meta( $pid, '_xrv_watch_page', true ) ? '0' : '1',
					'dedicated_url' => '' === $ded ? null : xrv_cli_relative_url( $ded ),
					'duration'      => (string) get_post_meta( $pid, '_xrv_duration_iso', true ),
					'upload'        => (string) get_post_meta( $pid, '_xrv_upload_date', true ),
					'published_at'  => $pub,
					'is_short'      => '1' === (string) get_post_meta( $pid, '_xrv_short', true ),
					'description'   => (string) get_post_meta( $pid, '_xrv_description', true ),
				),
			);
		}
		return $m;
	}

	// XRV-CLI-FUNCS-END

	/* ---- 12z. Command classes and registration ---- */

	/**
	 * Manage the XRV video library: import, apply a manifest, export, roll back a run.
	 */
	class XRV_CLI_Command extends WP_CLI_Command {

		/**
		 * Import videos from a JSON file. Never fetches a URL (no oEmbed, no API, no remote thumbnail).
		 *
		 * Run with the global --user=<admin> flag: capability-gated writes and kses filtering need a real
		 * administrator. Takes the shared library lock. Chunked and resumable: --max-seconds (default 420)
		 * stops cleanly between records, writes the run log and prints the exact resume command.
		 *
		 * ## OPTIONS
		 *
		 * <file>
		 * : A JSON array of records, or {"videos":[...]}. Fields: provider (youtube), id, title, slug, post_date ("YYYY-MM-DD HH:MM:SS" site-local), menu_order, watch_page, dedicated_url ("/path/" or absolute http(s); null or "" removes), description (or desc), upload (YYYY-MM-DD), published_at (ISO-8601 with a zone), duration (ISO-8601, e.g. PT12M30S), is_short, poster_id (existing image attachment) or poster_path (local image file), terms ({"xrv_series":[],"xrv_audience":[],"xrv_topic":[]} by name).
		 *
		 * [--status=<status>]
		 * : Status for CREATED videos; updates never change status. Default: draft.
		 * ---
		 * options:
		 *   - draft
		 *   - publish
		 * ---
		 *
		 * [--on-existing=<action>]
		 * : A video that already exists (same provider + ID, any status, trash included). Default: skip.
		 * ---
		 * options:
		 *   - skip
		 *   - update
		 * ---
		 *
		 * [--limit=<n>]
		 * : Process at most n records in this invocation, then stop and print the resume command.
		 *
		 * [--resume=<log>]
		 * : Continue an earlier run (same run id) from its run log; records already done are skipped.
		 *
		 * [--max-seconds=<n>]
		 * : Stop cleanly between records after n seconds; 0 = no limit. Default: 420.
		 *
		 * [--log=<path>]
		 * : Run-log file or directory. Default: wp-content/xrv-runs/.
		 *
		 * [--dry-run]
		 * : Validate and print per-field diffs (field, before, after); write nothing but a run log marked dry_run.
		 *
		 * ## EXAMPLES
		 *
		 *     wp xrv import videos.json --dry-run --user=admin
		 *     wp xrv import videos.json --status=draft --limit=20 --user=admin
		 *     wp xrv import videos.json --resume=wp-content/xrv-runs/xrv-import-20261001-120000-0123456789abcdef.json --user=admin
		 *
		 * @when after_wp_load
		 */
		public function import( $args, $assoc ) {
			xrv_cli_require_admin();
			$t0     = microtime( true );
			$dry    = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
			$resume = isset( $assoc['resume'] ) ? (string) $assoc['resume'] : '';
			$limit  = isset( $assoc['limit'] ) ? max( 0, (int) $assoc['limit'] ) : 0;
			$max_s  = isset( $assoc['max-seconds'] ) ? max( 0, (int) $assoc['max-seconds'] ) : 420;
			$file   = realpath( $args[0] );
			if ( false === $file ) {
				WP_CLI::error( 'Import file not found: ' . $args[0] );
			}
			$data    = xrv_cli_read_json( $file, 'Import file' );
			$records = ( isset( $data['videos'] ) && is_array( $data['videos'] ) ) ? $data['videos'] : $data;
			if ( ! wp_is_numeric_array( $records ) ) {
				WP_CLI::error( 'Expected a JSON array of records or {"videos":[...]}.' );
			}
			$records = array_values( $records );
			$sha     = sha1_file( $file );

			if ( '' !== $resume ) {
				if ( $dry ) {
					WP_CLI::error( '--resume cannot be combined with --dry-run.' );
				}
				$log = XRV_CLI_Log::open( $resume );
				$d   = $log->data;
				if ( 'import' !== $d['kind'] || ! empty( $d['dry_run'] ) ) {
					WP_CLI::error( 'That run log is not a (non-dry-run) import log.' );
				}
				if ( isset( $d['home_url'] ) && home_url() !== $d['home_url'] ) {
					WP_CLI::error( sprintf( 'That run log belongs to %s, not %s.', $d['home_url'], home_url() ) );
				}
				if ( isset( $d['source']['sha1'] ) && $sha !== $d['source']['sha1'] ) {
					WP_CLI::error( 'The import file changed since this run started (sha1 differs). Resume with the original file.' );
				}
				$status      = isset( $d['options']['status'] ) ? $d['options']['status'] : 'draft';
				$on_existing = isset( $d['options']['on_existing'] ) ? $d['options']['on_existing'] : 'skip';
				foreach ( array( 'status' => $status, 'on-existing' => $on_existing ) as $k => $was ) {
					if ( isset( $assoc[ $k ] ) && $assoc[ $k ] !== $was ) {
						WP_CLI::warning( sprintf( '--%s=%s ignored: a resumed run keeps its original --%s=%s.', $k, $assoc[ $k ], $k, $was ) );
					}
				}
				xrv_cli_lock( $d['run_id'], 'import' );
				$log->data['state']     = 'running';
				$log->data['finished']  = null;
				$log->data['resumes'][] = array( 'at' => xrv_cli_now(), 'assoc' => $assoc );
				$log->save();
				WP_CLI::log( sprintf( 'Resuming run %s. Run log: %s', $d['run_id'], $log->path ) );
			} else {
				$status      = isset( $assoc['status'] ) ? $assoc['status'] : 'draft';
				$on_existing = isset( $assoc['on-existing'] ) ? $assoc['on-existing'] : 'skip';
				$run_id      = XRV_CLI_Log::new_id();
				if ( ! $dry ) {
					xrv_cli_lock( $run_id, 'import' );
				}
				$log = XRV_CLI_Log::start( 'import', 'import', array( $file ), $assoc, $dry, isset( $assoc['log'] ) ? $assoc['log'] : '', $run_id );
				$log->data['source']  = array( 'file' => $file, 'sha1' => $sha, 'records' => count( $records ) );
				$log->data['options'] = array( 'status' => $status, 'on_existing' => $on_existing );
				$log->save();
			}
			list( $dupes, $slugs ) = xrv_cli_import_prepass( $records );
			$ctx = array( 'dry_run' => $dry, 'status' => $status, 'on_existing' => $on_existing, 'run_id' => $log->data['run_id'], 'base_dir' => dirname( $file ), 'dupes' => $dupes, 'file_slugs' => $slugs );
			$o   = array( 'records' => $records, 'total' => count( $records ), 'limit' => $limit, 'max_seconds' => $max_s, 't0' => $t0, 'dry_run' => $dry );
			list( $processed, $failed, $stopped, $changed ) = xrv_cli_import_loop( $log, $ctx, $o );

			$log->finish( $stopped ? 'stopped' : 'complete', array( 'summary' => xrv_cli_log_counts( $log ) ) );
			if ( ! $dry && $changed ) {
				do_action( 'xrv_library_changed', array_values( array_unique( $changed ) ) );
			}
			if ( ! $dry ) {
				xrv_lock_release( $log->data['run_id'] );
			}
			$sum = array();
			foreach ( $log->data['summary'] as $s => $c ) {
				$sum[] = $s . ' ' . $c;
			}
			WP_CLI::log( sprintf( 'This invocation: %d record(s) processed. Whole run: %s.', $processed, $sum ? implode( ', ', $sum ) : 'nothing yet' ) );
			WP_CLI::log( 'Run log: ' . $log->path );
			if ( 'lock' === $stopped ) {
				WP_CLI::error( 'Lost the library lock (another writer took it over); stopped between records. Resume with: ' . xrv_cli_resume_cmd( $file, $log, $assoc ) );
			}
			if ( $stopped && ! $dry ) {
				WP_CLI::log( sprintf( 'Stopped (%s) between records. Resume with:', 'time' === $stopped ? 'time budget reached' : '--limit reached' ) );
				WP_CLI::log( '  ' . xrv_cli_resume_cmd( $file, $log, $assoc ) );
			}
			if ( $failed ) {
				WP_CLI::error( sprintf( '%d record(s) failed validation or writing; details above and in the run log.', $failed ) );
			}
			WP_CLI::success( $dry ? 'Dry run finished; nothing was written.' : ( $stopped ? 'Chunk finished.' : 'Import finished.' ) );
		}

		/**
		 * Roll back a run from its log: an import or collection run log, or an apply pre-image (detected by "kind").
		 *
		 * Import / collection: force-deletes (never trashes) the posts the run created, with the attachments it
		 * SIDELOADED for them (re-checked: only when no other post uses them); reused posters are never deleted.
		 * A created post that changed status since the run is kept unless --force. Updated posts get every
		 * changed field back from the before-image (post fields, meta, terms); terms are recounted.
		 *
		 * Apply: exact restore of the pre-image (raw options bypassing sanitize filters, permalinks with rewrite
		 * rebuild, collections, video fields, the sync schedule). Refused when anything changed since the apply
		 * (compared with the stored post-apply image) unless --force.
		 *
		 * Run with the global --user=<admin> flag. Takes the shared library lock and writes its own run log.
		 *
		 * ## OPTIONS
		 *
		 * <log>
		 * : The run log (import / collection) or the apply log holding the pre-image.
		 *
		 * [--force]
		 * : Delete created posts whose status changed, overwrite fields changed since the run, ignore a home_url mismatch.
		 *
		 * [--dry-run]
		 * : Print what would be deleted or restored; write nothing but a run log marked dry_run.
		 *
		 * ## EXAMPLES
		 *
		 *     wp xrv rollback wp-content/xrv-runs/xrv-import-20261001-120000-0123456789abcdef.json --dry-run --user=admin
		 *     wp xrv rollback wp-content/xrv-runs/xrv-apply-20261001-130000-fedcba9876543210.json --user=admin
		 *
		 * @when after_wp_load
		 */
		public function rollback( $args, $assoc ) {
			xrv_cli_require_admin();
			$dry   = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
			$force = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'force', false );
			$src   = XRV_CLI_Log::open( $args[0] );
			$kind  = (string) $src->data['kind'];
			if ( ! in_array( $kind, array( 'import', 'collection', 'apply', 'handover' ), true ) ) {
				WP_CLI::error( sprintf( 'A "%s" log cannot be rolled back (import, collection, apply and handover logs can).', $kind ) );
			}
			if ( ! empty( $src->data['dry_run'] ) ) {
				WP_CLI::error( 'That is a dry-run log: it wrote nothing, so there is nothing to roll back.' );
			}
			if ( isset( $src->data['home_url'] ) && home_url() !== $src->data['home_url'] ) {
				if ( ! $force ) {
					WP_CLI::error( sprintf( 'That log belongs to %s, not %s. Use --force only if this is the same site under a new URL.', $src->data['home_url'], home_url() ) );
				}
				WP_CLI::warning( 'home_url differs from the log; continuing because of --force.' );
			}
			$run_id = XRV_CLI_Log::new_id();
			if ( ! $dry ) {
				xrv_cli_lock( $run_id, 'rollback' );
			}
			$rb = XRV_CLI_Log::start( 'rollback', 'rollback', array( $src->path ), $assoc, $dry, '', $run_id );
			$rb->data['of'] = array( 'run_id' => $src->data['run_id'], 'kind' => $kind, 'log' => $src->path );
			$rb->save();
			if ( 'apply' === $kind ) {
				list( $changed, $problems ) = xrv_cli_rollback_apply( $src, $rb, $force, $dry );
			} else {
				list( $changed, $problems ) = xrv_cli_rollback_run( $src, $rb, $force, $dry );
			}
			$rb->finish( $problems ? 'partial' : 'complete', array( 'summary' => xrv_cli_log_counts( $rb ) ) );
			if ( ! $dry ) {
				$src->data['rolled_back'][] = array( 'at' => xrv_cli_now(), 'by' => $rb->path, 'state' => $rb->data['state'] );
				$src->save();
				if ( $changed && 'handover' === $kind ) {
					xrv_cli_purge_caches(); // the addresses changed hands again
				}
				if ( $changed ) {
					do_action( 'xrv_library_changed', array_values( array_unique( array_map( 'intval', $changed ) ) ) );
				}
				xrv_lock_release( $run_id );
			}
			WP_CLI::log( 'Run log: ' . $rb->path );
			if ( $problems ) {
				WP_CLI::warning( sprintf( '%d item(s) were kept or could not be restored; see above.', $problems ) );
			}
			WP_CLI::success( $dry ? 'Dry run finished; nothing was changed.' : 'Rollback finished.' );
		}

		/**
		 * Apply a manifest (the file `wp xrv export` writes is valid input) in one process, in the order
		 * settings, permalinks, videos, collections; then flush caches (and WP Engine's, when present).
		 *
		 * The pre-image (raw xrv_settings, raw xrv_permalinks or "absent", each touched collection and video,
		 * the sync schedule) is written to the run log BEFORE any write, and the post-apply image after, so
		 * `wp xrv rollback <log>` can restore exactly and detect later edits. Settings go through
		 * xrv_settings_prepare(); sync_* keys are left alone unless --include-sync. Videos patch only status,
		 * dedicated_url (null or "" deletes it), watch_page, menu_order and post_date. A URL base change must
		 * be named in --sections AND confirmed with --confirm-urls after reading the shadow report.
		 * Run with the global --user=<admin> flag. Takes the shared library lock.
		 *
		 * ## OPTIONS
		 *
		 * <manifest>
		 * : Manifest JSON: {"settings":{},"permalinks":{"single":"","archive":""},"collections":[{"slug","title","layout","orderby","videos":["youtube:ID"]}],"videos":[{"provider","id","status","post_date","menu_order","meta":{"watch_page","dedicated_url"}}]}.
		 *
		 * [--sections=<list>]
		 * : Comma-separated: settings, collections, permalinks, videos. Default: settings,collections.
		 *
		 * [--confirm-urls]
		 * : Required to change the video URL base (permalinks section).
		 *
		 * [--include-sync]
		 * : Also apply the sync_* settings (channel auto-sync).
		 *
		 * [--log=<path>]
		 * : Run-log file or directory. Default: wp-content/xrv-runs/.
		 *
		 * [--dry-run]
		 * : Print the per-field diffs and the URL report; write nothing but a run log marked dry_run.
		 *
		 * ## EXAMPLES
		 *
		 *     wp xrv apply manifest.json --dry-run --user=admin
		 *     wp xrv apply manifest.json --sections=permalinks,videos --confirm-urls --user=admin
		 *
		 * @when after_wp_load
		 */
		public function apply( $args, $assoc ) {
			xrv_cli_require_admin();
			$dry     = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
			$confirm = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'confirm-urls', false );
			$sync    = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'include-sync', false );
			$path    = realpath( $args[0] );
			if ( false === $path ) {
				WP_CLI::error( 'Manifest not found: ' . $args[0] );
			}
			$m        = xrv_cli_read_json( $path, 'Manifest' );
			$sections = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', strtolower( isset( $assoc['sections'] ) ? (string) $assoc['sections'] : 'settings,collections' ) ) ) ) ) );
			$bad      = array_diff( $sections, array( 'settings', 'collections', 'permalinks', 'videos' ) );
			if ( $bad || ! $sections ) {
				WP_CLI::error( 'Unknown --sections value(s): ' . implode( ', ', $bad ) . '. Use settings, collections, permalinks, videos.' );
			}
			list( $errors, $warnings, $plan ) = xrv_cli_apply_plan( $m, $sections, $sync );
			foreach ( $warnings as $w ) {
				WP_CLI::warning( $w );
			}
			if ( $errors ) {
				foreach ( $errors as $e ) {
					WP_CLI::warning( $e );
				}
				WP_CLI::error( sprintf( '%d error(s) in the manifest; nothing was written.', count( $errors ) ) );
			}
			$report = null;
			$cur    = xrv_permalinks();
			if ( null !== $plan['permalinks'] && ( $cur['single'] !== $plan['permalinks']['single'] || $cur['archive'] !== $plan['permalinks']['archive'] ) ) {
				$report = xrv_cli_permalink_report( $plan['permalinks']['single'], $plan['permalinks']['archive'] );
				WP_CLI::log( sprintf( 'URL base change report (%d shadow finding(s)):', $report['shadow'] ) );
				foreach ( $report['lines'] as $line ) {
					WP_CLI::log( '  ' . $line );
				}
				if ( ! $confirm && ! $dry ) {
					WP_CLI::error( 'Refusing to change the video URL base without --confirm-urls. Review the report above; nothing was written.' );
				}
				if ( ! $confirm ) {
					WP_CLI::warning( 'A real run would refuse this base change without --confirm-urls.' );
				}
			}
			$refs = array();
			foreach ( $plan['videos'] as $ref => $spec ) {
				$refs[ $ref ] = $spec['post_id'];
			}
			$run_id = XRV_CLI_Log::new_id();
			if ( ! $dry ) {
				xrv_cli_lock( $run_id, 'apply' );
			}
			$log = XRV_CLI_Log::start( 'apply', 'apply', array( $path ), $assoc, $dry, isset( $assoc['log'] ) ? $assoc['log'] : '', $run_id );
			$log->data['sections']   = $sections;
			$log->data['manifest']   = array( 'file' => $path, 'sha1' => sha1_file( $path ) );
			$log->data['url_report'] = $report;
			$log->data['targets']    = array( 'collections' => array_keys( $plan['collections'] ), 'videos' => $refs );
			$log->data['preimage']   = xrv_cli_apply_capture( array_keys( $plan['collections'] ), $refs );
			$log->save(); // the pre-image is on disk before the first write
			WP_CLI::log( 'Pre-image saved.' );
			list( $changed, $fail ) = xrv_cli_apply_run( $plan, $log, $dry, $sync );
			if ( ! $dry ) {
				$log->data['postimage'] = xrv_cli_apply_capture( array_keys( $plan['collections'] ), $refs );
			}
			$log->finish( $fail ? 'partial' : 'complete', array( 'summary' => xrv_cli_log_counts( $log ) ) );
			if ( ! $dry ) {
				if ( $changed ) {
					do_action( 'xrv_library_changed', array_values( array_unique( $changed ) ) );
				}
				xrv_lock_release( $run_id );
			}
			WP_CLI::log( 'Run log (holds the pre-image for `wp xrv rollback`): ' . $log->path );
			if ( $fail ) {
				WP_CLI::error( sprintf( '%d step(s) failed; see above.', $fail ) );
			}
			WP_CLI::success( $dry ? 'Dry run finished; nothing was written.' : 'Manifest applied.' );
		}

		/**
		 * Export settings (never the API key), permalinks, collections and videos as a manifest that
		 * `wp xrv apply` accepts. Collections list their videos as ordered "provider:id"; on-site dedicated
		 * URLs are written site-relative. Read-only: no lock; the run log only records the export.
		 *
		 * ## OPTIONS
		 *
		 * --file=<file>
		 * : Where to write the manifest JSON (written atomically).
		 *
		 * [--dry-run]
		 * : Print the counts; write no manifest.
		 *
		 * ## EXAMPLES
		 *
		 *     wp xrv export --file=xrv-manifest.json --user=admin
		 *
		 * @when after_wp_load
		 */
		public function export( $args, $assoc ) {
			$dry  = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
			$file = (string) $assoc['file'];
			$m    = xrv_cli_export_manifest();
			$log  = XRV_CLI_Log::start( 'export', 'export', $args, $assoc, $dry );
			$sum  = sprintf( '%d setting(s), permalinks single "%s" archive "%s", %d collection(s), %d video(s)', count( $m['settings'] ), $m['permalinks']['single'], $m['permalinks']['archive'], count( $m['collections'] ), count( $m['videos'] ) );
			if ( ! $dry ) {
				$json = wp_json_encode( xrv_cli_scrub( $m ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				$tmp  = $file . '.tmp' . getmypid();
				if ( false === $json || false === file_put_contents( $tmp, $json . "\n" ) || ! rename( $tmp, $file ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					WP_CLI::error( 'Could not write ' . $file );
				}
			}
			$log->finish( 'complete', array( 'summary' => array( 'file' => $dry ? null : realpath( $file ), 'contents' => $sum ) ) );
			WP_CLI::log( 'Run log: ' . $log->path );
			WP_CLI::success( ( $dry ? 'Would export ' : 'Exported ' ) . $sum . ( $dry ? '.' : ' to ' . $file ) );
		}

		/**
		 * Hand video addresses to XRV, or back to the pages that held them (a batch, or everything at once).
		 *
		 * A video whose dedicated URL is its own address has not been handed over: the page that lives
		 * there today (an old post) keeps serving it and XRV steps aside. Handing over removes the
		 * dedicated URL (remembered in _xrv_handover_from), so XRV serves the address from the next request;
		 * --to=old puts it back. A dedicated URL that points somewhere else is handled the same way: handing
		 * over stops that redirect. Purges the object cache (and WP Engine's caches) after a real run.
		 * Run with the global --user=<admin> flag. Takes the shared library lock and writes a run log
		 * (kind "handover") that `wp xrv rollback` can undo.
		 *
		 * ## OPTIONS
		 *
		 * [<ids>]
		 * : Comma-separated video IDs: "provider:id" or a bare YouTube ID.
		 *
		 * [--all]
		 * : Instead of IDs: every video that still has a dedicated URL (--to=xrv), or every video this
		 * command handed over (--to=old).
		 *
		 * [--to=<to>]
		 * : Who serves the address afterwards.
		 * ---
		 * default: xrv
		 * options:
		 *   - xrv
		 *   - old
		 * ---
		 *
		 * [--force]
		 * : Also hand over videos whose watch page is off (their address then redirects home).
		 *
		 * [--log=<path>]
		 * : Run-log file or directory. Default: wp-content/xrv-runs/.
		 *
		 * [--dry-run]
		 * : Print what would change; write nothing but a run log marked dry_run.
		 *
		 * ## EXAMPLES
		 *
		 *     wp xrv handover yahxL3E6azk,4LAX2feihbE --dry-run --user=admin
		 *     wp xrv handover yahxL3E6azk,4LAX2feihbE --user=admin
		 *     wp xrv handover yahxL3E6azk --to=old --user=admin
		 *     wp xrv handover --all --user=admin
		 *
		 * @when after_wp_load
		 */
		public function handover( $args, $assoc ) {
			xrv_cli_require_admin();
			$dry = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
			$all = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'all', false );
			$force = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'force', false );
			$to  = isset( $assoc['to'] ) ? strtolower( (string) $assoc['to'] ) : 'xrv';
			if ( ! in_array( $to, array( 'xrv', 'old' ), true ) ) {
				WP_CLI::error( '--to must be xrv or old.' );
			}
			if ( $all === ! empty( $args[0] ) ) {
				WP_CLI::error( 'Give comma-separated video IDs, or --all (not both).' );
			}
			$targets = array();
			$errors  = array();
			if ( $all ) {
				$targets = get_posts( array(
					'post_type'   => 'xroad_video',
					'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_key'    => ( 'xrv' === $to ) ? '_xrv_dedicated_url' : '_xrv_handover_from',
					'orderby'     => 'ID',
					'order'       => 'ASC',
				) );
			} else {
				foreach ( explode( ',', (string) $args[0] ) as $ref ) {
					if ( '' === trim( $ref ) ) {
						continue;
					}
					$p = xrv_cli_parse_ref( $ref );
					if ( is_wp_error( $p ) ) {
						$errors[] = $p->get_error_message();
						continue;
					}
					$found = array_values( array_filter( xrv_cli_find_videos( $p[0], $p[1] ), function ( $id ) {
						return 'trash' !== get_post_status( $id );
					} ) );
					if ( ! $found ) {
						$errors[] = sprintf( '"%s": no XRV video with that ID', trim( $ref ) );
						continue;
					}
					$targets[] = $found[0];
				}
			}
			if ( $errors ) {
				foreach ( $errors as $e ) {
					WP_CLI::warning( $e );
				}
				WP_CLI::error( sprintf( '%d error(s); nothing was written.', count( $errors ) ) );
			}
			$targets = array_values( array_unique( array_map( 'intval', $targets ) ) );
			if ( ! $targets ) {
				WP_CLI::success( 'Nothing to hand ' . ( 'xrv' === $to ? 'over.' : 'back.' ) );
				return;
			}
			$run_id = XRV_CLI_Log::new_id();
			if ( ! $dry ) {
				xrv_cli_lock( $run_id, 'handover' );
			}
			$log     = XRV_CLI_Log::start( 'handover', 'handover', $args, $assoc, $dry, isset( $assoc['log'] ) ? $assoc['log'] : '', $run_id );
			$log->data['to'] = $to;
			$total   = count( $targets );
			$changed = array();
			$fails   = 0;
			$labels  = array(
				'xrv'      => 'XRV serves its address',
				'old'      => 'the page that lives at its address serves it',
				'redirect' => 'its address redirects to %s',
				'home'     => 'watch page off: its address redirects home',
				'draft'    => 'still a draft: the page at its address serves it until it is published',
			);
			foreach ( $targets as $i => $pid ) {
				$ref  = xrv_cli_video_ref( $pid );
				$ref  = '' !== $ref ? $ref : 'post:' . $pid;
				$ded  = xrv_cli_field_get( $pid, 'meta._xrv_dedicated_url' );
				$from = xrv_cli_field_get( $pid, 'meta._xrv_handover_from' );
				$e    = array( 'key' => $ref, 'post_id' => $pid, 'status' => 'skipped', 'message' => '', 'warnings' => array() );
				$want = array();
				if ( 'xrv' === $to ) {
					if ( null === $ded || '' === $ded ) {
						$e['message'] = 'already handed over (no dedicated URL)';
					} elseif ( '0' === (string) get_post_meta( $pid, '_xrv_watch_page', true ) && ! $force ) {
						$e['message'] = 'kept: its watch page is off, so XRV would redirect this address home. Turn the watch page on first, or pass --force';
					} else {
						$want = array( 'meta._xrv_dedicated_url' => null, 'meta._xrv_handover_from' => $ded );
					}
				} elseif ( null !== $from && '' !== $from ) {
					$want = array( 'meta._xrv_dedicated_url' => $from, 'meta._xrv_handover_from' => null );
				} elseif ( null !== $ded && '' !== $ded ) {
					$e['message'] = 'already with the old page (it has a dedicated URL)';
				} else {
					$e['status']  = 'failed';
					$e['message'] = 'no earlier address recorded for this video; undo with `wp xrv rollback <handover log>` instead';
				}
				if ( $want ) {
					$d          = xrv_cli_diff( $pid, $want );
					$e['diffs'] = $d['diffs'];
					if ( $dry ) {
						$e['status']  = 'would-update';
						$e['message'] = 'xrv' === $to ? 'would hand the address to XRV' : 'would hand the address back to ' . xrv_cli_relative_url( $from );
					} else {
						$errs = xrv_cli_fields_set( $pid, $d['set'] );
						$e['status']   = $errs ? 'failed' : 'updated';
						$e['before']   = $d['before'];
						$e['after']    = $d['set'];
						$e['warnings'] = array_merge( $e['warnings'], $errs );
						$changed[]     = $pid;
						xrv_lock_heartbeat( $run_id );
					}
				}
				if ( 'publish' !== get_post_status( $pid ) && 'xrv' === $to ) {
					$e['warnings'][] = 'not published yet: XRV serves the address once the video is published';
				}
				if ( ! $dry && 'failed' !== $e['status'] ) {
					$st = xrv_address_state( $pid );
					$e['address'] = $st;
					$e['message'] = trim( $e['message'] . '; now ' . sprintf( $labels[ $st['state'] ], xrv_cli_relative_url( $st['to'] ) ) . ' (' . xrv_cli_relative_url( $st['url'] ) . ')', '; ' );
				}
				if ( 'failed' === $e['status'] ) {
					$fails++;
				}
				$log->put( $ref, $e );
				xrv_cli_print_entry( $i + 1, $total, $e );
			}
			$log->finish( $fails ? 'partial' : 'complete', array( 'summary' => xrv_cli_log_counts( $log ) ) );
			if ( ! $dry ) {
				if ( $changed ) {
					xrv_cli_purge_caches();
					do_action( 'xrv_library_changed', $changed );
				}
				xrv_lock_release( $run_id );
			}
			WP_CLI::log( 'Run log: ' . $log->path );
			if ( $fails ) {
				WP_CLI::warning( sprintf( '%d video(s) could not be changed; see above.', $fails ) );
			}
			WP_CLI::success( $dry ? 'Dry run finished; nothing was written.' : sprintf( '%d address(es) handed %s.', count( $changed ), 'xrv' === $to ? 'to XRV' : 'back' ) );
		}

		/**
		 * List videos and who serves each one's own address right now.
		 *
		 * States: xrv (XRV serves it), old (not handed over: the page that lives there serves it),
		 * redirect (its dedicated URL points elsewhere), home (watch page off), draft (not published).
		 *
		 * ## OPTIONS
		 *
		 * [<ids>]
		 * : Comma-separated video IDs ("provider:id" or a bare YouTube ID). Default: every video.
		 *
		 * [--state=<state>]
		 * : Only videos in this state: xrv, old, redirect, home or draft.
		 *
		 * [--format=<format>]
		 * : table, csv, json, count or ids.
		 * ---
		 * default: table
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp xrv list --user=admin
		 *     wp xrv list --state=old --format=csv --user=admin
		 *
		 * @subcommand list
		 * @when after_wp_load
		 */
		public function list_( $args, $assoc ) {
			$ids = array();
			if ( ! empty( $args[0] ) ) {
				foreach ( explode( ',', (string) $args[0] ) as $ref ) {
					$p = xrv_cli_parse_ref( $ref );
					if ( is_wp_error( $p ) ) {
						WP_CLI::error( $p->get_error_message() );
					}
					$ids = array_merge( $ids, xrv_cli_find_videos( $p[0], $p[1] ) );
				}
				if ( ! $ids ) {
					WP_CLI::error( 'No XRV video has those IDs.' );
				}
			}
			$posts = get_posts( array(
				'post_type'   => 'xroad_video',
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => -1,
				'post__in'    => $ids,
				'orderby'     => 'menu_order date ID',
				'order'       => 'ASC',
			) );
			$want = isset( $assoc['state'] ) ? strtolower( (string) $assoc['state'] ) : '';
			$rows = array();
			foreach ( $posts as $p ) {
				$st = xrv_address_state( $p->ID );
				if ( '' !== $want && $want !== $st['state'] ) {
					continue;
				}
				$rows[] = array(
					'ID'      => $p->ID,
					'video'   => xrv_cli_video_ref( $p->ID ),
					'status'  => $p->post_status,
					'state'   => $st['state'],
					'address' => xrv_cli_relative_url( $st['url'] ),
					'to'      => xrv_cli_relative_url( $st['to'] ),
					'title'   => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				);
			}
			$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';
			if ( 'ids' === $format ) {
				WP_CLI::log( implode( ' ', wp_list_pluck( $rows, 'ID' ) ) );
				return;
			}
			if ( 'count' === $format ) {
				WP_CLI::log( (string) count( $rows ) );
				return;
			}
			\WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'video', 'status', 'state', 'address', 'to', 'title' ) );
		}

		// XRV-CLI-CLASS-END
	}

	/**
	 * Manage XRV collections (named, placeable galleries).
	 */
	class XRV_CLI_Collection_Command extends WP_CLI_Command {

		/**
		 * Create or update a collection by slug (idempotent: an identical second run writes nothing and says so).
		 *
		 * The collection is published (the type is non-public) with an explicit post_name, and afterwards
		 * get_page_by_path( <slug>, OBJECT, 'xrv_collection' ) must resolve to it. Numeric slugs are rejected
		 * (the shortcode would read them as a post ID). Unknown video IDs are errors and nothing is written.
		 * Run with the global --user=<admin> flag. Takes the shared library lock and writes a run log
		 * (kind "collection") that `wp xrv rollback` can undo.
		 *
		 * ## OPTIONS
		 *
		 * <slug>
		 * : The collection slug, as used in [xroad-videos collection="<slug>"].
		 *
		 * --ids=<ids>
		 * : Ordered, comma-separated video IDs: "provider:id" or a bare YouTube ID.
		 *
		 * [--layout=<layout>]
		 * : grid, carousel or library; "" = site default. Default on create: "".
		 *
		 * [--orderby=<orderby>]
		 * : curated, newest, oldest or title; "" = site default. Default on create: "".
		 *
		 * [--title=<title>]
		 * : Collection title. Default on create: the slug in title case.
		 *
		 * [--log=<path>]
		 * : Run-log file or directory. Default: wp-content/xrv-runs/.
		 *
		 * [--dry-run]
		 * : Print per-field diffs; write nothing but a run log marked dry_run.
		 *
		 * ## EXAMPLES
		 *
		 *     wp xrv collection set featured --ids=yahxL3E6azk,youtube:eEnZMJPAadY --layout=carousel --user=admin
		 *
		 * @when after_wp_load
		 */
		public function set( $args, $assoc ) {
			xrv_cli_require_admin();
			$dry  = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
			$spec = array( 'slug' => $args[0], 'videos' => (string) $assoc['ids'] );
			foreach ( array( 'layout', 'orderby', 'title' ) as $k ) {
				if ( isset( $assoc[ $k ] ) ) {
					$spec[ $k ] = true === $assoc[ $k ] ? '' : $assoc[ $k ];
				}
			}
			list( $errors, $warnings, $c ) = xrv_cli_collection_validate( $spec );
			foreach ( $warnings as $w ) {
				WP_CLI::warning( $w );
			}
			if ( $errors ) {
				foreach ( $errors as $e ) {
					WP_CLI::warning( $e );
				}
				WP_CLI::error( sprintf( '%d error(s); nothing was written.', count( $errors ) ) );
			}
			$run_id = XRV_CLI_Log::new_id();
			if ( ! $dry ) {
				xrv_cli_lock( $run_id, 'collection set' );
			}
			$log = XRV_CLI_Log::start( 'collection', 'collection set', $args, $assoc, $dry, isset( $assoc['log'] ) ? $assoc['log'] : '', $run_id );
			$ref = 'collection:' . $c['slug'];
			$out = xrv_cli_collection_upsert( $c, array(
				'dry_run' => $dry,
				'run_id'  => $run_id,
				'intent'  => function ( $entry ) use ( $log, $ref ) {
					$log->put( $ref, $entry );
				},
			) );
			$out['warnings'] = array_merge( $warnings, $out['warnings'] );
			$log->put( $ref, $out );
			xrv_cli_print_entry( 1, 1, $out );
			$log->finish( 'failed' === $out['status'] ? 'failed' : 'complete', array( 'summary' => xrv_cli_log_counts( $log ) ) );
			if ( ! $dry && in_array( $out['status'], array( 'created', 'updated' ), true ) ) {
				do_action( 'xrv_library_changed', array( (int) $out['post_id'] ) );
			}
			if ( ! $dry ) {
				xrv_lock_release( $run_id );
			}
			WP_CLI::log( 'Run log: ' . $log->path );
			if ( 'failed' === $out['status'] || ( isset( $out['resolves'] ) && ! $out['resolves'] ) ) {
				WP_CLI::error( 'The collection was not written cleanly; see above.' );
			}
			WP_CLI::success( 'skipped' === $out['status'] ? 'Nothing to change: the collection already matches.' : ( $dry ? 'Dry run finished; nothing was written.' : 'Collection saved.' ) );
		}
	}

	WP_CLI::add_command( 'xrv', 'XRV_CLI_Command' );
	WP_CLI::add_command( 'xrv collection', 'XRV_CLI_Collection_Command' );

	// XRV-CLI-END
}
