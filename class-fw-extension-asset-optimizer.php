<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

class FW_Extension_Asset_Optimizer extends FW_Extension {

	const KNOWN_CSS_HANDLES_OPTION = 'fw_ext_asset_optimizer_known_css_handles';
	const KNOWN_JS_HANDLES_OPTION  = 'fw_ext_asset_optimizer_known_js_handles';
	const CSS_EXCLUDED_OPTION      = 'fw_ext_asset_optimizer_css_excluded';
	const COMBINED_CSS_HANDLE      = 'unysonplus-asset-optimizer-css';
	const COMBINED_JS_HANDLE       = 'unysonplus-asset-optimizer-js';
	const CACHE_SUBDIR             = 'unysonplus-asset-optimizer';
	const DISCOVERY_QUERY_ARG      = 'fw_asset_optimizer_discover';
	const MIGRATION_OPTION         = 'fw_ext_asset_optimizer_migrated_v1';
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

		// Maintenance hooks (admin / cron context). Auto-purge the cache when the
		// active asset set can change, plus the settings-page action buttons.
		add_action( 'switch_theme', array( $this, 'purge_all' ) );
		add_action( 'activated_plugin', array( $this, 'purge_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'purge_all' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge_all' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_CACHE, array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_post_' . self::ACTION_RESCAN, array( $this, 'handle_rescan' ) );

		if ( is_admin() ) {
			// Dedicated settings page under the Unyson+ menu (in addition to the
			// Extensions-manager "Settings" link). Registered before the early
			// return below, which only skips the frontend combining hooks.
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
			add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );

			// When the Extensions-manager settings form saves our options, recompute
			// the persisted CSS-exclusion list (the dedicated settings page does the
			// same in _maybe_save_settings). Both entry points write to the same store,
			// so both must keep the derived exclusion list in sync.
			add_action( 'fw_extension_settings_form_saved:' . $this->get_name(), array( $this, '_after_manager_settings_saved' ) );
		}

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_combined_css' ), 9999 );

		// Combine eligible footer scripts. Runs late on wp_enqueue_scripts so the
		// bulk of registrations/enqueues are in place; works off the live,
		// dependency-resolved script list (not the persisted map) because JS
		// execution order is significant.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_combined_js' ), 9999 );

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

		// Force a fresh render when explicitly discovering from the admin.
		if ( isset( $_GET[ self::DISCOVERY_QUERY_ARG ] ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
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
		.fw-ext-asset-optimizer .nav-tab{border-radius:.25rem .25rem 0 0}
		.fw-ext-asset-optimizer .fw-ao-panel{display:none}
		.fw-ext-asset-optimizer .fw-ao-panel.is-active{display:block}
		.fw-ext-asset-optimizer .fw-ao-url{color:#a7aaad;transition:color .12s ease}
		.fw-ext-asset-optimizer label:hover .fw-ao-url{color:#50575e}
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
		} )();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Cache maintenance (purge + the settings-page action buttons)
	 * ------------------------------------------------------------------- */

	/** Absolute path to the cache directory, or '' if uploads is unavailable. */
	private function cache_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		return trailingslashit( $uploads['basedir'] ) . self::CACHE_SUBDIR;
	}

	/** Deletes every combined CSS/JS file in the cache directory. */
	private function purge_cache_files() {
		$dir = $this->cache_dir();
		if ( $dir === '' ) {
			return;
		}
		$files = glob( $dir . '/combined-*.{css,js}', GLOB_BRACE );
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
		$files = glob( $dir . '/combined-*.{css,js}', GLOB_BRACE );
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
			update_option( self::KNOWN_CSS_HANDLES_OPTION, $old_known, true );
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
	 * Returns the map of every CSS handle ever seen on the frontend:
	 *   array( handle => src )
	 *
	 * Backend/admin-only stylesheets are stripped on the way out, so even a
	 * list captured by an older build (which could pick up admin-bar styles or
	 * builder option-type CSS) is self-healing - the settings page and the
	 * combiner both go through here.
	 */
	public function get_known_css_handles() {
		$known = get_option( self::KNOWN_CSS_HANDLES_OPTION, array() );
		if ( ! is_array( $known ) ) {
			return array();
		}
		foreach ( $known as $handle => $src ) {
			if ( $this->is_backend_css_handle( $handle, $src ) || $this->src_is_dead( $src ) ) {
				unset( $known[ $handle ] );
			}
		}
		return $known;
	}

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
		static $needles = array(
			'/wp-admin/',
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
	 * Fires one internal HTTP request to the home URL so the frontend hooks
	 * run and populate BOTH the known-CSS and known-JS handle maps. The single
	 * request triggers both shutdown sweeps, so callers don't need two hits.
	 */
	public function discover_handles() {
		$url = add_query_arg( self::DISCOVERY_QUERY_ARG, time(), home_url( '/' ) );

		wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'headers'   => array(
					'Cache-Control' => 'no-cache, no-store, max-age=0',
					'Pragma'        => 'no-cache',
				),
				'cookies'   => array(),
			)
		);

		wp_cache_delete( self::KNOWN_CSS_HANDLES_OPTION, 'options' );
		wp_cache_delete( self::KNOWN_JS_HANDLES_OPTION, 'options' );
	}

	/**
	 * Back-compat wrapper: discover and return the CSS handle map.
	 */
	public function discover_css_handles() {
		$this->discover_handles();
		return $this->get_known_css_handles();
	}

	/**
	 * Discover and return the JS handle map.
	 */
	public function discover_js_handles() {
		$this->discover_handles();
		return $this->get_known_js_handles();
	}

	/**
	 * Builds and enqueues the combined stylesheet.
	 *
	 * Source list is the remembered handle map filtered by the user's
	 * selections, so it's stable regardless of when individual handles
	 * actually get enqueued during this request.
	 *
	 * @internal
	 */
	public function enqueue_combined_css() {
		$known = $this->get_known_css_handles();
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

		$items = array();
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

		wp_register_style( self::COMBINED_CSS_HANDLE, $combined_url, array(), null );
		wp_enqueue_style( self::COMBINED_CSS_HANDLE );
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
			$handles = (array) apply_filters(
				'fw:ext:asset-optimizer:preset_css_handles',
				array( 'unysonplus-presets' )
			);
			$handles = array_flip( $handles );
		}
		return isset( $handles[ $handle ] );
	}

	/**
	 * Cascade-authority bucket for a CSS handle (higher = wins):
	 *   1 = anything else (core, plugins, framework, shortcodes),
	 *   2 = the active PARENT theme,
	 *   3 = the UnysonPlus design presets (handle `unysonplus-presets`),
	 *   4 = the active CHILD theme (top authority).
	 */
	private function css_theme_bucket( $handle, $src ) {
		$child = $this->url_path_only( get_stylesheet_directory_uri() ); // child (or the theme itself)

		if ( is_string( $src ) && $src !== '' && $child !== '' && stripos( $src, $child ) !== false ) {
			return 4;
		}
		if ( $this->is_preset_css_handle( $handle ) ) {
			return 3;
		}
		$parent = $this->url_path_only( get_template_directory_uri() ); // parent
		if ( is_string( $src ) && $src !== '' && $parent !== '' && $parent !== $child && stripos( $src, $parent ) !== false ) {
			return 2;
		}
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
		$buckets = array( 1 => array(), 2 => array(), 3 => array(), 4 => array() );
		foreach ( $handles as $handle ) {
			$src = isset( $src_map[ $handle ] ) ? $src_map[ $handle ] : '';
			$buckets[ $this->css_theme_bucket( $handle, $src ) ][] = $handle;
		}
		return array_merge( $buckets[1], $buckets[2], $buckets[3], $buckets[4] );
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
			update_option( self::KNOWN_CSS_HANDLES_OPTION, $known, true );
			wp_cache_delete( self::KNOWN_CSS_HANDLES_OPTION, 'options' );
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
		$known = get_option( self::KNOWN_JS_HANDLES_OPTION, array() );
		if ( ! is_array( $known ) ) {
			return array();
		}
		foreach ( $known as $handle => $src ) {
			if ( $this->is_backend_js_handle( $handle, $src ) || $this->src_is_dead( $src ) ) {
				unset( $known[ $handle ] );
			}
		}
		return $known;
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

		// Resolve the dependency order of everything enqueued so far.
		$wp_scripts->all_deps( $wp_scripts->queue );
		$ordered = (array) $wp_scripts->to_do;
		if ( empty( $ordered ) ) {
			return;
		}

		// Read the saved selection straight from the settings store (not via
		// fw_get_db_ext_settings_option) so resolving it on the frontend can
		// never load settings-options.php / trigger a discovery request.
		$store     = get_option( 'fw_ext_settings_options:' . $this->get_name(), array() );
		$has_saved = is_array( $store ) && isset( $store['js_handles'] ) && is_array( $store['js_handles'] );
		$saved     = $has_saved ? $store['js_handles'] : array();
		$minify    = ! empty( $store['js_minify'] );
		$defer     = ! empty( $store['js_defer'] );

		/**
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
		$items = array();
		$deps  = array();
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
			update_option( self::KNOWN_JS_HANDLES_OPTION, $known, true );
			wp_cache_delete( self::KNOWN_JS_HANDLES_OPTION, 'options' );
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
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$dir      = trailingslashit( $uploads['basedir'] ) . self::CACHE_SUBDIR;
		$url_base = trailingslashit( $uploads['baseurl'] ) . self::CACHE_SUBDIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

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
			$output = $this->minify_js( $output );
		}

		if ( file_put_contents( $filepath, $output, LOCK_EX ) === false ) {
			return false;
		}

		$this->cleanup_old_files( $dir, $filename );

		return $fileurl;
	}

	/**
	 * Conservative, opt-in JS minifier.
	 *
	 * A single-pass character scanner that is aware of strings ('...', "..."),
	 * template literals (`...`, passed through verbatim including ${...}), regex
	 * literals, and line/block comments. It only does the SAFE transforms:
	 *   - strip comments;
	 *   - collapse runs of horizontal whitespace to a single space;
	 *   - collapse blank lines and drop indentation.
	 * Crucially it PRESERVES single newlines, so automatic-semicolon-insertion
	 * can't change the program's meaning (e.g. a bare `return\n5`). It never
	 * rewrites tokens, so it can't fuse identifiers or mangle operators.
	 *
	 * Regex-vs-division is resolved with the standard heuristic (the previous
	 * significant char / keyword). When in doubt it treats `/` as division and
	 * simply emits it verbatim - which is lossless, just less compressed.
	 */
	private function minify_js( $js ) {
		$len      = strlen( $js );
		$out      = '';
		$i        = 0;
		$prev_sig = '';   // last significant char emitted
		$word     = '';   // current identifier/keyword run (for regex heuristic)

		$append = static function ( $s ) use ( &$out ) {
			$out .= $s;
		};

		while ( $i < $len ) {
			$c  = $js[ $i ];
			$c2 = $i + 1 < $len ? $js[ $i + 1 ] : '';

			// Line comment - drop to end of line (newline handled next loop).
			if ( $c === '/' && $c2 === '/' ) {
				$i += 2;
				while ( $i < $len && $js[ $i ] !== "\n" && $js[ $i ] !== "\r" ) {
					$i++;
				}
				continue;
			}

			// Block comment - drop entirely.
			if ( $c === '/' && $c2 === '*' ) {
				$i += 2;
				while ( $i < $len && ! ( $js[ $i ] === '*' && ( $i + 1 < $len ) && $js[ $i + 1 ] === '/' ) ) {
					$i++;
				}
				$i += 2;
				continue;
			}

			// String literal.
			if ( $c === '"' || $c === "'" ) {
				$q = $c;
				$append( $c );
				$i++;
				while ( $i < $len ) {
					$ch = $js[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$append( $ch . $js[ $i + 1 ] );
						$i += 2;
						continue;
					}
					$append( $ch );
					$i++;
					if ( $ch === $q ) {
						break;
					}
				}
				$prev_sig = $q;
				$word     = '';
				continue;
			}

			// Template literal - pass through verbatim (incl. ${...}).
			if ( $c === '`' ) {
				$append( $c );
				$i++;
				while ( $i < $len ) {
					$ch = $js[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$append( $ch . $js[ $i + 1 ] );
						$i += 2;
						continue;
					}
					$append( $ch );
					$i++;
					if ( $ch === '`' ) {
						break;
					}
				}
				$prev_sig = '`';
				$word     = '';
				continue;
			}

			// Regex literal (only when a regex is grammatically allowed here).
			if ( $c === '/' && $this->js_regex_allowed( $prev_sig, $word ) ) {
				$append( $c );
				$i++;
				$in_class = false;
				while ( $i < $len ) {
					$ch = $js[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$append( $ch . $js[ $i + 1 ] );
						$i += 2;
						continue;
					}
					$append( $ch );
					$i++;
					if ( $ch === '[' ) {
						$in_class = true;
					} elseif ( $ch === ']' ) {
						$in_class = false;
					} elseif ( $ch === '/' && ! $in_class ) {
						break;
					} elseif ( $ch === "\n" || $ch === "\r" ) {
						break; // malformed - bail safely
					}
				}
				while ( $i < $len && ctype_alpha( $js[ $i ] ) ) { // flags
					$append( $js[ $i ] );
					$i++;
				}
				$prev_sig = '/';
				$word     = '';
				continue;
			}

			// Horizontal whitespace - collapse to one space (never before a newline).
			if ( $c === ' ' || $c === "\t" || $c === "\f" || $c === "\v" ) {
				while ( $i < $len && ( $js[ $i ] === ' ' || $js[ $i ] === "\t" || $js[ $i ] === "\f" || $js[ $i ] === "\v" ) ) {
					$i++;
				}
				$last = $out === '' ? '' : substr( $out, -1 );
				if ( $last !== '' && $last !== "\n" && $last !== ' ' ) {
					$append( ' ' );
				}
				continue;
			}

			// Newlines - collapse runs (and adjacent ws) to a single \n.
			if ( $c === "\n" || $c === "\r" ) {
				while ( $i < $len && ( $js[ $i ] === "\n" || $js[ $i ] === "\r" || $js[ $i ] === ' ' || $js[ $i ] === "\t" ) ) {
					$i++;
				}
				$out = rtrim( $out, " \t" );
				if ( $out !== '' && substr( $out, -1 ) !== "\n" ) {
					$append( "\n" );
				}
				continue;
			}

			// Significant char.
			$append( $c );
			$prev_sig = $c;
			if ( ctype_alnum( $c ) || $c === '_' || $c === '$' ) {
				$word .= $c;
			} else {
				$word = '';
			}
			$i++;
		}

		return trim( $out );
	}

	/**
	 * Whether a `/` at this point begins a regex literal (vs. division), using
	 * the standard previous-significant-token heuristic.
	 *
	 * @param string $prev_sig Last significant char emitted.
	 * @param string $word     Trailing identifier/keyword run, if any.
	 */
	private function js_regex_allowed( $prev_sig, $word ) {
		if ( $prev_sig === '' ) {
			return true;
		}
		if ( strpos( '(,=:[!&|?{};+-*%<>~^', $prev_sig ) !== false ) {
			return true;
		}
		if ( ctype_alnum( $prev_sig ) || $prev_sig === '_' || $prev_sig === '$' ) {
			static $keywords = array(
				'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete',
				'void', 'throw', 'else', 'do', 'yield', 'await', 'case',
			);
			return in_array( $word, $keywords, true );
		}
		return false;
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
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$dir      = trailingslashit( $uploads['basedir'] ) . self::CACHE_SUBDIR;
		$url_base = trailingslashit( $uploads['baseurl'] ) . self::CACHE_SUBDIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// 'fmt' token participates in the hash so changing the output format
		// (e.g. enabling minification) invalidates previously cached files and
		// forces a one-time regeneration.
		$signature = array( 'fmt:min1' );
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

		$output = $this->minify_css( $output );

		if ( file_put_contents( $filepath, $output, LOCK_EX ) === false ) {
			return false;
		}

		$this->cleanup_old_files( $dir, $filename );

		return $fileurl;
	}

	/**
	 * Lightweight CSS minifier for the combined output.
	 *
	 * Strips comments and collapses non-significant whitespace. Deliberately
	 * conservative: it leaves spacing around value operators (`+ - * /` inside
	 * calc(), combinators) and colons untouched so declarations and selectors
	 * keep their meaning - the bulk of the savings comes from dropping the
	 * newlines/indentation and comments between the merged stylesheets.
	 */
	private function minify_css( $css ) {
		// Remove CSS comments (not the rare ones that may live inside strings;
		// real framework CSS doesn't carry those).
		$css = preg_replace( '#/\*[\s\S]*?\*/#', '', $css );

		// Collapse every run of whitespace (incl. newlines/tabs) to one space.
		$css = preg_replace( '#\s+#', ' ', $css );

		// Drop whitespace around purely structural characters. `>` is the only
		// safe combinator to tighten (`+ ~` are skipped to avoid touching
		// calc()/value math).
		$css = preg_replace( '#\s*([{};,>])\s*#', '$1', $css );

		// Remove the now-redundant final semicolon before a closing brace.
		$css = str_replace( ';}', '}', $css );

		return trim( $css );
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

	private function cleanup_old_files( $dir, $keep ) {
		$files = glob( $dir . '/combined-*.{css,js}', GLOB_BRACE );
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
