<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * "Serve WebP images" (Asset Optimizer → General).
 *
 * Every uploaded JPG/PNG, each of its generated sizes, and each responsive crop from
 * fw_image_crop_renditions() gets a WebP copy beside it (photo-768x512.jpg →
 * photo-768x512.webp), and front-end image URLs are swapped to the WebP when that
 * copy exists. The originals are never touched, so the media library, post content,
 * feeds and social previews keep working, and switching the option off simply goes
 * back to the originals.
 *
 * Copies are made on upload, when a crop is written, and on demand for images that
 * predate the option (a few per request, so a first page view never stalls). A WebP
 * that comes out LARGER than its source is discarded and a small ".nowebp" marker is
 * left so it isn't retried on every request.
 */
if ( ! class_exists( 'FW_AO_Webp' ) ) :
class FW_AO_Webp {

	/** Max on-demand conversions per request (existing images are caught up gradually). */
	const MAX_PER_REQUEST = 8;

	/** Remembers the quality the copies on disk were encoded at, and when it last changed. */
	const QUALITY_OPTION = 'fw_ao_webp_quality_state';

	/** @var int */
	private static $made_this_request = 0;

	/** @var array url => url */
	private static $url_cache = array();

	/** @var array|null */
	private static $upload_dir = null;

	/** Whether this server can write WebP at all. */
	public static function supported() {
		if ( function_exists( 'imagewebp' ) ) {
			return true;
		}
		return class_exists( 'Imagick' ) && in_array( 'WEBP', (array) \Imagick::queryFormats( 'WEBP' ), true );
	}

	public static function init() {
		if ( ! self::supported() ) {
			return;
		}
		// Generation: new uploads (after WordPress has made the sizes) and new crops.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_attachment_metadata' ), 20, 2 );
		add_action( 'fw_image_rendition_saved', array( __CLASS__, 'make' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		// Serving: every place a front-end image URL comes from.
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ) );
		add_filter( 'wp_calculate_image_srcset_meta', array( __CLASS__, 'match_srcset_meta' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ) );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img' ) );
		add_filter( 'fw_image_src_url', array( __CLASS__, 'url' ) );
	}

	/**
	 * Write the WebP copy of one JPG/PNG file. True when a usable copy exists afterwards.
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function make( $path ) {
		$path = (string) $path;
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$quality = self::quality();
		$out     = self::twin_path( $path );
		// Stale also means "made before the quality was last changed" - otherwise
		// changing the setting would silently do nothing to the images already on
		// disk, which is the whole point of changing it.
		if ( file_exists( $out ) && filemtime( $out ) >= max( filemtime( $path ), self::quality_changed_at() ) ) {
			return true;
		}
		if ( file_exists( $path . '.nowebp' ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( 'image/webp' ) ) {
			return false;
		}

		// Set the quality through the filter, NOT $editor->set_quality().
		//
		// save() re-derives the quality whenever the output format differs from the
		// source's - which it always does here, JPEG/PNG in and WebP out - so a
		// value set beforehand is discarded and WordPress's own WebP default (86)
		// is used instead. Measured on a real 768x512 photo: 75,944 bytes at every
		// requested quality from 80 down to 60, the editor reporting 86 each time,
		// against 59,398 at a genuine 80 and 46,362 at 70. So this extension's
		// documented default of 80 had never actually been applied to anything, and
		// the WebP copies were only a few per cent smaller than the JPEGs they
		// replaced rather than the quarter-or-more they should be.
		$set_q = function ( $q, $mime = '' ) use ( $quality ) {
			return ( '' === $mime || 'image/webp' === $mime ) ? $quality : $q;
		};
		add_filter( 'wp_editor_set_quality', $set_q, PHP_INT_MAX, 2 );
		$saved = $editor->save( $out, 'image/webp' );
		remove_filter( 'wp_editor_set_quality', $set_q, PHP_INT_MAX );

		if ( is_wp_error( $saved ) || ! file_exists( $out ) ) {
			return false;
		}
		// Keep it only if it's actually smaller; remember a loser so it isn't redone.
		if ( filesize( $out ) >= filesize( $path ) ) {
			@unlink( $out );
			@touch( $path . '.nowebp' );
			return false;
		}
		return true;
	}

	/**
	 * The WebP encoder quality to use.
	 *
	 * @return int 1-100
	 */
	public static function quality() {
		$ext = function_exists( 'fw_ext' ) ? fw_ext( 'asset-optimizer' ) : null;
		$set = ( $ext && method_exists( $ext, 'webp_quality_setting' ) ) ? (int) $ext->webp_quality_setting() : 0;
		$q   = $set > 0 ? $set : 80;

		/** Filters the WebP encoder quality (1-100). */
		$q = (int) apply_filters( 'fw:ext:asset-optimizer:webp_quality', $q );

		return max( 1, min( 100, $q ) );
	}

	/**
	 * When the quality last changed, as a timestamp.
	 *
	 * Copies written before then were encoded at a different quality and must be
	 * redone, so this is compared against each twin's mtime. The existing
	 * per-request throttle means a site works through them a few at a time rather
	 * than re-encoding everything at once.
	 *
	 * @return int
	 */
	private static function quality_changed_at() {
		$state = get_option( self::QUALITY_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$q     = self::quality();

		if ( ! isset( $state['quality'] ) || (int) $state['quality'] !== $q ) {
			$state = array( 'quality' => $q, 'changed_at' => time() );
			update_option( self::QUALITY_OPTION, $state, false );
		}

		return isset( $state['changed_at'] ) ? (int) $state['changed_at'] : 0;
	}

	/** photo.jpg → photo.webp */
	private static function twin_path( $path ) {
		return preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
	}

	/** Every file of an attachment: the original plus each generated size. */
	private static function attachment_files( $attachment_id, $meta = null ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return array();
		}
		$meta  = is_array( $meta ) ? $meta : wp_get_attachment_metadata( $attachment_id );
		$files = array( $file );
		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = dirname( $file ) . '/' . $meta['original_image'];
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = dirname( $file ) . '/' . $size['file'];
				}
			}
		}
		return array_unique( $files );
	}

	/** @internal */
	public static function on_attachment_metadata( $meta, $attachment_id ) {
		foreach ( self::attachment_files( $attachment_id, $meta ) as $f ) {
			self::make( $f );
		}
		return $meta;
	}

	/** @internal Remove the WebP copies (and markers) together with the attachment. */
	public static function on_delete_attachment( $attachment_id ) {
		foreach ( self::attachment_files( $attachment_id ) as $f ) {
			foreach ( array( self::twin_path( $f ), $f . '.nowebp' ) as $x ) {
				if ( $x !== $f && is_file( $x ) ) {
					@unlink( $x );
				}
			}
		}
	}

	/**
	 * The WebP URL for an uploads JPG/PNG URL, making the copy on demand (within the
	 * per-request budget). Anything else is returned unchanged.
	 *
	 * @param string $url
	 * @return string
	 */
	public static function url( $url ) {
		if ( ! is_string( $url ) || ! preg_match( '/\.(jpe?g|png)$/i', $url ) ) {
			return $url;
		}
		if ( isset( self::$url_cache[ $url ] ) ) {
			return self::$url_cache[ $url ];
		}
		if ( null === self::$upload_dir ) {
			self::$upload_dir = wp_get_upload_dir();
		}
		$base   = preg_replace( '#^https?:#i', '', self::$upload_dir['baseurl'] );
		$bare   = preg_replace( '#^https?:#i', '', $url );
		$result = $url;
		if ( 0 === strpos( $bare, $base . '/' ) ) {
			$path = self::$upload_dir['basedir'] . rawurldecode( substr( $bare, strlen( $base ) ) );
			$twin = self::twin_path( $path );
			$have = file_exists( $twin ) && filemtime( $twin ) >= @filemtime( $path );
			if ( ! $have && self::$made_this_request < self::MAX_PER_REQUEST && is_readable( $path ) && ! file_exists( $path . '.nowebp' ) ) {
				self::$made_this_request++;
				$have = self::make( $path );
			}
			if ( $have ) {
				$result = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $url );
			}
		}
		return self::$url_cache[ $url ] = $result;
	}

	/** @internal */
	public static function filter_image_src( $image ) {
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$image[0] = self::url( $image[0] );
		}
		return $image;
	}

	/**
	 * @internal Keep srcset working once the src is the WebP copy.
	 *
	 * WordPress builds a srcset only when the image's src contains one of the attachment's
	 * file names (#35045). filter_image_src() has already turned photo.jpg into photo.webp,
	 * so nothing matched and every image with a WebP original lost its srcset (phones got the
	 * full-size file). Point whichever meta entry the src belongs to -- the original, or one of
	 * the generated sizes when the src is a resized copy -- at its WebP twin: the match then
	 * succeeds, and filter_srcset() turns the remaining candidates into WebP too.
	 *
	 * The meta is a local variable inside wp_calculate_image_srcset(), so the rewrite is scoped
	 * to that one calculation and never reaches the stored attachment metadata. Only the file
	 * name changes; each candidate keeps its own width, height and URL directory.
	 */
	public static function match_srcset_meta( $image_meta, $size_array, $image_src ) {
		if ( ! is_array( $image_meta ) || empty( $image_meta['file'] ) || ! is_string( $image_src ) ) {
			return $image_meta;
		}
		$src = wp_basename( strtok( $image_src, '?#' ) );
		if ( '' === $src || ! preg_match( '/\.webp$/i', $src ) ) {
			return $image_meta;
		}

		// The src is the twin of the original (photo.webp).
		$file = (string) $image_meta['file'];
		$twin = self::twin_path( $file );
		if ( $twin !== $file && wp_basename( $twin ) === $src ) {
			$image_meta['file'] = $twin;
			return $image_meta;
		}

		// The src is the twin of a generated size (photo-1024x685.webp).
		if ( ! empty( $image_meta['sizes'] ) && is_array( $image_meta['sizes'] ) ) {
			foreach ( $image_meta['sizes'] as $name => $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) ) {
					continue;
				}
				$size_twin = self::twin_path( (string) $size['file'] );
				if ( $size_twin !== $size['file'] && wp_basename( $size_twin ) === $src ) {
					$image_meta['sizes'][ $name ]['file'] = $size_twin;
					break;
				}
			}
		}

		return $image_meta;
	}

	/** @internal */
	public static function filter_srcset( $sources ) {
		if ( is_array( $sources ) ) {
			foreach ( $sources as $w => $source ) {
				if ( ! empty( $source['url'] ) ) {
					$sources[ $w ]['url'] = self::url( $source['url'] );
				}
			}
		}
		return $sources;
	}

	/** @internal Images written straight into post content. */
	public static function filter_content_img( $html ) {
		return preg_replace_callback( '#https?://[^\s"\',]+\.(?:jpe?g|png)\b#i', function ( $m ) {
			return FW_AO_Webp::url( $m[0] );
		}, (string) $html );
	}
}
endif;
