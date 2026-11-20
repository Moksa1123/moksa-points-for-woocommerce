<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Read (or add) a flag without trusting a stale "this option does not exist" cache entry.
 *
 * With a persistent object cache (Redis, Memcached), WordPress keeps one shared `notoptions` list.
 * Two requests that each update that list can overwrite each other, leaving an option marked as
 * missing although its row is in the database. A flag that gates one-time work (migration done, index
 * ready) then reads as missing and the work runs again, and add_option() skips its "already there?"
 * check, so a first-time default can overwrite what the owner chose. So when the cached answer is
 * "missing", drop this one name from the cached list and ask the database once more.
 *
 * Without a persistent object cache the list only lives for the current request and cannot go
 * stale, so plain get_option() / add_option() are used. Each name is re-checked at most once per
 * request; after that this request's cache is accurate (writes in this request keep it current).
 *
 * Same idea as moksa-coupons' Support\FreshOption.
 */
final class FreshOption {

	/** @var array<string,true> Names already re-checked against the database in this request. */
	private static array $checked = array();

	/**
	 * @param string $name     Option name.
	 * @param mixed  $fallback Returned when the option really is missing.
	 * @return mixed
	 */
	public static function get( string $name, $fallback = false ) {
		$value = get_option( $name, null );
		if ( null !== $value ) {
			return $value;
		}
		if ( self::recheck( $name ) ) {
			$value = get_option( $name, null );
		}
		return null === $value ? $fallback : $value;
	}

	/**
	 * add_option() that refuses when the option exists even if the cache says it does not (used for
	 * first-time defaults).
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Load on every request.
	 */
	public static function add( string $name, $value, bool $autoload = false ): bool {
		self::recheck( $name );
		return add_option( $name, $value, '', $autoload );
	}

	/**
	 * Drop one name from the cached "missing" list and its cached value, unconditionally, so the next
	 * get_option() reads the database. For values another request may change at any moment (a lock).
	 */
	public static function forget( string $name ): void {
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Remove one name from the cached "missing" list (once per request, persistent cache only).
	 *
	 * @param string $name Option name.
	 * @return bool Whether the cache was touched (a second read is worth doing).
	 */
	private static function recheck( string $name ): bool {
		if ( ! wp_using_ext_object_cache() || isset( self::$checked[ $name ] ) ) {
			return false;
		}
		self::$checked[ $name ] = true;
		self::forget( $name );
		return true;
	}

	private function __construct() {}
}
