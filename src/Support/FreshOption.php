<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Read a "done" flag without trusting a stale "this option does not exist" cache entry.
 *
 * With a persistent object cache, WordPress keeps one shared `notoptions` list. Two requests that each
 * update that list can overwrite each other, leaving a flag marked as missing even though the row is in
 * the database; update_option() with the same value then does not clear it either. For flags that gate
 * one-time work (migration done, index ready) that would re-run the work on every request. So when the
 * cached answer is "missing", drop the cached entries and read the database once more.
 */
final class FreshOption {

	/** @return mixed The option value, or $fallback when it really is missing. */
	public static function get( string $name, $fallback = false ) {
		$value = get_option( $name, null );
		if ( null !== $value ) {
			return $value;
		}
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( $name, 'options' );
		$value = get_option( $name, null );
		return null === $value ? $fallback : $value;
	}

	private function __construct() {}
}
