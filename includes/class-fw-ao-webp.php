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
		$out = self::twin_path( $path );
		if ( file_exists( $out ) && filemtime( $out ) >= filemtime( $path ) ) {
			return true;
		}
		if ( file_exists( $path . '.nowebp' ) ) {
			return false;
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( 'image/webp' ) ) {
			return false;
		}
		$editor->set_quality( (int) apply_filters( 'fw:ext:asset-optimizer:webp_quality', 80 ) );
		$saved = $editor->save( $out, 'image/webp' );
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
