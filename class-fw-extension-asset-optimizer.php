<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

class FW_Extension_Asset_Optimizer extends FW_Extension {

	const KNOWN_CSS_HANDLES_OPTION = 'fw_ext_asset_optimizer_known_css_handles';
	const COMBINED_CSS_HANDLE      = 'unysonplus-asset-optimizer-css';
	const CACHE_SUBDIR             = 'unysonplus-asset-optimizer';
	const DISCOVERY_QUERY_ARG      = 'fw_asset_optimizer_discover';
	const MIGRATION_OPTION         = 'fw_ext_asset_optimizer_migrated_v1';

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
	 * @internal
	 */
	public function _init() {
		$this->maybe_migrate();

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_combined_css' ), 9999 );

		// Final safety net: at shutdown, remember every handle that was enqueued
		// or printed during this request - including handles enqueued late by
		// shortcodes during content rendering.
		add_action( 'shutdown', array( $this, 'remember_all_seen_css_handles' ), 0 );

		// Suppress any <link> tag for a handle we've absorbed into the combined
		// file - regardless of whether it was enqueued before or after our hook,
		// printed in head or footer, or re-enqueued during shortcode rendering.
		add_filter( 'style_loader_tag', array( $this, 'suppress_absorbed_css_tag' ), 0, 2 );

		// Force a fresh render when explicitly discovering from the admin.
		if ( isset( $_GET[ self::DISCOVERY_QUERY_ARG ] ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
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
	 */
	public function get_known_css_handles() {
		$known = get_option( self::KNOWN_CSS_HANDLES_OPTION, array() );
		return is_array( $known ) ? $known : array();
	}

	/**
	 * Fires an internal HTTP request to the home URL so the frontend hooks
	 * run and populate the known-handles option. Returns the up-to-date list.
	 */
	public function discover_css_handles() {
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

		return $this->get_known_css_handles();
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

		$excluded = $this->get_excluded_css_handles();

		$items = array();
		foreach ( $known as $handle => $src ) {
			if ( $handle === self::COMBINED_CSS_HANDLE ) {
				continue;
			}
			if ( in_array( $handle, $excluded, true ) ) {
				continue;
			}
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

		$handles = array_unique( array_merge(
			array_values( (array) $wp_styles->queue ),
			array_values( (array) $wp_styles->done )
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
		$known   = $this->get_known_css_handles();
		$changed = false;

		foreach ( $handles as $handle ) {
			if ( $handle === self::COMBINED_CSS_HANDLE ) {
				continue;
			}
			$reg = isset( $wp_styles->registered[ $handle ] ) ? $wp_styles->registered[ $handle ] : null;
			$src = $reg && ! empty( $reg->src ) ? (string) $reg->src : '';

			if ( $src !== '' ) {
				$src = preg_replace( '#\?.*$#', '', $src );
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
	 * Reads saved settings and returns handles the user has explicitly excluded.
	 * Missing or unsaved handles default to "include" (checked by default).
	 */
	private function get_excluded_css_handles() {
		$value = fw_get_db_ext_settings_option( $this->get_name(), 'css_handles' );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$excluded = array();
		foreach ( $value as $handle => $included ) {
			if ( empty( $included ) ) {
				$excluded[] = $handle;
			}
		}
		return $excluded;
	}

	/**
	 * Maps a stylesheet URL back to an on-disk path. Returns false for
	 * remote URLs or URLs we can't resolve.
	 */
	private function url_to_path( $url ) {
		if ( strpos( $url, '//' ) === 0 ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		$url_parts  = parse_url( $url );
		$site_parts = parse_url( site_url() );
		$abspath    = untrailingslashit( ABSPATH );

		$path = isset( $url_parts['path'] ) ? $url_parts['path'] : '';
		if ( $path === '' ) {
			return false;
		}

		if ( ! empty( $url_parts['host'] ) ) {
			if ( ! isset( $site_parts['host'] ) || strcasecmp( $url_parts['host'], $site_parts['host'] ) !== 0 ) {
				return false;
			}
		}

		$candidate = $abspath . $path;
		return file_exists( $candidate ) ? $candidate : false;
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

		$signature = array();
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

		if ( file_put_contents( $filepath, $output, LOCK_EX ) === false ) {
			return false;
		}

		$this->cleanup_old_files( $dir, $filename );

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

	private function cleanup_old_files( $dir, $keep ) {
		$files = glob( $dir . '/combined-*.css' );
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
