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

// On a server that does not read .htaccess (nginx), say so up front and give the
// equivalent config, rather than offering a switch that would silently do nothing.
$fw_ao_static_cache_desc = '';
if ( class_exists( 'FW_Extension_Asset_Optimizer' ) && ! FW_Extension_Asset_Optimizer::server_reads_htaccess() ) {
	$fw_ao_static_cache_desc = '<strong>' . esc_html__( 'This server does not use .htaccess, so this switch cannot apply the rule itself.', 'fw' ) . '</strong> '
		. esc_html__( 'Add the equivalent to your server config instead:', 'fw' )
		. '<pre style="white-space:pre-wrap;margin:6px 0">' . esc_html( FW_Extension_Asset_Optimizer::static_cache_nginx_snippet() ) . '</pre>';
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
								'desc'    => __( 'Site-wide (default): one shared stylesheet built from every stylesheet discovered across the site, reused on every page. A visitor who looks at more than one page downloads it once and the browser serves the rest from cache, so only the first page pays for it. Per-page: each page combines ONLY the stylesheets it actually uses into its own file — smaller on any single page, in exact document order, and it scores better on the “unused CSS” audits because a one-page test never sees the saving a shared bundle is for. Prefer per-page when most visitors land on one page and leave. JavaScript is always combined per-page.', 'fw' ),
								'no-validate' => true,
								'choices' => array(
									'per_page' => __( 'Per-page (each page combines its own CSS)', 'fw' ),
									'site'     => __( 'Site-wide (one shared bundle — default)', 'fw' ),
								),
								'value'   => 'site',
							),
							'css_delivery' => array(
								'type'    => 'select',
								'label'   => __( 'CSS delivery', 'fw' ),
								'desc'    => __( 'Linked file (default): the combined CSS loads as a separate cached file, which the browser must download before it can paint. Inline: the combined CSS is printed in a style tag in the page head instead, so the first paint no longer waits on a second request (fixes the “render-blocking requests” insight). Best for small sites where most visitors land on one page. A bundle larger than 50 KB compressed always stays a linked file.', 'fw' ),
								'no-validate' => true,
								'choices' => array(
									'file'   => __( 'Linked file (default)', 'fw' ),
									'inline' => __( 'Inline in the page head — no render-blocking request', 'fw' ),
								),
								'value'   => 'file',
							),
							'purge_css' => array(
								'type'  => 'switch',
								'label' => __( 'Remove unused CSS', 'fw' ),
								'desc'  => __( 'Strip rules nothing on the page can use. A typical page uses under 15% of the CSS it downloads; on measured sites this halves the combined file. Each page gets its own purged copy, generated on its first view and cached afterwards. <strong>Off by default, and worth testing before you rely on it:</strong> a rule removed in error shows up as a wrong hover state or a broken menu rather than an error, so after switching it on, click through a few pages — open the menu, expand an accordion, hover the buttons. Anything that looks wrong can be protected with the safelist below. Requires <em>Combine CSS</em> on. Pairs well with <em>CSS delivery: Inline</em>: once the stylesheet has been trimmed it is usually small enough to put straight into the page, which removes the render-blocking request altogether.', 'fw' ),
								'value' => false,
							),
							'purge_safelist' => array(
								'type'  => 'textarea',
								'label' => __( 'Never remove (safelist)', 'fw' ),
								'desc'  => __( 'One entry per line: any selector containing the text is kept. Wrap in slashes for a regular expression, e.g. <code>/^\\.promo-/</code>. Common state classes (<code>is-</code>, <code>has-</code>, <code>active</code>, <code>open</code>…), all hover/focus rules, animation and slider classes, and the Animation Engine are already protected — add entries here only for class names your own code adds with JavaScript.', 'fw' ),
								'value' => '',
							),
							'critical_css' => array(
								'type'  => 'switch',
								'label' => __( 'Inline the first screen\'s CSS', 'fw' ),
								'desc'  => __( 'A linked stylesheet holds up the first paint: the browser will not draw anything until it has the whole file. Switching <em>CSS delivery</em> to <em>Inline</em> removes that wait, but only by putting the entire stylesheet inside every page, which costs more than it saves on a site people browse — and on a large stylesheet it is refused outright. This takes the middle path. The rules the first screen actually needs are put straight into the page, and the rest of the stylesheet is fetched afterwards without holding anything up, so it still gets cached and reused across pages. On a measured site the stylesheet was 338&nbsp;KB (91&nbsp;KB compressed) — far too big to inline whole, while the part the first screen needed was a small fraction of it. <strong>Off by default, and check it before relying on it:</strong> which rules the first screen needs is worked out from the order of the page\'s own markup, not by drawing the page, so a layout whose main content sits late in the source can flash unstyled for an instant before the rest arrives. Load a few pages and watch the top of the screen as they appear. Requires <em>CSS delivery: Linked file</em>, and works best with <em>Remove unused CSS</em> on.', 'fw' ),
								'value' => false,
							),
							'inline_small_css' => array(
								'type'  => 'switch',
								'label' => __( 'Inline small leftover stylesheets', 'fw' ),
								'desc'  => __( 'Combining cannot reach every stylesheet. Some elements load their styling only on the pages that use it — which is the right thing to do, and it leaves those files outside the bundle. Each one is then its own request that the browser waits for before drawing, and at a kilobyte apiece the waiting, not the downloading, is the whole cost. On a measured page four such files of about a kilobyte each were holding up the first paint by an estimated 380&nbsp;ms between them. This puts their contents straight into the page where the link was, so they cost nothing to fetch. Only small files are moved, with a limit on the total per page, and anything scoped to a particular medium such as print is left alone. Independent of <em>CSS delivery</em> — it tidies up whatever is left over after the rest has been bundled.', 'fw' ),
								'value' => false,
							),
							'js_source_map' => array(
								'type'  => 'switch',
								'label' => __( 'Record which script each line came from', 'fw' ),
								'desc'  => __( 'Combining JavaScript into one file makes performance reports hard to act on: a browser that blames a slow function reports a line number in the <em>combined</em> file, which belongs to no script anyone wrote, so there is no way to tell whose code it is. Switching this on writes a small companion file next to the bundle listing which script occupies which lines, so a reported line can be traced back to the file it came from. <strong>For diagnosis, not for everyday use:</strong> it changes how the bundle is assembled (each script is shrunk on its own rather than all together), so leave it off unless you are chasing something. The companion file is public, and names script paths relative to your WordPress folder.', 'fw' ),
								'value' => false,
							),
							'drop_admin_assets' => array(
								'type'  => 'switch',
								'label' => __( 'Drop admin-only stylesheets', 'fw' ),
								'desc'  => __( 'WordPress loads the icon font it uses for its own dashboard — <code>dashicons</code>, about 36&nbsp;KB — on the public site as well, where not one of its icons is ever drawn. It is a render-blocking request that every visitor waits for and none of them benefits from. The same goes for the editor and page-builder panel styles, which only exist inside wp-admin. Switching this on stops them being requested on the front end. It is left alone while the admin toolbar is on screen, because the toolbar genuinely uses those icons, so what you see while logged in is unchanged — log out, or use a private window, to see the difference. Independent of <em>Combine CSS</em>: those files were already kept out of the combined stylesheet, but keeping them out of the bundle still left the browser fetching them separately, which is the request this removes.', 'fw' ),
								'value' => false,
							),
							'hoist_late_styles' => array(
								'type'  => 'switch',
								'label' => __( 'Move late CSS into the head', 'fw' ),
								'desc'  => __( 'Some elements write their own CSS while the page is being built, and it ends up at the bottom of the page &mdash; after the browser has already drawn them. Rules that decide how wide an element is, or whether it is centred, then arrive too late, and the element visibly jumps into place. On a measured page the hero was drawn against the left edge and then jumped 360 pixels across when its CSS finally loaded; that single jump accounted for the entire layout-shift score on that page. Switching this on collects those blocks and puts them in the page head, where they belong, so everything is drawn in its final position the first time. The page looks exactly the same once loaded &mdash; it just stops moving while it gets there.', 'fw' ),
								'value' => false,
							),
							'preload_lcp_image' => array(
								'type'  => 'switch',
								'label' => __( 'Preload the hero image', 'fw' ),
								'desc'  => __( 'Tell the browser about the page\'s main image straight away, instead of leaving it to find it after the stylesheet has downloaded and the layout is built. This does not make anything smaller — it changes the <em>order</em> things are fetched, which is usually where a slow "largest contentful paint" actually comes from. The hero is taken to be the first reasonably-sized image after your header, skipping logos and icons. Lazy images are deliberately <em>included</em> in that search, because the page builder marks every image lazy &mdash; including the one in your hero, which is exactly the element the score is measured on. Its <code>loading="lazy"</code> is removed. If no suitable image is found, nothing is added.', 'fw' ),
								'value' => false,
							),
							'webp_quality' => array(
								'type'       => 'slider',
								'label'      => __( 'WebP quality', 'fw' ),
								'desc'       => __( 'How hard to compress the WebP copies made for <em>Serve WebP images</em>. This is a separate control from <em>Image quality</em> above, because WebP and JPEG are not comparable at the same number — WebP holds up better, so it can be set lower for the same apparent result. <strong>80 is the default and is a safe place to stay</strong>; around 70 is noticeably smaller and still hard to fault on a photograph; below about 65 the difference starts to show on skies and smooth gradients, so look at an image you care about before keeping it. Changing this re-makes the copies the next time each image is shown, so the saving appears gradually rather than all at once. Only applies where the WebP copy actually comes out smaller than the original — if it does not, the original is kept.', 'fw' ),
								'value'      => 80,
								'properties' => array(
									'min'  => 40,
									'max'  => 100,
									'step' => 1,
								),
							),
							'image_quality' => array(
								'type'       => 'slider',
								'label'      => __( 'Image quality', 'fw' ),
								'desc'       => __( 'How hard to compress the cropped images the theme generates. Lower means smaller files. <strong>82 matches the WordPress default</strong> and is a safe place to stay; 80 is a little smaller again and hard to tell apart; below about 75 the difference starts to show on photographs, so check one you care about before keeping it. Changing this re-makes each crop the next time it is shown, so the saving appears gradually rather than all at once. PNG images are unaffected — PNG is lossless, so there is no quality to trade.', 'fw' ),
								'value'      => 82,
								'properties' => array(
									'min'  => 40,
									'max'  => 100,
									'step' => 1,
								),
							),
							'generate_image_sizes' => array(
								'type'  => 'switch',
								'label' => __( 'Generate missing image sizes', 'fw' ),
								'desc'  => __( 'Make the in-between sizes for images that do not have them, so the browser can pick a file that suits the space it is filling rather than always taking the full-size original. Images imported by the Site Converter are the usual case: it generates only two sizes on purpose, because resizing is the slowest part of a conversion. On a measured page the result was a 1024-pixel-wide slot downloading the 1440-wide original at 91.9 KB; with the missing sizes in place the browser took the 1024-wide file at 67.7 KB instead &mdash; <strong>26% less for exactly the same picture</strong>. The work is done a little at a time as pages are viewed (one image per view), so nothing stalls, and a busy page catches up over its next few visits. An image that cannot be processed is marked and not retried.', 'fw' ),
								'value' => false,
							),
							'webp_images' => array(
								'type'  => 'switch',
								'label' => __( 'Serve WebP images', 'fw' ),
								'desc'  => __( 'Make a WebP copy of every uploaded JPG / PNG (and each of its sizes and crops) and show visitors the WebP instead: usually 25–80% smaller for the same look. The originals are kept untouched, so turning this off simply goes back to them. Existing images are converted gradually as pages are viewed.', 'fw' ),
								'value' => false,
							),
							'static_cache_headers' => array(
								'type'  => 'switch',
								'label' => __( 'Browser cache for static files', 'fw' ),
								'desc'  => $fw_ao_static_cache_desc . __( 'Ask browsers to keep images, fonts, scripts and stylesheets for a year instead of re-checking them on every visit. This only affects <strong>repeat</strong> visits — a first-time visitor downloads everything either way — so treat it as a small, free win rather than a fix for a slow page. The generated files this extension makes are already cached this way; switching this on extends the same policy to your uploads, plugins and themes by adding a block to <code>wp-content/.htaccess</code> (anything already in that file is left alone, and turning this off removes only the block it added). Plugin and theme files carry a version in their URL that changes when they update, and WordPress never reuses an upload filename, so a normal update cannot leave anyone looking at a stale file. The one case to know about: if you replace a file in place — over FTP, or with a "replace media" plugin — people who already have it keep the old one until a hard refresh. Needs Apache or LiteSpeed; on nginx the rule belongs in your server config instead.', 'fw' ),
								'value' => false,
							),
							'warm_images' => array(
								'type'  => 'switch',
								'label' => __( 'Load below-the-fold images early', 'fw' ),
								'desc'  => __( 'Fetch the remaining images on the page once it has finished loading, instead of waiting for the reader to scroll to each one. A lazy image is not requested until the reader comes near it, so on a long page the picture drops into an empty slot a beat after the text — which reads as a slow site even when the file is tiny. On a measured page the first content image was not even <em>requested</em> until 13.9 seconds in, yet took 175&nbsp;ms to arrive: the wait was never the download, it was that nothing had asked for it yet. This fills that gap — after the page loads, during an idle moment, the remaining images are fetched one at a time so they are already in cache by the time anyone scrolls. It is deliberately cautious: Save-Data and 2G visitors are skipped entirely, only one image is in flight at a time so it never competes with anything you are waiting for, and it stops after 24 images. <strong>Worth knowing before you switch it on:</strong> this spends bandwidth on images a visitor may never scroll to, and it will usually make your PageSpeed score slightly <em>worse</em>, not better. The warmer starts after the page’s load event whether or not anyone scrolls, so an audit does download those images inside its measurement window even though Lighthouse never scrolls — on a measured site it turned a 0.7&nbsp;MB phone page load into about 2&nbsp;MB. Turn it on for readers on a long, image-heavy page, not for the number, and leave it off on a page whose images are large.', 'fw' ),
								'value' => false,
							),
							'speculative_mode' => array(
								'type'    => 'select',
								'label'   => __( 'Instant navigation', 'fw' ),
								'desc'    => __( 'Load the next page <em>before</em> it is clicked. Hover or begin a click on an internal link and the browser quietly fetches that page in the background, so the click lands on something already in memory — the trick that makes a React site feel instant, applied to a normal WordPress site. <strong>Prefetch</strong> fetches the HTML only; nothing on the page runs, so it is safe on any site and is the right first choice. <strong>Prerender</strong> goes further and renders the page completely in a hidden tab, which can make a click feel like it took no time at all — but the page really does run, so its scripts execute, its scroll animations may start and finish unseen, and analytics can count a visit nobody made. Test prerender before relying on it, especially with scroll-triggered motion. <strong>Auto</strong> leaves the decision to WordPress, which currently prefetches conservatively. Only Chromium browsers act on this today; Safari and Firefox simply navigate as they always did, so there is no downside to leaving it on. WordPress switches the whole feature off for logged-in users and for sites without pretty permalinks, so you will not see it while editing — check it in a logged-out window.', 'fw' ),
								'no-validate' => true,
								'choices' => array(
									'auto'      => __( 'Auto — leave it to WordPress (default)', 'fw' ),
									'prefetch'  => __( 'Prefetch — fetch the next page early (safe)', 'fw' ),
									'prerender' => __( 'Prerender — render it fully in advance (fastest; test first)', 'fw' ),
									'off'       => __( 'Off — never speculate', 'fw' ),
								),
								'value'   => 'auto',
							),
							'speculative_eagerness' => array(
								'type'    => 'select',
								'label'   => __( 'How early to start', 'fw' ),
								'desc'    => __( 'How keen the browser should be. <strong>Conservative</strong> waits until the mouse goes down on a link — the click is already happening, so almost nothing is wasted, and it still removes most of the wait. <strong>Moderate</strong> starts after the pointer rests on a link for about 200ms, which usually means the page is ready by the time the click registers. <strong>Eager</strong> starts as soon as a link looks likely, which is the fastest and also the most wasteful: every page fetched and not visited is bandwidth your host paid for, and with <em>Prerender</em> it is a full page render too. Start conservative, move to moderate if you want more, and keep eager for small sites with few outgoing links. The browser caps how many speculations it runs at once regardless of what you ask for.', 'fw' ),
								'no-validate' => true,
								'choices' => array(
									'auto'         => __( 'Auto — leave it to WordPress (default)', 'fw' ),
									'conservative' => __( 'Conservative — on mouse-down', 'fw' ),
									'moderate'     => __( 'Moderate — after a short hover', 'fw' ),
									'eager'        => __( 'Eager — as soon as a link looks likely', 'fw' ),
								),
								'value'   => 'auto',
							),
							'speculative_skip_sensitive' => array(
								'type'  => 'switch',
								'label' => __( 'Never speculate cart, checkout or account pages', 'fw' ),
								'desc'  => __( 'Keep shop and account pages out of it. WordPress already skips every link carrying a query string, which covers add-to-cart and logout links, but a cart or checkout page reached by a normal permalink is not covered and WooCommerce does not exclude it either. Fetching one starts a shopping session for a visitor who never went there; prerendering one also runs its scripts, which can fire a begin-checkout event or a stray page view. The pages are found from WooCommerce and Easy Digital Downloads directly rather than guessed from their slugs, so a renamed or translated checkout is still covered. Leave this on unless you have a specific reason not to — those pages are the ones nobody wants cached anyway.', 'fw' ),
								'value' => true,
							),
							'speculative_exclude' => array(
								'type'  => 'textarea',
								'label' => __( 'Never speculate these paths', 'fw' ),
								'desc'  => __( 'One path per line, each starting with a slash; <code>*</code> is a wildcard, and a pasted full URL is reduced to its path. Examples: <code>/cart/</code>, <code>/members/*</code>, <code>/downloads/*</code>. Use this for anything that <em>does</em> something when it is merely loaded — a download link, a one-click unsubscribe, a link that marks something read. WordPress already excludes wp-admin, <code>wp-*.php</code>, your uploads and theme folders, and every URL with a query string, so those need no entry. A single link can also opt out on its own by carrying <code>rel="nofollow"</code> or a <code>no-prefetch</code> / <code>no-prerender</code> class.', 'fw' ),
								'value' => '',
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
