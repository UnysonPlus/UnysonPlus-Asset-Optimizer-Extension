<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

// Standalone, dependency-free CSS/JS minifiers (extracted for testability).
require_once __DIR__ . '/includes/class-fw-ao-minifier.php';
require_once __DIR__ . '/includes/class-fw-ao-webp.php';
require_once __DIR__ . '/includes/class-fw-ao-purger.php';

class FW_Extension_Asset_Optimizer extends FW_Extension {

	const KNOWN_CSS_HANDLES_OPTION = 'fw_ext_asset_optimizer_known_css_handles';
	const KNOWN_JS_HANDLES_OPTION  = 'fw_ext_asset_optimizer_known_js_handles';
	const CSS_EXCLUDED_OPTION      = 'fw_ext_asset_optimizer_css_excluded';
	const COMBINED_CSS_HANDLE      = 'unysonplus-asset-optimizer-css';
	const COMBINED_JS_HANDLE       = 'unysonplus-asset-optimizer-js';
	const CACHE_SUBDIR             = 'unysonplus/asset-optimizer';
	const DISCOVERY_QUERY_ARG      = 'fw_asset_optimizer_discover';
	const NOPURGE_QUERY_ARG        = 'fw_ao_nopurge';
	const DISCOVERY_TOKEN_PREFIX   = 'fw_ao_discover_';
	const MIGRATION_OPTION         = 'fw_ext_asset_optimizer_migrated_v1';
	const AUTOLOAD_FIX_OPTION      = 'fw_ext_asset_optimizer_autoload_v2';
	const ACTION_CLEAR_CACHE       = 'fw_asset_optimizer_clear_cache';
	const ACTION_RESCAN            = 'fw_asset_optimizer_rescan';
	const NONCE_ACTION             = 'fw_asset_optimizer_maintenance';

	// Dedicated settings page under the Unyson+ menu.
	const PARENT_SLUG    = 'fw-extensions';
	const PAGE_SLUG      = 'fw-asset-optimizer';
	const CAPABILITY     = 'manage_options';
	const SETTINGS_NONCE = 'fw_ext_asset_optimizer_save';

	/** @var string|null Hook suffix returned by add_submenu_page() for the settings page. */
	private $settings_hook_suffix = null;

	/**
	 * Handles that have been combined into the combined CSS file on this request.
	 * The style_loader_tag filter uses this to suppress any <link> the rest
	 * of WordPress tries to print for them (including late re-enqueues from
	 * shortcode rendering).
	 *
	 * @var array<string, true>
	 */
	private $absorbed_css_handles = array();

	/**
	 * Script handles folded into the combined JS file on this request. The
	 * script_loader_tag filter uses this to blank each original <script src>
	 * tag so its code isn't loaded twice.
	 *
	 * @var array<string, true>
	 */
	private $absorbed_js_handles = array();

	/**
	 * Whether to add `defer` to the combined JS bundle's <script> tag this
	 * request (set from the opt-in setting when the bundle is enqueued).
	 *
	 * @var bool
	 */
	private $defer_combined_js = false;

	/**
	 * @internal
	 */
	public function _init() {
		$this->maybe_migrate();
		$this->maybe_fix_autoload();

		// Maintenance hooks (admin / cron context). Auto-purge the cache when the
		// active asset set can change, plus the settings-page action buttons.
		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'activated_plugin', array( $this, 'purge_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'purge_all' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge_all' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_CACHE, array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_post_' . self::ACTION_RESCAN, array( $this, 'handle_rescan' ) );

		if ( is_admin() ) {
			// Dedicated settings page under the Unyson+ menu. Registered before the
			// early return below, which only skips the frontend combining hooks.
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
			add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );

			// ONE settings URL, not two. The Extensions manager hands every
			// extension that ships settings-options.php a generic settings screen
			// at fw-extensions&sub-page=extension&extension=<name>. We also
			// register our own page, which renders the SAME options plus the tab
			// strip, cache stats and the Clear cache / Re-scan actions - so the
			// generic one was a second, poorer door to the same room, and any
			// settings-page work had to be done twice to keep them in step.
			// Redirecting (rather than suppressing) keeps old bookmarks and the
			// manager's own "Settings" link working, and lands them on the page
			// that actually has the controls.
			add_action( 'admin_init', array( $this, '_redirect_manager_settings_page' ) );

			// When the Extensions-manager settings form saves our options, recompute
			// the persisted CSS-exclusion list (the dedicated settings page does the
			// same in _maybe_save_settings). Both entry points write to the same store,
			// so both must keep the derived exclusion list in sync.
			add_action( 'fw_extension_settings_form_saved:' . $this->get_name(), array( $this, '_after_manager_settings_saved' ) );
		}

		// Serve WebP images (opt-in). Registered in every context: copies are made on
		// upload (admin / REST) and swapped in on the front end.
		if ( ! empty( $this->general_setting( 'webp_images', false ) ) ) {
			FW_AO_Webp::init();
		}

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Priority 99999 (PHP_INT_MAX-ish, well after the usual 9999 orderers):
		// the active theme can re-order stylesheets via dependencies at
		// wp_enqueue_scripts:9999 (UnysonPlus' parent theme does exactly this to
		// build its parent-style -> presets -> hf-custom -> child cascade). We
		// MUST run after that so our dependency-resolved capture reflects the
		// theme's intended order; otherwise the combined file bakes in the wrong
		// cascade and breaks layouts (e.g. the header).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_combined_css' ), 99999 );

		// Combine eligible footer scripts. Same late priority so registrations,
		// dependencies and any theme ordering are all in place; works off the
		// live, dependency-resolved script list because JS execution order is
		// significant.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_combined_js' ), 99999 );

		// Unused-CSS purging (opt-in). This has to run on the FINISHED page, not
		// at enqueue time: the combined file is built at wp_enqueue_scripts:99999,
		// long before the body renders, so at that point there is no DOM to scan.
		// So we buffer the whole response, scan the rendered HTML for the classes
		// and ids it actually contains, write a purged copy of the bundle keyed by
		// that fingerprint, and rewrite the <link> href in the buffered HTML to
		// point at it. First view of a given page shape generates the file; every
		// later view is a cache hit and does no CSS work at all.
		// One buffer, several passes. Purging and the LCP preload both need the
		// finished HTML, so they share a single ob_start rather than nesting two.
		if ( $this->purge_enabled() || $this->preload_lcp_enabled() ) {
			add_action( 'template_redirect', array( $this, 'start_purge_buffer' ), 1 );
		}

		// Final safety net: at shutdown, remember every handle that was enqueued
		// or printed during this request - including handles enqueued late by
		// shortcodes during content rendering.
		add_action( 'shutdown', array( $this, 'remember_all_seen_css_handles' ), 0 );
		add_action( 'shutdown', array( $this, 'remember_all_seen_js_handles' ), 0 );

		// Suppress any <link> tag for a handle we've absorbed into the combined
		// file - regardless of whether it was enqueued before or after our hook,
		// printed in head or footer, or re-enqueued during shortcode rendering.
		add_filter( 'style_loader_tag', array( $this, 'suppress_absorbed_css_tag' ), 0, 2 );

		// Blank the <script src> tag for any handle folded into the combined JS.
		add_filter( 'script_loader_tag', array( $this, 'suppress_absorbed_js_tag' ), 0, 2 );

		// Force a fresh render for the internal discovery crawl - but ONLY for a
		// request carrying a valid one-time token (set by discover_handles()).
		// Without this gate any anonymous visitor could append the query arg to
		// bypass full-page caching on every request (a cheap cache-buster / DoS
		// amplifier). The loopback crawl has no login session, so the token - not
		// a capability check - is what authorises it.
		if ( isset( $_GET[ self::DISCOVERY_QUERY_ARG ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET[ self::DISCOVERY_QUERY_ARG ] ) );
			if ( $token !== '' && get_transient( self::DISCOVERY_TOKEN_PREFIX . $token ) ) {
				delete_transient( self::DISCOVERY_TOKEN_PREFIX . $token ); // single use
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true );
				}
				nocache_headers();
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Dedicated settings page (Unyson+ → Asset Optimizer)
	 *
	 * The extension already exposes its settings through the Extensions-manager
	 * settings form (the "Settings" link on its card). This adds a first-class
	 * menu item that renders the SAME settings-options.php options and saves to
	 * the SAME store, so both entry points stay in sync.
	 * ------------------------------------------------------------------- */

	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 * Slot this page right after "Component Presets" in the shared Unyson+
	 * submenu order (the Post Types extension owns the actual sort).
	 *
	 * @param string[] $order
	 * @return string[]
	 */
	public function _filter_submenu_order( $order ) {
		if ( ! is_array( $order ) || in_array( self::PAGE_SLUG, $order, true ) ) {
			return $order;
		}
		$pos = array_search( 'fw-component-presets', $order, true );
		if ( false === $pos ) {
			$order[] = self::PAGE_SLUG; // unknown anchor: append at the end
		} else {
			array_splice( $order, $pos + 1, 0, self::PAGE_SLUG );
		}
		return $order;
	}

	/**
	 * @internal
	 */
	/**
	 * @internal
	 * Send the Extensions-manager's generic settings screen for THIS extension to
	 * our own settings page, so there is exactly one Asset Optimizer settings URL.
	 *
	 * Only the settings view is redirected: the manager's other sub-pages for this
	 * extension (docs, activate/deactivate, install) are left alone, and so is
	 * every other extension's settings screen.
	 */
	public function _redirect_manager_settings_page() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! isset( $_GET['page'], $_GET['sub-page'], $_GET['extension'] ) ) {
			return;
		}
		if ( 'fw-extensions' !== $_GET['page'] || 'extension' !== $_GET['sub-page'] ) {
			return;
		}
		if ( $this->get_name() !== $_GET['extension'] ) {
			return;
		}
		// The manager's per-extension view has a `docs` tab alongside `settings`;
		// only the settings one duplicates our page.
		$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
		if ( 'settings' !== $tab ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return; // let the manager render its own permission error
		}

		wp_safe_redirect( self::get_page_url(), 302 );
		exit;
	}

	public function _action_admin_menu() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->settings_hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Asset Optimizer Settings', 'fw' ), // page title (browser <title>)
			__( 'Asset Optimizer', 'fw' ),          // menu label
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $this->settings_hook_suffix ) {
			// Save before any output so we can PRG-redirect.
			add_action( 'load-' . $this->settings_hook_suffix, array( $this, '_maybe_save_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, '_enqueue_settings_static' ) );
		}
	}

	/**
	 * @internal
	 * Enqueue the Unyson option-editor assets (every option type's JS/CSS, plus the
	 * postbox toggle handling). The page itself uses native WordPress nav-tabs, so
	 * no extra tab stylesheet is needed.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function _enqueue_settings_static( $hook ) {
		if ( $hook !== $this->settings_hook_suffix ) {
			return;
		}

		fw()->backend->enqueue_options_static( $this->get_settings_options() );
	}

	/**
	 * @internal
	 * Save handler — runs on the page's `load-` hook, before any output.
	 * Mirrors the Extensions-manager settings-form save (merge over existing
	 * values, then write the whole store back).
	 */
	public function _maybe_save_settings() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::SETTINGS_NONCE );

		$before = (array) fw_get_db_ext_settings_option( $this->get_name() );
		$values = array_merge(
			$before,
			fw_get_options_values_from_input( $this->get_settings_options() )
		);

		fw_set_db_ext_settings_option( $this->get_name(), null, $values );

		// Persist which CSS handles are excluded (see recompute_css_exclusions).
		$this->recompute_css_exclusions( $values );

		wp_safe_redirect( add_query_arg( 'fw-saved', '1', self::get_page_url() ) );
		exit;
	}

	/**
	 * @internal
	 * Recompute the CSS-exclusion list after the Extensions-manager settings
	 * form saves our options (the dedicated page handles its own save above).
	 *
	 * @param array $options_before_save Unused; passed by the Unyson hook.
	 */
	public function _after_manager_settings_saved( $options_before_save = array() ) {
		$this->recompute_css_exclusions();
	}

	/**
	 * @internal
	 * Render the standalone settings page.
	 *
	 * Uses NATIVE WordPress nav-tabs + one metabox-holder `box` postbox per tab —
	 * identical to the Convert / Post Types / Custom Fields pages — rather than the
	 * option framework's own jQuery-UI tab chrome (which rendered an out-of-place
	 * double border). settings-options.php nests each tab's fields in a box → group,
	 * so we just split off the top-level `tab` entries and render each tab's inner
	 * options (the box) inside its own panel.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$schema = $this->get_settings_options();
		$values = (array) fw_get_db_ext_settings_option( $this->get_name() );

		// Show each CSS checkbox as checked unless the handle is on the persisted
		// exclusion list, so the panel mirrors what actually combines (the saved
		// `css_handles` map alone can't represent a handle discovered since the
		// last save). Null = legacy/never-saved: leave the saved map as-is.
		$css_display = $this->get_css_display_values( array_keys( $this->get_known_css_handles() ) );
		if ( null !== $css_display ) {
			$values['css_handles'] = $css_display;
		}

		// Collect the top-level `tab` entries (skip the apply_filters before/after
		// placeholder arrays).
		$tabs = array();
		foreach ( (array) $schema as $key => $entry ) {
			if ( is_array( $entry ) && isset( $entry['type'] ) && 'tab' === $entry['type'] ) {
				$tabs[ $key ] = $entry;
			}
		}
		?>
		<div class="wrap fw-ext-asset-optimizer">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Asset Optimizer Settings', 'fw' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Combine enqueued frontend CSS and JavaScript into single minified, cached files to cut HTTP requests and payload size. Every detected asset is listed on its tab — tick the ones to merge.', 'fw' ); ?>
			</p>

			<?php if ( isset( $_GET['fw-saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'fw' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['fw-ao-notice'] ) && 'cleared' === $_GET['fw-ao-notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Combined file cache cleared.', 'fw' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['fw-ao-notice'] ) && 'rescanned' === $_GET['fw-ao-notice'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Frontend re-scanned — the asset lists below are now up to date.', 'fw' ); ?></p>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper fw-ao-tabs" style="margin:.4em 0 1.4em">
				<?php $first = true; foreach ( $tabs as $tab_id => $tab ) : ?>
					<a href="#<?php echo esc_attr( $tab_id ); ?>"
					   class="nav-tab<?php echo $first ? ' nav-tab-active' : ''; ?>"
					   data-tab="<?php echo esc_attr( $tab_id ); ?>"><?php echo esc_html( isset( $tab['title'] ) ? $tab['title'] : $tab_id ); ?></a>
				<?php $first = false; endforeach; ?>
			</h2>

			<form method="post" action="">
				<?php wp_nonce_field( self::SETTINGS_NONCE ); ?>
				<?php $first = true; foreach ( $tabs as $tab_id => $tab ) :
					$inner = ( isset( $tab['options'] ) && is_array( $tab['options'] ) ) ? $tab['options'] : array();
					?>
					<div class="fw-ao-panel<?php echo $first ? ' is-active' : ''; ?>" id="panel-<?php echo esc_attr( $tab_id ); ?>">
						<?php echo fw()->backend->render_options( $inner, $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				<?php $first = false; endforeach; ?>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
				</p>
			</form>

			<?php
			$stats   = $this->get_cache_stats();
			$kb      = $stats['bytes'] > 0 ? size_format( $stats['bytes'], 1 ) : '0 KB';
			?>
			<div class="fw-ao-maintenance" style="margin-top:1.5em;padding-top:1em;border-top:1px solid #dcdcde">
				<p style="margin:0 0 .6em">
					<strong><?php esc_html_e( 'Cache', 'fw' ); ?>:</strong>
					<?php
					printf(
						/* translators: 1: number of cached files, 2: total size */
						esc_html__( '%1$s combined file(s) · %2$s', 'fw' ),
						esc_html( number_format_i18n( $stats['count'] ) ),
						esc_html( $kb )
					);
					?>
				</p>
				<p style="margin:0">
					<a class="button" href="<?php echo esc_url( self::get_clear_cache_url() ); ?>"><?php esc_html_e( 'Clear cache', 'fw' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::get_rescan_url() ); ?>"><?php esc_html_e( 'Re-scan frontend', 'fw' ); ?></a>
				</p>
				<p class="description" style="margin:.5em 0 0">
					<?php esc_html_e( 'Clear cache deletes the generated combined files (they rebuild on the next visit). Re-scan forgets the detected asset lists and fetches the homepage to rebuild them — useful after activating or removing a plugin or theme.', 'fw' ); ?>
				</p>
			</div>
		</div>

		<style>
		/* Harden our tab strip against themes/plugins that globally restyle the shared
		   `.nav-tab-wrapper` class in wp-admin (e.g. a Blockskit theme's theme-info.css
		   centres it on a tinted flex bar). Scoped high enough to outrank a `.wrap h2.nav-tab-wrapper`
		   rule, so our tabs stay left-aligned with the native underline regardless of the active theme. */
		.fw-ext-asset-optimizer .fw-ao-tabs.nav-tab-wrapper{justify-content:flex-start;background:none;border-bottom:1px solid #c3c4c7}
		.fw-ext-asset-optimizer .nav-tab{border-radius:.25rem .25rem 0 0}
		.fw-ext-asset-optimizer .fw-ao-panel{display:none}
		.fw-ext-asset-optimizer .fw-ao-panel.is-active{display:block}
		.fw-ext-asset-optimizer .fw-ao-url{color:#a7aaad;transition:color .12s ease}
		.fw-ext-asset-optimizer label:hover .fw-ao-url{color:#50575e}

		/* Grouped handle list. The toolbar sticks because with 250 handles the
		   check-all / filter controls would otherwise scroll out of reach exactly
		   when you need them. */
		.fw-ext-asset-optimizer .fw-ao-groupbar{position:sticky;top:32px;z-index:5;display:flex;flex-wrap:wrap;gap:8px;align-items:center;
			padding:8px 10px;margin:0 0 10px;background:#fff;border:1px solid #dcdcde;border-radius:4px}
		.fw-ext-asset-optimizer .fw-ao-filter{min-width:240px;flex:1 1 240px}
		.fw-ext-asset-optimizer .fw-ao-onlyunchecked{display:inline-flex;align-items:center;gap:4px;white-space:nowrap;color:#50575e}
		.fw-ext-asset-optimizer .fw-ao-total{margin-left:auto;color:#646970;font-variant-numeric:tabular-nums;white-space:nowrap}

		.fw-ext-asset-optimizer .fw-ao-group{margin:0 0 6px;border:1px solid #dcdcde;border-radius:4px;background:#fff}
		.fw-ext-asset-optimizer .fw-ao-group>summary{display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;
			font-weight:600;color:#1d2327;list-style:none;user-select:none}
		.fw-ext-asset-optimizer .fw-ao-group>summary::-webkit-details-marker{display:none}
		/* Own caret, so it can sit after the checkbox rather than before it. */
		.fw-ext-asset-optimizer .fw-ao-group>summary::after{content:"";margin-left:4px;width:0;height:0;
			border-left:4px solid transparent;border-right:4px solid transparent;border-top:5px solid #787c82;transition:transform .12s ease}
		.fw-ext-asset-optimizer .fw-ao-group[open]>summary::after{transform:rotate(180deg)}
		.fw-ext-asset-optimizer .fw-ao-group>summary:hover{background:#f6f7f7}
		.fw-ext-asset-optimizer .fw-ao-gname{flex:0 1 auto}
		.fw-ext-asset-optimizer .fw-ao-gcount{margin-left:auto;font-weight:400;color:#646970;font-variant-numeric:tabular-nums}
		/* A fully excluded group is de-emphasised, so a glance down the collapsed
		   list shows what is NOT being combined without opening anything. */
		.fw-ext-asset-optimizer .fw-ao-group--none>summary .fw-ao-gname{color:#8c8f94;font-weight:400}
		.fw-ext-asset-optimizer .fw-ao-gbody{padding:4px 10px 10px 32px;border-top:1px solid #f0f0f1}
		.fw-ext-asset-optimizer .fw-ao-gbody>div{padding:1px 0}
		</style>
		<script>
		( function () {
			var wrap = document.querySelector( '.fw-ext-asset-optimizer' );
			if ( ! wrap ) { return; }
			function activate( tab ) {
				wrap.querySelectorAll( '.fw-ao-tabs .nav-tab' ).forEach( function ( a ) {
					a.classList.toggle( 'nav-tab-active', a.getAttribute( 'data-tab' ) === tab );
				} );
				wrap.querySelectorAll( '.fw-ao-panel' ).forEach( function ( p ) {
					p.classList.toggle( 'is-active', p.id === 'panel-' + tab );
				} );
			}
			wrap.querySelectorAll( '.fw-ao-tabs .nav-tab' ).forEach( function ( a ) {
				a.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					activate( a.getAttribute( 'data-tab' ) );
				} );
			} );

			// Gray out the " — /path/to/file.css" portion of each handle label
			// (it's a plain text node, so wrap it in a span we can style). Hover
			// over the row brings it back to normal for readability.
			wrap.querySelectorAll( '.fw-ao-panel label' ).forEach( function ( label ) {
				Array.prototype.forEach.call( Array.prototype.slice.call( label.childNodes ), function ( node ) {
					if ( node.nodeType !== 3 ) { return; }
					var idx = node.nodeValue.indexOf( '—' ); // em dash
					if ( idx === -1 ) { return; }
					var span = document.createElement( 'span' );
					span.className = 'fw-ao-url';
					span.textContent = node.nodeValue.slice( idx );
					label.replaceChild( span, node );
					label.insertBefore( document.createTextNode( node.nodeValue.slice( 0, idx ) ), span );
				} );
			} );

			/* ----------------------------------------------------------------
			 * Group the handle checkboxes.
			 *
			 * A real site registers 250+ stylesheets. As one flat list that is
			 * unusable: you cannot find a handle, you cannot tell framework CSS
			 * from a third-party plugin's, and there is no way to act on a whole
			 * category at once. So the rows are folded into collapsible groups
			 * (the group comes from data-ao-group, set server-side from each
			 * asset's PATH) with a tri-state parent checkbox, per-group counts,
			 * a filter box and check-all / uncheck-all.
			 *
			 * Rows are MOVED, never re-sorted: the server emits them in the
			 * combined file's cascade order, and that order is information.
			 * Groups appear in the order their first handle appears, so the
			 * page still reads top-to-bottom as the cascade does.
			 *
			 * This is progressive enhancement over the stock `checkboxes`
			 * option type - the inputs, their names and the stored value are
			 * untouched, so with JS off the list simply renders flat as before.
			 * -------------------------------------------------------------- */
			wrap.querySelectorAll( '.fw-option-type-checkboxes' ).forEach( function ( box ) {
				var rows = Array.prototype.slice.call(
					box.querySelectorAll( 'input[type=checkbox][data-ao-group]' )
				).map( function ( input ) {
					return { input: input, row: input.closest( 'div' ) };
				} ).filter( function ( r ) { return r.row && r.row.parentNode === box; } );

				if ( rows.length < 8 ) { return; } // short list reads fine as-is

				// --- toolbar -------------------------------------------------
				var bar = document.createElement( 'div' );
				bar.className = 'fw-ao-groupbar';
				bar.innerHTML =
					'<input type="search" class="fw-ao-filter" placeholder="<?php echo esc_js( __( 'Filter handles or paths…', 'fw' ) ); ?>">' +
					'<button type="button" class="button fw-ao-all"><?php echo esc_js( __( 'Check all', 'fw' ) ); ?></button>' +
					'<button type="button" class="button fw-ao-none"><?php echo esc_js( __( 'Uncheck all', 'fw' ) ); ?></button>' +
					'<label class="fw-ao-onlyunchecked"><input type="checkbox"> <?php echo esc_js( __( 'Only unchecked', 'fw' ) ); ?></label>' +
					'<span class="fw-ao-total"></span>';
				box.parentNode.insertBefore( bar, box );

				// --- partition into groups, first-seen order ------------------
				var order = [], groups = {};
				rows.forEach( function ( r ) {
					var key = r.input.getAttribute( 'data-ao-group' ) || 'other';
					if ( ! groups[ key ] ) {
						groups[ key ] = {
							label: r.input.getAttribute( 'data-ao-group-label' ) || key,
							rows: []
						};
						order.push( key );
					}
					groups[ key ].rows.push( r );
				} );

				order.forEach( function ( key ) {
					var g   = groups[ key ];
					var det = document.createElement( 'details' );
					det.className = 'fw-ao-group';
					var sum = document.createElement( 'summary' );
					sum.innerHTML =
						'<input type="checkbox" class="fw-ao-gcheck">' +
						'<span class="fw-ao-gname"></span>' +
						'<span class="fw-ao-gcount"></span>';
					sum.querySelector( '.fw-ao-gname' ).textContent = g.label;
					det.appendChild( sum );
					var body = document.createElement( 'div' );
					body.className = 'fw-ao-gbody';
					g.rows.forEach( function ( r ) { body.appendChild( r.row ); } );
					det.appendChild( body );
					box.appendChild( det );
					g.details = det;
					g.parent  = sum.querySelector( '.fw-ao-gcheck' );
					g.count   = sum.querySelector( '.fw-ao-gcount' );

					// Clicking the parent box must not also open/close the group.
					g.parent.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
					g.parent.addEventListener( 'change', function () {
						g.rows.forEach( function ( r ) {
							if ( r.row.style.display === 'none' ) { return; } // respect the filter
							r.input.checked = g.parent.checked;
						} );
						sync();
					} );
				} );

				function sync() {
					var total = 0, on = 0;
					order.forEach( function ( key ) {
						var g = groups[ key ], gOn = 0, gTotal = 0;
						g.rows.forEach( function ( r ) {
							gTotal++;
							if ( r.input.checked ) { gOn++; }
						} );
						total += gTotal; on += gOn;
						g.parent.checked       = gOn === gTotal && gTotal > 0;
						g.parent.indeterminate = gOn > 0 && gOn < gTotal;
						g.count.textContent    = gOn + ' / ' + gTotal;
						g.details.classList.toggle( 'fw-ao-group--none', gOn === 0 );
					} );
					bar.querySelector( '.fw-ao-total' ).textContent =
						on + ' / ' + total + ' <?php echo esc_js( __( 'combined', 'fw' ) ); ?>';
				}

				rows.forEach( function ( r ) {
					r.input.addEventListener( 'change', sync );
				} );

				bar.querySelector( '.fw-ao-all' ).addEventListener( 'click', function () {
					rows.forEach( function ( r ) {
						if ( r.row.style.display !== 'none' ) { r.input.checked = true; }
					} );
					sync();
				} );
				bar.querySelector( '.fw-ao-none' ).addEventListener( 'click', function () {
					rows.forEach( function ( r ) {
						if ( r.row.style.display !== 'none' ) { r.input.checked = false; }
					} );
					sync();
				} );

				var onlyUnchecked = bar.querySelector( '.fw-ao-onlyunchecked input' );
				function applyFilter() {
					var q    = bar.querySelector( '.fw-ao-filter' ).value.trim().toLowerCase();
					var only = onlyUnchecked.checked;
					order.forEach( function ( key ) {
						var g = groups[ key ], shown = 0;
						g.rows.forEach( function ( r ) {
							var hay = r.row.textContent.toLowerCase();
							var ok  = ( ! q || hay.indexOf( q ) !== -1 ) && ( ! only || ! r.input.checked );
							r.row.style.display = ok ? '' : 'none';
							if ( ok ) { shown++; }
						} );
						g.details.style.display = shown ? '' : 'none';
						// A search is only useful if it reveals what it found.
						if ( ( q || only ) && shown ) { g.details.open = true; }
						else if ( ! q && ! only ) { g.details.open = false; }
					} );
				}
				bar.querySelector( '.fw-ao-filter' ).addEventListener( 'input', applyFilter );
				onlyUnchecked.addEventListener( 'change', applyFilter );

				sync();
			} );
		} )();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Cache maintenance (purge + the settings-page action buttons)
	 * ------------------------------------------------------------------- */

	/**
	 * { path, url } of the combined-file cache directory - the ONE source of
	 * truth for the cache location. Routes through the shared uploads helper
	 * (fw_upw_uploads_dir), which enforces the uploads/unysonplus/<subdir>
	 * convention project-wide; CACHE_SUBDIR is only the fallback literal for
	 * when the helper isn't loaded. Returns empty strings if uploads is
	 * unavailable. No trailing slash.
	 *
	 * @return array{path:string,url:string}
	 */
	private function combined_paths() {
		if ( function_exists( 'fw_upw_uploads_dir' ) ) {
			return fw_upw_uploads_dir( 'asset-optimizer' );
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array( 'path' => '', 'url' => '' );
		}
		return array(
			'path' => wp_normalize_path( trailingslashit( $uploads['basedir'] ) . self::CACHE_SUBDIR ),
			'url'  => trailingslashit( $uploads['baseurl'] ) . self::CACHE_SUBDIR,
		);
	}

	/** Absolute path to the cache directory, or '' if uploads is unavailable. */
	private function cache_dir() {
		return $this->combined_paths()['path'];
	}

	/** Public URL of the cache directory, or '' if uploads is unavailable. */
	private function cache_url() {
		return $this->combined_paths()['url'];
	}

	/** Deletes every combined CSS/JS file in the cache directory. */
	private function purge_cache_files() {
		$dir = $this->cache_dir();
		if ( $dir === '' ) {
			return;
		}
		$files = glob( $dir . '/{combined,purged}-*.{css,js}', GLOB_BRACE );
		if ( $files ) {
			foreach ( $files as $f ) {
				@unlink( $f );
			}
		}
	}

	/**
	 * Public purge hook target. Fired when the active asset set can change
	 * (theme switch, plugin (de)activation, any upgrade). Clears the cached
	 * bundles so the next request rebuilds them against the new asset set; the
	 * discovered lists self-heal via src_is_dead() + the shutdown sweeps, so we
	 * leave them in place to avoid an "uncombined" window.
	 *
	 * @internal
	 */
	public function purge_all() {
		$this->purge_cache_files();
	}

	/* ---------------------------------------------------------------------
	 * Unused-CSS purging
	 * ------------------------------------------------------------------- */

	/**
	 * Whether purging should run on this request.
	 *
	 * Deliberately narrow. Purging is only sound when we control the whole
	 * stylesheet AND can rewrite the tag that points at it, which means CSS
	 * combining must be on and delivery must be a linked file - with `inline`
	 * delivery the bundle is already printed into the head before we see the
	 * buffer. Admin, AJAX, REST, feeds and the discovery crawl are all excluded:
	 * the crawl in particular must see the UNPURGED page, or it would learn a
	 * handle list derived from an already-purged bundle.
	 */
	public function purge_enabled() {
		if ( empty( $this->general_setting( 'purge_css', false ) ) ) {
			return false;
		}
		if ( ! $this->should_combine( 'css' ) ) {
			return false;
		}
		if ( 'file' !== $this->general_setting( 'css_delivery', 'file' ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( isset( $_GET[ self::DISCOVERY_QUERY_ARG ] ) ) {
			return false;
		}
		// Escape hatch: ?fw_ao_nopurge=1 serves the full bundle for one request.
		// Needed to compare a purged page against an unpurged one (which is how
		// you prove a rendering bug IS the purge rather than something else), and
		// the first thing to try when a page looks wrong. It can only ever serve
		// MORE css, never less, so it is safe to leave ungated.
		if ( isset( $_GET[ self::NOPURGE_QUERY_ARG ] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * The safelist: selectors that survive purging even when nothing in the
	 * scanned DOM matches them.
	 *
	 * This exists because a static scan sees ONE moment of the page, and a great
	 * deal of CSS is for moments it never sees - a menu opened, a row hovered, a
	 * slider initialised, an element scrolled into view. Measured on a real page:
	 * purging with no safelist destroyed 111 of 120 hover rules, every
	 * [aria-expanded] rule and 78 of 79 state rules named .is-... or .has-..., and a
	 * screenshot of the result still looked perfect. The list below is the
	 * validated default; users can add to it, and the filter lets a theme or
	 * extension register its own conventions.
	 *
	 * @return string[] Full preg patterns.
	 */
	public function get_purge_safelist() {
		$patterns = FW_AO_Purger::default_safelist();

		// User additions: one pattern per line, plain substrings or /regex/.
		$raw = (string) $this->general_setting( 'purge_safelist', '' );
		foreach ( preg_split( '#[\r\n]+#', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( strlen( $line ) > 2 && '/' === $line[0] && '/' === substr( $line, -1 ) ) {
				$patterns[] = '~' . str_replace( '~', '\~', substr( $line, 1, -1 ) ) . '~';
			} else {
				$patterns[] = '~' . preg_quote( $line, '~' ) . '~';
			}
		}

		/**
		 * Filter the purge safelist.
		 *
		 * @param string[] $patterns Full preg patterns.
		 */
		return apply_filters( 'fw:ext:asset-optimizer:purge_safelist', $patterns );
	}

	/**
	 * Start buffering the page so the rendered HTML can be scanned.
	 *
	 * @internal
	 */
	public function start_purge_buffer() {
		ob_start( array( $this, 'filter_buffer' ) );
	}

	/**
	 * Run every output-buffer pass that needs the finished page.
	 *
	 * @internal
	 * @param string $html
	 * @return string
	 */
	public function filter_buffer( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 200 || false === stripos( $html, '</html>' ) ) {
			return $html; // not a full document (a REST / partial response)
		}
		if ( $this->purge_enabled() ) {
			$html = $this->purge_buffer( $html );
		}
		if ( $this->preload_lcp_enabled() ) {
			$html = $this->preload_lcp_image( $html );
		}
		return $html;
	}

	/**
	 * Purge the combined stylesheet against the finished page, then point the
	 * page's <link> at the purged copy.
	 *
	 * Every failure path returns the HTML UNCHANGED. A purge that cannot be
	 * completed must degrade to the full bundle, never to a broken page.
	 *
	 * @internal
	 * @param string $html
	 * @return string
	 */
	public function purge_buffer( $html ) {
		try {
			if ( ! is_string( $html ) || strlen( $html ) < 200 || false === stripos( $html, '</html>' ) ) {
				return $html; // not a full document (a REST/partial response)
			}

			// Find the combined stylesheet this page actually printed.
			if ( ! preg_match( '#<link[^>]+href=([\'"])([^\'"]*?/' . preg_quote( self::CACHE_SUBDIR, '#' ) . '/combined-[a-f0-9]+\.css)\1#i', $html, $m ) ) {
				return $html;
			}
			$url  = $m[2];
			$path = $this->url_to_path( $url );
			if ( ! $path || ! is_readable( $path ) ) {
				return $html;
			}

			$tokens = FW_AO_Purger::collect_tokens( $html );
			if ( empty( $tokens['classes'] ) && empty( $tokens['ids'] ) ) {
				return $html; // nothing learned - refuse to purge blind
			}

			// Cache key: the bundle + what the page contains + the safelist. Any
			// of the three changing must produce a different file.
			$safelist = $this->get_purge_safelist();
			$key      = substr( md5(
				basename( $path ) . '|' .
				md5( implode( ',', array_keys( $tokens['classes'] ) ) . '|' . implode( ',', array_keys( $tokens['ids'] ) ) ) . '|' .
				md5( implode( '|', $safelist ) ) . '|' .
				(string) filemtime( $path )
			), 0, 12 );

			$dir       = $this->cache_dir();
			$file      = 'purged-' . $key . '.css';
			$dest      = trailingslashit( $dir ) . $file;
			$dest_url  = trailingslashit( $this->cache_url() ) . $file;

			if ( ! file_exists( $dest ) ) {
				$css = file_get_contents( $path );
				if ( false === $css || '' === $css ) {
					return $html;
				}
				$purged = FW_AO_Purger::purge( $css, $tokens, $safelist );

				// A purge that removed almost everything means the scan failed to
				// understand the page, not that the page needs no CSS. Bail rather
				// than serve a stylesheet that would render the site unstyled.
				if ( '' === trim( $purged ) || strlen( $purged ) < ( strlen( $css ) * 0.02 ) ) {
					return $html;
				}
				if ( ! wp_mkdir_p( $dir ) ) {
					return $html;
				}
				$this->ensure_cache_headers( $dir );
				if ( false === file_put_contents( $dest, $purged, LOCK_EX ) ) {
					return $html;
				}
			}

			$html = str_replace( $url, $dest_url, $html );

			// WordPress's inline block/theme.json CSS never reaches the combiner
			// (it is a <style> block, not a handle with a src), so purge it here.
			return $this->purge_inline_styles( $html, $tokens, $safelist );
		} catch ( Exception $e ) {
			return $html;
		} catch ( Error $e ) {
			return $html;   // PHP 7+ internal errors must not take the page down
		}
	}

	/**
	 * Drop an .htaccess into the cache directory so the generated files are
	 * cached for a year instead of revalidated on every visit.
	 *
	 * Measured before this existed: the combined CSS and JS came back with NO
	 * cache header at all, so a returning visitor re-requested both every time.
	 * Caching them forever is safe by construction, not by optimism - every
	 * filename contains a hash of its contents, so a rebuild is a different URL
	 * and a stale file can never be served.
	 *
	 * Apache only. On nginx/LiteSpeed the file is inert and the rules belong in
	 * the server config; the same one-year policy applies there.
	 */
	private function ensure_cache_headers( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$file = trailingslashit( $dir ) . '.htaccess';
		if ( file_exists( $file ) ) {
			return;
		}
		$rules = "# Generated by the UnysonPlus Asset Optimizer - safe to delete, it is recreated.\n"
			. "# Every filename here contains a hash of the file's contents, so a rebuild\n"
			. "# produces a new URL and these can be cached indefinitely.\n"
			. "<IfModule mod_headers.c>\n"
			. "\t<FilesMatch \"\\.(css|js)$\">\n"
			. "\t\tHeader set Cache-Control \"public, max-age=31536000, immutable\"\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n"
			. "<IfModule mod_expires.c>\n"
			. "\tExpiresActive On\n"
			. "\tExpiresByType text/css \"access plus 1 year\"\n"
			. "\tExpiresByType application/javascript \"access plus 1 year\"\n"
			. "</IfModule>\n";
		@file_put_contents( $file, $rules );
	}

	/**
	 * WordPress prints these stylesheets INLINE, which puts them out of reach of
	 * both the combiner and the purger - they are not handles with a src, they
	 * are <style> blocks in the head. On a page built with the page builder
	 * rather than blocks, `global-styles-inline-css` alone measured 23,883 bytes
	 * with 75% of it unmatched.
	 *
	 * Only WordPress's own block/theme.json output is touched. The plugin's and
	 * theme's own inline styles (preset CSS, header/footer custom CSS) are left
	 * alone: they are generated FROM the site's settings, so they are already
	 * exactly what the site asked for - measured 0% unused.
	 *
	 * @return string[]
	 */
	private function purgeable_inline_style_ids() {
		return apply_filters( 'fw:ext:asset-optimizer:purgeable_inline_style_ids', array(
			'global-styles-inline-css',
			'wp-block-library-inline-css',
			'classic-theme-styles-inline-css',
		) );
	}

	/**
	 * Purge the WordPress core inline <style> blocks in place.
	 *
	 * @param string $html
	 * @param array  $tokens
	 * @param array  $safelist
	 * @return string
	 */
	private function purge_inline_styles( $html, array $tokens, array $safelist ) {
		foreach ( $this->purgeable_inline_style_ids() as $id ) {
			$html = preg_replace_callback(
				'#(<style[^>]*\bid=([\'"])' . preg_quote( $id, '#' ) . '\2[^>]*>)(.*?)(</style>)#is',
				function ( $m ) use ( $tokens, $safelist ) {
					$purged = FW_AO_Purger::purge( $m[3], $tokens, $safelist );
					// Same guard as the file pass: a near-total wipe means the
					// scan failed, not that the page needs no CSS.
					if ( '' === trim( $purged ) || strlen( $purged ) < ( strlen( $m[3] ) * 0.02 ) ) {
						return $m[0];
					}
					return $m[1] . $purged . $m[4];
				},
				$html
			);
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * LCP image preload
	 * ------------------------------------------------------------------- */

	/** Whether to emit a preload for the page's likely LCP image. */
	public function preload_lcp_enabled() {
		if ( empty( $this->general_setting( 'preload_lcp_image', false ) ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Tell the browser about the hero image before it has parsed the CSS.
	 *
	 * This is not a bytes optimisation - it is a discovery-order one, and on the
	 * measured site it is the largest user-visible win available: LCP was 3.9s
	 * live, on an image the browser cannot find until it has downloaded the
	 * stylesheet, built the layout and reached that element.
	 *
	 * The hero is taken to be the first <img> that is NOT lazy-loaded, which is
	 * what WordPress's own above-the-fold heuristic produces (core omits
	 * loading="lazy" from the first in-content image for the same reason). If a
	 * page has none, nothing is emitted - a wrong guess would cost a wasted
	 * download, so the rule stays narrow.
	 *
	 * @internal
	 * @param string $html
	 * @return string
	 */
	public function preload_lcp_image( $html ) {
		try {
			if ( false !== stripos( $html, 'rel="preload" as="image"' ) ) {
				return $html; // something already preloads an image; don't compete
			}
			$body = stripos( $html, '<body' );
			if ( false === $body ) {
				return $html;
			}

			// Start AFTER the site header. "First non-lazy image" sounds like the
			// hero and is not: on the measured page it selected the 40px logo
			// while the actual LCP element was a large illustration further down
			// the first screen. Preloading the logo spends the browser's early
			// bandwidth on something it was going to fetch anyway and leaves the
			// real LCP exactly as late as before.
			$start = $body;
			$hdr   = stripos( $html, '</header>', $body );
			if ( false !== $hdr ) {
				$start = $hdr;
			}

			if ( ! preg_match_all( '#<img\b[^>]*>#i', substr( $html, $start ), $imgs ) ) {
				return $html;
			}

			// Only the first few content images are candidates. Beyond that we are
			// guessing at something below the fold, and eagerly loading it would
			// cost bandwidth to no benefit.
			$candidates = array_slice( $imgs[0], 0, 3 );

			$hero = '';
			foreach ( $candidates as $tag ) {
				// NOTE: a lazy image is NOT skipped here, which looks wrong and is
				// the whole point. On the measured page the LCP element itself
				// carried loading="lazy" - the page builder marks every image lazy,
				// including the one in the hero - so the browser was deliberately
				// deferring the exact element that defines the LCP. Skipping lazy
				// images found nothing to preload and left that bug in place. The
				// hero's lazy attribute is removed below.
				//
				// Chrome ignores an LCP candidate smaller than a few thousand
				// square pixels, so anything declaring itself tiny is not it.
				if ( preg_match( '#\bwidth\s*=\s*([\'"])(\d+)\1#i', $tag, $w ) && (int) $w[2] < 150 ) {
					continue;
				}
				if ( preg_match( '#\bheight\s*=\s*([\'"])(\d+)\1#i', $tag, $h ) && (int) $h[2] < 150 ) {
					continue;
				}
				// Logos, icons and tracking pixels are never the LCP.
				if ( preg_match( '#\b(class|id)\s*=\s*([\'"])[^\'"]*\b(logo|icon|avatar|badge|pixel|spinner)\b#i', $tag ) ) {
					continue;
				}
				if ( ! preg_match( '#\bsrc\s*=\s*([\'"])(.*?)\1#i', $tag ) ) {
					continue;
				}
				$hero = $tag;
				break;
			}
			if ( '' === $hero ) {
				return $html;
			}

			preg_match( '#\bsrc\s*=\s*([\'"])(.*?)\1#i', $hero, $m );
			$src = $m[2];
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				return $html;
			}
			$srcset = preg_match( '#\bsrcset\s*=\s*([\'"])(.*?)\1#i', $hero, $m2 ) ? $m2[2] : '';
			$sizes  = preg_match( '#\bsizes\s*=\s*([\'"])(.*?)\1#i', $hero, $m3 ) ? $m3[2] : '';

			$link = '<link rel="preload" as="image" href="' . esc_url( $src ) . '"'
				. ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '' )
				. ( $sizes ? ' imagesizes="' . esc_attr( $sizes ) . '"' : '' )
				. ' fetchpriority="high">';

			// Rewrite the hero tag itself:
			//   - drop loading="lazy" (preloading an image the browser has been
			//     told to defer is self-defeating - the two directives fight and
			//     the browser warns about it in the console),
			//   - add fetchpriority="high" so it stays ahead of the other images
			//     once discovered.
			// The preload sets priority for the FETCH; these keep it for the
			// ELEMENT. Neither half is much use without the other.
			$new_hero = preg_replace( '#\s*\bloading\s*=\s*([\'"])lazy\1#i', '', $hero );
			if ( ! preg_match( '#\bfetchpriority\s*=#i', $new_hero ) ) {
				$new_hero = preg_replace( '#<img\b#i', '<img fetchpriority="high"', $new_hero, 1 );
			}
			if ( $new_hero !== $hero ) {
				$pos = strpos( $html, $hero, $start );
				if ( false !== $pos ) {
					$html = substr_replace( $html, $new_hero, $pos, strlen( $hero ) );
				}
			}

			$head = stripos( $html, '</head>' );
			if ( false === $head ) {
				return $html;
			}
			return substr_replace( $html, $link . "\n", $head, 0 );
		} catch ( Exception $e ) {
			return $html;
		} catch ( Error $e ) {
			return $html;
		}
	}

	/**
	 * Cache stats for the settings page: number of combined files and their
	 * total size in bytes.
	 *
	 * @return array{count:int, bytes:int}
	 */
	public function get_cache_stats() {
		$dir = $this->cache_dir();
		if ( $dir === '' ) {
			return array( 'count' => 0, 'bytes' => 0 );
		}
		$files = glob( $dir . '/{combined,purged}-*.{css,js}', GLOB_BRACE );
		$count = 0;
		$bytes = 0;
		if ( $files ) {
			foreach ( $files as $f ) {
				$count++;
				$bytes += (int) @filesize( $f );
			}
		}
		return array( 'count' => $count, 'bytes' => $bytes );
	}

	/** URL (with nonce) for the Clear-cache action button. */
	public static function get_clear_cache_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_CLEAR_CACHE ),
			self::NONCE_ACTION
		);
	}

	/** URL (with nonce) for the Rescan action button. */
	public static function get_rescan_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_RESCAN ),
			self::NONCE_ACTION
		);
	}

	/**
	 * @internal
	 * admin-post handler: clear the cached combined files.
	 */
	public function handle_clear_cache() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$this->purge_cache_files();

		wp_safe_redirect( add_query_arg( 'fw-ao-notice', 'cleared', self::get_page_url() ) );
		exit;
	}

	/**
	 * @internal
	 * admin-post handler: forget the discovered lists and re-scan the frontend.
	 */
	public function handle_rescan() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		// Forget the discovered handle maps, then re-populate from a fresh fetch.
		delete_option( self::KNOWN_CSS_HANDLES_OPTION );
		delete_option( self::KNOWN_JS_HANDLES_OPTION );
		wp_cache_delete( self::KNOWN_CSS_HANDLES_OPTION, 'options' );
		wp_cache_delete( self::KNOWN_JS_HANDLES_OPTION, 'options' );

		// A changed list usually means a changed bundle - drop stale files too.
		$this->purge_cache_files();

		$this->discover_handles();

		wp_safe_redirect( add_query_arg( 'fw-ao-notice', 'rescanned', self::get_page_url() ) );
		exit;
	}

	/**
	 * One-shot migration from the previous "CSS Combiner" extension.
	 *
	 * The old extension stored data under different keys; we copy it over
	 * the first time the new extension boots so saved selections survive
	 * the rename. Reads the underlying WP options directly because the old
	 * extension no longer exists, so fw_get_db_ext_settings_option() would
	 * trigger an "Invalid extension" warning.
	 */
	private function maybe_migrate() {
		if ( get_option( self::MIGRATION_OPTION ) ) {
			return;
		}

		// 1. Migrate the discovered-handles map.
		$old_known = get_option( 'fw_ext_css_combiner_known_handles', null );
		if ( is_array( $old_known ) && ! empty( $old_known ) ) {
			update_option( self::KNOWN_CSS_HANDLES_OPTION, $old_known, false );
		}
		delete_option( 'fw_ext_css_combiner_known_handles' );

		// 2. Migrate the user's saved checkbox state. The Unyson settings store
		// for an extension is one WP option named "fw_ext_settings_options:{slug}"
		// holding an array of option_id => value.
		$old_settings = get_option( 'fw_ext_settings_options:css-combiner', null );
		if ( is_array( $old_settings ) && isset( $old_settings['handles'] ) ) {
			$new_settings = get_option( 'fw_ext_settings_options:asset-optimizer', array() );
			if ( ! is_array( $new_settings ) ) {
				$new_settings = array();
			}
			// Old option id was 'handles'; new is 'css_handles' (future-proof
			// for a parallel 'js_handles' list). Don't overwrite if user has
			// already saved under the new slug somehow.
			if ( ! isset( $new_settings['css_handles'] ) ) {
				$new_settings['css_handles'] = $old_settings['handles'];
				update_option( 'fw_ext_settings_options:asset-optimizer', $new_settings, false );
			}
		}
		delete_option( 'fw_ext_settings_options:css-combiner' );

		// 3. Best-effort: wipe the old cache directory so disk doesn't grow.
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) ) {
			$old_dir = trailingslashit( $uploads['basedir'] ) . 'unysonplus-css-combiner';
			if ( is_dir( $old_dir ) ) {
				$old_files = glob( $old_dir . '/combined-*.css' );
				if ( $old_files ) {
					foreach ( $old_files as $f ) {
						@unlink( $f );
					}
				}
				@rmdir( $old_dir );
			}
		}

		update_option( self::MIGRATION_OPTION, 1, true );
	}

	/**
	 * One-time: drop the discovered-handle maps from autoload on installs that
	 * saved them before this version (they were stored with autoload=yes).
	 * update_option alone can't fix a STABLE map - WP skips the autoload change
	 * when the value is unchanged - so delete + re-add is needed to force
	 * autoload=no. New writes already pass false. These maps are only read on
	 * the frontend combine pass + settings page, so autoloading them taxed every
	 * admin / cron / REST / AJAX request for nothing.
	 *
	 * The guard flag is itself autoloaded (a 1-byte scalar), so this check is a
	 * free in-memory read on every subsequent request - no extra query.
	 */
	private function maybe_fix_autoload() {
		if ( get_option( self::AUTOLOAD_FIX_OPTION ) ) {
			return;
		}
		foreach ( array( self::KNOWN_CSS_HANDLES_OPTION, self::KNOWN_JS_HANDLES_OPTION ) as $opt ) {
			$val = get_option( $opt, null );
			if ( null !== $val ) {
				delete_option( $opt );
				add_option( $opt, $val, '', false ); // autoload = no
			}
		}
		update_option( self::AUTOLOAD_FIX_OPTION, 1, true ); // tiny flag: autoload OK
	}

	/**
	 * Returns the map of every CSS handle ever seen on the frontend:
	 *   array( handle => src )
	 *
	 * Backend/admin-only stylesheets are stripped on the way out, so even a
	 * list captured by an older build (which could pick up admin-bar styles or
	 * builder option-type CSS) is self-healing - the settings page and the
	 * combiner both go through here.
	 */
	public function get_known_css_handles() {
		// Memoized: filtering calls src_is_dead()->url_to_path() (a disk stat) for
		// every stored handle, and this getter is hit from several call sites in
		// one request (the combine pass, the settings page, exclusion recompute).
		// The underlying option only changes at shutdown (remember_css_handles,
		// which invalidates this cache), so one filter pass per request suffices.
		if ( null !== $this->known_css_cache ) {
			return $this->known_css_cache;
		}
		$known = get_option( self::KNOWN_CSS_HANDLES_OPTION, array() );
		if ( ! is_array( $known ) ) {
			return $this->known_css_cache = array();
		}
		foreach ( $known as $handle => $src ) {
			if ( $this->is_backend_css_handle( $handle, $src ) || $this->src_is_dead( $src ) ) {
				unset( $known[ $handle ] );
			}
		}
		return $this->known_css_cache = $known;
	}
	/** @var array|null Per-request cache for get_known_css_handles(). */
	private $known_css_cache = null;
	/** @var array|null Per-request cache for get_known_js_handles(). */
	private $known_js_cache = null;

	/**
	 * Whether a remembered src is "dead" - a SAME-HOST (or root-relative) asset
	 * whose file no longer exists on disk, e.g. left behind by a plugin/theme
	 * that was since deactivated or deleted. Used to prune the discovered lists.
	 *
	 * Conservative on purpose: a src on a DIFFERENT host (CDN / asset offload)
	 * is never judged dead - we can't stat it, so we keep it.
	 *
	 * @param string $src
	 * @return bool
	 */
	private function src_is_dead( $src ) {
		if ( ! is_string( $src ) || $src === '' ) {
			return false; // inline-only / src-less handle - keep
		}

		// Only judge same-site assets; a foreign host (CDN / offload) we can't
		// stat, so never call it dead. A local asset is dead only when the
		// (sub-directory-aware) resolver can't find it on disk.
		if ( ! $this->is_local_src( $src ) ) {
			return false;
		}

		return $this->url_to_path( $src ) === false;
	}

	/**
	 * Whether a stylesheet is a backend/admin-only asset that must never enter
	 * the frontend combined file.
	 *
	 * The extension only hooks on the frontend, but a logged-in admin browsing
	 * the site still drags the admin bar (and its dashicons/pointer styles)
	 * onto the page, and the page-builder can enqueue option-type CSS while
	 * rendering. Those are only ever needed inside wp-admin / the builder
	 * modals, so we exclude them by handle and by source path.
	 *
	 * @param string $handle
	 * @param string $src
	 * @return bool
	 */
	private function is_backend_css_handle( $handle, $src ) {
		static $admin_handles = array( 'admin-bar', 'dashicons', 'wp-pointer' );
		if ( in_array( $handle, $admin_handles, true ) ) {
			return true;
		}

		if ( ! is_string( $src ) || $src === '' ) {
			return false;
		}

		// Backend asset paths: WordPress core admin, and Unyson / extension
		// option-type styles which only load inside the builder/options UI.
		// Also the dashicons FILE regardless of which handle registered it (e.g.
		// `fw-option-type-wp-editor-dashicons` points at wp-includes/css/
		// dashicons.min.css) - it's the wp-admin/editor icon font; visitors never
		// need its ~46KB of icon rules in the bundle.
		static $needles = array(
			'/wp-admin/',
			'/includes/option-types/',
			'/wp-includes/css/dashicons',
		);
		foreach ( $needles as $needle ) {
			if ( stripos( $src, $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fires one internal HTTP request to the home URL so the frontend hooks
	 * run and populate BOTH the known-CSS and known-JS handle maps. The single
	 * request triggers both shutdown sweeps, so callers don't need two hits.
	 */
	public function discover_handles() {
		// One-time token authorising the cache-bust on the crawled request (see
		// _init). Short TTL - the crawl completes in seconds - and single-use.
		$token = wp_generate_password( 24, false );
		set_transient( self::DISCOVERY_TOKEN_PREFIX . $token, 1, MINUTE_IN_SECONDS );

		$url  = add_query_arg( self::DISCOVERY_QUERY_ARG, $token, home_url( '/' ) );
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Cache-Control' => 'no-cache, no-store, max-age=0',
				'Pragma'        => 'no-cache',
			),
			'cookies' => array(),
		);

		// Verify TLS by default; only a certificate failure on this self-loopback
		// (e.g. a self-signed local cert) falls back to unverified - never a
		// blanket disable. Discovery is best-effort anyway (the shutdown sweeps
		// also populate the maps organically), so a hard failure is non-fatal.
		$res = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) && false !== stripos( $res->get_error_message(), 'ssl' ) ) {
			$args['sslverify'] = false;
			wp_remote_get( $url, $args );
		}

		wp_cache_delete( self::KNOWN_CSS_HANDLES_OPTION, 'options' );
		wp_cache_delete( self::KNOWN_JS_HANDLES_OPTION, 'options' );
	}

	/* ---------------------------------------------------------------------
	 * General settings (master toggles, logged-out-only, URL exclusions)
	 * ------------------------------------------------------------------- */

	/**
	 * The extension's settings store, memoized for the request. general_setting()
	 * is hit ~8-12x per request (should_combine alone reads it 3x, plus css_scope
	 * and both combine passes); the store never changes mid-request on the front
	 * end (admin saves redirect via PRG), so reading it once is safe and saves the
	 * repeated fetch + array validation.
	 *
	 * @var array|null
	 */
	private $settings_store_cache = null;

	/** Memoized settings store (frontend-safe; see $settings_store_cache). */
	private function settings_store() {
		if ( null === $this->settings_store_cache ) {
			$store                      = get_option( 'fw_ext_settings_options:' . $this->get_name(), array() );
			$this->settings_store_cache = is_array( $store ) ? $store : array();
		}
		return $this->settings_store_cache;
	}

	/** Reads a General-tab setting straight from the store (frontend-safe). */
	private function general_setting( $key, $default ) {
		$store = $this->settings_store();
		return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
	}

	/**
	 * Whether the combiner should run for the given type ('css'|'js') on THIS
	 * request - honoring the master switch, the logged-out-only option, and the
	 * URL-exclusion list. All default to "combine".
	 */
	private function should_combine( $type ) {
		if ( empty( $this->general_setting( 'js' === $type ? 'combine_js' : 'combine_css', true ) ) ) {
			return false;
		}
		if ( ! empty( $this->general_setting( 'logged_out_only', false ) ) && is_user_logged_in() ) {
			return false;
		}
		if ( $this->request_is_excluded() ) {
			return false;
		}
		return true;
	}

	/** Whether the current request path matches any Exclude-URL pattern (* = wildcard). */
	private function request_is_excluded() {
		$raw = (string) $this->general_setting( 'exclude_urls', '' );
		if ( trim( $raw ) === '' ) {
			return false;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( $path === '' ) {
			return false;
		}
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( $pattern === '' ) {
				continue;
			}
			if ( strpos( $pattern, '*' ) !== false ) {
				$regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
				if ( preg_match( $regex, $path ) ) {
					return true;
				}
			} elseif ( stripos( $path, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Public combine API for cooperating extensions
	 *
	 * The Animation Engine enqueues its per-style CSS/JS partials LATE
	 * (wp_footer:5) and ON-DEMAND (only the styles a page uses). Neither fits
	 * our generic passes: the JS pass runs at wp_enqueue_scripts:99999 (before
	 * the engine enqueues), and folding the on-demand CSS into the SITE-WIDE
	 * bundle would break the engine's "ship only used styles" contract. So the
	 * engine folds ITS OWN per-page partials via these helpers, honoring our
	 * master switches / logged-out-only / URL-exclusion, while our site-wide
	 * combiner leaves the engine's handles alone (it registers a
	 * `css_exclude_handles` filter for them).
	 * ------------------------------------------------------------------- */

	/**
	 * Whether combining is enabled for 'css'|'js' on THIS request (master
	 * switch + logged-out-only + URL exclusions). Public entry point for
	 * cooperating extensions.
	 */
	public function is_combine_enabled( $type ) {
		return $this->should_combine( 'js' === $type ? 'js' : 'css' );
	}

	/**
	 * Concatenate an ordered list of LOCAL files into one cached combined file
	 * (CSS or JS) and return its public URL, or false if there's nothing to do.
	 * The cache key is the ordered file set + mtimes, so different pages with
	 * different style-sets get correctly distinct combined files (per-page).
	 *
	 * @param array  $files Ordered list of ['abs'=>absolute path, 'src'=>public URL, 'handle'=>id].
	 * @param string $type  'css' | 'js'
	 * @return string|false
	 */
	public function combine_files( array $files, $type ) {
		$items = array();
		foreach ( $files as $f ) {
			$abs = isset( $f['abs'] ) ? $f['abs'] : '';
			if ( ! $abs || ! is_readable( $abs ) ) {
				continue;
			}
			$handle = isset( $f['handle'] ) && $f['handle'] !== '' ? $f['handle'] : basename( $abs );
			if ( 'js' === $type ) {
				$items[] = array( 'handle' => $handle, 'path' => $abs );
			} else {
				$items[] = array(
					'handle' => $handle,
					'src'    => isset( $f['src'] ) ? $f['src'] : '',
					'path'   => $abs,
					'media'  => 'all',
				);
			}
		}
		if ( count( $items ) < 2 ) {
			return false;
		}
		if ( 'js' === $type ) {
			return $this->build_combined_js_file( $items, ! empty( $this->general_setting( 'js_minify', true ) ) );
		}
		return $this->build_combined_css_file( $items );
	}

	/**
	 * The combinable CSS handles enqueued on THIS request (per-page scope), as a
	 * handle => src map - the live-queue analogue of get_known_css_handles().
	 *
	 * Runs at wp_enqueue_scripts:99999 (after the theme's stylesheet orderer, so
	 * the dependency graph / eventual cascade order is resolved). Applies the same
	 * filtering as the site-wide map (backend/admin, dead files) plus a media
	 * guard (print/query-scoped sheets stay separate). The Animation Engine's
	 * footer partials aren't enqueued yet, so they're naturally excluded - the
	 * engine folds those itself.
	 */
	private function get_page_css_handles() {
		global $wp_styles;
		if ( ! ( $wp_styles instanceof WP_Styles ) ) {
			return array();
		}
		$wp_styles->all_deps( $wp_styles->queue );

		$handles = array();
		foreach ( (array) $wp_styles->to_do as $handle ) {
			if ( $handle === self::COMBINED_CSS_HANDLE ) {
				continue;
			}
			$reg = isset( $wp_styles->registered[ $handle ] ) ? $wp_styles->registered[ $handle ] : null;
			if ( ! $reg ) {
				continue;
			}
			// Only 'all' / empty media - a print- or query-scoped sheet must stay separate.
			$media = isset( $reg->args ) ? $reg->args : 'all';
			if ( $media && 'all' !== $media ) {
				continue;
			}
			$src = ! empty( $reg->src ) ? preg_replace( '#\?.*$#', '', (string) $reg->src ) : '';
			if ( $src === '' ) {
				continue;
			}
			if ( $this->is_backend_css_handle( $handle, $src ) || $this->src_is_dead( $src ) ) {
				continue;
			}
			$handles[ $handle ] = $src;
		}
		return $handles;
	}

	/**
	 * Builds and enqueues the combined stylesheet.
	 *
	 * The handle SOURCE depends on the CSS scope. Site-wide (default): the
	 * persisted map of every stylesheet ever discovered, cached and reused across
	 * pages. Per-page: only the stylesheets THIS page enqueued, so its combined
	 * file contains only its own CSS in exact document order. Everything
	 * downstream (ordering, cascade priority, url-rewrite, minify, suppression)
	 * is identical either way.
	 *
	 * @internal
	 */
	public function enqueue_combined_css() {
		if ( ! $this->should_combine( 'css' ) ) {
			return;
		}
		$per_page = ( 'site' !== $this->general_setting( 'css_scope', 'per_page' ) );
		$known    = $per_page ? $this->get_page_css_handles() : $this->get_known_css_handles();
		if ( empty( $known ) ) {
			return;
		}

		$excluded = $this->get_excluded_css_handles( $known );

		// Combine in the live, dependency-resolved frontend order so the bundle's
		// cascade matches what WordPress would have printed. CSS is order
		// sensitive (later rules win), and the discovery map alone isn't reliably
		// in print order.
		$ordered = $this->ordered_css_handles( array_keys( $known ) );

		// Give theme stylesheets cascade authority: keep everything else in
		// frontend order, then the parent theme, then the child theme LAST so it
		// can override all the combined framework/shortcode CSS.
		$ordered = $this->prioritize_css_handles( $ordered, $known );

		$items        = array();
		$seen_paths   = array(); // resolved path => first handle
		$dupe_handles = array(); // handles whose FILE is already in the bundle under another handle

		foreach ( $ordered as $handle ) {
			if ( $handle === self::COMBINED_CSS_HANDLE ) {
				continue;
			}
			if ( in_array( $handle, $excluded, true ) ) {
				continue;
			}
			$src = isset( $known[ $handle ] ) ? $known[ $handle ] : '';
			if ( empty( $src ) ) {
				continue;
			}

			$path = $this->url_to_path( $src );
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}

			// De-dupe by FILE: two handles can point at the same stylesheet (e.g.
			// `font-awesome` and the icon-pack handle both registering
			// font-awesome.min.css). Concatenate it once; the duplicate handle's
			// tag still gets suppressed below since its content is in the bundle.
			$path_key = strtolower( wp_normalize_path( $path ) );
			if ( isset( $seen_paths[ $path_key ] ) ) {
				$dupe_handles[] = $handle;
				continue;
			}
			$seen_paths[ $path_key ] = $handle;

			$items[ $handle ] = array(
				'handle' => $handle,
				'src'    => $src,
				'path'   => $path,
				'media'  => 'all',
			);
		}

		if ( count( $items ) < 2 ) {
			return;
		}

		$combined_url = $this->build_combined_css_file( array_values( $items ) );
		if ( ! $combined_url ) {
			return;
		}

		foreach ( $items as $handle => $_ ) {
			$this->absorbed_css_handles[ $handle ] = true;
		}
		foreach ( $dupe_handles as $handle ) {
			$this->absorbed_css_handles[ $handle ] = true;
		}

		// Inline delivery: print the bundle in a <style> in <head> instead of a <link>,
		// so first paint doesn't wait on a second round trip. Same handle and cascade
		// position either way; the cached file stays the source of truth. url()s are
		// already absolute (rewrite_urls), so the CSS works unchanged inline.
		$inline_css = $this->combined_css_inline_body( $combined_url );
		if ( $inline_css !== '' ) {
			wp_register_style( self::COMBINED_CSS_HANDLE, false, array(), null );
			wp_enqueue_style( self::COMBINED_CSS_HANDLE );
			wp_add_inline_style( self::COMBINED_CSS_HANDLE, $inline_css );
			return;
		}

		wp_register_style( self::COMBINED_CSS_HANDLE, $combined_url, array(), null );
		wp_enqueue_style( self::COMBINED_CSS_HANDLE );
	}

	/**
	 * The combined CSS to print inline, or '' to keep the linked file: when
	 * the CSS delivery setting is "inline", the file is readable, and its
	 * COMPRESSED size (what actually travels) is within the cap - filter
	 * fw:ext:asset-optimizer:css_inline_max_bytes, default 50 KB gzipped.
	 * Inlining a big bundle into every HTML response costs more than the
	 * request it saves. The measured size is cached per bundle file.
	 *
	 * @param string $combined_url Public URL of the combined file.
	 * @return string
	 */
	private function combined_css_inline_body( $combined_url ) {
		if ( 'inline' !== $this->general_setting( 'css_delivery', 'file' ) ) {
			return '';
		}
		$path = $this->url_to_path( $combined_url );
		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}
		$css = (string) file_get_contents( $path );
		if ( $css === '' ) {
			return '';
		}

		$size_key = 'fw_ao_gz_' . md5( basename( $path ) );
		$gz_size  = get_transient( $size_key );
		if ( false === $gz_size ) {
			$gz_size = function_exists( 'gzencode' ) ? strlen( gzencode( $css, 6 ) ) : (int) ( strlen( $css ) / 5 );
			set_transient( $size_key, $gz_size, WEEK_IN_SECONDS );
		}
		$max = (int) apply_filters( 'fw:ext:asset-optimizer:css_inline_max_bytes', 51200 );
		if ( (int) $gz_size > $max ) {
			return '';
		}

		// @charset is only valid in a linked stylesheet; a closing style tag in a
		// string or comment must not end the inline block early.
		$css = preg_replace( '#^\s*@charset\s+[^;]+;\s*#i', '', $css );
		return str_ireplace( '</style', '<\/style', $css );
	}

	/**
	 * Orders the given CSS handles by the live, dependency-resolved frontend
	 * order ($wp_styles->to_do). Handles not yet enqueued at this hook (e.g.
	 * enqueued later while rendering content) keep their discovered order and
	 * are appended afterwards.
	 *
	 * @param string[] $candidates Handle names to order.
	 * @return string[]
	 */
	private function ordered_css_handles( $candidates ) {
		global $wp_styles;

		$set     = array_flip( $candidates );
		$ordered = array();

		if ( $wp_styles instanceof WP_Styles ) {
			$wp_styles->all_deps( $wp_styles->queue );
			foreach ( (array) $wp_styles->to_do as $handle ) {
				if ( isset( $set[ $handle ] ) && ! isset( $ordered[ $handle ] ) ) {
					$ordered[ $handle ] = true;
				}
			}
		}

		// Append any candidates not resolved above, in their original order.
		foreach ( $candidates as $handle ) {
			if ( ! isset( $ordered[ $handle ] ) ) {
				$ordered[ $handle ] = true;
			}
		}

		return array_keys( $ordered );
	}

	/**
	 * Whether a handle is a UnysonPlus design-preset stylesheet (the design
	 * tokens / Component Presets the plugin writes out). These sit just below
	 * the child theme in cascade authority. Filterable so the plugin/theme can
	 * register additional preset-level handles.
	 */
	private function is_preset_css_handle( $handle ) {
		static $handles = null;
		if ( null === $handles ) {
			/** Filters the handles treated as UnysonPlus design-preset stylesheets in the cascade (default the presets handle). */
			$handles = (array) apply_filters(
				'fw:ext:asset-optimizer:preset_css_handles',
				array( 'unysonplus-presets' )
			);
			$handles = array_flip( $handles );
		}
		return isset( $handles[ $handle ] );
	}

	/**
	 * Whether a stylesheet is a UnysonPlus *generated* stylesheet written to the
	 * uploads directory - the design presets (`presets-*.css`), the theme design
	 * tokens / header-footer custom CSS (`unysonplus-generated.css`), and any
	 * other CSS the plugin renders into `wp-content/uploads/`. These carry the
	 * user's live customizations and must sit just BELOW the child theme but
	 * ABOVE the parent theme + framework, exactly as they print uncombined
	 * (parent -> presets -> generated -> child). Detected by location so we catch
	 * every generated file regardless of its enqueue handle.
	 *
	 * @param string $src
	 * @return bool
	 */
	private function is_generated_css_src( $src ) {
		if ( ! is_string( $src ) || $src === '' ) {
			return false;
		}
		$uploads = wp_upload_dir();
		if ( empty( $uploads['baseurl'] ) ) {
			return false;
		}
		$base = $this->url_path_only( $uploads['baseurl'] ); // e.g. /wp-content/uploads/
		return $base !== '' && stripos( $src, $base ) !== false;
	}

	/**
	 * Cascade-authority bucket for a CSS handle (higher = wins):
	 *   1 = everything else, INCLUDING the parent theme (natural frontend order),
	 *   2 = the UnysonPlus design presets + generated customization CSS
	 *       (the `unysonplus-presets` handle and anything rendered to uploads),
	 *   3 = the active CHILD theme (top authority).
	 *
	 * The PARENT theme is intentionally NOT hoisted. Its assets sit at very
	 * different natural positions - e.g. header-footer-builder.css prints FIRST
	 * (a base everything is meant to override) while style.css prints LATE,
	 * already after the shortcodes. Lumping the whole theme into one high bucket
	 * drags that early base above the framework/shortcodes and inverts the
	 * cascade, which breaks layouts. Leaving the parent theme in bucket 1 keeps
	 * each of its files in real order; the presets, generated CSS and child theme
	 * are naturally last anyway, so floating them to the end just reproduces the
	 * working uncombined cascade.
	 */
	private function css_theme_bucket( $handle, $src ) {
		// The active CHILD theme gets top authority - but only when a child theme
		// is actually in use. Otherwise the lone theme's assets (which include
		// early bases like header-footer-builder.css) must keep their natural
		// order, not all jump to the end.
		if ( is_child_theme() && is_string( $src ) && $src !== '' ) {
			$child = $this->url_path_only( get_stylesheet_directory_uri() );
			if ( $child !== '' && stripos( $src, $child ) !== false ) {
				return 3;
			}
		}
		// UnysonPlus presets + generated customization CSS sit just below the child theme.
		if ( $this->is_preset_css_handle( $handle ) || $this->is_generated_css_src( $src ) ) {
			return 2;
		}
		// Everything else - including the parent theme - keeps natural order.
		return 1;
	}

	/**
	 * Reorders CSS handles for cascade correctness: everything else keeps its
	 * (frontend) order, then the PARENT theme, then the UnysonPlus presets, then
	 * the CHILD theme last - so a theme always wins over the framework/shortcode
	 * CSS it is meant to style, the presets sit just under the child theme, and
	 * the child theme overrides everything. A deliberate improvement over the
	 * raw frontend order, where shortcode CSS enqueued late (in the footer)
	 * would otherwise outrank the theme.
	 *
	 * @param string[] $handles Handles in frontend order.
	 * @param array    $src_map handle => src.
	 * @return string[]
	 */
	public function prioritize_css_handles( $handles, $src_map ) {
		$buckets = array( 1 => array(), 2 => array(), 3 => array() );
		foreach ( $handles as $handle ) {
			$src = isset( $src_map[ $handle ] ) ? $src_map[ $handle ] : '';
			$buckets[ $this->css_theme_bucket( $handle, $src ) ][] = $handle;
		}
		return array_merge( $buckets[1], $buckets[2], $buckets[3] );
	}

	/**
	 * Hook target for style_loader_tag.
	 *
	 * @internal
	 */
	public function suppress_absorbed_css_tag( $tag, $handle ) {
		if ( isset( $this->absorbed_css_handles[ $handle ] ) ) {
			return '';
		}
		return $tag;
	}

	/**
	 * Hook target: at shutdown, sweep $wp_styles for every handle that was
	 * registered, enqueued, or printed during this request and remember them
	 * all. This catches handles that bypassed any earlier capture - including
	 * page-builder shortcodes that enqueue their CSS during render.
	 *
	 * @internal
	 */
	public function remember_all_seen_css_handles() {
		global $wp_styles;

		if ( ! ( $wp_styles instanceof WP_Styles ) ) {
			return;
		}

		// `done` is the actual print order (the true cascade order); list it
		// first so the stored map - and therefore the settings list - reflects
		// frontend order. Append any still-queued stragglers after.
		$handles = array_unique( array_merge(
			array_values( (array) $wp_styles->done ),
			array_values( (array) $wp_styles->queue )
		) );

		$handles = array_values( array_filter( $handles, function ( $h ) {
			return $h !== self::COMBINED_CSS_HANDLE;
		} ) );

		if ( empty( $handles ) ) {
			return;
		}

		$this->remember_css_handles( $wp_styles, $handles );
	}

	/**
	 * Persists the handle => src map so the settings page can list them.
	 */
	private function remember_css_handles( $wp_styles, $handles ) {
		// Read the raw option (not the filtered getter) so we can physically
		// purge any backend handles a previous build persisted, rather than
		// just hiding them.
		$known = get_option( self::KNOWN_CSS_HANDLES_OPTION, array() );
		if ( ! is_array( $known ) ) {
			$known = array();
		}
		$changed = false;

		foreach ( $known as $handle => $src ) {
			if ( $this->is_backend_css_handle( $handle, $src ) ) {
				unset( $known[ $handle ] );
				$changed = true;
			}
		}

		foreach ( $handles as $handle ) {
			if ( $handle === self::COMBINED_CSS_HANDLE ) {
				continue;
			}
			$reg = isset( $wp_styles->registered[ $handle ] ) ? $wp_styles->registered[ $handle ] : null;
			$src = $reg && ! empty( $reg->src ) ? (string) $reg->src : '';

			if ( $src !== '' ) {
				$src = preg_replace( '#\?.*$#', '', $src );
			}

			// Never remember backend/admin-only stylesheets.
			if ( $this->is_backend_css_handle( $handle, $src ) ) {
				continue;
			}

			if ( ! array_key_exists( $handle, $known ) || $known[ $handle ] !== $src ) {
				$known[ $handle ] = $src;
				$changed          = true;
			}
		}

		if ( $changed ) {
			// autoload = false: this map is only read on the frontend combine
			// pass + the settings page, so it must NOT load on every request.
			update_option( self::KNOWN_CSS_HANDLES_OPTION, $known, false );
			wp_cache_delete( self::KNOWN_CSS_HANDLES_OPTION, 'options' );
			$this->known_css_cache = null; // invalidate the per-request memo
		}
	}

	/**
	 * Returns the CSS handles the user has excluded from combining.
	 *
	 * A CSS handle combines BY DEFAULT - it is excluded only when the user
	 * explicitly unchecked it. That intent CANNOT be read back from the saved
	 * `css_handles` map alone: the `checkboxes` option type persists only CHECKED
	 * handles (it never stores an unchecked one as `false`), so an unchecked box
	 * and a handle discovered after the last save are both simply "absent" and
	 * indistinguishable. (An earlier attempt to read the saved map directly was
	 * therefore inert - nothing was ever excluded, so unchecking a stylesheet did
	 * nothing - which is the bug this replaces.)
	 *
	 * Instead we persist the exclusion set explicitly: at save time
	 * recompute_css_exclusions() diffs the handles known THEN against the checked
	 * map and stores the difference. Here we just read that list (intersected with
	 * the currently-known handles, so stale entries are ignored). A handle
	 * discovered after the last save is absent from the list and therefore still
	 * combines by default, exactly as intended.
	 *
	 * @param array $known  The known CSS handle => src map.
	 * @return string[]
	 */
	private function get_excluded_css_handles( $known ) {
		$excluded = array();

		$stored = get_option( self::CSS_EXCLUDED_OPTION, null );
		if ( is_array( $stored ) && $stored ) {
			$stored = array_flip( $stored );
			foreach ( $known as $handle => $src ) {
				if ( isset( $stored[ $handle ] ) ) {
					$excluded[] = $handle;
				}
			}
		}

		/**
		 * Filters the list of CSS handles force-excluded from combining, a developer escape hatch given the known handle map.
		 *
		 * Force-exclude CSS handles from combining (developer escape hatch).
		 *
		 * @param string[] $handles Extra handles to leave as separate requests.
		 * @param array    $known   The known handle => src map.
		 */
		$forced = apply_filters( 'fw:ext:asset-optimizer:css_exclude_handles', array(), $known );
		if ( is_array( $forced ) && $forced ) {
			$excluded = array_merge( $excluded, $forced );
		}

		return array_values( array_unique( $excluded ) );
	}

	/**
	 * Recomputes and persists the explicit CSS-exclusion list from a just-saved
	 * settings value set.
	 *
	 * The `checkboxes` option type stores only checked handles, so we capture the
	 * user's intent at save time - while we still know the full set of handles the
	 * form presented (= the currently-known handles): any known handle NOT in the
	 * checked map was unchecked, so it is excluded. Storing the difference (rather
	 * than re-deriving it later from the lossy saved map) is what lets an unchecked
	 * stylesheet actually drop out of the bundle while a handle discovered after
	 * the save still combines by default.
	 *
	 * @param array|null $values The saved settings values; read from the store
	 *                           when null (e.g. the Extensions-manager save path).
	 */
	private function recompute_css_exclusions( $values = null ) {
		if ( ! is_array( $values ) ) {
			$values = (array) fw_get_db_ext_settings_option( $this->get_name() );
		}

		$checked = ( isset( $values['css_handles'] ) && is_array( $values['css_handles'] ) )
			? $values['css_handles']
			: array();

		$excluded = array();
		foreach ( array_keys( $this->get_known_css_handles() ) as $handle ) {
			if ( empty( $checked[ $handle ] ) ) {
				$excluded[] = $handle;
			}
		}

		update_option( self::CSS_EXCLUDED_OPTION, array_values( $excluded ), false );
		wp_cache_delete( self::CSS_EXCLUDED_OPTION, 'options' );
	}

	/**
	 * Checkbox display state for the CSS handles on the settings page: each known
	 * handle is checked unless it appears in the persisted exclusion list, so the
	 * UI always mirrors what actually combines - including handles discovered
	 * after the last save (absent from the exclusion list -> shown checked).
	 *
	 * Returns null before the exclusion list has ever been written (legacy /
	 * never-saved installs), so the caller leaves the saved map (or the
	 * all-checked default) to drive the display untouched.
	 *
	 * @param string[] $known_handles Currently-known CSS handle names.
	 * @return array<string,bool>|null
	 */
	public function get_css_display_values( $known_handles ) {
		$stored = get_option( self::CSS_EXCLUDED_OPTION, null );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$excluded = array_flip( $stored );
		$value    = array();
		foreach ( $known_handles as $handle ) {
			$value[ $handle ] = ! isset( $excluded[ $handle ] );
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * JavaScript combining
	 *
	 * Conservative by design. Unlike CSS, JS order and timing are significant,
	 * so the combiner only folds scripts it can prove are safe:
	 *   - LOCAL, FOOTER scripts only (head + external are left alone);
	 *   - no async / defer strategy;
	 *   - NO inline or localized data (wp_localize_script / wp_add_inline_script
	 *     / translations) - that data is frequently per-request/per-user, so it
	 *     must never be baked into a cached file;
	 *   - never a dependency of a script we're NOT absorbing (its code would
	 *     move into the bundle and could run after the dependent);
	 *   - WordPress core (/wp-includes/, /wp-admin/) is excluded.
	 * By default only FIRST-PARTY scripts (the UnysonPlus plugin + the active
	 * parent/child theme) are pre-selected; third-party scripts are listed but
	 * unchecked until the user opts them in.
	 * ------------------------------------------------------------------- */

	/**
	 * Returns the map of JS handles ever seen on the frontend: handle => src.
	 * Backend/core handles are stripped on the way out (self-healing list).
	 */
	public function get_known_js_handles() {
		// Memoized like get_known_css_handles() (invalidated by remember_js_handles).
		if ( null !== $this->known_js_cache ) {
			return $this->known_js_cache;
		}
		$known = get_option( self::KNOWN_JS_HANDLES_OPTION, array() );
		if ( ! is_array( $known ) ) {
			return $this->known_js_cache = array();
		}
		foreach ( $known as $handle => $src ) {
			if ( $this->is_backend_js_handle( $handle, $src ) || $this->src_is_dead( $src ) ) {
				unset( $known[ $handle ] );
			}
		}
		return $this->known_js_cache = $known;
	}

	/**
	 * Default checkbox state for the known JS handles: first-party scripts
	 * (UnysonPlus plugin + active themes) pre-checked, everything else off.
	 * Used by the settings page as the option's default `value`.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_js_values() {
		$defaults = array();
		foreach ( $this->get_known_js_handles() as $handle => $src ) {
			$defaults[ $handle ] = $this->is_first_party_src( $src );
		}
		return $defaults;
	}

	/**
	 * Whether a script is a backend/core asset that must never be listed or
	 * combined into the frontend bundle.
	 */
	private function is_backend_js_handle( $handle, $src ) {
		static $admin_handles = array( 'admin-bar', 'heartbeat' );
		if ( in_array( $handle, $admin_handles, true ) ) {
			return true;
		}
		if ( ! is_string( $src ) || $src === '' ) {
			return false;
		}
		static $needles = array(
			'/wp-admin/',
			'/wp-includes/',          // WordPress core (jQuery core, wp-* scripts)
			'/includes/option-types/',
		);
		foreach ( $needles as $needle ) {
			if ( stripos( $src, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * URL fragments that identify a FIRST-PARTY script (the UnysonPlus plugin
	 * and the active parent + child themes). Used for the default-checked set.
	 *
	 * @return string[]
	 */
	private function first_party_url_fragments() {
		$frags = array( '/plugins/unysonplus/' );

		if ( function_exists( 'get_template_directory_uri' ) ) {
			$frags[] = $this->url_path_only( get_template_directory_uri() );   // parent theme
			$frags[] = $this->url_path_only( get_stylesheet_directory_uri() ); // child theme
		}

		return array_values( array_filter( array_unique( $frags ) ) );
	}

	/** Returns the path portion of a URL with a trailing slash, e.g. /wp-content/themes/foo/. */
	private function url_path_only( $url ) {
		$path = (string) parse_url( (string) $url, PHP_URL_PATH );
		return $path === '' ? '' : trailingslashit( $path );
	}

	/** Whether a src belongs to first-party code (plugin / active themes). */
	private function is_first_party_src( $src ) {
		if ( ! is_string( $src ) || $src === '' ) {
			return false;
		}
		foreach ( $this->first_party_url_fragments() as $frag ) {
			if ( stripos( $src, $frag ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the user has the given JS handle selected for combining.
	 *
	 * The `checkboxes` option type only persists CHECKED handles, so once the
	 * user has saved the JS tab, an absent handle means "unchecked" - not
	 * "untouched". We therefore key off whether js_handles was ever saved:
	 *   - never saved  -> default ON for first-party scripts only;
	 *   - saved        -> the saved map is authoritative (present = checked,
	 *                     absent = unchecked), with NO first-party fallback.
	 *
	 * @param string $handle
	 * @param string $src
	 * @param array  $saved      Saved js_handles map (handle => true).
	 * @param bool   $has_saved  Whether js_handles exists in the settings store.
	 */
	private function js_handle_is_selected( $handle, $src, $saved, $has_saved ) {
		if ( $has_saved ) {
			return ! empty( $saved[ $handle ] );
		}
		return $this->is_first_party_src( $src );
	}

	/**
	 * Builds and enqueues the combined footer script.
	 *
	 * @internal
	 */
	public function enqueue_combined_js() {
		global $wp_scripts;

		if ( ! ( $wp_scripts instanceof WP_Scripts ) ) {
			return;
		}
		if ( ! $this->should_combine( 'js' ) ) {
			return;
		}

		// Resolve the dependency order of everything enqueued so far.
		$wp_scripts->all_deps( $wp_scripts->queue );
		$ordered = (array) $wp_scripts->to_do;
		if ( empty( $ordered ) ) {
			return;
		}

		// Read the saved selection straight from the settings store (not via
		// fw_get_db_ext_settings_option) so resolving it on the frontend can
		// never load settings-options.php / trigger a discovery request.
		$store     = $this->settings_store();
		$has_saved = isset( $store['js_handles'] ) && is_array( $store['js_handles'] );
		$saved     = $has_saved ? $store['js_handles'] : array();
		// js_minify defaults ON: unset -> minify; an explicit saved false wins.
		$minify    = array_key_exists( 'js_minify', $store ) ? ! empty( $store['js_minify'] ) : true;
		$defer     = ! empty( $store['js_defer'] );

		/**
		 * Filters the list of JS handles that must never be folded into the combined bundle.
		 *
		 * Force-exclude JS handles from combining (developer escape hatch).
		 *
		 * @param string[] $handles Handles to never fold into the bundle.
		 */
		$forced_excluded = (array) apply_filters( 'fw:ext:asset-optimizer:js_exclude_handles', array() );
		$forced_excluded = $forced_excluded ? array_flip( $forced_excluded ) : array();

		// Pass 1: candidate set (everything that individually qualifies).
		$absorb = array();
		foreach ( $ordered as $handle ) {
			if ( isset( $forced_excluded[ $handle ] ) ) {
				continue;
			}
			if ( $this->can_absorb_js( $handle, $wp_scripts, $saved, $has_saved ) ) {
				$absorb[ $handle ] = true;
			}
		}
		if ( count( $absorb ) < 2 ) {
			return;
		}

		// Pass 2: never absorb a handle that a NON-absorbed enqueued script
		// depends on - moving its code into the bundle could reorder it after
		// the dependent.
		$needed_by_others = array();
		foreach ( $ordered as $handle ) {
			if ( isset( $absorb[ $handle ] ) ) {
				continue;
			}
			$reg = isset( $wp_scripts->registered[ $handle ] ) ? $wp_scripts->registered[ $handle ] : null;
			if ( $reg && ! empty( $reg->deps ) ) {
				foreach ( $reg->deps as $d ) {
					$needed_by_others[ $d ] = true;
				}
			}
		}
		foreach ( array_keys( $absorb ) as $h ) {
			if ( isset( $needed_by_others[ $h ] ) ) {
				unset( $absorb[ $h ] );
			}
		}
		if ( count( $absorb ) < 2 ) {
			return;
		}

		// Pass 3: resolve to on-disk paths (in dependency order) and collect the
		// external deps the bundle still needs (e.g. jQuery core).
		$items      = array();
		$deps       = array();
		$seen_paths = array();
		foreach ( $ordered as $handle ) {
			if ( ! isset( $absorb[ $handle ] ) ) {
				continue;
			}
			$reg  = $wp_scripts->registered[ $handle ];
			$src  = preg_replace( '#\?.*$#', '', (string) $reg->src );
			$path = $this->url_to_path( $src );
			if ( ! $path || ! is_readable( $path ) ) {
				unset( $absorb[ $handle ] );
				continue;
			}
			// De-dupe by FILE: if two handles register the same script, include it
			// once; the duplicate stays in $absorb so its tag is still suppressed.
			$path_key = strtolower( wp_normalize_path( $path ) );
			if ( isset( $seen_paths[ $path_key ] ) ) {
				continue;
			}
			$seen_paths[ $path_key ] = true;

			$items[] = array( 'handle' => $handle, 'path' => $path );
			foreach ( (array) $reg->deps as $d ) {
				if ( ! isset( $absorb[ $d ] ) ) {
					$deps[ $d ] = true;
				}
			}
		}
		if ( count( $items ) < 2 ) {
			return;
		}

		$combined_url = $this->build_combined_js_file( $items, $minify );
		if ( ! $combined_url ) {
			return;
		}

		foreach ( $items as $it ) {
			$this->absorbed_js_handles[ $it['handle'] ] = true;
		}

		wp_register_script( self::COMBINED_JS_HANDLE, $combined_url, array_keys( $deps ), null, true );
		wp_enqueue_script( self::COMBINED_JS_HANDLE );

		// The bundle is self-contained and dependency-ordered, so deferring it is
		// safe. Opt-in; applied to its <script> tag via suppress_absorbed_js_tag().
		$this->defer_combined_js = $defer;
	}

	/**
	 * Whether a single script handle is safe to fold into the combined bundle.
	 */
	private function can_absorb_js( $handle, $wp_scripts, $saved, $has_saved ) {
		if ( $handle === self::COMBINED_JS_HANDLE ) {
			return false;
		}

		$reg = isset( $wp_scripts->registered[ $handle ] ) ? $wp_scripts->registered[ $handle ] : null;
		if ( ! $reg ) {
			return false;
		}

		$src = is_string( $reg->src ) ? preg_replace( '#\?.*$#', '', $reg->src ) : '';
		if ( $src === '' ) {
			return false; // alias/meta handle (e.g. 'jquery') - nothing to combine
		}

		// Core / backend, and external (non-local) scripts are out.
		if ( $this->is_backend_js_handle( $handle, $src ) ) {
			return false;
		}
		if ( ! $this->url_to_path( $src ) ) {
			return false; // remote/CDN or unresolvable
		}

		// Footer scripts only (group === 1).
		if ( (int) $wp_scripts->get_data( $handle, 'group' ) !== 1 ) {
			return false;
		}

		// No async/defer strategy.
		$strategy = isset( $reg->extra['strategy'] ) ? $reg->extra['strategy'] : '';
		if ( $strategy === 'async' || $strategy === 'defer' ) {
			return false;
		}

		// No inline or localized data (often per-request/per-user; unsafe to cache).
		if ( $wp_scripts->get_data( $handle, 'data' )
			|| $wp_scripts->get_data( $handle, 'before' )
			|| $wp_scripts->get_data( $handle, 'after' ) ) {
			return false;
		}

		// Finally, honor the user's selection (first-party checked by default).
		return $this->js_handle_is_selected( $handle, $src, $saved, $has_saved );
	}

	/**
	 * Hook target for script_loader_tag - blank an absorbed handle's <script>,
	 * and (when enabled) add `defer` to the combined bundle's own tag.
	 *
	 * @internal
	 */
	public function suppress_absorbed_js_tag( $tag, $handle ) {
		if ( isset( $this->absorbed_js_handles[ $handle ] ) ) {
			return '';
		}
		if ( $handle === self::COMBINED_JS_HANDLE && $this->defer_combined_js && strpos( $tag, ' defer' ) === false ) {
			$tag = preg_replace( '#<script(?=[\s>])#', '<script defer', $tag, 1 );
		}
		return $tag;
	}

	/**
	 * Shutdown sweep: remember every JS handle seen this request for the list.
	 *
	 * @internal
	 */
	public function remember_all_seen_js_handles() {
		global $wp_scripts;

		if ( ! ( $wp_scripts instanceof WP_Scripts ) ) {
			return;
		}

		// `done` is the actual print order; list it first so the stored map -
		// and the settings list - reflects frontend order. Stragglers after.
		$handles = array_unique( array_merge(
			array_values( (array) $wp_scripts->done ),
			array_values( (array) $wp_scripts->queue )
		) );

		$handles = array_values( array_filter( $handles, function ( $h ) {
			return $h !== self::COMBINED_JS_HANDLE;
		} ) );

		if ( empty( $handles ) ) {
			return;
		}

		$this->remember_js_handles( $wp_scripts, $handles );
	}

	/**
	 * Persists the JS handle => src map so the settings page can list them.
	 */
	private function remember_js_handles( $wp_scripts, $handles ) {
		$known = get_option( self::KNOWN_JS_HANDLES_OPTION, array() );
		if ( ! is_array( $known ) ) {
			$known = array();
		}
		$changed = false;

		// Purge any backend/core handles an older build may have stored.
		foreach ( $known as $handle => $src ) {
			if ( $this->is_backend_js_handle( $handle, $src ) ) {
				unset( $known[ $handle ] );
				$changed = true;
			}
		}

		foreach ( $handles as $handle ) {
			if ( $handle === self::COMBINED_JS_HANDLE ) {
				continue;
			}
			$reg = isset( $wp_scripts->registered[ $handle ] ) ? $wp_scripts->registered[ $handle ] : null;
			$src = $reg && ! empty( $reg->src ) ? (string) $reg->src : '';

			if ( $src !== '' ) {
				$src = preg_replace( '#\?.*$#', '', $src );
			}

			// Skip alias handles (no src) and backend/core scripts.
			if ( $src === '' || $this->is_backend_js_handle( $handle, $src ) ) {
				continue;
			}

			if ( ! array_key_exists( $handle, $known ) || $known[ $handle ] !== $src ) {
				$known[ $handle ] = $src;
				$changed          = true;
			}
		}

		if ( $changed ) {
			// autoload = false (see remember_css_handles): frontend-only data.
			update_option( self::KNOWN_JS_HANDLES_OPTION, $known, false );
			wp_cache_delete( self::KNOWN_JS_HANDLES_OPTION, 'options' );
			$this->known_js_cache = null; // invalidate the per-request memo
		}
	}

	/**
	 * Builds (or reuses) the combined JS file. Returns its public URL.
	 *
	 * Only static file bodies are concatenated (no inline/localized data ever
	 * reaches here), so the result is safe to cache by content fingerprint.
	 * Segments are joined with ";\n" so automatic-semicolon-insertion quirks at
	 * a file boundary can't fuse two statements.
	 */
	private function build_combined_js_file( $items, $minify = false ) {
		$paths    = $this->combined_paths();
		$dir      = $paths['path'];
		$url_base = $paths['url'];
		if ( $dir === '' ) {
			return false;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$this->ensure_cache_headers( $dir );

		// The format token participates in the hash, so toggling minification
		// invalidates previously cached bundles and forces a regeneration.
		$signature = array( $minify ? 'fmt:js2-min' : 'fmt:js2' );
		foreach ( $items as $item ) {
			$signature[] = $item['handle'] . '|' . $item['path'] . '|' . filemtime( $item['path'] );
		}
		$hash = substr( md5( implode( "\n", $signature ) ), 0, 12 );

		$filename = 'combined-' . $hash . '.js';
		$filepath = $dir . '/' . $filename;
		$fileurl  = $url_base . '/' . $filename;

		if ( file_exists( $filepath ) && filesize( $filepath ) > 0 ) {
			return $fileurl;
		}

		$output = '';
		foreach ( $items as $item ) {
			$js = @file_get_contents( $item['path'] );
			if ( $js === false ) {
				continue;
			}
			$js = str_replace( "\xEF\xBB\xBF", '', $js );

			// Drop the trailing source-map reference - its relative path would
			// 404 from the combined file's location and spam devtools. Covers
			// both the line (//# / //@) and block (/*# ... */) forms.
			$js = preg_replace( '~^[ \t]*//[#@]\s*sourceMappingURL=.*$~mi', '', $js );
			$js = preg_replace( '~/\*[#@]\s*sourceMappingURL=.*?\*/~i', '', $js );

			$output .= "/* ==== " . $item['handle'] . " ==== */\n" . rtrim( $js ) . "\n;\n";
		}

		if ( $output === '' ) {
			return false;
		}

		if ( $minify ) {
			$output = FW_AO_Minifier::js( $output );
		}

		if ( file_put_contents( $filepath, $output, LOCK_EX ) === false ) {
			return false;
		}

		$this->maybe_gc( $dir, $filename );

		return $fileurl;
	}


	/**
	 * Maps a stylesheet URL back to an on-disk path. Returns false for
	 * remote URLs or URLs we can't resolve.
	 */
	private function url_to_path( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return false;
		}

		if ( strpos( $url, '//' ) === 0 ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		$url = preg_replace( '#[?\#].*$#', '', $url );

		// Map each public URL root to its on-disk directory and resolve against
		// the matching one. This is correct on sub-directory and moved-wp-content
		// installs, unlike naively gluing the URL path onto ABSPATH (which
		// double-counts the sub-directory, e.g. /demos + /demos/wp-content/...).
		$strip_scheme = static function ( $u ) {
			return preg_replace( '#^https?:#i', '', (string) $u );
		};
		$url_n = $strip_scheme( $url );

		$maps = array();
		if ( defined( 'WP_PLUGIN_URL' ) && defined( 'WP_PLUGIN_DIR' ) ) {
			$maps[] = array( WP_PLUGIN_URL, WP_PLUGIN_DIR );
		}
		if ( defined( 'WPMU_PLUGIN_URL' ) && defined( 'WPMU_PLUGIN_DIR' ) ) {
			$maps[] = array( WPMU_PLUGIN_URL, WPMU_PLUGIN_DIR );
		}
		$maps[] = array( content_url(), WP_CONTENT_DIR );
		$maps[] = array( includes_url(), trailingslashit( ABSPATH ) . WPINC );
		$maps[] = array( site_url(), untrailingslashit( ABSPATH ) );

		foreach ( $maps as $map ) {
			$base_n = rtrim( $strip_scheme( $map[0] ), '/' );
			if ( $base_n === '' ) {
				continue;
			}
			if ( strpos( $url_n, $base_n ) === 0 ) {
				$rel = substr( $url_n, strlen( $base_n ) );
				if ( $rel === '' ) {
					continue;
				}
				$candidate = rtrim( $map[1], '/\\' ) . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
				if ( file_exists( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return false;
	}

	/** Whether a src points at this site (same host, or host-less/relative). */
	private function is_local_src( $src ) {
		if ( strpos( $src, '//' ) === 0 ) {
			$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		$host = parse_url( $src, PHP_URL_HOST );
		if ( ! $host ) {
			return true; // relative / root-relative
		}
		$site_host = parse_url( site_url(), PHP_URL_HOST );
		return $site_host && strcasecmp( $host, $site_host ) === 0;
	}

	/**
	 * Builds (or reuses) the combined CSS file. Returns its public URL.
	 */
	private function build_combined_css_file( $items ) {
		$paths    = $this->combined_paths();
		$dir      = $paths['path'];
		$url_base = $paths['url'];
		if ( $dir === '' ) {
			return false;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$this->ensure_cache_headers( $dir );

		// 'fmt' token participates in the hash so changing the output format
		// (e.g. enabling minification) invalidates previously cached files and
		// forces a one-time regeneration.
		$signature = array( 'fmt:min2' );
		foreach ( $items as $item ) {
			$signature[] = $item['handle'] . '|' . $item['path'] . '|' . filemtime( $item['path'] );
		}
		$hash = substr( md5( implode( "\n", $signature ) ), 0, 12 );

		$filename = 'combined-' . $hash . '.css';
		$filepath = $dir . '/' . $filename;
		$fileurl  = $url_base . '/' . $filename;

		if ( file_exists( $filepath ) && filesize( $filepath ) > 0 ) {
			return $fileurl;
		}

		$imports = array();
		$body    = '';

		foreach ( $items as $item ) {
			$css = @file_get_contents( $item['path'] );
			if ( $css === false ) {
				continue;
			}

			$css = str_replace( "\xEF\xBB\xBF", '', $css );

			// Strip comments PER FILE with a string-aware scanner BEFORE merging.
			// This is critical for robustness: a malformed comment in one source
			// stylesheet (a stray `*/`, or an unclosed `/*` - e.g. a third-party
			// plugin's CSS) must never corrupt the combined bundle. Stripping per
			// file contains any imbalance to that file (an unclosed `/*` drops only
			// the rest of THAT file, never the following stylesheets), so one bad
			// stylesheet can't abort parsing for the entire merged file.
			$css = FW_AO_Minifier::strip_css_comments( $css );

			// Contain a source that ends with an OPEN string or brace (e.g. a
			// corrupt `url("data:…` value with no closing quote). Without this the
			// dangling quote makes the final css() string scanner swallow across
			// the file boundary and drop every following block (a real bug: a dark
			// site's `:root{--site-bg-color:…}` vanished this way). Balanced files
			// are returned byte-for-byte unchanged, so valid combines are identical.
			$css = FW_AO_Minifier::close_unbalanced( $css );

			$css = preg_replace( '#@charset\s+[^;]+;\s*#i', '', $css );

			$css = $this->rewrite_urls( $css, $item['src'] );

			$css = preg_replace_callback(
				'#@import\s+[^;]+;#i',
				function ( $m ) use ( &$imports ) {
					$imports[] = $m[0];
					return '';
				},
				$css
			);

			$body .= "\n/* ==== " . $item['handle'] . " ==== */\n" . $css . "\n";
		}

		$output = "@charset \"UTF-8\";\n";
		if ( ! empty( $imports ) ) {
			$output .= implode( "\n", array_unique( $imports ) ) . "\n";
		}
		$output .= $body;

		$output = FW_AO_Minifier::css( $output );

		if ( file_put_contents( $filepath, $output, LOCK_EX ) === false ) {
			return false;
		}

		$this->maybe_gc( $dir, $filename );

		return $fileurl;
	}


	/**
	 * Rewrites relative url(...) references to be absolute against the
	 * source stylesheet's URL so they still resolve after relocation.
	 */
	private function rewrite_urls( $css, $source_url ) {
		$source_dir_url = preg_replace( '#/[^/]*$#', '/', $source_url );

		return preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			function ( $m ) use ( $source_dir_url ) {
				$quote = $m[1];
				$url   = trim( $m[2] );

				if ( $url === '' ) {
					return $m[0];
				}
				if ( preg_match( '#^(data:|https?:|//|/|\#)#i', $url ) ) {
					return $m[0];
				}

				$abs = $this->resolve_relative_url( $source_dir_url, $url );
				return 'url(' . $quote . $abs . $quote . ')';
			},
			$css
		);
	}

	private function resolve_relative_url( $base, $rel ) {
		$tail     = '';
		$tail_pos = strcspn( $rel, '?#' );
		if ( $tail_pos < strlen( $rel ) ) {
			$tail = substr( $rel, $tail_pos );
			$rel  = substr( $rel, 0, $tail_pos );
		}

		$parts  = parse_url( $base );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';

		$segments = explode( '/', $path . $rel );
		$resolved = array();
		foreach ( $segments as $seg ) {
			if ( $seg === '..' ) {
				array_pop( $resolved );
			} elseif ( $seg !== '.' && $seg !== '' ) {
				$resolved[] = $seg;
			}
		}

		return $scheme . $host . $port . '/' . implode( '/', $resolved ) . $tail;
	}

	/**
	 * Throttled entry point for the age-based GC. cleanup_old_files() globs the
	 * ENTIRE cache directory (O(files)) and stats every file, so running it on
	 * every single cache write is wasteful once a per-page cache holds thousands
	 * of files. Run it only occasionally (~1 in 50 writes). The file just written
	 * is always preserved (cleanup_old_files skips $keep), and the 7-day TTL means
	 * skipping most sweeps merely delays reclaiming already-stale files - never
	 * the current one - so the cache stays bounded without a per-write scan.
	 */
	private function maybe_gc( $dir, $keep ) {
		$roll = function_exists( 'wp_rand' ) ? wp_rand( 1, 50 ) : mt_rand( 1, 50 );
		if ( 1 === $roll ) {
			$this->cleanup_old_files( $dir, $keep );
		}
	}

	private function cleanup_old_files( $dir, $keep ) {
		$files = glob( $dir . '/{combined,purged}-*.{css,js}', GLOB_BRACE );
		if ( ! $files ) {
			return;
		}
		$cutoff = time() - 7 * DAY_IN_SECONDS;
		foreach ( $files as $f ) {
			if ( basename( $f ) === $keep ) {
				continue;
			}
			if ( filemtime( $f ) < $cutoff ) {
				@unlink( $f );
			}
		}
	}
}
