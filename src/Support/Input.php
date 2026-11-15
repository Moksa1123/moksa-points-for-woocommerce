<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only request parameters that never carry a WP nonce: which admin view to show, which row to
 * prefill an edit form with, which page of a list, a public referral landing parameter, a same-host
 * return URL. None of them changes state; every write path verifies its own nonce.
 *
 * Reads the raw request with filter_input (not WordPress' slashed $_GET), so callers must NOT
 * wp_unslash the result. The value is untrusted: the caller still sanitizes it (sanitize_key, absint,
 * esc_url_raw …) before use. Same approach as Moksa for WooCommerce's Http\Input.
 */
final class Input {

	/** GET parameter as a raw string; '' when absent or not a string. */
	public static function get( string $key ): string {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
		return is_string( $value ) ? $value : '';
	}

	/** POST parameter as a raw string; '' when absent or not a string. */
	public static function post( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
		return is_string( $value ) ? $value : '';
	}

	public static function has_get( string $key ): bool {
		return filter_has_var( INPUT_GET, $key );
	}

	private function __construct() {}
}
