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

// ---- CSS choices (all checked by default) ----
$css_choices  = array();
$css_defaults = array();
foreach ( $known_css as $handle => $src ) {
	$label = $handle;
	if ( ! empty( $src ) ) {
		$short  = preg_replace( '#^https?://[^/]+#i', '', $src );
		$label .= '  —  ' . $short;
	}
	$css_choices[ $handle ]  = $label;
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
	$js_choices[ $handle ] = $label;
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
	apply_filters( 'fw:ext:asset-optimizer:settings-options:before', array() ),

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
								'desc'  => __( 'Strip comments and redundant whitespace from the combined bundle. Conservative (string/template/regex-aware, preserves line breaks for safety). Most scripts are already minified, so the extra saving is usually small. Experimental — leave off unless you want it.', 'fw' ),
								'value' => false,
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

	apply_filters( 'fw:ext:asset-optimizer:settings-options:after', array() ),
);
