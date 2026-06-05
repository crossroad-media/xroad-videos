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
 * Version:           1.0.2
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
 * PROVIDER ROUTING. A scalar _xrv_provider meta (youtube default; vimeo reserved for 2.0) routes all
 * provider-specific logic (ID parser, thumbnail candidates, embed URL) through a switch(), so a second
 * provider is additive, not a rewrite.
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

add_action( 'init', 'xrv_register_data_model' );
function xrv_register_data_model() {

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
		'has_archive'   => false,                 // the curated source for the grid; cards link out via _xrv_dedicated_url
		'show_in_rest'  => true,
		'menu_icon'     => 'dashicons-video-alt3',
		'rewrite'       => array( 'slug' => 'xroad-video' ),
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
		'_xrv_provider'      => 'string',  // youtube (default) | vimeo (reserved for 2.0)
		'_xrv_video_id'      => 'string',  // the platform video ID (11 chars for YouTube)
		'_xrv_source_url'    => 'string',  // canonical watch URL
		'_xrv_dedicated_url' => 'string',  // optional: a dedicated page for this video the card links to
		'_xrv_duration_iso'  => 'string',  // ISO 8601, e.g. PT12M30S
		'_xrv_upload_date'   => 'string',  // YYYY-MM-DD
		'_xrv_description'    => 'string', // plain-language summary
		'_xrv_local_thumb_id' => 'integer', // media-library attachment ID for the locally stored poster
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

	update_option( 'xrv_version', '1.0.2' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* =================================================================================================
 * 2. PROVIDER ROUTING  (the additive seam for Vimeo in 2.0)
 *    Every provider-specific operation routes through a switch($provider). 1.0 implements youtube only;
 *    vimeo is reserved. Adding it in 2.0 means filling three branches, not rewriting the renderer.
 * ================================================================================================= */

/** Extract the platform video ID from a pasted URL (or a bare ID). Returns '' if none found. */
function xrv_extract_video_id( $url, $provider = 'youtube' ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}
	switch ( $provider ) {
		case 'vimeo':
			// Reserved for 2.0. Vimeo IDs are numeric; accept a bare ID or a player/clip URL.
			if ( preg_match( '/(?:vimeo\.com\/|video\/)(\d+)/', $url, $m ) ) {
				return $m[1];
			}
			return preg_match( '/^\d+$/', $url ) ? $url : '';

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
function xrv_thumb_candidates( $id, $provider = 'youtube' ) {
	switch ( $provider ) {
		case 'vimeo':
			// Reserved for 2.0: vumbnail provides a no-key thumbnail by Vimeo ID.
			return array( 'https://vumbnail.com/' . $id . '.jpg' );

		case 'youtube':
		default:
			// maxres is absent on older uploads and YouTube serves a valid-looking 404 BODY, so the
			// sideload routine must check HTTP status, not image bytes. hqdefault always exists.
			return array(
				'https://i.ytimg.com/vi_webp/' . $id . '/maxresdefault.webp',
				'https://i.ytimg.com/vi/' . $id . '/maxresdefault.jpg',
				'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
			);
	}
}

/** The privacy-enhanced embed URL injected ON CLICK only. autoplay=1 because the click is the consent. */
function xrv_embed_url( $id, $provider = 'youtube' ) {
	switch ( $provider ) {
		case 'vimeo':
			// Reserved for 2.0: dnt=1 is Vimeo's do-not-track flag.
			return 'https://player.vimeo.com/video/' . $id . '?autoplay=1&dnt=1';

		case 'youtube':
		default:
			return 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0&modestbranding=1';
	}
}

/** The remote thumbnail URL used as a SECONDARY thumbnailUrl in schema (not on the rendered card). */
function xrv_remote_thumb_url( $id, $provider = 'youtube' ) {
	switch ( $provider ) {
		case 'vimeo':
			return 'https://vumbnail.com/' . $id . '.jpg';
		case 'youtube':
		default:
			return 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
	}
}

/** The canonical watch URL for a video, used for schema contentUrl and as a source-URL fallback. */
function xrv_watch_url( $id, $provider = 'youtube' ) {
	switch ( $provider ) {
		case 'vimeo':
			return 'https://vimeo.com/' . $id;
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
function xrv_sideload_thumbnail( $post_id, $id, $provider = 'youtube' ) {
	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$last_error = new WP_Error( 'xrv_no_candidate', 'No thumbnail candidate was reachable.' );

	foreach ( xrv_thumb_candidates( $id, $provider ) as $src ) {
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

		$ext       = preg_match( '/\.webp(\?|$)/i', $src ) ? 'webp' : 'jpg';
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

/** The local poster URL for a card: the sideloaded attachment if present, else the post thumbnail. */
function xrv_local_poster_url( $post_id, $thumb_id ) {
	if ( $thumb_id ) {
		$url = wp_get_attachment_image_url( (int) $thumb_id, 'large' );
		if ( $url ) {
			return $url;
		}
	}
	$pt = get_the_post_thumbnail_url( $post_id, 'large' );
	return $pt ? $pt : '';
}

/* =================================================================================================
 * 5. THE RENDERER
 *    Queries every video ordered by menu_order (the editor's manual drag-order), prints the full markup
 *    server-side — inline critical CSS, the SVG sprite, the filter bar, one card per video, the inline
 *    facade + filter JS, and the JSON-LD graph — and returns it as one string. Theme-agnostic; nothing
 *    here references Divi. Shortcode attributes pre-filter the query and set the column width.
 * ================================================================================================= */

function xrv_render( $atts = array() ) {

	$atts = shortcode_atts( array(
		'series'   => '',   // comma-separated xrv_series slugs to pre-filter
		'audience' => '',   // comma-separated xrv_audience slugs
		'topic'    => '',   // comma-separated xrv_topic slugs
		'limit'    => -1,   // max videos (default: all)
		'columns'  => '',   // force a fixed column count; blank = responsive column-width
	), $atts, 'xroad-videos' );

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

		// Pull slugs for each filterable taxonomy once; tally facet counts from the live result set.
		$term_map = array();
		$groups   = array();
		foreach ( $tax_for as $group => $tax ) {
			$slugs = wp_get_post_terms( $id, $tax, array( 'fields' => 'slugs' ) );
			$slugs = is_wp_error( $slugs ) ? array() : $slugs;
			$term_map[ $tax ] = $slugs;
			$groups[ $group ] = $slugs;
			foreach ( $slugs as $s ) {
				$facet[ $group ][ $s ] = ( $facet[ $group ][ $s ] ?? 0 ) + 1;
			}
		}
		$provider  = (string) get_post_meta( $id, '_xrv_provider', true );
		$provider  = $provider !== '' ? $provider : 'youtube';
		$vid       = (string) get_post_meta( $id, '_xrv_video_id', true );
		$desc      = (string) get_post_meta( $id, '_xrv_description', true );
		$dedicated = (string) get_post_meta( $id, '_xrv_dedicated_url', true );
		$dur_iso   = (string) get_post_meta( $id, '_xrv_duration_iso', true );
		$upload    = (string) get_post_meta( $id, '_xrv_upload_date', true );
		$thumb_id  = (int) get_post_meta( $id, '_xrv_local_thumb_id', true );

		$records[] = array(
			'id'         => $id,
			'title'      => get_the_title( $id ),
			'provider'   => $provider,
			'vid'        => $vid,
			'source_url' => (string) get_post_meta( $id, '_xrv_source_url', true ),
			'desc'       => $desc,
			'dedicated'  => $dedicated,
			'dur_iso'    => $dur_iso,
			'dur_clock'  => xrv_iso_to_clock( $dur_iso ),
			'upload'     => $upload,
			'poster'     => xrv_local_poster_url( $id, $thumb_id ),
			'series'     => $groups['series'],
			'audience'   => $groups['audience'],
			'topic'      => $groups['topic'],
			'date_key'   => $upload !== '' ? (int) preg_replace( '/\D/', '', $upload ) : 0,
			'search'     => xrv_build_search_index( $id, $term_map, $desc ),
		);
	}
	wp_reset_postdata();

	$total = count( $records );

	// Grid column rule: a fixed count if the editor asked for one, else responsive column-width.
	$grid_style = ( (int) $atts['columns'] > 0 )
		? 'column-count:' . (int) $atts['columns']
		: 'column-width:320px';

	ob_start();
	?>
<div id="xroad-videos-app" class="xrv">
	<?php echo xrv_icon_sprite(); ?>
	<?php echo xrv_inline_css(); ?>

	<section class="xrv-tool">
		<div class="xrv-bar">
			<div class="xrv-search">
				<svg class="xrv-ic"><use href="#xrv-i-search"/></svg>
				<input type="text" id="xrv-q" placeholder="Search videos&hellip;" aria-label="Search videos by keyword">
			</div>
			<div class="xrv-sortwrap">
				<label for="xrv-sort">Sort</label>
				<select id="xrv-sort">
					<option value="curated">Curated order</option>
					<option value="newest">Newest first</option>
					<option value="oldest">Oldest first</option>
					<option value="title">Title (A&ndash;Z)</option>
				</select>
			</div>
		</div>

		<?php
		// Filter chip rows, one per facet, built from the live counts so no empty filter ever shows.
		echo xrv_render_filter_group( 'series',   'Series',   'xrv_series',   $facet['series'] );
		echo xrv_render_filter_group( 'audience', 'Audience', 'xrv_audience', $facet['audience'] );
		echo xrv_render_filter_group( 'topic',    'Topic',    'xrv_topic',    $facet['topic'] );
		?>

		<div class="xrv-statusbar">
			<div class="xrv-count">Showing <strong id="xrv-shown"><?php echo (int) $total; ?></strong> of <strong><?php echo (int) $total; ?></strong> videos</div>
			<button type="button" class="xrv-reset" id="xrv-reset"><svg class="xrv-ic"><use href="#xrv-i-reset"/></svg> Reset</button>
		</div>

		<div class="xrv-grid" id="xrv-grid" style="<?php echo esc_attr( $grid_style ); ?>">
			<?php foreach ( $records as $r ) { echo xrv_render_card( $r ); } ?>
		</div>

		<div class="xrv-empty" id="xrv-empty" style="display:none">
			<h3>No videos match your filters</h3>
			<p>Try removing a filter or clearing the keyword search.</p>
		</div>
	</section>

	<?php echo xrv_inline_js(); ?>
</div>
	<?php
	echo xrv_schema_jsonld( $records, get_permalink() );

	return ob_get_clean();
}

/* -------------------------------------------------------------------------------------------------
 * 5a. One video card. The initial state is a LOCAL poster + a native <button> play control. No iframe,
 *     no third-party request, no cookie. The facade JS swaps in the youtube-nocookie iframe on click.
 * ------------------------------------------------------------------------------------------------- */
function xrv_render_card( $r ) {
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
	<figure class="xrv-card"
		data-vid="<?php echo esc_attr( $r['vid'] ); ?>"
		data-provider="<?php echo esc_attr( $r['provider'] ); ?>"
		data-series="<?php echo esc_attr( implode( ' ', $r['series'] ) ); ?>"
		data-audience="<?php echo esc_attr( implode( ' ', $r['audience'] ) ); ?>"
		data-topic="<?php echo esc_attr( implode( ' ', $r['topic'] ) ); ?>"
		data-date="<?php echo esc_attr( $r['date_key'] ); ?>"
		data-title="<?php echo esc_attr( strtolower( $title ) ); ?>"
		data-search="<?php echo esc_attr( $r['search'] ); ?>">
		<div class="xrv-frame">
			<button type="button" class="xrv-facade" aria-label="Play video: <?php echo esc_attr( $title ); ?>">
				<?php if ( $poster !== '' ) : ?>
					<img class="xrv-thumb" src="<?php echo esc_url( $poster ); ?>" width="480" height="360" loading="lazy" decoding="async" alt="<?php echo esc_attr( $title ); ?>">
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
			<?php if ( $r['desc'] !== '' ) : ?><p class="xrv-desc"><?php echo esc_html( $r['desc'] ); ?></p><?php endif; ?>
			<?php if ( $tags_html !== '' ) : ?><p class="xrv-tags"><?php echo $tags_html; ?></p><?php endif; ?>
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
		$thumbs[] = xrv_remote_thumb_url( $r['vid'], $r['provider'] );
		$node['thumbnailUrl'] = $thumbs;

		if ( $r['upload'] !== '' ) {
			$node['uploadDate'] = $r['upload'];
		}
		if ( $r['dur_iso'] !== '' ) {
			$node['duration'] = $r['dur_iso'];
		}

		$node['contentUrl'] = ! empty( $r['source_url'] ) ? $r['source_url'] : xrv_watch_url( $r['vid'], $r['provider'] );
		$node['embedUrl']   = xrv_embed_url( $r['vid'], $r['provider'] );
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

	return "\n" . '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}

/* =================================================================================================
 * 7. INLINE ASSETS  (SVG sprite, scoped critical CSS, vanilla JS)
 *    Emitted inline inside the rendered block, namespaced under .xrv- and #xroad-videos-app. Inlining is
 *    deliberate: first paint is self-sufficient, the styles survive a performance plugin's unused-CSS
 *    pass (inline styles are not removal candidates), and the tool stays theme-independent.
 *    WCAG AA: 4.5:1 text contrast, 3:1 non-text/focus ring.
 * ================================================================================================= */

function xrv_icon_sprite() {
	return <<<'SVG'
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<symbol id="xrv-i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol>
<symbol id="xrv-i-reset" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></symbol>
<symbol id="xrv-i-arrow" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></symbol>
</defs></svg>
SVG;
}

function xrv_inline_css() {
	return <<<'CSS'
<style>
#xroad-videos-app{font-family:'Gotham',Helvetica,Arial,sans-serif !important;color:#1a2332 !important;line-height:1.55 !important;max-width:1180px;margin:0 auto;padding:0}
#xroad-videos-app *,#xroad-videos-app *::before,#xroad-videos-app *::after{box-sizing:border-box}
#xroad-videos-app h3{font-family:inherit !important;line-height:1.3 !important;margin:0;font-weight:700;text-align:left}
#xroad-videos-app p{margin:0}
#xroad-videos-app a{color:#017A8E;text-decoration:none}
#xroad-videos-app a:hover{text-decoration:underline}
.xrv-ic{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-2px;flex:0 0 auto}
.xrv-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.xrv-search{position:relative;flex:1 1 320px;min-width:240px}
.xrv-search .xrv-ic{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#5a6573}
.xrv-search input{width:100%;padding:10px 12px 10px 36px;border:1px solid #c4ccd6;border-radius:4px;font-family:inherit;font-size:14px;color:#1a2332;background:#fff}
.xrv-search input:focus{outline:2px solid #019AB3;outline-offset:-1px;border-color:#019AB3}
.xrv-sortwrap{display:flex;align-items:center;gap:9px;font-size:12px}
.xrv-sortwrap label{letter-spacing:.08em;text-transform:uppercase;color:#5a6573;font-weight:700}
.xrv-sortwrap select{padding:7px 10px;border:1px solid #c4ccd6;border-radius:4px;font-family:inherit;font-size:13px;background:#fff;color:#1a2332;cursor:pointer}
.xrv-fg{display:flex;align-items:baseline;gap:10px;margin:0 0 10px;flex-wrap:wrap}
.xrv-ft{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#5a6573;font-weight:700;flex:0 0 64px;padding-top:5px}
.xrv-chips{display:flex;flex-wrap:wrap;gap:7px}
.xrv-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;background:#fff;border:1px solid #c4ccd6;border-radius:999px;font-family:inherit;font-size:12.5px;color:#1a2332;cursor:pointer;transition:background .12s ease,border-color .12s ease,color .12s ease}
.xrv-chip:hover{border-color:#019AB3;color:#017A8E}
.xrv-chip[aria-pressed="true"]{background:#013C60;border-color:#013C60;color:#fff}
.xrv-chip:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-cnt{font-size:11px;color:#636c79;font-variant-numeric:tabular-nums}
.xrv-chip[aria-pressed="true"] .xrv-cnt{color:rgba(255,255,255,.75)}
.xrv-statusbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0 16px;border-bottom:2px solid #013C60;margin-bottom:20px}
.xrv-count{font-size:14px;color:#5a6573}
.xrv-count strong{color:#013C60;font-weight:700;font-size:16px}
.xrv-reset{background:transparent;border:1px solid #c4ccd6;color:#5a6573;font-family:inherit;font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:700;padding:6px 11px;border-radius:3px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.xrv-reset:hover{border-color:#013C60;color:#013C60}
.xrv-reset:focus-visible{outline:3px solid rgba(1,154,179,.5);outline-offset:2px}
.xrv-grid{column-gap:20px}
.xrv-card{break-inside:avoid;-webkit-column-break-inside:avoid;page-break-inside:avoid;margin:0 0 24px;display:inline-block;width:100%;content-visibility:auto;contain-intrinsic-size:auto 320px}
.xrv-frame{position:relative;width:100%}
.xrv-facade{display:block;position:relative;width:100%;padding:0;margin:0;border:none;background:#0a1622;border-radius:6px;overflow:hidden;cursor:pointer;aspect-ratio:16/9;line-height:0}
.xrv-facade:focus-visible{outline:3px solid #019AB3;outline-offset:3px}
.xrv-thumb{display:block;width:100%;height:100%;object-fit:cover;border:0;transition:transform .3s ease,opacity .2s ease}
.xrv-thumb--ph{background:linear-gradient(135deg,#013C60,#017A8E)}
.xrv-facade:hover .xrv-thumb{transform:scale(1.04);opacity:.92}
.xrv-play{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:64px;height:46px;display:flex;align-items:center;justify-content:center;transition:transform .15s ease}
.xrv-play svg{width:100%;height:100%;display:block}
.xrv-play__bg{fill:#013C60;fill-opacity:.92;transition:fill .15s ease}
.xrv-facade:hover .xrv-play{transform:translate(-50%,-50%) scale(1.08)}
.xrv-facade:hover .xrv-play__bg{fill:#007A53;fill-opacity:1}
.xrv-dur{position:absolute;right:8px;bottom:8px;background:rgba(10,22,34,.85);color:#fff;font-size:12px;font-weight:600;line-height:1;padding:4px 6px;border-radius:3px;font-variant-numeric:tabular-nums}
.xrv-iframe{display:block;width:100%;aspect-ratio:16/9;border:0;border-radius:6px}
.xrv-cap{padding:12px 2px 0}
.xrv-title{font-size:16px !important;font-weight:700;color:#013C60 !important;margin:0 0 6px !important;line-height:1.3 !important}
.xrv-desc{font-size:13.5px;color:#4a5663;line-height:1.5;margin:0 0 9px}
.xrv-tags{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:11.5px;color:#5a6573;margin:0 0 9px}
.xrv-tags span::before{content:"#";color:#c4ccd6;margin-right:1px}
.xrv-page-link{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;letter-spacing:.02em;color:#017A8E !important}
.xrv-page-link .xrv-ic{width:13px;height:13px;transition:transform .15s ease}
.xrv-page-link:hover{text-decoration:none !important}
.xrv-page-link:hover .xrv-ic{transform:translateX(3px)}
.xrv-empty{text-align:center;padding:50px 20px;color:#5a6573}
.xrv-empty h3{color:#1a2332 !important;font-size:18px !important;margin-bottom:6px !important}
@media (max-width:880px){
.xrv-grid{column-width:auto !important;column-count:2 !important}
.xrv-ft{flex-basis:100%}
}
@media (max-width:560px){
.xrv-grid{column-count:1 !important}
.xrv-bar{flex-direction:column;align-items:stretch}
}
</style>
CSS;
}

function xrv_inline_js() {
	return <<<'JS'
<script>
(function(){
	function init(){
		var ROOT = document.getElementById('xroad-videos-app');
		if(!ROOT || ROOT.dataset.xrvReady) return;
		ROOT.dataset.xrvReady = '1';

		var grid    = ROOT.querySelector('#xrv-grid');
		var cards   = Array.prototype.slice.call(ROOT.querySelectorAll('.xrv-card'));
		var shownEl = ROOT.querySelector('#xrv-shown');
		var emptyEl = ROOT.querySelector('#xrv-empty');
		var qEl     = ROOT.querySelector('#xrv-q');
		var sortEl  = ROOT.querySelector('#xrv-sort');

		var origOrder = cards.slice();
		var state = { q:'', series:[], audience:[], topic:[], sort:'curated' };

		function groupVals(card, g){ return (card.getAttribute('data-'+g) || '').split(' ').filter(Boolean); }

		function matchGroup(g, card){
			if(state[g].length === 0) return true;
			var vals = groupVals(card, g);
			return state[g].some(function(v){ return vals.indexOf(v) > -1; });
		}
		function matchSearch(card){
			if(!state.q) return true;
			return (card.getAttribute('data-search') || '').indexOf(state.q) > -1;
		}
		function cmp(a, b){
			var s = state.sort;
			if(s === 'newest') return (+b.getAttribute('data-date')) - (+a.getAttribute('data-date'));
			if(s === 'oldest') return (+a.getAttribute('data-date')) - (+b.getAttribute('data-date'));
			if(s === 'title')  return a.getAttribute('data-title').localeCompare(b.getAttribute('data-title'));
			return 0; // curated == the server's menu_order, i.e. the original DOM order
		}

		function apply(){
			var ordered = (state.sort === 'curated') ? origOrder.slice() : cards.slice().sort(cmp);
			ordered.forEach(function(c){ grid.appendChild(c); });
			var shown = 0;
			ordered.forEach(function(c){
				var ok = matchGroup('series', c) && matchGroup('audience', c) && matchGroup('topic', c) && matchSearch(c);
				c.style.display = ok ? '' : 'none';
				if(ok) shown++;
			});
			if(shownEl) shownEl.textContent = shown;
			if(emptyEl) emptyEl.style.display = shown ? 'none' : 'block';
		}

		if(qEl) qEl.addEventListener('input', function(){ state.q = this.value.trim().toLowerCase(); apply(); });
		if(sortEl) sortEl.addEventListener('change', function(){ state.sort = this.value; apply(); });

		// Filter chips: toggle aria-pressed and recompute the active set for that group.
		ROOT.querySelectorAll('.xrv-chip').forEach(function(chip){
			chip.addEventListener('click', function(){
				var pressed = this.getAttribute('aria-pressed') === 'true';
				this.setAttribute('aria-pressed', pressed ? 'false' : 'true');
				var g = this.getAttribute('data-group');
				state[g] = Array.prototype.slice
					.call(ROOT.querySelectorAll('.xrv-chip[data-group="' + g + '"][aria-pressed="true"]'))
					.map(function(x){ return x.value; });
				apply();
			});
		});

		var resetBtn = ROOT.querySelector('#xrv-reset');
		if(resetBtn) resetBtn.addEventListener('click', function(){
			state = { q:'', series:[], audience:[], topic:[], sort:'curated' };
			if(qEl) qEl.value = '';
			if(sortEl) sortEl.value = 'curated';
			ROOT.querySelectorAll('.xrv-chip').forEach(function(x){ x.setAttribute('aria-pressed','false'); });
			apply();
		});

		// THE FACADE. One delegated click listener for the whole grid. Until this fires, the page has
		// made ZERO requests to any Google domain. On click we inject the youtube-nocookie iframe (the
		// user's click is the consent) and push a video_play event to the dataLayer for GTM / GA4.
		grid.addEventListener('click', function(e){
			var btn = e.target && e.target.closest ? e.target.closest('.xrv-facade') : null;
			if(!btn) return;
			var card = btn.closest('.xrv-card');
			if(!card) return;
			var id = card.getAttribute('data-vid');
			if(!id) return;
			var provider = card.getAttribute('data-provider') || 'youtube';

			var src = (provider === 'vimeo')
				? 'https://player.vimeo.com/video/' + id + '?autoplay=1&dnt=1'
				: 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1';

			var iframe = document.createElement('iframe');
			iframe.className = 'xrv-iframe';
			iframe.setAttribute('allowfullscreen', '');
			iframe.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
			iframe.title = (card.querySelector('.xrv-title') || {}).textContent || 'Video player';
			iframe.src = src;

			var frame = btn.closest('.xrv-frame') || btn.parentNode;
			btn.replaceWith(iframe);
			// Let the iframe own the aspect ratio now that the facade button is gone.
			if(frame && frame.style){ frame.style.lineHeight = '0'; }

			var titleEl = card.querySelector('.xrv-title');
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push({
				event: 'video_play',
				video_provider: provider,
				video_id: id,
				video_title: titleEl ? titleEl.textContent.trim() : '',
				video_series: (card.getAttribute('data-series') || '').split(' ')[0]
			});
		});

		// Mask post-click load latency: preconnect on first hover/focus, once per card.
		function preconnect(card){
			if(card.dataset.xrvPre) return;
			card.dataset.xrvPre = '1';
			var provider = card.getAttribute('data-provider') || 'youtube';
			var host = (provider === 'vimeo') ? 'https://player.vimeo.com' : 'https://www.youtube-nocookie.com';
			var l = document.createElement('link');
			l.rel = 'preconnect';
			l.href = host;
			document.head.appendChild(l);
		}
		grid.addEventListener('mouseover', function(e){
			var card = e.target && e.target.closest ? e.target.closest('.xrv-card') : null;
			if(card) preconnect(card);
		});
		grid.addEventListener('focusin', function(e){
			var card = e.target && e.target.closest ? e.target.closest('.xrv-card') : null;
			if(card) preconnect(card);
		});

		apply();
	}

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

add_action( 'init', 'xrv_register_block' );
function xrv_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	register_block_type( 'xroad/videos', array(
		'render_callback' => function( $attributes ) { return xrv_render( (array) $attributes ); },
	) );
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
function xrv_render_single( $post_id ) {
	$provider = (string) get_post_meta( $post_id, '_xrv_provider', true );
	$provider = $provider !== '' ? $provider : 'youtube';
	$vid      = (string) get_post_meta( $post_id, '_xrv_video_id', true );
	if ( $vid === '' ) {
		return ''; // nothing to render; leave the post body as-is.
	}

	$dur_iso  = (string) get_post_meta( $post_id, '_xrv_duration_iso', true );
	$upload   = (string) get_post_meta( $post_id, '_xrv_upload_date', true );
	$thumb_id = (int) get_post_meta( $post_id, '_xrv_local_thumb_id', true );

	$series   = wp_get_post_terms( $post_id, 'xrv_series', array( 'fields' => 'slugs' ) );
	$audience = wp_get_post_terms( $post_id, 'xrv_audience', array( 'fields' => 'slugs' ) );
	$topic    = wp_get_post_terms( $post_id, 'xrv_topic', array( 'fields' => 'slugs' ) );

	$r = array(
		'id'         => $post_id,
		'title'      => get_the_title( $post_id ),
		'provider'   => $provider,
		'vid'        => $vid,
		'source_url' => (string) get_post_meta( $post_id, '_xrv_source_url', true ),
		'desc'       => (string) get_post_meta( $post_id, '_xrv_description', true ),
		'dedicated'  => '', // on its own page there is nowhere else to link out to.
		'dur_iso'    => $dur_iso,
		'dur_clock'  => xrv_iso_to_clock( $dur_iso ),
		'upload'     => $upload,
		'poster'     => xrv_local_poster_url( $post_id, $thumb_id ),
		'series'     => is_wp_error( $series ) ? array() : $series,
		'audience'   => is_wp_error( $audience ) ? array() : $audience,
		'topic'      => is_wp_error( $topic ) ? array() : $topic,
		'date_key'   => $upload !== '' ? (int) preg_replace( '/\D/', '', $upload ) : 0,
		'search'     => '',
	);

	ob_start();
	?>
<div id="xroad-videos-app" class="xrv xrv--single">
	<?php echo xrv_icon_sprite(); ?>
	<?php echo xrv_inline_css(); ?>
	<div class="xrv-grid" style="column-count:1">
		<?php echo xrv_render_card( $r ); ?>
	</div>
	<?php echo xrv_inline_js(); ?>
</div>
	<?php
	echo xrv_schema_jsonld( array( $r ), get_permalink( $post_id ) );
	return ob_get_clean();
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
	$provider  = $provider !== '' ? $provider : 'youtube';
	$src_url    = (string) get_post_meta( $post->ID, '_xrv_source_url', true );
	$vid       = (string) get_post_meta( $post->ID, '_xrv_video_id', true );
	$dedicated = (string) get_post_meta( $post->ID, '_xrv_dedicated_url', true );
	$dur       = (string) get_post_meta( $post->ID, '_xrv_duration_iso', true );
	$upload    = (string) get_post_meta( $post->ID, '_xrv_upload_date', true );
	$desc      = (string) get_post_meta( $post->ID, '_xrv_description', true );
	$thumb_id  = (int) get_post_meta( $post->ID, '_xrv_local_thumb_id', true );

	$row = function( $label, $name, $value, $placeholder = '', $type = 'text' ) {
		printf(
			'<p style="margin:0 0 14px"><label for="%1$s" style="display:block;font-weight:600;margin-bottom:4px">%2$s</label>'
			. '<input type="%5$s" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" class="widefat"></p>',
			esc_attr( $name ), esc_html( $label ), esc_attr( $value ), esc_attr( $placeholder ), esc_attr( $type )
		);
	};

	echo '<div style="max-width:760px">';

	echo '<p style="margin:0 0 14px"><label for="_xrv_provider" style="display:block;font-weight:600;margin-bottom:4px">Provider</label>'
		. '<select id="_xrv_provider" name="_xrv_provider" class="widefat">'
		. '<option value="youtube"' . selected( $provider, 'youtube', false ) . '>YouTube</option>'
		. '<option value="vimeo"' . selected( $provider, 'vimeo', false ) . ' disabled>Vimeo (reserved for 2.0)</option>'
		. '</select></p>';

	$row( 'Video URL (paste the watch link; the ID is extracted automatically)', '_xrv_source_url', $src_url, 'e.g. https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'url' );

	echo '<p style="margin:0 0 14px;color:#666;font-size:12px">Detected video ID: <code>' . ( $vid !== '' ? esc_html( $vid ) : '— (saved after you add a URL)' ) . '</code>';
	if ( $thumb_id ) {
		echo ' &nbsp;·&nbsp; Local thumbnail: <strong style="color:#007A53">stored</strong> (attachment #' . (int) $thumb_id . ')';
	} else {
		echo ' &nbsp;·&nbsp; Local thumbnail: <strong style="color:#a05a00">not yet stored</strong> — it is downloaded automatically on save';
	}
	echo '</p>';

	echo '<p style="margin:0 0 14px"><label for="_xrv_description" style="display:block;font-weight:600;margin-bottom:4px">Plain-language description (one or two sentences)</label>'
		. '<textarea id="_xrv_description" name="_xrv_description" rows="3" class="widefat" placeholder="A short, plain-language summary of the video.">'
		. esc_textarea( $desc ) . '</textarea></p>';

	$row( 'Dedicated page URL (optional — a page this card links out to)', '_xrv_dedicated_url', $dedicated, 'e.g. https://example.com/videos/example/', 'url' );
	$row( 'Duration (ISO 8601, optional — auto where available)', '_xrv_duration_iso', $dur, 'e.g. PT12M30S' );
	$row( 'Upload date (YYYY-MM-DD, optional — auto where available)', '_xrv_upload_date', $upload, 'e.g. 2025-09-15' );

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

	// Provider (whitelisted; vimeo is reserved so it cannot be saved in 1.0).
	$provider = isset( $_POST['_xrv_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['_xrv_provider'] ) ) : 'youtube';
	if ( ! in_array( $provider, array( 'youtube' ), true ) ) {
		$provider = 'youtube';
	}
	update_post_meta( $post_id, '_xrv_provider', $provider );

	// URLs.
	$source_url = isset( $_POST['_xrv_source_url'] ) ? esc_url_raw( wp_unslash( $_POST['_xrv_source_url'] ) ) : '';
	$dedicated  = isset( $_POST['_xrv_dedicated_url'] ) ? esc_url_raw( wp_unslash( $_POST['_xrv_dedicated_url'] ) ) : '';
	update_post_meta( $post_id, '_xrv_source_url', $source_url );
	update_post_meta( $post_id, '_xrv_dedicated_url', $dedicated );

	// Description + manual metadata.
	if ( isset( $_POST['_xrv_description'] ) ) {
		update_post_meta( $post_id, '_xrv_description', sanitize_textarea_field( wp_unslash( $_POST['_xrv_description'] ) ) );
	}
	$dur    = isset( $_POST['_xrv_duration_iso'] ) ? sanitize_text_field( wp_unslash( $_POST['_xrv_duration_iso'] ) ) : '';
	$upload = isset( $_POST['_xrv_upload_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_xrv_upload_date'] ) ) : '';

	// Derive the platform ID from the pasted URL. Editors never hand-type IDs.
	$old_id = (string) get_post_meta( $post_id, '_xrv_video_id', true );
	$new_id = xrv_extract_video_id( $source_url, $provider );
	if ( $new_id !== '' ) {
		update_post_meta( $post_id, '_xrv_video_id', $new_id );
	}
	$id = $new_id !== '' ? $new_id : $old_id;

	// First-save prefill via no-key oEmbed: fill an empty post title and an empty description so schema
	// is never blank. Never overwrites an editor-entered value.
	if ( $id !== '' && ( ( $post->post_title === '' || $post->post_title === 'Auto Draft' ) || get_post_meta( $post_id, '_xrv_description', true ) === '' ) ) {
		$watch  = $source_url !== '' ? $source_url : xrv_watch_url( $id, $provider );
		$oembed = xrv_fetch_oembed( $watch, $provider );
		if ( ! empty( $oembed['title'] ) ) {
			if ( $post->post_title === '' || $post->post_title === 'Auto Draft' ) {
				// Unhook to avoid recursion, update the title, re-hook.
				remove_action( 'save_post_xroad_video', 'xrv_save_meta', 10 );
				wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $oembed['title'] ) ) );
				add_action( 'save_post_xroad_video', 'xrv_save_meta', 10, 2 );
			}
			if ( get_post_meta( $post_id, '_xrv_description', true ) === '' && ! isset( $_POST['_xrv_description'] ) ) {
				update_post_meta( $post_id, '_xrv_description', sanitize_text_field( $oembed['title'] ) );
			}
		}
	}

	update_post_meta( $post_id, '_xrv_duration_iso', $dur );
	update_post_meta( $post_id, '_xrv_upload_date', $upload );

	// Sideload a LOCAL thumbnail when none is stored yet OR the video ID changed. This is what makes the
	// rendered grid reference a /wp-content/uploads/ image and fire ZERO requests to i.ytimg.com.
	$thumb_id    = (int) get_post_meta( $post_id, '_xrv_local_thumb_id', true );
	$needs_thumb = $id !== '' && ( $thumb_id === 0 || $new_id !== $old_id );
	if ( $needs_thumb ) {
		$attach = xrv_sideload_thumbnail( $post_id, $id, $provider );
		if ( ! is_wp_error( $attach ) ) {
			update_post_meta( $post_id, '_xrv_local_thumb_id', (int) $attach );
			set_post_thumbnail( $post_id, (int) $attach );
		}
		// On WP_Error the editor's manually uploaded featured image (if any) remains the poster fallback.
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
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'xroad_video' !== $screen->post_type ) {
		return;
	}
	// Dependency-only handle (false src) so we can attach inline JS that runs after these cores load.
	wp_register_script( 'xrv-admin', false, array( 'wp-api-fetch', 'wp-dom-ready', 'wp-data' ), '1.0.2', true );
	wp_enqueue_script( 'xrv-admin' );
	wp_add_inline_script( 'xrv-admin', xrv_admin_autotitle_js() );
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

	delete_option( 'xrv_version' );
	delete_option( 'xrv_delete_data_on_uninstall' );
	delete_option( 'xrv_delete_thumbs_on_uninstall' );
}
