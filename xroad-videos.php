<?php
/**
 * Plugin Name:       Crossroad Videos
 * Plugin URI:        https://crossroad.us
 * Description:        Privacy-first, click-to-load video gallery. A curated custom-post-type model
 *                     (editors add and order each video) that renders a server-side masonry grid of
 *                     LOCALLY stored thumbnails and makes ZERO network calls to YouTube or Google until a
 *                     visitor clicks play, so no consent manager has anything to block and no banner,
 *                     warning overlay, or black player can ever appear. A drop-in alternative to Smash
 *                     Balloon YouTube Feed for sites running a cookie/consent manager. On click it injects
 *                     a youtube-nocookie.com iframe and pushes a video_play event to dataLayer. Self-
 *                     generates VideoObject JSON-LD inside a CollectionPage/ItemList that merges with the
 *                     site's Organization node. Shortcode [xroad-videos] and block (xroad/videos).
 *                     By Crossroad Media.
 * Version:           2.1.1
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

/* =================================================================================================
 * 1. DATA MODEL  (this plugin owns the post type)
 *    We register `xroad_video` as the curated source for the grid. has_archive is false by default: a
 *    card can link out to a dedicated page per video via the _xrv_dedicated_url meta, so the CPT itself
 *    only needs to feed the gallery rather than expose its own archive.
 * ================================================================================================= */

/* Video URL structure (write-once). single = single-post base; archive = optional collection/archive
 * base (empty = no archive). Defaults preserve the historical /xroad-video/ base, so existing installs
 * are unchanged until an admin deliberately locks a structure — irreversible, since live URLs depend on it. */
function xrv_permalinks() {
	return wp_parse_args( (array) get_option( 'xrv_permalinks', array() ), array(
		'locked'  => false,
		'single'  => 'xroad-video',
		'archive' => '',
	) );
}

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
			'menu_name'          => 'Crossroad Videos',
		),
		'public'        => true,
		'has_archive'   => ( '' !== $pl['archive'] ) ? $pl['archive'] : false, // optional collection archive (admin-selected, write-once)
		'show_in_rest'  => true,
		'menu_icon'     => 'dashicons-video-alt3',
		'rewrite'       => array( 'slug' => $pl['single'] ),  // single-post base (admin-selected, write-once)
		'supports'      => array( 'title', 'editor', 'thumbnail', 'page-attributes' ), // page-attributes => menu_order for manual drag-ordering
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
		'_xrv_dedicated_url' => 'string',  // optional: a dedicated page for this video the card links to
		'_xrv_duration_iso'  => 'string',  // ISO 8601, e.g. PT12M30S
		'_xrv_upload_date'   => 'string',  // YYYY-MM-DD
		'_xrv_description'    => 'string', // plain-language summary
		'_xrv_local_thumb_id' => 'integer', // media-library attachment ID for the locally stored poster
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

	update_option( 'xrv_version', '2.1.1' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* Write-once permalink lock. Sets the video URL structure and flushes rewrite rules ONCE; repeat attempts
 * are ignored so a live URL structure can't be silently changed out from under existing links/SEO. */
add_action( 'admin_post_xrv_lock_urls', 'xrv_lock_urls_handler' );
function xrv_lock_urls_handler() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
	check_admin_referer( 'xrv_lock_urls' );
	$pl = xrv_permalinks();
	if ( empty( $pl['locked'] ) && ! empty( $_POST['xrv_confirm'] ) ) {
		$single  = isset( $_POST['xrv_single'] ) ? sanitize_title( wp_unslash( $_POST['xrv_single'] ) ) : '';
		$archive = isset( $_POST['xrv_archive'] ) ? sanitize_title( wp_unslash( $_POST['xrv_archive'] ) ) : '';
		if ( '' === $single ) { $single = 'xroad-video'; }
		update_option( 'xrv_permalinks', array( 'locked' => true, 'single' => $single, 'archive' => $archive ) );
		xrv_register_data_model();   // re-register the CPT with the new base, then flush so the new rules are written now
		flush_rewrite_rules();
	}
	wp_safe_redirect( add_query_arg( 'xrv_urls', '1', admin_url( 'edit.php?post_type=xroad_video&page=xrv-settings' ) ) );
	exit;
}

/* =================================================================================================
 * 2. PROVIDER ROUTING  (the additive seam for Vimeo in 2.0)
 *    Every provider-specific operation routes through a switch($provider). 1.0 implements youtube only;
 *    vimeo is reserved. Adding it in 2.0 means filling three branches, not rewriting the renderer.
 * ================================================================================================= */

/** Whitelist of providers xroad-videos can render. */
function xrv_providers() {
	return array( 'youtube', 'vimeo', 'wistia', 'loom', 'dailymotion', 'file' );
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

/* =================================================================================================
 * 5. THE RENDERER
 *    Queries every video ordered by menu_order (the editor's manual drag-order), prints the full markup
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

function xrv_render( $atts = array() ) {

	// Drop empty attrs so a blank shortcode value OR an unset/"site default" block control falls through to
	// the site defaults below (instead of overriding them with an empty string).
	$atts = array_filter( (array) $atts, function( $v ) { return '' !== $v && null !== $v; } );
	$s = xrv_get_settings(); // site-wide defaults (Videos -> Settings); explicit shortcode/block attrs override these
	$atts = shortcode_atts( array(
		'series'   => '',   // comma-separated xrv_series slugs to pre-filter
		'audience' => '',   // comma-separated xrv_audience slugs
		'topic'    => '',   // comma-separated xrv_topic slugs
		'limit'    => -1,   // max videos (default: all)
		'columns'  => '',   // fixed column count; blank = responsive (masonry for grid, 3 for library/carousel)
		'playback' => 'lightbox', // 'lightbox' (pops out into a centered overlay) | 'inline' (plays in the card)
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
		'consent_notice'  => $s['consent_notice'],  // off | light | strict | geo  — informed-consent UI at the facade
		'consent_text'    => $s['consent_text'],
		'consent_button'  => $s['consent_button'],
		'consent_decline' => $s['consent_decline'], // label for the decline/dismiss control on the prompt
		'privacy_url'     => $s['privacy_url'],      // privacy policy link in the notice; defaults to the WP privacy page
		'shorts'   => $s['shorts_default'],          // all | only | hide — YouTube Shorts (vertical 9:16) handling for this gallery
	), $atts, 'xroad-videos' );

	$playback = ( 'inline' === $atts['playback'] ) ? 'inline' : 'lightbox';
	$layout   = in_array( $atts['layout'], array( 'grid', 'carousel', 'library' ), true ) ? $atts['layout'] : 'grid';
	$controls = ! in_array( strtolower( (string) $atts['controls'] ), array( 'false', '0', 'no', 'off' ), true );
	$filter_ui = ( 'chips' === strtolower( (string) $atts['filter_ui'] ) ) ? 'chips' : 'select';
	$cn = strtolower( (string) $atts['consent_notice'] );
	$consent_notice = in_array( $cn, array( 'light', 'strict', 'geo' ), true ) ? $cn : 'off';
	$card_meta = in_array( strtolower( (string) $atts['card_meta'] ), array( 'full', 'compact', 'title' ), true ) ? strtolower( (string) $atts['card_meta'] ) : 'full';
	$privacy_url = '' !== $atts['privacy_url'] ? esc_url( $atts['privacy_url'] ) : esc_url( (string) get_privacy_policy_url() );
	$per_page  = max( 1, (int) $atts['per_page'] );
	$load_step = max( 1, (int) $atts['load_more'] );
	$subscribe_url = esc_url( $atts['subscribe_url'] );

	$tax_query = array();
	foreach ( array( 'xrv_series' => 'series', 'xrv_audience' => 'audience', 'xrv_topic' => 'topic' ) as $tax => $key ) {
		$slugs = array_filter( array_map( 'trim', explode( ',', (string) $atts[ $key ] ) ) );
		if ( $slugs ) {
			$tax_query[] = array( 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $slugs );
		}
	}

	$query_args = array(
		'post_type'      => 'xroad_video',
		'post_status'    => 'publish',
		'posts_per_page' => (int) $atts['limit'] === 0 ? -1 : (int) $atts['limit'],
		'orderby'        => 'menu_order',  // the editor's drag-order controls the grid sequence
		'order'          => 'ASC',
	);
	if ( $tax_query ) {
		$query_args['tax_query'] = $tax_query;
	}

	// Shorts filter: only = just verticals; hide = exclude them; all = mix (default).
	$shorts = in_array( $atts['shorts'], array( 'all', 'only', 'hide' ), true ) ? $atts['shorts'] : $s['shorts_default'];
	if ( 'only' === $shorts )     { $query_args['meta_query'] = array( array( 'key' => '_xrv_short', 'compare' => 'EXISTS' ) ); }
	elseif ( 'hide' === $shorts ) { $query_args['meta_query'] = array( array( 'key' => '_xrv_short', 'compare' => 'NOT EXISTS' ) ); }

	$q = new WP_Query( $query_args );
	if ( ! $q->have_posts() ) {
		return '<p>No videos found.</p>';
	}

	// First pass: normalise a record per post so we can both count facets and render.
	$records = array();
	$facet   = array( 'series' => array(), 'audience' => array(), 'topic' => array() );
	$tax_for = array( 'series' => 'xrv_series', 'audience' => 'xrv_audience', 'topic' => 'xrv_topic' );

	foreach ( $q->posts as $p ) {
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
			foreach ( $slugs as $s ) {
				$facet[ $group ][ $s ] = ( $facet[ $group ][ $s ] ?? 0 ) + 1;
			}
		}
		$provider  = (string) get_post_meta( $id, '_xrv_provider', true );
		$provider  = $provider !== '' ? $provider : 'youtube';
		$desc      = (string) get_post_meta( $id, '_xrv_description', true );
		$dedicated = (string) get_post_meta( $id, '_xrv_dedicated_url', true );
		$dur_iso   = (string) get_post_meta( $id, '_xrv_duration_iso', true );
		$upload    = (string) get_post_meta( $id, '_xrv_upload_date', true );
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
			'upload'     => $upload,
			'poster'     => xrv_local_poster_url( $id, $thumb_id ),
			'poster_srcset' => $poster_ss['srcset'],
			'poster_sizes'  => $poster_ss['sizes'],
			'series'     => $groups['series'],
			'audience'   => $groups['audience'],
			'topic'      => $groups['topic'],
			'date_key'   => $upload !== '' ? (int) preg_replace( '/\D/', '', $upload ) : 0,
			'search'     => xrv_build_search_index( $id, $term_map, $desc ),
			'is_short'   => xrv_is_short( $id ),
		);
	}
	wp_reset_postdata();

	$total = count( $records );

	// The featured carousel (library/carousel layout) is the first N records in curated (menu_order) order.
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
<div id="xroad-videos-app" class="xrv xrv--<?php echo esc_attr( $layout ); ?>" data-playback="<?php echo esc_attr( $playback ); ?>" data-layout="<?php echo esc_attr( $layout ); ?>" data-consent="<?php echo esc_attr( $consent_notice ); ?>" data-consent-text="<?php echo esc_attr( $atts['consent_text'] ); ?>" data-consent-btn="<?php echo esc_attr( $atts['consent_button'] ); ?>" data-consent-decline="<?php echo esc_attr( $atts['consent_decline'] ); ?>" data-privacy="<?php echo esc_attr( $privacy_url ); ?>"<?php if ( 'geo' === $consent_notice ) : ?> data-region-url="<?php echo esc_url( rest_url( 'xrv/v1/region' ) ); ?>"<?php endif; ?>>
	<?php echo xrv_head_assets_once(); ?>

	<?php if ( 'library' !== $layout && '' !== $atts['heading'] ) : ?>
		<h2 class="xrv-section-title"><?php echo esc_html( $atts['heading'] ); ?></h2>
	<?php endif; ?>

	<?php if ( $show_carousel ) : ?>
		<?php if ( 'library' === $layout ) : ?><h2 class="xrv-section-title">Featured Videos</h2><?php endif; ?>
		<?php echo xrv_render_carousel( $featured, $caro_cols, $card_meta ); ?>
	<?php endif; ?>

	<?php if ( $show_grid ) : ?>
		<?php if ( 'library' === $layout ) : ?><h2 class="xrv-section-title">Browse our Library</h2><?php endif; ?>
		<section class="xrv-tool">
			<?php if ( $controls ) : ?>
			<div class="xrv-bar">
				<div class="xrv-search">
					<svg class="xrv-ic"><use href="#xrv-i-search"/></svg>
					<input type="text" id="xrv-q" placeholder="Search videos&hellip;" aria-label="Search videos by keyword">
				</div>
				<div class="xrv-ctrls">
					<?php if ( 'select' === $filter_ui ) {
						// Facet filters as compact dropdown selects, grouped with Sort on the right of the bar.
						echo xrv_render_filter_select( 'series',   'Series',   'xrv_series',   $facet['series'] );
						echo xrv_render_filter_select( 'audience', 'Audience', 'xrv_audience', $facet['audience'] );
						echo xrv_render_filter_select( 'topic',    'Topic',    'xrv_topic',    $facet['topic'] );
					} ?>
					<div class="xrv-sortwrap">
						<label for="xrv-sort">Sort</label>
						<select id="xrv-sort">
							<option value="curated">Curated order</option>
							<option value="newest">Newest first</option>
							<option value="oldest">Oldest first</option>
							<option value="title">Title (A&ndash;Z)</option>
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
				<div class="xrv-count">Showing <strong id="xrv-shown"><?php echo (int) min( $per_page, $total ); ?></strong> of <strong id="xrv-total"><?php echo (int) $total; ?></strong> videos</div>
				<button type="button" class="xrv-reset" id="xrv-reset"><svg class="xrv-ic"><use href="#xrv-i-reset"/></svg> Reset</button>
			</div>
			<?php endif; ?>

			<div class="<?php echo esc_attr( $grid_class ); ?>" id="xrv-grid" style="<?php echo esc_attr( $grid_style ); ?>" data-perpage="<?php echo (int) $per_page; ?>" data-loadstep="<?php echo (int) $load_step; ?>">
				<?php foreach ( $records as $r ) { echo xrv_render_card( $r, $card_meta ); } ?>
			</div>

			<div class="xrv-empty" id="xrv-empty" style="display:none">
				<h3>No videos match your filters</h3>
				<p>Try removing a filter or clearing the keyword search.</p>
			</div>

			<div class="xrv-more">
				<button type="button" class="xrv-loadmore" id="xrv-loadmore" style="display:none">Load More&hellip;</button>
				<?php if ( $subscribe_url !== '' ) : ?>
					<a class="xrv-subscribe" href="<?php echo esc_url( $subscribe_url ); ?>" target="_blank" rel="noopener"><svg class="xrv-yt" viewBox="0 0 24 24" aria-hidden="true"><use href="#xrv-i-yt"/></svg> <?php echo esc_html( $atts['subscribe_label'] ); ?></a>
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
function xrv_render_carousel( $records, $cols, $card_meta = 'full' ) {
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
				<?php foreach ( $records as $r ) { echo xrv_render_card( $r, $card_meta ); } ?>
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
 * ------------------------------------------------------------------------------------------------- */
function xrv_render_card( $r, $meta = 'full' ) {
	$title    = $r['title'];
	$dedicated = $r['dedicated'];

	// Tag chips: the display names across the three facet taxonomies, de-duplicated.
	$tags_html = '';
	$seen = array();
	foreach ( array( 'xrv_series' => $r['series'], 'xrv_audience' => $r['audience'], 'xrv_topic' => $r['topic'] ) as $tax => $slugs ) {
		foreach ( $slugs as $s ) {
			$t = get_term_by( 'slug', $s, $tax );
			if ( $t && empty( $seen[ $t->name ] ) ) {
				$tags_html .= '<span>' . esc_html( $t->name ) . '</span>';
				$seen[ $t->name ] = true;
			}
		}
	}

	$poster = $r['poster'];

	ob_start();
	?>
	<figure class="xrv-card"<?php if ( ! empty( $r['is_short'] ) ) echo ' data-short="1"'; ?>
		data-vid="<?php echo esc_attr( $r['vid'] ); ?>"
		data-provider="<?php echo esc_attr( $r['provider'] ); ?>"
		data-hash="<?php echo esc_attr( $r['hash'] ?? '' ); ?>"
		data-series="<?php echo esc_attr( implode( ' ', $r['series'] ) ); ?>"
		data-audience="<?php echo esc_attr( implode( ' ', $r['audience'] ) ); ?>"
		data-topic="<?php echo esc_attr( implode( ' ', $r['topic'] ) ); ?>"
		data-date="<?php echo esc_attr( $r['date_key'] ); ?>"
		data-seconds="<?php echo (int) ( $r['dur_sec'] ?? 0 ); ?>"
		data-title="<?php echo esc_attr( strtolower( $title ) ); ?>"
		data-search="<?php echo esc_attr( $r['search'] ); ?>">
		<div class="xrv-frame">
			<button type="button" class="xrv-facade" aria-label="Play video: <?php echo esc_attr( $title ); ?>">
				<?php if ( $poster !== '' ) : ?>
					<img class="xrv-thumb" src="<?php echo esc_url( $poster ); ?>"<?php if ( ! empty( $r['poster_srcset'] ) ) : ?> srcset="<?php echo esc_attr( $r['poster_srcset'] ); ?>" sizes="<?php echo esc_attr( $r['poster_sizes'] ); ?>"<?php endif; ?> width="480" height="360" loading="lazy" decoding="async" alt="<?php echo esc_attr( $title ); ?>">
				<?php else : ?>
					<span class="xrv-thumb xrv-thumb--ph" aria-hidden="true"></span>
				<?php endif; ?>
				<span class="xrv-play" aria-hidden="true"><svg viewBox="0 0 68 48"><path class="xrv-play__bg" d="M66.5 7.7c-.8-2.9-2.5-5.2-5.4-6C55.8.3 34 .3 34 .3S12.2.3 6.9 1.6C4 2.4 2.3 4.8 1.5 7.7.2 13 .2 24 .2 24s0 11 1.3 16.3c.8 2.9 2.5 5.2 5.4 6C12.2 47.7 34 47.7 34 47.7s21.8 0 27.1-1.4c2.9-.8 4.6-3.1 5.4-6C67.8 35 67.8 24 67.8 24s0-11-1.3-16.3z"/><path d="M27 34V14l18 10-18 10z" fill="#fff"/></svg></span>
				<?php if ( $r['dur_clock'] !== '' ) : ?>
					<span class="xrv-dur"><?php echo esc_html( $r['dur_clock'] ); ?></span>
				<?php endif; ?>
			</button>
		</div>
		<figcaption class="xrv-cap">
			<h3 class="xrv-title"><?php echo esc_html( $title ); ?></h3>
			<?php if ( 'title' !== $meta && $r['desc'] !== '' ) : ?><p class="xrv-desc"><?php echo esc_html( $r['desc'] ); ?></p><?php endif; ?>
			<?php if ( 'full' === $meta && $tags_html !== '' ) : ?><p class="xrv-tags"><?php echo $tags_html; ?></p><?php endif; ?>
			<?php if ( $dedicated !== '' ) : ?>
				<a class="xrv-page-link" href="<?php echo esc_url( $dedicated ); ?>">Watch on its page <svg class="xrv-ic"><use href="#xrv-i-arrow"/></svg></a>
			<?php endif; ?>
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
	$id = 'xrv-fsel-' . $group;
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

		$node = array(
			'@type' => 'VideoObject',
			'name'  => $r['title'],
		);

		$desc = $r['desc'] !== '' ? $r['desc'] : $r['title'];
		$node['description'] = $desc;

		// thumbnailUrl: the LOCAL upload first (what the page actually renders), then the platform URL.
		$thumbs = array();
		if ( $r['poster'] !== '' ) {
			$thumbs[] = $r['poster'];
		}
		$rt = xrv_remote_thumb_url( $r['vid'], $r['provider'] );
		if ( '' !== $rt ) { $thumbs[] = $rt; }
		$node['thumbnailUrl'] = $thumbs;

		if ( $r['upload'] !== '' ) {
			$node['uploadDate'] = $r['upload'];
		}
		if ( $r['dur_iso'] !== '' ) {
			$node['duration'] = $r['dur_iso'];
		}

		$node['contentUrl'] = ! empty( $r['source_url'] ) ? $r['source_url'] : xrv_watch_url( $r['vid'], $r['provider'] );
		$node['embedUrl']   = xrv_embed_url( $r['vid'], $r['provider'], isset( $r['hash'] ) ? $r['hash'] : '' );
		$node['publisher']  = $org_ref;

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
		'name'  => get_bloginfo( 'name' ),
		'url'   => home_url( '/' ),
	);

	$list_name = apply_filters( 'xrv_list_name', get_bloginfo( 'name' ) . ' video library' );

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

	return "\n" . '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n";
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

	$title  = get_the_title( $post_id );
	$desc   = (string) get_post_meta( $post_id, '_xrv_description', true );
	$desc   = $desc !== '' ? $desc : $title;
	$upload = (string) get_post_meta( $post_id, '_xrv_upload_date', true );
	$upload = $upload !== '' ? $upload : get_the_date( 'Y-m-d', $post_id ); // uploadDate is required
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
			array( '@type' => 'Organization', '@id' => xrv_org_id(), 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
			$node,
		),
	);
	return "\n" . '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n";
}

/* =================================================================================================
 * 7. INLINE ASSETS  (SVG sprite, scoped critical CSS, vanilla JS)
 *    Emitted inline inside the rendered block, namespaced under .xrv- and #xroad-videos-app. Inlining is
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
	return xrv_icon_sprite() . "\n" . xrv_inline_css() . xrv_dynamic_css();
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
	return "\n" . '<style id="xrv-dynamic">#xroad-videos-app{' . $v . '}</style>';
}
function xrv_footer_js_once() {
	static $done = false;
	if ( $done ) { return ''; }
	$done = true;
	return xrv_inline_js();
}

function xrv_icon_sprite() {
	return <<<'SVG'
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<symbol id="xrv-i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol>
<symbol id="xrv-i-reset" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></symbol>
<symbol id="xrv-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></symbol>
<symbol id="xrv-i-yt" viewBox="0 0 24 24"><path fill="#FF0000" d="M23 7.5a3 3 0 0 0-2.1-2.1C19 4.9 12 4.9 12 4.9s-7 0-8.9.5A3 3 0 0 0 1 7.5 31 31 0 0 0 .5 12 31 31 0 0 0 1 16.5a3 3 0 0 0 2.1 2.1c1.9.5 8.9.5 8.9.5s7 0 8.9-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 23.5 12 31 31 0 0 0 23 7.5z"/><path fill="#fff" d="M9.75 15.5l6-3.5-6-3.5z"/></symbol>
</defs></svg>
SVG;
}

function xrv_inline_css() {
	return <<<'CSS'
<style>
#xroad-videos-app{font-family:var(--xrv-font, 'Gotham',Helvetica,Arial,sans-serif) !important;color:var(--xrv-text,#1a2332) !important;line-height:1.55 !important;max-width:1180px;margin:0 auto;padding:0}
#xroad-videos-app *,#xroad-videos-app *::before,#xroad-videos-app *::after{box-sizing:border-box}
#xroad-videos-app h3{font-family:inherit !important;line-height:1.3 !important;margin:0;font-weight:700;text-align:left}
#xroad-videos-app p{margin:0}
#xroad-videos-app a{color:var(--xrv-link,#017A8E);text-decoration:none}
#xroad-videos-app a:hover{text-decoration:underline}
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
.xrv-frame{position:relative;width:100%}
.xrv-facade{display:block;position:relative;width:100%;padding:0;margin:0;border:none;background:#0a1622;border-radius:6px;overflow:hidden;cursor:pointer;aspect-ratio:16/9;line-height:0}
.xrv-card[data-short]{max-width:300px;margin-left:auto;margin-right:auto}
.xrv-card[data-short] .xrv-facade,.xrv-card[data-short] .xrv-iframe{aspect-ratio:9/16}
.xrv-facade:focus-visible{outline:3px solid var(--xrv-accent,#019AB3);outline-offset:3px}
.xrv-thumb{display:block;width:100%;height:100%;object-fit:cover;border:0;transition:transform .3s ease,opacity .2s ease}
.xrv-thumb--ph{background:linear-gradient(135deg,var(--xrv-primary,#013C60),var(--xrv-link,#017A8E))}
.xrv-facade:hover .xrv-thumb{transform:scale(1.04);opacity:.92}
.xrv-play{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:64px;height:46px;display:flex;align-items:center;justify-content:center;transition:transform .15s ease}
.xrv-play svg{width:100%;height:100%;display:block}
.xrv-play__bg{fill:var(--xrv-play,var(--xrv-primary,#013C60));fill-opacity:.92;transition:fill .15s ease}
.xrv-facade:hover .xrv-play{transform:translate(-50%,-50%) scale(1.08)}
.xrv-facade:hover .xrv-play__bg{fill:var(--xrv-play-hover,var(--xrv-action,#007A53));fill-opacity:1}
.xrv-dur{position:absolute;right:8px;bottom:8px;background:rgba(10,22,34,.85);color:#fff;font-size:12px;font-weight:600;line-height:1;padding:4px 6px;border-radius:3px;font-variant-numeric:tabular-nums}
.xrv-iframe,.xrv-video{display:block;width:100%;aspect-ratio:16/9;border:0;border-radius:6px}
.xrv-video{background:#000;object-fit:contain}
.xrv-cap{padding:12px 2px 0}
.xrv-consent-note{font-size:11.5px;color:var(--xrv-muted,#5a6573);line-height:1.4;margin:7px 0 0}
.xrv-consent-note a{color:var(--xrv-link,#017A8E)}
.xrv-consent{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:11px;padding:18px;text-align:center;background:rgba(10,22,34,.88);border-radius:6px;z-index:4}
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
.xrv-title{font-size:16px !important;font-weight:700;color:var(--xrv-primary,#013C60) !important;margin:0 0 6px !important;line-height:1.2 !important}
/* Match the production carousel caption (13px / 1.3) and cap the blurb at ~5 lines so cards stay uniform. */
.xrv-desc{font-size:13px;color:var(--xrv-desc,#4a5663);line-height:1.3;margin:0 0 9px;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:5;line-clamp:5;overflow:hidden}
.xrv--single .xrv-desc{-webkit-line-clamp:unset;line-clamp:unset;display:block;overflow:visible;font-size:14px;margin-bottom:14px}
.xrv-tags{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:11.5px;color:var(--xrv-muted,#5a6573);margin:0 0 9px}
.xrv-tags span::before{content:"#";color:var(--xrv-border,#c4ccd6);margin-right:1px}
.xrv-page-link{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;letter-spacing:.02em;color:var(--xrv-link,#017A8E) !important}
.xrv-page-link .xrv-ic{width:13px;height:13px;transition:transform .15s ease}
.xrv-page-link:hover{text-decoration:none !important}
.xrv-page-link:hover .xrv-ic{transform:translateX(3px)}
.xrv-empty{text-align:center;padding:50px 20px;color:var(--xrv-muted,#5a6573)}
.xrv-empty h3{color:var(--xrv-text,#1a2332) !important;font-size:18px !important;margin-bottom:6px !important}
@media (max-width:880px){
.xrv-grid{column-width:auto !important;column-count:2 !important}
.xrv-ft{flex-basis:100%}
}
@media (max-width:560px){
.xrv-grid{column-count:1 !important}
.xrv-bar{flex-direction:column;align-items:stretch}
.xrv-ctrls{flex-direction:column;align-items:stretch;gap:10px}
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
.xrv-carousel .xrv-tags,.xrv-carousel .xrv-page-link{display:none}
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
.xrv-loadmore{background:var(--xrv-text,#1a2332);color:#fff;border:none;font-family:inherit;font-size:14px;font-weight:600;letter-spacing:.01em;padding:13px 28px;border-radius:5px;cursor:pointer;transition:background .15s ease}
.xrv-loadmore:hover{background:var(--xrv-primary,#013C60)}
.xrv-loadmore:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-subscribe{display:inline-flex;align-items:center;gap:9px;background:var(--xrv-subscribe,#00AA77);color:#fff !important;text-decoration:none !important;font-family:inherit;font-size:14px;font-weight:600;padding:12px 22px;border-radius:5px;transition:background .15s ease}
.xrv-subscribe:hover{background:var(--xrv-action,#007A53);text-decoration:none !important}
.xrv-subscribe:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-yt{width:22px;height:22px;flex:0 0 auto;vertical-align:-5px}
/* Lightbox modal. UNSCOPED on purpose: the overlay is appended to <body>, outside #xroad-videos-app. */
.xrv-modal{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:24px;font-family:var(--xrv-font, 'Gotham',Helvetica,Arial,sans-serif)}
.xrv-modal[hidden]{display:none}
.xrv-modal__backdrop{position:absolute;inset:0;background:rgba(8,16,28,.85)}
.xrv-modal__dialog{position:relative;width:100%;max-width:1100px}
.xrv-modal__frame{position:relative;width:100%;aspect-ratio:16/9;background:#000;border-radius:8px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.55)}
.xrv-modal__frame .xrv-iframe,.xrv-modal__frame .xrv-video{position:absolute;inset:0;width:100%;height:100%;aspect-ratio:auto;border-radius:8px}
.xrv-modal--short .xrv-modal__dialog{max-width:none;width:auto;display:flex;justify-content:center}
.xrv-modal--short .xrv-modal__frame{aspect-ratio:9/16;width:auto;height:min(86vh,760px);max-width:94vw}
.xrv-modal__frame iframe,.xrv-modal__frame video{position:absolute;inset:0;width:100%;height:100%;border:0;background:#000}
.xrv-modal__close{position:absolute;top:-46px;right:0;width:38px;height:38px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.55);color:#fff;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;padding:0}
.xrv-modal__close:hover{background:rgba(255,255,255,.28)}
.xrv-modal__close:focus-visible{outline:3px solid var(--xrv-accent,#019AB3);outline-offset:2px}
body.xrv-modal-open{overflow:hidden}
@media (max-width:600px){.xrv-modal{padding:14px}.xrv-modal__close{top:-42px}}
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
<script>
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
	function xrvOpenModal(node, title, isShort){
		var m = xrvGetModal();
		m.classList.toggle('xrv-modal--short', !!isShort);
		var frame = m.querySelector('.xrv-modal__frame');
		frame.innerHTML = '';
		frame.appendChild(node);
		m.setAttribute('aria-label', title || 'Video player');
		xrvLastFocus = document.activeElement;
		m.removeAttribute('hidden');
		document.body.classList.add('xrv-modal-open');
		var c = m.querySelector('.xrv-modal__close'); if(c) c.focus();
	}
	function xrvCloseModal(){
		var m = document.getElementById('xrv-modal');
		if(!m || m.hasAttribute('hidden')) return;
		m.setAttribute('hidden', '');
		document.body.classList.remove('xrv-modal-open');
		var frame = m.querySelector('.xrv-modal__frame'); if(frame) frame.innerHTML = ''; // stop playback
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

		// ---- Informed-consent notice (consent_notice = off | light | strict | geo) ----
		// The facade still makes ZERO third-party requests until a click. This only governs whether an
		// informed notice precedes that click, and (for geo) whether it shows based on the visitor's region.
		var consentMode = ROOT.getAttribute('data-consent') || 'off';
		var consentText = ROOT.getAttribute('data-consent-text') || '';
		var consentBtnLabel = ROOT.getAttribute('data-consent-btn') || 'Load video';
		var consentDeclineLabel = ROOT.getAttribute('data-consent-decline') || 'No thanks';
		var privacyUrl = ROOT.getAttribute('data-privacy') || '';
		function consentRequiredNow(){
			if(consentMode === 'off' || consentMode === 'light') return false;
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
		if(consentMode === 'light' && consentText){
			Array.prototype.forEach.call(ROOT.querySelectorAll('.xrv-card'), function(card){
				if(card.querySelector('.xrv-consent-note')) return;
				var cap = card.querySelector('.xrv-cap') || card;
				var p = document.createElement('p'); p.className = 'xrv-consent-note'; p.textContent = consentText + ' ';
				if(privacyUrl){ var a = document.createElement('a'); a.href = privacyUrl; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Privacy'; p.appendChild(a); }
				cap.appendChild(p);
			});
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
			var node = xrvEmbedNode(provider, id, hash, title, short);
			if(playbackMode === 'inline'){
				var frame = (btn && btn.closest) ? (btn.closest('.xrv-frame') || btn.parentNode) : card.querySelector('.xrv-frame');
				if(btn && btn.replaceWith){ btn.replaceWith(node); } else if(frame){ frame.appendChild(node); }
				if(frame && frame.style){ frame.style.lineHeight = '0'; }
			} else {
				xrvOpenModal(node, title, short); /* shorts pop in a 9:16 portrait modal */
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
			var card = btn.closest('.xrv-card'); if(!card) return;
			if(consentRequiredNow() && card.dataset.xrvConsented !== '1'){ showConsentOverlay(card); return; }
			playCard(card, btn);
		});

		// Mask post-click load latency: preconnect on first hover/focus, once per card. SUPPRESSED whenever a
		// consent gate is required for this view (strict always; geo for EU/UK/EEA) — so a gated visitor's
		// browser makes ZERO contact with YouTube (not even a DNS/TLS warm-up) until they accept.
		function preconnect(card){
			if(consentRequiredNow()) return;
			if(card.dataset.xrvPre) return;
			card.dataset.xrvPre = '1';
			var provider = card.getAttribute('data-provider') || 'youtube';
			var hosts = { youtube:'https://www.youtube-nocookie.com', vimeo:'https://player.vimeo.com', wistia:'https://fast.wistia.net', loom:'https://www.loom.com', dailymotion:'https://www.dailymotion.com' };
			var host = hosts[provider];
			if(!host) return; // self-hosted files: nothing third-party to warm up
			var l = document.createElement('link'); l.rel = 'preconnect'; l.href = host;
			document.head.appendChild(l);
		}
		ROOT.addEventListener('mouseover', function(e){ var c = e.target && e.target.closest ? e.target.closest('.xrv-card') : null; if(c) preconnect(c); });
		ROOT.addEventListener('focusin', function(e){ var c = e.target && e.target.closest ? e.target.closest('.xrv-card') : null; if(c) preconnect(c); });

		// ---- Featured carousel (paged, 3/2/1 per view, arrows + dots) ----
		var caro = ROOT.querySelector('.xrv-carousel');
		if(caro){
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
		}

		// ---- Browse grid: filter / search / sort (only when the controls + grid are present) ----
		var grid = ROOT.querySelector('.xrv-grid');
		if(grid){
			var cards = Array.prototype.slice.call(grid.querySelectorAll('.xrv-card'));
			var shownEl = ROOT.querySelector('#xrv-shown');
			var totalEl = ROOT.querySelector('#xrv-total');
			var emptyEl = ROOT.querySelector('#xrv-empty');
			var qEl = ROOT.querySelector('#xrv-q');
			var sortEl = ROOT.querySelector('#xrv-sort');
			var loadMoreBtn = ROOT.querySelector('#xrv-loadmore');
			var pageSize = parseInt(grid.getAttribute('data-perpage'), 10) || 9;
			var loadStep = parseInt(grid.getAttribute('data-loadstep'), 10) || 3;
			var visibleLimit = pageSize;
			var origOrder = cards.slice();
			var state = { q:'', series:[], audience:[], topic:[], sort:'curated' };

			var groupVals = function(card, g){ return (card.getAttribute('data-'+g) || '').split(' ').filter(Boolean); };
			var matchGroup = function(g, card){ if(state[g].length === 0) return true; var vals = groupVals(card, g); return state[g].some(function(v){ return vals.indexOf(v) > -1; }); };
			var matchSearch = function(card){ if(!state.q) return true; return (card.getAttribute('data-search') || '').indexOf(state.q) > -1; };
			var cmp = function(a, b){ var s = state.sort; if(s==='newest') return (+b.getAttribute('data-date'))-(+a.getAttribute('data-date')); if(s==='oldest') return (+a.getAttribute('data-date'))-(+b.getAttribute('data-date')); if(s==='title') return a.getAttribute('data-title').localeCompare(b.getAttribute('data-title')); if(s==='short') return (+a.getAttribute('data-seconds'))-(+b.getAttribute('data-seconds')); if(s==='long') return (+b.getAttribute('data-seconds'))-(+a.getAttribute('data-seconds')); return 0; };
			var apply = function(){
				var ordered = (state.sort === 'curated') ? origOrder.slice() : cards.slice().sort(cmp);
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
			var resetBtn = ROOT.querySelector('#xrv-reset');
			if(resetBtn) resetBtn.addEventListener('click', function(){
				state = { q:'', series:[], audience:[], topic:[], sort:'curated' };
				if(qEl) qEl.value = '';
				if(sortEl) sortEl.value = 'curated';
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
</script>
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
	$a  = shortcode_atts( array( 'id' => 0, 'playback' => 'inline' ), $atts, 'xroad-video' );
	$id = (int) $a['id'];
	if ( 'xroad_video' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
		// ponytail: id only. video="https://..." URL resolve is the upgrade path if editors prefer pasting links.
		return current_user_can( 'edit_posts' ) ? '<!-- xroad-video: no published video #' . $id . ' -->' : '';
	}
	return xrv_render_single( $id, $a['playback'] ); // playback="lightbox" pops a modal (Divi-style); default plays in place
}

add_action( 'init', 'xrv_register_block' );
function xrv_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	// No-build editor UI: a dependency-only handle (false src) carries the inline registerBlockType call,
	// loaded in the editor as the block's editor_script (mirrors the xrv-admin inline pattern).
	wp_register_script( 'xrv-block', false, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), '2.1.1', true );
	wp_add_inline_script( 'xrv-block', xrv_block_editor_js() );
	$str = array( 'type' => 'string' );
	register_block_type( 'xroad/videos', array(
		'render_callback' => function( $attributes ) { return xrv_render( (array) $attributes ); },
		'editor_script'   => 'xrv-block',
		'attributes'      => array(
			'layout' => $str, 'columns' => $str, 'playback' => $str, 'controls' => $str, 'filter_ui' => $str, 'card_meta' => $str, 'shorts' => $str,
			'per_page' => $str, 'load_more' => $str, 'featured_limit' => $str, 'heading' => $str,
			'subscribe_url' => $str, 'subscribe_label' => $str, 'consent_notice' => $str, 'consent_text' => $str,
			'consent_button' => $str, 'consent_decline' => $str, 'privacy_url' => $str, 'series' => $str, 'audience' => $str, 'topic' => $str, 'limit' => $str,
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
		title: 'Crossroad Videos',
		description: 'Privacy-first YouTube gallery (click-to-load facade).',
		icon: 'video-alt3',
		category: 'media',
		example: {},
		edit: function(props){
			var a = props.attributes, set = props.setAttributes;
			var f = function(k){ return function(v){ var o={}; o[k]=v; set(o); }; };
			var num = function(k, def){ return function(v){ var o={}; o[k]=String(v); set(o); }; };
			var controlsOn = (a.controls !== 'false');
			return el(Fragment, {},
				el(InspectorControls, {},
					el(PanelBody, { title:'Layout', initialOpen:true },
						el(SelectControl, { label:'Layout', value:a.layout||'grid', options:[
							{label:'Grid', value:'grid'}, {label:'Library (featured + grid)', value:'library'}, {label:'Carousel', value:'carousel'} ], onChange:f('layout') }),
						el(SelectControl, { label:'Playback', value:a.playback||'lightbox', options:[
							{label:'Lightbox (pop-out)', value:'lightbox'}, {label:'Inline', value:'inline'} ], onChange:f('playback') }),
						el(TextControl, { label:'Fixed columns (blank = responsive)', value:a.columns||'', onChange:f('columns') }),
						el(TextControl, { label:'Heading (optional)', value:a.heading||'', onChange:f('heading') }),
						el(ToggleControl, { label:'Show search / sort / filter bar', checked:controlsOn, onChange:function(v){ set({controls: v?'true':'false'}); } })
					),
					el(PanelBody, { title:'Browse', initialOpen:false },
						el(SelectControl, { label:'Filter style (blank = site default)', value:a.filter_ui||'', options:[
							{label:'Site default', value:''}, {label:'Dropdown selects', value:'select'}, {label:'Clickable chips', value:'chips'} ], onChange:f('filter_ui') }),
						el(SelectControl, { label:'Card text (blank = site default)', value:a.card_meta||'', options:[
							{label:'Site default', value:''}, {label:'Full (title + desc + tags)', value:'full'}, {label:'Compact (title + desc)', value:'compact'}, {label:'Title only', value:'title'} ], onChange:f('card_meta') }),
						el(SelectControl, { label:'YouTube Shorts (blank = site default)', value:a.shorts||'', options:[
							{label:'Site default', value:''}, {label:'Show alongside regular', value:'all'}, {label:'Only Shorts (swipe shelf)', value:'only'}, {label:'Hide Shorts', value:'hide'} ], onChange:f('shorts') }),
						el(RangeControl, { label:'Show before “Load more” (0 = site default)', min:0, max:60, value: parseInt(a.per_page,10)||0, onChange:num('per_page') }),
						el(RangeControl, { label:'“Load more” step (0 = site default)', min:0, max:24, value: parseInt(a.load_more,10)||0, onChange:num('load_more') }),
						el(TextControl, { label:'Subscribe URL', value:a.subscribe_url||'', onChange:f('subscribe_url') })
					),
					el(PanelBody, { title:'Privacy & consent', initialOpen:false },
						el(SelectControl, { label:'Consent mode (blank = site default)', value:a.consent_notice||'', options:[
							{label:'Site default', value:''}, {label:'Global (GDPR + CCPA)', value:'geo'}, {label:'Strict GDPR (every visitor)', value:'strict'}, {label:'No consent integration', value:'off'} ], onChange:f('consent_notice') }),
						el(TextControl, { label:'Privacy URL (blank = site default)', value:a.privacy_url||'', onChange:f('privacy_url') })
					),
					el(PanelBody, { title:'Pre-filter to terms (optional)', initialOpen:false },
						el(TextControl, { label:'Series slugs (comma-separated)', value:a.series||'', onChange:f('series') }),
						el(TextControl, { label:'Audience slugs', value:a.audience||'', onChange:f('audience') }),
						el(TextControl, { label:'Topic slugs', value:a.topic||'', onChange:f('topic') })
					)
				),
				el('div', useBlockProps ? useBlockProps() : {},
					el('div', { style:{ border:'1px dashed var(--xrv-border,#c4ccd6)', borderRadius:'6px', padding:'18px', textAlign:'center', color:'#50575e', background:'#f6f7f7' } },
						el('strong', { style:{ color:'var(--xrv-primary,#013C60)' } }, '▶ Crossroad Videos'),
						el('div', { style:{ fontSize:'12px', marginTop:'5px' } },
							(a.layout||'grid') + ' · ' + (a.consent_notice ? ('consent: '+a.consent_notice) : 'consent: site default') + ' · ' + (controlsOn ? 'controls on' : 'controls off')),
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
	if ( ! is_singular( 'xroad_video' ) ) {
		return;
	}
	$dest = (string) get_post_meta( get_queried_object_id(), '_xrv_dedicated_url', true );
	if ( $dest !== '' ) {
		$status = (int) apply_filters( 'xrv_dedicated_redirect_status', 301 );
		wp_redirect( esc_url_raw( $dest ), $status );
		exit;
	}
}

add_filter( 'the_content', 'xrv_single_content' );
function xrv_single_content( $content ) {
	if ( ! is_singular( 'xroad_video' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	return xrv_render_single( get_the_ID() ) . $content;
}

/** Render one video as a self-contained facade block (reusing the grid's assets, card, and schema). */
function xrv_render_single( $post_id, $playback = 'inline' ) {
	$playback = ( 'lightbox' === $playback ) ? 'lightbox' : 'inline';
	$provider = (string) get_post_meta( $post_id, '_xrv_provider', true );
	$provider = $provider !== '' ? $provider : 'youtube';
	$vid      = (string) get_post_meta( $post_id, '_xrv_video_id', true );
	if ( $vid === '' ) {
		return ''; // nothing to render; leave the post body as-is.
	}

	$dur_iso  = (string) get_post_meta( $post_id, '_xrv_duration_iso', true );
	$upload   = (string) get_post_meta( $post_id, '_xrv_upload_date', true );
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
		'poster'     => xrv_local_poster_url( $post_id, $thumb_id ),
		'poster_srcset' => $poster_ss['srcset'],
		'poster_sizes'  => $poster_ss['sizes'],
		'series'     => is_wp_error( $series ) ? array() : $series,
		'audience'   => is_wp_error( $audience ) ? array() : $audience,
		'topic'      => is_wp_error( $topic ) ? array() : $topic,
		'date_key'   => $upload !== '' ? (int) preg_replace( '/\D/', '', $upload ) : 0,
		'search'     => '',
		'is_short'   => xrv_is_short( $post_id ),
	);

	ob_start();
	?>
<div id="xroad-videos-app" class="xrv xrv--single" data-playback="<?php echo esc_attr( $playback ); ?>">
	<?php echo xrv_head_assets_once(); ?>
	<div class="xrv-grid" style="column-count:1">
		<?php echo xrv_render_card( $r ); ?>
	</div>
	<?php echo xrv_footer_js_once(); ?>
</div>
	<?php
	echo xrv_single_video_schema( $post_id ); // standalone rich VideoObject (not the CollectionPage wrapper)
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

	echo '<div style="max-width:760px">';

	$provider_opts = array(
		'auto'        => 'Auto-detect from URL (recommended)',
		'youtube'     => 'YouTube',
		'vimeo'       => 'Vimeo',
		'wistia'      => 'Wistia',
		'loom'        => 'Loom',
		'dailymotion' => 'Dailymotion',
		'file'        => 'Self-hosted file (MP4 / WebM)',
	);
	echo '<p style="margin:0 0 4px"><label for="_xrv_provider" style="display:block;font-weight:600;margin-bottom:4px">Provider</label>'
		. '<select id="_xrv_provider" name="_xrv_provider" class="widefat">';
	foreach ( $provider_opts as $pv => $plabel ) {
		echo '<option value="' . esc_attr( $pv ) . '"' . selected( $provider, $pv, false ) . '>' . esc_html( $plabel ) . '</option>';
	}
	echo '</select></p>';
	echo '<p style="margin:0 0 14px;color:#666;font-size:12px">Leave this on <strong>Auto-detect</strong> and just paste the video URL below. Supported hosts: YouTube, Vimeo, Wistia, Loom, Dailymotion. For a <strong>self-hosted file</strong>, choose that option and paste a direct MP4 or WebM URL, then upload a poster image below.</p>';

	$row( 'Video URL (paste the watch link; the ID is extracted automatically)', '_xrv_source_url', $src_url, 'e.g. https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'url' );

	echo '<p style="margin:-6px 0 14px"><button type="button" class="button xrv-file-pick">Choose a video file from the Media Library</button> <span style="color:#666;font-size:12px">for a self-hosted MP4 / WebM; this fills the URL above and sets Provider to Self-hosted file.</span></p>';

	echo '<p style="margin:0 0 14px;color:#666;font-size:12px">Detected video ID: <code>' . ( $vid !== '' ? esc_html( $vid ) : '— (saved after you add a URL)' ) . '</code></p>';

	// Poster image: a custom upload OR the auto-fetched YouTube thumbnail. Media picker wired in xrv_media_picker_js().
	$is_custom   = '1' === (string) get_post_meta( $post->ID, '_xrv_thumb_custom', true );
	$eff_id      = xrv_effective_thumb_id( $post->ID, $thumb_id );
	$preview_url = $eff_id ? wp_get_attachment_image_url( $eff_id, 'medium' ) : '';
	$src_label   = $is_custom ? 'custom upload' : ( $thumb_id ? 'auto-fetched from the video host' : ( $eff_id ? 'site default' : 'none yet' ) );
	$settings_link = esc_url( admin_url( 'edit.php?post_type=xroad_video&page=xrv-settings' ) );
	echo '<div class="xrv-media-field" style="margin:0 0 14px;padding:12px;border:1px solid #e2e6eb;border-radius:6px;background:#fbfbfc">'
		. '<label style="display:block;font-weight:600;margin-bottom:6px">Poster image <span style="font-weight:400;color:#787c82">(' . esc_html( $src_label ) . ')</span></label>'
		. '<div class="xrv-media-preview" style="margin-bottom:8px">' . ( $preview_url ? '<img src="' . esc_url( $preview_url ) . '" alt="" style="max-width:220px;height:auto;border-radius:4px;display:block">' : '<span style="color:#a05a00;font-size:12px">No poster yet — one is downloaded from the video host automatically on save (self-hosted files need a manual poster upload).</span>' ) . '</div>'
		. '<input type="hidden" class="xrv-media-id" name="_xrv_local_thumb_id" value="' . (int) $thumb_id . '">'
		. '<input type="hidden" class="xrv-media-custom" name="_xrv_thumb_custom" value="' . ( $is_custom ? '1' : '0' ) . '">'
		. '<button type="button" class="button xrv-media-pick">Upload / choose image</button>'
		. ' <button type="button" class="button-link xrv-media-clear" style="color:#b32d2e;margin-left:8px;' . ( $thumb_id ? '' : 'display:none' ) . '">Reset to automatic</button>'
		. '<p class="description" style="margin-top:8px">Upload your own poster to override the auto-fetched thumbnail. <strong>Reset to automatic</strong> clears it so the host thumbnail is re-fetched on save. If a video has no poster at all, the <a href="' . $settings_link . '">site default poster</a> is used.</p>'
		. '</div>';

	echo '<p style="margin:0 0 14px"><label for="_xrv_description" style="display:block;font-weight:600;margin-bottom:4px">Plain-language description (one or two sentences)</label>'
		. '<textarea id="_xrv_description" name="_xrv_description" rows="3" class="widefat" placeholder="A short, plain-language summary of the video.">'
		. esc_textarea( $desc ) . '</textarea></p>';

	$row( 'Dedicated page URL (optional — a page this card links out to)', '_xrv_dedicated_url', $dedicated, 'e.g. https://example.com/videos/example/', 'url' );
	$row( 'Duration (ISO 8601, optional — auto where available)', '_xrv_duration_iso', $dur, 'e.g. PT12M30S' );
	$row( 'Upload date (YYYY-MM-DD, optional — auto where available)', '_xrv_upload_date', $upload, 'e.g. 2025-09-15' );

	echo '<details class="xrv-adv" style="margin:14px 0 4px;border-top:1px solid #e2e6eb;padding-top:12px">'
		. '<summary style="cursor:pointer;font-weight:600;color:#6873B7">Rich video schema <span style="font-weight:400;color:#666">(optional — transcript &amp; key moments for richer Google results / AI citations)</span></summary>';

	echo '<p style="margin:0 0 14px"><label for="_xrv_transcript" style="display:block;font-weight:600;margin-bottom:4px">Transcript</label>'
		. '<textarea id="_xrv_transcript" name="_xrv_transcript" rows="6" class="widefat" placeholder="Paste the full transcript. Powers VideoObject.transcript — strong signal for accessibility, Google, and AI answer engines.">'
		. esc_textarea( $transcript ) . '</textarea></p>';

	echo '<p style="margin:0 0 6px"><label for="_xrv_chapters" style="display:block;font-weight:600;margin-bottom:4px">Key moments / chapters</label>'
		. '<textarea id="_xrv_chapters" name="_xrv_chapters" rows="5" class="widefat" placeholder="One per line:&#10;0:00 Introduction&#10;2:15 The diagnosis&#10;9:40 Treatment options">'
		. esc_textarea( $chapters ) . '</textarea></p>';
	echo '<p style="margin:0 0 14px;color:#666;font-size:12px">One per line as <code>M:SS Label</code> (or <code>H:MM:SS Label</code>). Emits <code>Clip</code> markup so the video can show "key moments" in Google search.</p>';
	echo '</details>';

	echo '</div>';
	echo '<p style="margin-top:6px;color:#666;font-size:12px">Series, Audience, and Topic are set in the taxonomy boxes in the sidebar. Drag videos in <strong>All Videos</strong> (or set the Order field under Page Attributes) to control the grid sequence. The keyword search index is built automatically.</p>';
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
	$dedicated  = isset( $_POST['_xrv_dedicated_url'] ) ? esc_url_raw( wp_unslash( $_POST['_xrv_dedicated_url'] ) ) : '';
	update_post_meta( $post_id, '_xrv_source_url', $source_url );
	update_post_meta( $post_id, '_xrv_dedicated_url', $dedicated );

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

	// Description + manual metadata.
	if ( isset( $_POST['_xrv_description'] ) ) {
		update_post_meta( $post_id, '_xrv_description', sanitize_textarea_field( wp_unslash( $_POST['_xrv_description'] ) ) );
	}
	if ( isset( $_POST['_xrv_transcript'] ) ) {
		update_post_meta( $post_id, '_xrv_transcript', sanitize_textarea_field( wp_unslash( $_POST['_xrv_transcript'] ) ) );
	}
	if ( isset( $_POST['_xrv_chapters'] ) ) {
		update_post_meta( $post_id, '_xrv_chapters', sanitize_textarea_field( wp_unslash( $_POST['_xrv_chapters'] ) ) );
	}
	$dur    = isset( $_POST['_xrv_duration_iso'] ) ? sanitize_text_field( wp_unslash( $_POST['_xrv_duration_iso'] ) ) : '';
	$upload = isset( $_POST['_xrv_upload_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_xrv_upload_date'] ) ) : '';

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
			update_post_meta( $post_id, '_xrv_description', sanitize_textarea_field( mb_substr( $od, 0, 5000 ) ) );
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
		wp_register_script( 'xrv-admin', false, array( 'wp-api-fetch', 'wp-dom-ready', 'wp-data' ), '2.1.1', true );
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
 *     A first-class admin tool under "Crossroad Videos > Import". Two tiers:
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
	);
}
function xrv_get_settings() {
	return wp_parse_args( (array) get_option( 'xrv_settings', array() ), xrv_settings_defaults() );
}
function xrv_sanitize_settings( $in ) {
	$d = xrv_settings_defaults(); $in = (array) $in; $out = array();
	$cn = isset( $in['consent_notice'] ) ? strtolower( $in['consent_notice'] ) : 'off';
	$out['consent_notice']  = in_array( $cn, array( 'off', 'light', 'strict', 'geo' ), true ) ? $cn : 'off';
	$out['consent_text']    = isset( $in['consent_text'] ) ? sanitize_text_field( $in['consent_text'] ) : $d['consent_text'];
	$out['consent_button']  = isset( $in['consent_button'] ) ? sanitize_text_field( $in['consent_button'] ) : $d['consent_button'];
	$out['consent_decline'] = isset( $in['consent_decline'] ) ? sanitize_text_field( $in['consent_decline'] ) : $d['consent_decline'];
	$out['privacy_url']     = isset( $in['privacy_url'] ) ? esc_url_raw( $in['privacy_url'] ) : '';
	$out['filter_ui']       = ( isset( $in['filter_ui'] ) && 'chips' === $in['filter_ui'] ) ? 'chips' : 'select';
	$cm = isset( $in['card_meta'] ) ? strtolower( $in['card_meta'] ) : 'full';
	$out['card_meta']       = in_array( $cm, array( 'full', 'compact', 'title' ), true ) ? $cm : 'full';
	$out['per_page']        = max( 1, (int) ( isset( $in['per_page'] ) ? $in['per_page'] : $d['per_page'] ) );
	$out['load_more']       = max( 1, (int) ( isset( $in['load_more'] ) ? $in['load_more'] : $d['load_more'] ) );
	$out['subscribe_url']   = isset( $in['subscribe_url'] ) ? esc_url_raw( $in['subscribe_url'] ) : '';
	$out['subscribe_label'] = isset( $in['subscribe_label'] ) ? sanitize_text_field( $in['subscribe_label'] ) : $d['subscribe_label'];
	$sd = isset( $in['shorts_default'] ) ? strtolower( $in['shorts_default'] ) : 'all';
	$out['shorts_default']  = in_array( $sd, array( 'all', 'only', 'hide' ), true ) ? $sd : 'all';
	$out['default_thumb_id'] = max( 0, (int) ( isset( $in['default_thumb_id'] ) ? $in['default_thumb_id'] : 0 ) );
	// Play-button color combo: only stored when the override box is ticked, so an unrelated save never
	// pins a color and clobbers a theme's --xrv-primary/--xrv-action brand tokens. (Admin-context fn.)
	if ( empty( $in['icon_override'] ) ) {
		$out['icon_color'] = '';
		$out['icon_hover'] = '';
	} else {
		$out['icon_color'] = isset( $in['icon_color'] ) ? (string) sanitize_hex_color( $in['icon_color'] ) : '';
		$out['icon_hover'] = isset( $in['icon_hover'] ) ? (string) sanitize_hex_color( $in['icon_hover'] ) : '';
	}
	$out['sync_url']        = isset( $in['sync_url'] ) ? esc_url_raw( trim( $in['sync_url'] ) ) : '';
	$sf = isset( $in['sync_freq'] ) ? strtolower( $in['sync_freq'] ) : 'off';
	$out['sync_freq']       = in_array( $sf, array( 'off', 'hourly', 'daily', 'weekly', 'monthly' ), true ) ? $sf : 'off';
	$out['sync_status']     = ( isset( $in['sync_status'] ) && 'draft' === $in['sync_status'] ) ? 'draft' : 'publish';
	$out['sync_max']        = min( 50, max( 1, (int) ( isset( $in['sync_max'] ) ? $in['sync_max'] : $d['sync_max'] ) ) );
	return $out;
}
add_action( 'admin_init', 'xrv_register_settings' );
function xrv_register_settings() {
	register_setting( 'xrv_settings_group', 'xrv_settings', array( 'type' => 'array', 'sanitize_callback' => 'xrv_sanitize_settings' ) );
	register_setting( 'xrv_settings_group', 'xrv_yt_api_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
}
add_action( 'admin_menu', 'xrv_register_settings_page' );
function xrv_register_settings_page() {
	$GLOBALS['xrv_settings_hook'] = add_submenu_page( 'edit.php?post_type=xroad_video', 'Crossroad Videos Settings', 'Settings', 'manage_options', 'xrv-settings', 'xrv_render_settings_page' );
}

/* =================================================================================================
 * 9e. CHANNEL AUTO-SYNC  (poll a channel/playlist for new uploads — on a schedule or on demand)
 *     Reuses the importer's YouTube Data API helpers. Only ADDS videos whose ID is not already in the
 *     library (dedup on _xrv_video_id), so a run is always idempotent and safe. Requires the API key
 *     (the only supported way to enumerate a channel's latest uploads). WP-Cron drives the schedule;
 *     "Sync now" runs it on demand. New videos publish or stay draft per the Settings choice.
 * ================================================================================================= */

/* Public entry point. A short-lived transient lock prevents two runs (e.g. a cron tick overlapping a
 * "Sync now" click) from both inserting the same new video. If a run is already in flight we return the
 * last result untouched rather than duplicating work. The lock self-heals: it expires on its own if a
 * run dies mid-way, so a crash can never wedge sync permanently. */
function xrv_sync_run() {
	if ( get_transient( 'xrv_sync_lock' ) ) {
		$last = get_option( 'xrv_sync_last', array() );
		return is_array( $last ) ? $last : array();
	}
	set_transient( 'xrv_sync_lock', 1, 15 * MINUTE_IN_SECONDS );
	try {
		$res = xrv_sync_perform();
	} finally {
		delete_transient( 'xrv_sync_lock' );
	}
	return $res;
}

/* The sync engine. Returns (and stores in option xrv_sync_last) a small result array. */
/* Shared YouTube provider-meta write for the importer and channel sync (both key duration/upload/desc
 * identically). ponytail: was a 6-line copy in each; one field set, one place to change. */
function xrv_apply_youtube_meta( $pid, $id, $f ) {
	update_post_meta( $pid, '_xrv_provider', 'youtube' );
	update_post_meta( $pid, '_xrv_video_id', $id );
	update_post_meta( $pid, '_xrv_source_url', 'https://www.youtube.com/watch?v=' . $id );
	if ( ! empty( $f['duration'] ) ) { update_post_meta( $pid, '_xrv_duration_iso', sanitize_text_field( $f['duration'] ) ); }
	if ( ! empty( $f['upload'] ) )   { update_post_meta( $pid, '_xrv_upload_date', sanitize_text_field( $f['upload'] ) ); }
	if ( ! empty( $f['desc'] ) )     { update_post_meta( $pid, '_xrv_description', sanitize_textarea_field( $f['desc'] ) ); }
}

/** Detect a Short. The Data API exposes no aspect ratio, so probe the canonical /shorts/ URL: YouTube
 *  303-redirects a non-Short to /watch but serves a Short in place. One HEAD per NEW video, admin-side only.
 *  ponytail: HEAD probe; replace with an API signal if YouTube ever ships one. */
function xrv_yt_is_short( $id ) {
	$r = wp_remote_head( 'https://www.youtube.com/shorts/' . rawurlencode( $id ), array( 'redirection' => 0, 'timeout' => 4 ) );
	return ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
}

function xrv_sync_perform() {
	$s   = xrv_get_settings();
	$key = (string) get_option( 'xrv_yt_api_key', '' );
	$url = isset( $s['sync_url'] ) ? trim( (string) $s['sync_url'] ) : '';
	$now = current_time( 'mysql' );

	if ( '' === $key || '' === $url ) {
		$res = array( 'time' => $now, 'ok' => false, 'checked' => 0, 'added' => 0, 'msg' => 'Needs a YouTube Data API key and a channel/playlist URL.' );
		update_option( 'xrv_sync_last', $res ); return $res;
	}

	// Resolve the source to a playlist of uploads (a ?list= URL is already a playlist).
	if ( preg_match( '#[?&]list=([A-Za-z0-9_-]+)#', $url, $m ) ) { $playlist = $m[1]; }
	else { $playlist = xrv_yt_uploads_playlist( $url, $key ); }
	if ( is_wp_error( $playlist ) ) {
		$res = array( 'time' => $now, 'ok' => false, 'checked' => 0, 'added' => 0, 'msg' => $playlist->get_error_message() );
		update_option( 'xrv_sync_last', $res ); return $res;
	}

	$max = min( 50, max( 1, (int) ( isset( $s['sync_max'] ) ? $s['sync_max'] : 25 ) ) );
	$ids = xrv_yt_playlist_ids( $playlist, $key, $max );
	if ( is_wp_error( $ids ) ) {
		$res = array( 'time' => $now, 'ok' => false, 'checked' => 0, 'added' => 0, 'msg' => $ids->get_error_message() );
		update_option( 'xrv_sync_last', $res ); return $res;
	}
	$ids = array_slice( array_values( array_unique( $ids ) ), 0, $max );

	// Which of these are not already in the library?
	global $wpdb;
	$existing = array_flip( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_xrv_video_id'" ) );
	$new = array();
	foreach ( $ids as $id ) { if ( ! isset( $existing[ $id ] ) ) { $new[] = $id; } }

	$added = 0; $titles = array();
	if ( $new ) {
		$status    = ( isset( $s['sync_status'] ) && 'draft' === $s['sync_status'] ) ? 'draft' : 'publish';
		$meta      = xrv_yt_videos_meta( $new, $key );
		if ( is_wp_error( $meta ) ) { $meta = array(); }
		$max_order = (int) $wpdb->get_var( "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = 'xroad_video'" );

		foreach ( $new as $id ) {
			$mv    = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$title = ! empty( $mv['title'] ) ? sanitize_text_field( $mv['title'] ) : $id;
			$max_order++;
			$pid = wp_insert_post( array( 'post_type' => 'xroad_video', 'post_status' => $status, 'post_title' => $title, 'menu_order' => $max_order ) );
			if ( is_wp_error( $pid ) ) { continue; }

			xrv_apply_youtube_meta( $pid, $id, $mv );

			$short = xrv_yt_is_short( $id );
			if ( $short ) { update_post_meta( $pid, '_xrv_short', '1' ); }
			$att = xrv_sideload_thumbnail( $pid, $id, 'youtube', $short ? xrv_thumb_candidates( $id, 'youtube', true ) : null );
			if ( ! is_wp_error( $att ) ) { update_post_meta( $pid, '_xrv_local_thumb_id', (int) $att ); set_post_thumbnail( $pid, (int) $att ); }

			$added++; $titles[] = $title;
		}
	}

	$res = array( 'time' => $now, 'ok' => true, 'checked' => count( $ids ), 'added' => $added, 'titles' => array_slice( $titles, 0, 10 ) );
	update_option( 'xrv_sync_last', $res );
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
	xrv_sync_run();
	wp_safe_redirect( add_query_arg( 'xrv_synced', '1', admin_url( 'edit.php?post_type=xroad_video&page=xrv-settings' ) ) );
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

function xrv_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$s       = xrv_get_settings();
	$key     = (string) get_option( 'xrv_yt_api_key', '' );
	$wp_priv = get_privacy_policy_url();
	?>
	<div class="wrap xrv-settings">
		<h1 style="display:flex;align-items:center;gap:14px;font-weight:600;margin-bottom:2px"><?php echo xrv_brand_logo( 30 ); ?> <span style="color:var(--xr-deep)">Videos <span style="color:var(--xr-blue);font-weight:500">— Settings</span></span></h1>
		<p style="max-width:780px;color:#50575e">Site-wide <strong>defaults</strong> for every gallery. Anything set directly on a <code>[xroad-videos]</code> shortcode or the block overrides what you choose here.</p>
		<style>
		/* Crossroad Media brand palette (admin chrome only; the front-end gallery stays per-tenant). */
		.xrv-settings{--xr-purple:#342669;--xr-deep:#2A1F4F;--xr-blue:#6873B7;--xr-light:#E8E3F3;--xr-charcoal:#414042;--xr-warm:#F7F7F7;--xr-line:#e6e3ef;font-family:'DM Sans',ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
		.xrv-settings h1{color:var(--xr-deep);font-weight:600;letter-spacing:-.01em}
		.xrv-settings .form-table th{width:210px;color:var(--xr-charcoal)}
		.xrv-settings .xrv-card{position:relative;background:#fff;border:1px solid var(--xr-line);border-radius:16px;margin:18px 0;box-shadow:0 1px 2px rgba(42,31,79,.05),0 10px 26px -14px rgba(42,31,79,.18)}
		.xrv-settings .xrv-card::before{content:"";position:absolute;left:12px;top:0;bottom:0;width:26px;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 22 120'%3E%3Cpath d='M18 3C13 3 13 7 13 12L13 50C13 56 12 60 6 60C12 60 13 64 13 70L13 108C13 113 13 117 18 117' fill='none' stroke='%23342669' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat left center;background-size:contain}
		.xrv-settings .xrv-card>*{margin:0;padding-left:44px;padding-right:26px}
		.xrv-settings .xrv-card>h2.title{padding-top:16px;padding-bottom:12px;border:0;border-bottom:1px solid var(--xr-light);border-radius:0;background:transparent;font-size:15px;font-weight:600;letter-spacing:-.01em;color:var(--xr-deep);scroll-margin-top:60px}
		.xrv-settings .xrv-card>.form-table{padding-top:8px;padding-bottom:14px;border:0;background:transparent}
		.xrv-settings .xrv-card>p,.xrv-settings .xrv-card>form{padding-top:6px;padding-bottom:14px}
		.xrv-settings h2.title{margin:24px 0 6px;font-size:15px;font-weight:600;color:var(--xr-deep)}
		.xrv-settings .xrv-nav{position:sticky;top:46px;z-index:9;display:flex;flex-wrap:wrap;gap:8px;margin:16px 0 6px;padding:10px;background:var(--xr-warm);border:1px solid var(--xr-line);border-radius:12px}
		.xrv-settings .xrv-nav a{text-decoration:none;font-size:12px;font-weight:600;letter-spacing:.02em;text-transform:uppercase;color:var(--xr-purple);background:#fff;border:1px solid var(--xr-line);border-radius:999px;padding:6px 14px;transition:background .12s ease,color .12s ease,border-color .12s ease}
		.xrv-settings .xrv-nav a:hover,.xrv-settings .xrv-nav a:focus{background:var(--xr-purple);color:#fff;border-color:var(--xr-purple)}
		.xrv-settings details.xrv-help{margin:.5em 0 0;max-width:760px}
		.xrv-settings details.xrv-help>summary{cursor:pointer;color:var(--xr-blue);font-weight:600;font-size:13px;list-style:none}
		.xrv-settings details.xrv-help>summary::-webkit-details-marker{display:none}
		.xrv-settings details.xrv-help>summary::before{content:"\25b8\00a0";color:var(--xr-blue)}
		.xrv-settings details.xrv-help[open]>summary::before{content:"\25be\00a0"}
		.xrv-settings .button-primary{background:var(--xr-purple);border-color:var(--xr-deep);box-shadow:none;text-shadow:none}
		.xrv-settings .button-primary:hover,.xrv-settings .button-primary:focus{background:var(--xr-deep);border-color:var(--xr-deep);color:#fff}
		.xrv-settings a{color:var(--xr-blue)}
		</style>
		<nav class="xrv-nav" aria-label="Settings sections">
			<a href="#xrv-sec-privacy">Privacy &amp; consent</a>
			<a href="#xrv-sec-browse">Browse</a>
			<a href="#xrv-sec-api">API key</a>
			<a href="#xrv-sec-poster">Default poster</a>
			<a href="#xrv-sec-color">Play button</a>
				<a href="#xrv-sec-shorts">Shorts</a>
			<a href="#xrv-sec-sync">Auto-sync</a>
				<a href="#xrv-sec-urls">URLs</a>
		</nav>
		<form method="post" action="options.php">
			<?php settings_fields( 'xrv_settings_group' ); ?>

			<div class="xrv-card"><h2 class="title" id="xrv-sec-privacy">Privacy &amp; consent</h2>
			<table class="form-table" role="presentation">
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
				<tr>
					<th scope="row"><label for="xrv-consent">Consent mode</label></th>
					<td>
						<select id="xrv-consent" name="xrv_settings[consent_notice]">
							<option value="geo"    <?php selected( $s['consent_notice'], 'geo' ); ?>>Global (respects regional privacy laws including GDPR and CCPA) - recommended</option>
							<option value="strict" <?php selected( $s['consent_notice'], 'strict' ); ?>>Strict GDPR (opt-in prompt for every visitor)</option>
							<option value="off"    <?php selected( $s['consent_notice'], 'off' ); ?>>No Consent Integration</option>
						</select>
						<p class="description" style="max-width:760px">
							Every mode uses the click-to-load facade: the player makes no request, cookie, or connection to YouTube until a visitor clicks play. These options set the consent layer on top of that.
						</p>
						<details class="xrv-help">
							<summary>How the modes differ (GDPR / CCPA)</summary>
						<ul class="description" style="max-width:760px;list-style:disc;margin-left:1.4em">
							<li><strong>Global</strong> (recommended): adapts to the visitor's region. Visitors in the EU, UK, EEA, or Switzerland get a dismissible opt-in "Load video" prompt before anything loads (GDPR / ePrivacy), and their browser makes <strong>zero</strong> contact with any Google domain (the background preconnect is suppressed too) until they accept. Everyone else, including US / California visitors, plays in one click; because the facade shares no data with YouTube until that click, this meets the US notice-and-opt-out model (CCPA / CPRA) without adding friction. Region is detected from an edge country header (see <strong>Geo source</strong> above).</li>
							<li><strong>Strict GDPR</strong>: the dismissible opt-in prompt and zero-contact guarantee for <strong>every</strong> visitor worldwide, regardless of region. The most defensible posture; slightly slower first play.</li>
							<li><strong>No Consent Integration</strong>: no prompt or notice. The click-to-load facade still applies (no YouTube contact until a click), but the plugin adds no consent layer. Use only where you handle consent elsewhere or do not serve regulated regions.</li>
						</ul>
						<p class="description" style="max-width:760px">
							The opt-in prompt is declinable (× or "No thanks"), so refusing is as easy as accepting, and the click is the consent that loads the embed. Global mode reads a visitor-country header from your edge/CDN; if none is present it fails safe by prompting everyone, and the <code>xrv_consent_required</code> filter can override the region logic.<br>
							<em>Informational only, not legal advice. Cookies and tags from your analytics, ads, and consent manager are governed by those tools, not this plugin.</em>
						</p>
						</details>
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
				<div class="xrv-card"><h2 class="title" id="xrv-sec-browse">Browse defaults</h2>
			<table class="form-table" role="presentation">
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
				<div class="xrv-card"><h2 class="title" id="xrv-sec-api">YouTube Data API key (optional)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="xrv-key">API key</label></th>
					<td><input type="text" id="xrv-key" name="xrv_yt_api_key" value="<?php echo esc_attr( $key ); ?>" class="regular-text" autocomplete="off">
					<p class="description">Only needed to import an entire channel/playlist with durations &amp; descriptions, and for <strong>auto-sync</strong> below. Pasting URLs or a JSON file needs no key. Used by <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=xroad_video&page=xrv-import' ) ); ?>">Import</a>.</p></td>
				</tr>
			</table>

			</div>
				<div class="xrv-card"><h2 class="title" id="xrv-sec-poster">Default poster image</h2>
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
			</table>

			</div>
				<div class="xrv-card"><h2 class="title" id="xrv-sec-color">Play button color</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Brand color combo</th>
					<td>
						<?php
						$icon_on = ( '' !== $s['icon_color'] || '' !== $s['icon_hover'] );
						$ic = '' !== $s['icon_color'] ? $s['icon_color'] : '#013C60';
						$ih = '' !== $s['icon_hover'] ? $s['icon_hover'] : '#007A53';
						?>
						<label style="display:block;margin-bottom:10px"><input type="checkbox" name="xrv_settings[icon_override]" value="1" <?php checked( $icon_on ); ?>> Override the play-button color</label>
						<label style="display:inline-flex;align-items:center;gap:8px;margin-right:24px">Idle <input type="color" name="xrv_settings[icon_color]" value="<?php echo esc_attr( $ic ); ?>"></label>
						<label style="display:inline-flex;align-items:center;gap:8px">Hover <input type="color" name="xrv_settings[icon_hover]" value="<?php echo esc_attr( $ih ); ?>"></label>
						<p class="description">Colors the click-to-load play button (the YouTube icon) for its idle and hover states. Leave the box unticked to inherit your theme's brand colors (defaults: navy <code>#013C60</code> / green <code>#007A53</code>). The white play triangle is unchanged. Applies to every gallery and single embed on the site.</p>
					</td>
				</tr>
			</table>

			</div>
				<div class="xrv-card"><h2 class="title" id="xrv-sec-shorts">Shorts</h2>
					<table class="form-table" role="presentation">
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
				<div class="xrv-card"><h2 class="title" id="xrv-sec-sync">Automatic channel sync</h2>
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
						<?php foreach ( array( 'off' => 'On demand / never', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly' ) as $val => $lbl ) {
							echo '<option value="' . esc_attr( $val ) . '" ' . selected( $s['sync_freq'], $val, false ) . '>' . esc_html( $lbl ) . '</option>';
						} ?>
					</select>
					<p class="description"><strong>On demand / never</strong> turns off the schedule &mdash; the library only updates when you click <em>Sync now</em> below. Any other choice checks automatically at that cadence.
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
			</table>
			</div>

			<?php submit_button(); ?>
		</form>

		<?php
		// Video URLs — write-once permalink structure (its own form; not a Settings-API save).
		$pl = xrv_permalinks();
		if ( isset( $_GET['xrv_urls'] ) ) {
			echo $pl['locked']
				? '<div class="notice notice-success is-dismissible"><p>Video URL structure locked in &mdash; rewrite rules flushed.</p></div>'
				: '<div class="notice notice-warning is-dismissible"><p>Tick the confirmation box to set the URL structure.</p></div>';
		}
		?>
		<div class="xrv-card">
			<h2 class="title" id="xrv-sec-urls">Video URLs</h2>
			<?php if ( $pl['locked'] ) : ?>
				<p><span style="color:#007a53;font-weight:600">&#128274; Locked.</span> Single video: <code><?php echo esc_html( '/' . $pl['single'] . '/{slug}' ); ?></code><?php if ( '' !== $pl['archive'] ) { echo ' &nbsp;&middot;&nbsp; Collection: <code>' . esc_html( '/' . $pl['archive'] . '/' ) . '</code>'; } ?></p>
				<p class="description">The video URL structure is permanent, so existing links and SEO stay intact. Changing it later means a deliberate URL migration (with redirects).</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="xrv_lock_urls">
					<?php wp_nonce_field( 'xrv_lock_urls' ); ?>
					<p class="description" style="margin:.5em 0 0">Choose the URL structure for your videos. <strong>This is set once and cannot be changed later</strong> (live links depend on it), so decide deliberately.</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="xrv-single">Single video base</label></th>
							<td><code>/</code> <input type="text" id="xrv-single" name="xrv_single" value="<?php echo esc_attr( $pl['single'] ); ?>" style="width:200px"> <code>/{slug}</code>
							<p class="description">A single video's own page. e.g. <code>video</code> &rarr; <code>/video/my-clip/</code>.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="xrv-archive">Collection base</label></th>
							<td><code>/</code> <input type="text" id="xrv-archive" name="xrv_archive" value="<?php echo esc_attr( $pl['archive'] ); ?>" style="width:200px" placeholder="(none)"> <code>/</code>
							<p class="description">Optional archive listing every video. e.g. <code>videos</code> &rarr; <code>/videos/</code>. Blank = videos via shortcode/block only.</p></td>
						</tr>
						<tr>
							<th scope="row">Confirm</th>
							<td><label><input type="checkbox" name="xrv_confirm" value="1"> I understand this sets the video URL structure <strong>permanently</strong> &mdash; it cannot be changed later without breaking existing links.</label></td>
						</tr>
					</table>
					<p><?php submit_button( 'Set permanent URLs', 'primary', 'submit', false ); ?></p>
				</form>
			<?php endif; ?>
		</div>

		<?php
		// On-demand sync (separate form: it performs an action, not a settings save).
		if ( isset( $_GET['xrv_synced'] ) ) {
			$last = get_option( 'xrv_sync_last', array() );
			if ( ! empty( $last['ok'] ) ) {
				$msg = sprintf( 'Sync complete: checked %d, added %d new video%s.', (int) $last['checked'], (int) $last['added'], 1 === (int) $last['added'] ? '' : 's' );
				if ( ! empty( $last['titles'] ) ) { $msg .= ' (' . esc_html( implode( ', ', array_map( 'sanitize_text_field', $last['titles'] ) ) ) . ')'; }
				echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
			} elseif ( ! empty( $last['msg'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>Sync could not run: ' . esc_html( $last['msg'] ) . '</p></div>';
			}
		}
		?>
		<h2 class="title">Run a sync now</h2>
		<p class="description" style="max-width:780px">Check the channel immediately, using the settings above. Save your changes first if you just edited them.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="xrv_sync_now">
			<?php wp_nonce_field( 'xrv_sync_now' ); ?>
			<?php submit_button( 'Sync now', 'secondary', 'submit', false, '' === $key || empty( $s['sync_url'] ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<?php
		$last = get_option( 'xrv_sync_last', array() );
		if ( ! empty( $last['time'] ) ) {
			echo '<p class="description" style="margin-top:8px">Last run: <strong>' . esc_html( get_date_from_gmt( get_gmt_from_date( $last['time'] ), 'M j, Y g:i a' ) ) . '</strong> &mdash; ' . ( ! empty( $last['ok'] ) ? esc_html( sprintf( 'checked %d, added %d.', (int) $last['checked'], (int) $last['added'] ) ) : esc_html( $last['msg'] ) ) . '</p>';
		}
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
function xrv_yt_videos_meta( $ids, $key ) {
	$out = array();
	foreach ( array_chunk( $ids, 50 ) as $chunk ) {
		$r = xrv_yt_get( 'videos', array( 'part' => 'snippet,contentDetails', 'id' => implode( ',', $chunk ), 'maxResults' => 50 ), $key );
		if ( is_wp_error( $r ) ) { return $r; }
		foreach ( (array) ( $r['items'] ?? array() ) as $it ) {
			$out[ $it['id'] ] = array(
				'title'    => $it['snippet']['title'] ?? '',
				'desc'     => $it['snippet']['description'] ?? '',
				'upload'   => isset( $it['snippet']['publishedAt'] ) ? substr( $it['snippet']['publishedAt'], 0, 10 ) : '',
				'duration' => $it['contentDetails']['duration'] ?? '',
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
				'title'    => isset( $rec['title'] ) ? (string) $rec['title'] : '',
				'desc'     => isset( $rec['desc'] ) ? (string) $rec['desc'] : '',
				'upload'   => isset( $rec['upload'] ) ? (string) $rec['upload'] : '',
				'duration' => isset( $rec['duration'] ) ? (string) $rec['duration'] : '',
				'series'   => xrv_import_term_list( isset( $rec['series'] ) ? $rec['series'] : '' ),
				'audience' => xrv_import_term_list( isset( $rec['audience'] ) ? $rec['audience'] : '' ),
				'topic'    => xrv_import_term_list( isset( $rec['topic'] ) ? $rec['topic'] : '' ),
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
			'series'   => isset( $meta[ $id ]['series'] ) ? (array) $meta[ $id ]['series'] : array(),
			'audience' => isset( $meta[ $id ]['audience'] ) ? (array) $meta[ $id ]['audience'] : array(),
			'topic'    => isset( $meta[ $id ]['topic'] ) ? (array) $meta[ $id ]['topic'] : array(),
			'exists'   => $is_existing,
			'thumb'    => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
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
		if ( $short ) { update_post_meta( $pid, '_xrv_short', '1' ); }
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
	<div class="wrap xrv-import">
		<h1 style="display:flex;align-items:center;gap:14px;font-weight:600"><?php echo xrv_brand_logo( 28 ); ?> <span style="color:#2A1F4F">Import Videos</span></h1>
		<p style="max-width:760px;color:#50575e">Paste YouTube video links (one per line) or upload a list. With an optional free
		<a href="https://developers.google.com/youtube/v3/getting-started" target="_blank" rel="noopener">YouTube Data API key</a>
		you can also paste a whole <strong>channel</strong> or <strong>playlist</strong> URL and pull duration, date, and description for richer schema.</p>

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
					<p><label class="button">Choose file&hellip;<input type="file" id="xrv-imp-file" accept=".txt,.csv,.json" style="display:none"></label> <span style="color:#787c82">.txt / .csv of URLs, or a .json metadata file (id, title, duration, upload, desc) for rich import with no API key</span></p>
				</td></tr>
		</tbody></table>

		<p><button id="xrv-imp-preview" class="button button-primary">Preview import</button> <span id="xrv-imp-status" style="margin-left:8px"></span></p>
		<div id="xrv-imp-results"></div>
		<div id="xrv-imp-progress"></div>
	</div>
	<style>
		.xrv-import #xrv-imp-results table{max-width:920px}
		.xrv-import td .xrv-badge-new{color:#007a53;font-weight:600}
		.xrv-import td .xrv-badge-exists{color:#8a6a2a;font-weight:600}
		.xrv-import fieldset{border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;max-width:920px}
		.xrv-import .xrv-bar-wrap{max-width:920px;background:#e2e6eb;border-radius:6px;height:16px;overflow:hidden}
		.xrv-import .xrv-bar{height:100%;width:0;background:#007a53;transition:width .3s}
		.xrv-import .xrv-tag{display:inline-block;margin:2px 4px 2px 0;padding:1px 8px;border-radius:10px;font-size:11px;line-height:1.7;white-space:nowrap}
		.xrv-import .xrv-tag.is-series{background:#e6f4ee;color:#0a6b48}
		.xrv-import .xrv-tag.is-aud{background:#eef1fb;color:#3a4a8c}
		.xrv-import .xrv-tag.is-topic{background:#f3eefb;color:#6b3a8c}
	</style>
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
			var payload=batch.map(function(v){ return {id:v.id,title:v.title,duration:v.duration,upload:v.upload,desc:v.desc,series:v.series||[],audience:v.audience||[],topic:v.topic||[]}; });
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
				$tid = (int) get_post_meta( $pid, '_xrv_local_thumb_id', true );
				if ( $tid ) {
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
	delete_option( 'xrv_delete_data_on_uninstall' );
	delete_option( 'xrv_delete_thumbs_on_uninstall' );
}
