<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$ext = fw()->extensions->get( 'asset-optimizer' );

// Bust any stale object-cache reads (WP Engine / Memcached / Redis).
wp_cache_delete( 'fw_ext_asset_optimizer_known_css_handles', 'options' );
wp_cache_delete( 'fw_ext_asset_optimizer_known_js_handles', 'options' );

$known_css = $ext ? $ext->get_known_css_handles() : array();
$known_js  = $ext ? $ext->get_known_js_handles() : array();

// If we have nothing remembered yet, do a one-shot internal request to the
// home page so the frontend hooks run and populate both lists. The hook honors
// a query arg that disables page caches for that single request.
//
// Guarded to admin only: this options array is also loaded on the FRONTEND when
// the options model resolves defaults, and we must never fire an HTTP request
// there.
if ( is_admin() && $ext && ( empty( $known_css ) || empty( $known_js ) ) ) {
	$ext->discover_handles();
	$known_css = $ext->get_known_css_handles();
	$known_js  = $ext->get_known_js_handles();
}

// The maps are stored in frontend print order. For CSS we additionally float
// the theme stylesheets to the end (parent then child) so the list mirrors the
// combined file's cascade - the child theme last, with authority to override
// the framework/shortcode CSS. JS keeps pure dependency order.
if ( $ext && ! empty( $known_css ) ) {
	$ordered_css = $ext->prioritize_css_handles( array_keys( $known_css ), $known_css );
	$reordered   = array();
	foreach ( $ordered_css as $h ) {
		$reordered[ $h ] = $known_css[ $h ];
	}
	$known_css = $reordered;
}

/**
 * Which group a handle belongs to, as array( key, label ).
 *
 * A site can easily register 250+ stylesheets, and a flat list that long is
 * unusable - you cannot find a handle in it, and you cannot tell the framework's
 * own CSS from a third-party plugin's. Grouping is derived from the asset's PATH
 * (which is what actually identifies its owner), never from the handle name,
 * which plugins choose freely.
 *
 * The group ORDER below is deliberate: it mirrors the combined file's cascade,
 * the same order prioritize_css_handles() puts the handles in - WordPress core,
 * then the framework, then shortcodes, then extensions, then the themes, with
 * the child theme last. Sorting the list alphabetically would read more tidily
 * and would destroy that signal, so we group without re-sorting, and handles
 * keep their cascade order inside each group.
 *
 * @param string $handle
 * @param string $src
 * @return array array( group_key, group_label )
 */
if ( ! function_exists( 'fw_ao_asset_group' ) ) {
	function fw_ao_asset_group( $handle, $src ) {
		$path = (string) preg_replace( '#^https?://[^/]+#i', '', (string) $src );

		// Handles WordPress prints inline carry no src at all, so they are
		// matched by name - the one place where the name is the only signal.
		if ( $path === '' ) {
			if ( preg_match( '#^(wp|core)[-_]#i', $handle ) || 'global-styles' === $handle ) {
				return array( 'wp-core', __( 'WordPress core', 'fw' ) );
			}
			return array( 'other', __( 'Other / unknown', 'fw' ) );
		}

		if ( stripos( $path, '/wp-includes/' ) !== false || preg_match( '#^(wp|core)[-_]#i', $handle ) ) {
			return array( 'wp-core', __( 'WordPress core', 'fw' ) );
		}
		// The Animation Engine's effect partials live under /css/animate/, NOT
		// under a path containing "animation-engine". Their classes are applied
		// by JS after load, which makes them the riskiest set on the page, so
		// they get their own group rather than being scattered through the list.
		if ( stripos( $path, '/css/animate/' ) !== false || stripos( $path, '/animation-engine/' ) !== false ) {
			return array( 'animation', __( 'Animation effects', 'fw' ) );
		}
		if ( stripos( $path, '/extensions/shortcodes/shortcodes/' ) !== false ) {
			return array( 'shortcodes', __( 'UnysonPlus — Shortcodes', 'fw' ) );
		}
		if ( preg_match( '#/framework/extensions/([^/]+)/#i', $path, $m ) ) {
			$name = ucwords( str_replace( array( '-', '_' ), ' ', $m[1] ) );
			return array( 'ext-' . sanitize_key( $m[1] ), sprintf( __( 'Extension: %s', 'fw' ), $name ) );
		}
		if ( stripos( $path, '/framework/' ) !== false ) {
			return array( 'framework', __( 'UnysonPlus — Framework core', 'fw' ) );
		}
		if ( stripos( $path, '/uploads/unysonplus/' ) !== false ) {
			return array( 'generated', __( 'Generated CSS (presets, dynamic)', 'fw' ) );
		}

		$parent = trailingslashit( wp_make_link_relative( get_template_directory_uri() ) );
		$child  = trailingslashit( wp_make_link_relative( get_stylesheet_directory_uri() ) );
		if ( $child !== $parent && strpos( $path, $child ) === 0 ) {
			return array( 'child-theme', __( 'Child theme', 'fw' ) );
		}
		if ( strpos( $path, $parent ) === 0 ) {
			return array( 'parent-theme', __( 'Parent theme', 'fw' ) );
		}
		if ( stripos( $path, '/themes/' ) !== false ) {
			return array( 'theme-other', __( 'Other theme', 'fw' ) );
		}
		if ( preg_match( '#/plugins/([^/]+)/#i', $path, $m ) && 'unysonplus' !== $m[1] ) {
			return array( 'plugin-other', __( 'Other plugins', 'fw' ) );
		}
		return array( 'other', __( 'Other / unknown', 'fw' ) );
	}
}

// ---- CSS choices (all checked by default) ----
// Each choice carries its group on the input as data-ao-group / data-ao-group-label.
// The settings page's JS reads those to build the collapsible grouped UI; with JS
// off the list still renders exactly as before, just flat.
$css_choices  = array();
$css_defaults = array();
foreach ( $known_css as $handle => $src ) {
	$label = $handle;
	if ( ! empty( $src ) ) {
		$short  = preg_replace( '#^https?://[^/]+#i', '', $src );
		$label .= '  —  ' . $short;
	}
	list( $g_key, $g_label ) = fw_ao_asset_group( $handle, $src );

	$css_choices[ $handle ]  = array(
		'text' => $label,
		'attr' => array(
			'data-ao-group'       => $g_key,
			'data-ao-group-label' => $g_label,
		),
	);
	$css_defaults[ $handle ] = true;
}

// ---- JS choices (only first-party checked by default) ----
$js_defaults = $ext ? $ext->get_default_js_values() : array();
$js_choices  = array();
foreach ( $known_js as $handle => $src ) {
	$label = $handle;
	if ( ! empty( $src ) ) {
		$short  = preg_replace( '#^https?://[^/]+#i', '', $src );
		$label .= '  —  ' . $short;
	}
	list( $g_key, $g_label ) = fw_ao_asset_group( $handle, $src );
	$js_choices[ $handle ] = array(
		'text' => $label,
		'attr' => array(
			'data-ao-group'       => $g_key,
			'data-ao-group-label' => $g_label,
		),
	);
	if ( ! isset( $js_defaults[ $handle ] ) ) {
		$js_defaults[ $handle ] = false;
	}
}

// ---- Intro copy ----
if ( empty( $css_choices ) ) {
	$css_intro = '<p>'
		. esc_html__( 'No stylesheets could be detected from a homepage fetch. This usually means the homepage is being served from a full-page cache (e.g. WP Engine). Open the site in a private window with the query arg ?fw_asset_optimizer_discover=1 to force a fresh render, then return here and refresh.', 'fw' )
		. '</p>';
} else {
	$css_intro = '<p>'
		. esc_html__( 'Every stylesheet detected on the frontend is listed below and checked by default. Uncheck any stylesheet you do NOT want merged into the combined file — those will keep loading on their own.', 'fw' )
		. '</p>'
		. '<p style="opacity:.75;">'
		. esc_html__( 'Tip: to re-scan the frontend (after activating a new plugin or theme), visit any page with ?fw_asset_optimizer_discover=1 appended to the URL.', 'fw' )
		. '</p>';
}

if ( empty( $js_choices ) ) {
	$js_intro = '<p>'
		. esc_html__( 'No combinable scripts have been detected yet. Visit any page with ?fw_asset_optimizer_discover=1 appended to the URL to force a fresh scan, then return here and refresh.', 'fw' )
		. '</p>';
} else {
	$js_intro = '<p>'
		. esc_html__( 'Scripts detected on the frontend are listed below. For safety, only first-party scripts (the UnysonPlus plugin and your active theme) are checked by default — tick a third-party script to combine it too.', 'fw' )
		. '</p>'
		. '<p style="opacity:.75;">'
		. esc_html__( 'Only local footer scripts with no async/defer strategy and no inline/localized data are ever merged; WordPress core, external/CDN and anything carrying per-request data is always left alone, even if checked.', 'fw' )
		. '</p>';
}

$options = array(
	/** Filters options inserted before the asset-optimizer settings tabs so extensions can prepend their own settings fields. */
	apply_filters( 'fw:ext:asset-optimizer:settings-options:before', array() ),

	'tab_general' => array(
		'type'    => 'tab',
		'title'   => __( 'General', 'fw' ),
		'options' => array(
			'general_box' => array(
				'title'   => __( 'General', 'fw' ),
				'type'    => 'box',
				'options' => array(
					'group_general' => array(
						'type'    => 'group',
						'options' => array(
							'general_intro' => array(
								'type'  => 'html',
								'label' => false,
								'desc'  => false,
								'html'  => '<p>' . esc_html__( 'Master switches for the combiner. Fine-grained per-file control lives on the CSS and JavaScript tabs.', 'fw' ) . '</p>',
							),
							'combine_css' => array(
								'type'  => 'switch',
								'label' => __( 'Combine CSS', 'fw' ),
								'desc'  => __( 'Master switch for CSS combining. Turn off to serve stylesheets separately without touching your per-file selections on the CSS tab.', 'fw' ),
								'value' => true,
							),
							'combine_js' => array(
								'type'  => 'switch',
								'label' => __( 'Combine JavaScript', 'fw' ),
								'desc'  => __( 'Master switch for JavaScript combining. Turn off to serve scripts separately without touching your per-file selections on the JavaScript tab.', 'fw' ),
								'value' => true,
							),
							'css_scope' => array(
								'type'    => 'select',
								'label'   => __( 'CSS combining scope', 'fw' ),
								'desc'    => __( 'Per-page (default): each page combines ONLY the stylesheets it actually uses into its own file — smaller per page, in exact document order (best Lighthouse / GTmetrix “unused CSS” scores). Site-wide: one shared stylesheet built from every stylesheet discovered across the site, reused on every page (fewest downloads when a visitor browses many pages, but ships CSS the page does not use). JavaScript is always combined per-page.', 'fw' ),
								'no-validate' => true,
								'choices' => array(
									'per_page' => __( 'Per-page (each page combines its own CSS — default)', 'fw' ),
									'site'     => __( 'Site-wide (one shared bundle)', 'fw' ),
								),
								'value'   => 'per_page',
							),
							'css_delivery' => array(
								'type'    => 'select',
								'label'   => __( 'CSS delivery', 'fw' ),
								'desc'    => __( 'Linked file (default): the combined CSS loads as a separate cached file, which the browser must download before it can paint. Inline: the combined CSS is printed in a style tag in the page head instead, so the first paint no longer waits on a second request (fixes the “render-blocking requests” insight). Best for small sites where most visitors land on one page. A bundle larger than 50 KB compressed always stays a linked file.', 'fw' ),
								'no-validate' => true,
								'choices' => array(
									'file'   => __( 'Linked file (default)', 'fw' ),
									'inline' => __( 'Inline in the page head', 'fw' ),
								),
								'value'   => 'file',
							),
							'purge_css' => array(
								'type'  => 'switch',
								'label' => __( 'Remove unused CSS', 'fw' ),
								'desc'  => __( 'Strip rules nothing on the page can use. A typical page uses under 15% of the CSS it downloads; on measured sites this halves the combined file. Each page gets its own purged copy, generated on its first view and cached afterwards. <strong>Off by default, and worth testing before you rely on it:</strong> a rule removed in error shows up as a wrong hover state or a broken menu rather than an error, so after switching it on, click through a few pages — open the menu, expand an accordion, hover the buttons. Anything that looks wrong can be protected with the safelist below. Requires <em>Combine CSS</em> on and <em>CSS delivery</em> set to a linked file.', 'fw' ),
								'value' => false,
							),
							'purge_safelist' => array(
								'type'  => 'textarea',
								'label' => __( 'Never remove (safelist)', 'fw' ),
								'desc'  => __( 'One entry per line: any selector containing the text is kept. Wrap in slashes for a regular expression, e.g. <code>/^\\.promo-/</code>. Common state classes (<code>is-</code>, <code>has-</code>, <code>active</code>, <code>open</code>…), all hover/focus rules, animation and slider classes, and the Animation Engine are already protected — add entries here only for class names your own code adds with JavaScript.', 'fw' ),
								'value' => '',
							),
							'preload_lcp_image' => array(
								'type'  => 'switch',
								'label' => __( 'Preload the hero image', 'fw' ),
								'desc'  => __( 'Tell the browser about the page\'s main image straight away, instead of leaving it to find it after the stylesheet has downloaded and the layout is built. This does not make anything smaller — it changes the <em>order</em> things are fetched, which is usually where a slow "largest contentful paint" actually comes from. The first image that is not lazy-loaded is treated as the hero; on a page that has none, nothing is added.', 'fw' ),
								'value' => false,
							),
							'webp_images' => array(
								'type'  => 'switch',
								'label' => __( 'Serve WebP images', 'fw' ),
								'desc'  => __( 'Make a WebP copy of every uploaded JPG / PNG (and each of its sizes and crops) and show visitors the WebP instead: usually 25–80% smaller for the same look. The originals are kept untouched, so turning this off simply goes back to them. Existing images are converted gradually as pages are viewed.', 'fw' ),
								'value' => false,
							),
							'logged_out_only' => array(
								'type'  => 'switch',
								'label' => __( 'Only for logged-out visitors', 'fw' ),
								'desc'  => __( 'Serve the combined files only to visitors. Logged-in users (you) get the un-combined assets — handy while editing, and it sidesteps admin-bar edge cases.', 'fw' ),
								'value' => false,
							),
							'exclude_urls' => array(
								'type'  => 'textarea',
								'label' => __( 'Exclude URLs', 'fw' ),
								'desc'  => __( 'One path per line — the combiner is skipped on matching pages (assets load un-combined there). Match is against the request path; use * as a wildcard. Examples: /cart/  /checkout*  *?no-combine=1', 'fw' ),
								'value' => '',
							),
						),
					),
				),
			),
		),
	),

	'tab_css' => array(
		'type'    => 'tab',
		'title'   => __( 'CSS', 'fw' ),
		'options' => array(
			'css_box' => array(
				'title'   => __( 'CSS Files', 'fw' ),
				'type'    => 'box',
				'options' => array(
					'group_css' => array(
						'type'    => 'group',
						'options' => array(
							'css_intro' => array(
								'type'  => 'html',
								'label' => false,
								'desc'  => false,
								'html'  => $css_intro,
							),
							'css_handles' => array(
								'type'    => 'checkboxes',
								'label'   => __( 'Stylesheets to combine', 'fw' ),
								'desc'    => __( 'Checked = merged into one file. Unchecked = left as a separate request.', 'fw' ),
								'choices' => $css_choices,
								'value'   => $css_defaults,
							),
						),
					),
				),
			),
		),
	),

	'tab_js' => array(
		'type'    => 'tab',
		'title'   => __( 'JavaScript', 'fw' ),
		'options' => array(
			'js_box' => array(
				'title'   => __( 'JavaScript Files', 'fw' ),
				'type'    => 'box',
				'options' => array(
					'group_js' => array(
						'type'    => 'group',
						'options' => array(
							'js_intro' => array(
								'type'  => 'html',
								'label' => false,
								'desc'  => false,
								'html'  => $js_intro,
							),
							'js_defer' => array(
								'type'  => 'switch',
								'label' => __( 'Defer combined script', 'fw' ),
								'desc'  => __( 'Add the `defer` attribute to the combined bundle so it loads without blocking page render. Safe because the bundle is self-contained and dependency-ordered. Leave off if you notice timing issues with scripts left out of the bundle.', 'fw' ),
								'value' => false,
							),
							'js_minify' => array(
								'type'  => 'switch',
								'label' => __( 'Minify combined script', 'fw' ),
								'desc'  => __( 'Strip comments and redundant whitespace from the combined bundle. Conservative (string/template/regex-aware, preserves line breaks for safety). On by default; turn off only if you hit an issue with an already-minified third-party script you have combined.', 'fw' ),
								'value' => true,
							),
							'js_handles' => array(
								'type'    => 'checkboxes',
								'label'   => __( 'Scripts to combine', 'fw' ),
								'desc'    => __( 'Checked = merged into one footer file. Unchecked = left as a separate request. Unsafe scripts are skipped automatically.', 'fw' ),
								'choices' => $js_choices,
								'value'   => $js_defaults,
							),
						),
					),
				),
			),
		),
	),

	/** Filters options appended after the asset-optimizer settings tabs so extensions can add their own settings fields. */
	apply_filters( 'fw:ext:asset-optimizer:settings-options:after', array() ),
);
