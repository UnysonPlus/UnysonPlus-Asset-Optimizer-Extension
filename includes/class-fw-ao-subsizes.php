<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }
/**
 * Fill in intermediate image sizes that were never generated.
 *
 * A `srcset` can only offer the sizes that exist on disk. An attachment that was
 * imported with a trimmed size set - the Site Converter deliberately generates
 * only `thumbnail` and `medium`, because resizing is the bulk of its media phase
 * - therefore offers the browser a 300w file and the full-size original and
 * nothing in between, so every visitor downloads the original however small the
 * slot. Measured on a converted page: a 1024x434 slot pulled the 1440w original
 * at 91.9 KiB; with the missing sizes present the browser took the 1024w file at
 * 67.7 KiB instead, 26% less, for the same rendered pixels.
 *
 * Rather than make imports slower for everyone, the sizes are filled in
 * afterwards, a little at a time, the same way the WebP copies are (see
 * FW_AO_Webp). The budget here is far tighter - one attachment per request by
 * default against WebP's eight - because resizing a large original is expensive
 * and this runs inside a page render.
 *
 * The hook is `wp_calculate_image_srcset_meta`, which fires BEFORE the candidate
 * list is built and hands back the metadata used to build it. Generating there
 * and returning the fresh metadata means the srcset is correct on the very
 * request that triggered the work, rather than only on the next one.
 */
if ( ! class_exists( 'FW_AO_Subsizes' ) ) :
class FW_AO_Subsizes {

	/**
	 * Attachments to repair per request. Deliberately 1: wp_update_image_subsizes()
	 * on a large original is hundreds of milliseconds, and it runs while a visitor
	 * waits. A page with many unrepaired images catches up over several views.
	 */
	const MAX_PER_REQUEST = 1;

	/** Post meta marking an attachment we could not repair, so it is not retried forever. */
	const FAILED_META = '_fw_ao_subsizes_failed';

	/** @var int */
	private static $done_this_request = 0;

	/** @var array attachment_id => true */
	private static $seen = array();

	public static function init() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		// NOTE: do NOT gate on function_exists( 'wp_get_missing_image_subsizes' )
		// here. Both that and wp_update_image_subsizes() live in
		// wp-admin/includes/image.php, which is not loaded on the front end - so
		// such a check is always false exactly where this feature has to work, and
		// the filter would never be registered. The file is pulled in on demand in
		// maybe_fill(), which is the only place either function is called.
		add_filter( 'wp_calculate_image_srcset_meta', array( __CLASS__, 'maybe_fill' ), 5, 4 );
	}

	/**
	 * Generate this attachment's missing sizes, then hand back its fresh metadata.
	 *
	 * @param array  $image_meta
	 * @param array  $size_array
	 * @param string $image_src
	 * @param int    $attachment_id
	 * @return array
	 */
	public static function maybe_fill( $image_meta, $size_array, $image_src, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || isset( self::$seen[ $attachment_id ] ) ) {
			return $image_meta;
		}
		self::$seen[ $attachment_id ] = true;

		/**
		 * How many attachments may be repaired in one request.
		 *
		 * @param int $max
		 */
		$max = (int) apply_filters( 'fw_ao_subsizes_per_request', self::MAX_PER_REQUEST );
		if ( self::$done_this_request >= max( 0, $max ) ) {
			return $image_meta;
		}
		if ( get_post_meta( $attachment_id, self::FAILED_META, true ) ) {
			return $image_meta;
		}

		if ( ! function_exists( 'wp_get_missing_image_subsizes' ) ) {
			$admin_image = ABSPATH . 'wp-admin/includes/image.php';
			if ( ! is_readable( $admin_image ) ) {
				return $image_meta;
			}
			require_once $admin_image;
			if ( ! function_exists( 'wp_get_missing_image_subsizes' ) ) {
				return $image_meta; // WordPress < 5.3
			}
		}

		$missing = wp_get_missing_image_subsizes( $attachment_id );
		if ( empty( $missing ) || ! is_array( $missing ) ) {
			return $image_meta;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return $image_meta; // nothing we can do; not a failure worth marking
		}

		self::$done_this_request++;

		$result = wp_update_image_subsizes( $attachment_id );

		// Checking for a WP_Error is not enough, and assuming it were cost us a
		// real bug: given a corrupt original, wp_update_image_subsizes() returns a
		// perfectly ordinary ARRAY, having generated nothing, in about 7ms. The
		// sizes therefore stay missing, the attachment is picked again on the next
		// page view, and because the per-request budget is 1 that one unfixable
		// image would quietly consume the whole allowance on every view - blocking
		// every other image on the page from ever being repaired.
		//
		// So judge it by what actually appeared on disk, not by the return type.
		$failed = is_wp_error( $result ) || ! is_array( $result );
		if ( ! $failed ) {
			$still_missing = wp_get_missing_image_subsizes( $attachment_id );
			$failed        = is_array( $still_missing ) && count( $still_missing ) >= count( $missing );
		}
		if ( $failed ) {
			update_post_meta( $attachment_id, self::FAILED_META, 1 );
			return is_array( $result ) ? $result : $image_meta;
		}
		return $result;
	}

	/** Forget the failure marker when the file is replaced, so a fixed image is retried. */
	public static function on_attachment_updated( $attachment_id ) {
		delete_post_meta( (int) $attachment_id, self::FAILED_META );
	}
}
endif;
