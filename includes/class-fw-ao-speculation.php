<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'FW_AO_Speculation' ) ) :

	/**
	 * Instant navigation: the browser's Speculation Rules API.
	 *
	 * Clicking a link on a WordPress site costs a full navigation - a request, a PHP
	 * render (unless a page cache answers it), then the browser tearing down the
	 * document and parsing every stylesheet and script again. Single-page apps feel
	 * instant mostly because they never pay that, and because their routers fetch the
	 * next page before the click. Speculative loading is how a normal, server-rendered
	 * site gets the second half of that: the browser fetches - or, in 'prerender' mode,
	 * fully renders - a likely next page in the background, so the click lands on
	 * something already in memory.
	 *
	 * WordPress ships this in core (6.8+) and does the hard parts: it builds the rules,
	 * disables itself for logged-in users and for sites without pretty permalinks,
	 * excludes wp-admin and every URL carrying a query string, and honours rel=nofollow
	 * plus the .no-prefetch / .no-prerender opt-out classes. So this class deliberately
	 * does NOT reimplement any of that. It adds the two things core leaves to plugins:
	 *
	 * 1. The choice of mode and eagerness, as a setting rather than a code snippet.
	 * 2. Exclusions for pages where speculating is actively harmful. Core excludes
	 *    query-string URLs, which covers action links like ?add-to-cart=. It does not
	 *    cover a pretty permalink such as /cart/ or /my-account/orders/, and WooCommerce
	 *    does not exclude them either. Fetching those starts a session and can skew
	 *    analytics; prerendering them also runs their JavaScript.
	 *
	 * Browser support is Chromium-only today. Everywhere else the rules are ignored and
	 * navigation is unchanged, so this is safe to leave on.
	 */
	class FW_AO_Speculation {

		/** Mode/eagerness for this request, as resolved from the settings. */
		private static $config = array();

		/** Extra path patterns from the settings textarea. */
		private static $custom_excludes = array();

		/** Whether to add the built-in commerce/account exclusions. */
		private static $skip_sensitive = true;

		/**
		 * @param string $mode      'auto' | 'prefetch' | 'prerender' | 'off'
		 * @param string $eagerness 'auto' | 'conservative' | 'moderate' | 'eager'
		 * @param string $excludes  One path pattern per line.
		 * @param bool   $skip_sensitive
		 */
		public static function init( $mode, $eagerness, $excludes = '', $skip_sensitive = true ) {
			// Nothing to do on a WordPress without the API.
			if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
				return;
			}

			self::$config = array(
				'mode'      => (string) $mode,
				'eagerness' => (string) $eagerness,
			);
			self::$custom_excludes = self::parse_excludes( $excludes );
			self::$skip_sensitive  = (bool) $skip_sensitive;

			// 'auto' for both means core's own behaviour is already what is wanted, so
			// we stay out of the configuration filter entirely.
			if ( 'auto' !== self::$config['mode'] || 'auto' !== self::$config['eagerness'] ) {
				add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'filter_configuration' ) );
			}
			add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'filter_exclude_paths' ), 10, 2 );
		}

		/**
		 * One pattern per line, '#' comments allowed, each normalised to a leading
		 * slash. Core prefixes these with the site's subdirectory itself.
		 */
		private static function parse_excludes( $raw ) {
			$out = array();
			foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || '#' === $line[0] ) {
					continue;
				}
				// A full URL is accepted and reduced to a site-root-relative path, since
				// that is what people copy out of a browser. Core would strip a
				// duplicated subdirectory itself, but normalising here keeps every
				// entry in this list in the same shape.
				if ( preg_match( '#^https?://#i', $line ) ) {
					$path = self::relative_path( $line );
					$line = '' === $path ? '/' : $path;
				}
				if ( '/' !== $line[0] ) {
					$line = '/' . $line;
				}
				$out[] = $line;
			}
			return array_values( array_unique( $out ) );
		}

		/** @internal Force the configured mode/eagerness; 'auto' defers to core. */
		public static function filter_configuration( $config ) {
			// Core passes null when it has already decided speculative loading is off
			// for this request (a logged-in user, or no pretty permalinks). Turning it
			// back on here would break those assumptions, so null stays null.
			if ( null === $config ) {
				return null;
			}
			if ( 'off' === self::$config['mode'] ) {
				return null;
			}
			if ( ! is_array( $config ) ) {
				$config = array();
			}
			if ( 'auto' !== self::$config['mode'] ) {
				$config['mode'] = self::$config['mode'];
			}
			if ( 'auto' !== self::$config['eagerness'] ) {
				$config['eagerness'] = self::$config['eagerness'];
			}
			return $config;
		}

		/** @internal Add our exclusions to core's base list. */
		public static function filter_exclude_paths( $paths, $mode = 'prefetch' ) {
			$paths = is_array( $paths ) ? $paths : array();
			if ( self::$skip_sensitive ) {
				$paths = array_merge( $paths, self::sensitive_paths() );
			}
			return array_merge( $paths, self::$custom_excludes );
		}

		/**
		 * Paths that should never be speculatively loaded.
		 *
		 * Resolved from the actual pages where a plugin provides them, rather than
		 * guessed from slugs, so a renamed or translated checkout page is still
		 * covered. The hard-coded fallbacks only apply when no plugin answers.
		 *
		 * @return string[]
		 */
		private static function sensitive_paths() {
			$paths = array();

			// WooCommerce: cart, checkout and account, each with its endpoints
			// (/my-account/orders/, /checkout/order-received/<id>/ ...).
			if ( function_exists( 'wc_get_page_id' ) ) {
				foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
					$id = (int) wc_get_page_id( $page );
					if ( $id > 0 ) {
						$path = self::relative_path( get_permalink( $id ) );
						if ( '' !== $path ) {
							$paths[] = $path;
							$paths[] = rtrim( $path, '/' ) . '/*';
						}
					}
				}
			}

			// Easy Digital Downloads.
			if ( function_exists( 'edd_get_checkout_uri' ) ) {
				$path = self::relative_path( edd_get_checkout_uri() );
				if ( '' !== $path ) {
					$paths[] = $path;
					$paths[] = rtrim( $path, '/' ) . '/*';
				}
			}

			// Nothing answered: cover the conventional slugs. Harmless on a site that
			// has no such page, since a pattern matching nothing excludes nothing.
			if ( ! $paths ) {
				foreach ( array( 'cart', 'checkout', 'my-account', 'account' ) as $slug ) {
					$paths[] = '/' . $slug . '/';
					$paths[] = '/' . $slug . '/*';
				}
			}

			/**
			 * Filters the paths this extension excludes from speculative loading.
			 *
			 * For ADDING paths, prefer core's own 'wp_speculation_rules_href_exclude_paths'.
			 * This filter exists so a site can REMOVE one of the built-in commerce
			 * exclusions, which that one cannot do.
			 *
			 * @param string[] $paths Path patterns, each starting with a slash.
			 */
			return (array) apply_filters( 'fw:ext:asset-optimizer:speculation_sensitive_paths', $paths );
		}

		/** An absolute URL reduced to a site-root-relative path, or '' if off-site. */
		private static function relative_path( $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				return '';
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' === $path ) {
				return '';
			}
			// Core's WP_URL_Pattern_Prefixer prefixes the site's subdirectory, so a
			// path that already carries it would end up doubled.
			$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
			if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) );
			}
			return '' === $path ? '' : $path;
		}
	}

endif;
