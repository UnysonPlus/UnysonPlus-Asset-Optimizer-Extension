<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$ext = fw()->extensions->get( 'asset-optimizer' );

// Bust any stale object-cache read (WP Engine / Memcached / Redis).
wp_cache_delete( 'fw_ext_asset_optimizer_known_css_handles', 'options' );

$known = $ext ? $ext->get_known_css_handles() : array();

// If we have nothing remembered yet, do a one-shot internal request to the
// home page so the frontend hooks run and populate the list. The hook honors
// a query arg that disables page caches for that single request.
if ( $ext && empty( $known ) ) {
	$known = $ext->discover_css_handles();
}

ksort( $known );

$choices  = array();
$defaults = array();
foreach ( $known as $handle => $src ) {
	$label = $handle;
	if ( ! empty( $src ) ) {
		// Show only the short path (drop the host) so the row stays readable.
		$short = preg_replace( '#^https?://[^/]+#i', '', $src );
		$label .= '  —  ' . $short;
	}
	$choices[ $handle ]  = $label;
	$defaults[ $handle ] = true;
}

if ( empty( $choices ) ) {
	$intro_html = '<p>'
		. esc_html__( 'No stylesheets could be detected from a homepage fetch. This usually means the homepage is being served from a full-page cache (e.g. WP Engine). Open the site in a private window with the query arg ?fw_asset_optimizer_discover=1 to force a fresh render, then return here and refresh.', 'fw' )
		. '</p>';
} else {
	$intro_html = '<p>'
		. esc_html__( 'Every stylesheet detected on the frontend is listed below and checked by default. Uncheck any stylesheet you do NOT want merged into the combined file — those will keep loading on their own.', 'fw' )
		. '</p>'
		. '<p style="opacity:.75;">'
		. esc_html__( 'Tip: to re-scan the frontend (after activating a new plugin or theme), visit any page with ?fw_asset_optimizer_discover=1 appended to the URL.', 'fw' )
		. '</p>';
}

$options = array(
	apply_filters( 'fw:ext:asset-optimizer:settings-options:before', array() ),

	'css_box' => array(
		'title'   => __( 'CSS Files', 'fw' ),
		'type'    => 'box',
		'options' => array(
			'css_intro' => array(
				'type'  => 'html',
				'label' => false,
				'desc'  => false,
				'html'  => $intro_html,
			),
			'css_handles' => array(
				'type'    => 'checkboxes',
				'label'   => __( 'Stylesheets to combine', 'fw' ),
				'desc'    => __( 'Checked = merged into one file. Unchecked = left as a separate request.', 'fw' ),
				'choices' => $choices,
				'value'   => $defaults,
			),
		),
	),

	apply_filters( 'fw:ext:asset-optimizer:settings-options:after', array() ),
);
