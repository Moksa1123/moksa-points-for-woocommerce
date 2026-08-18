<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 賺點排除名單 — roles / individual users that never EARN points (employees, wholesale, test
 * accounts). Applied once, centrally, at the very top of the `earn` branch in
 * {@see \Moksafopoi\Modules\Ledger\Ledger::record_once()}, so every current and
 * future earn module is covered without editing each one. Everything except earning is
 * untouched: an excluded member can still SPEND an existing balance, receive manual
 * adjustments, and use store credit. Both lists default empty (feature off).
 */
final class EarnExclusions {

	private const ROLES_OPTION = 'moksafopoi_exclude_roles';
	private const USERS_OPTION = 'moksafopoi_exclude_users';

	/** Whether this user is barred from earning points. */
	public static function excluded( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$uids = self::excluded_user_ids();
		if ( isset( $uids[ $user_id ] ) ) {
			return true;
		}

		$roles = self::excluded_roles();
		if ( array() === $roles ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( isset( $roles[ (string) $role ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The excluded role slugs as a set. Option stores a comma/newline separated list.
	 *
	 * @return array<string,true>
	 */
	private static function excluded_roles(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( self::split( (string) get_option( self::ROLES_OPTION, '' ) ) as $slug ) {
			$slug = sanitize_key( $slug );
			if ( '' !== $slug ) {
				$cache[ $slug ] = true;
			}
		}
		return $cache;
	}

	/**
	 * The excluded users resolved to a user-id set. Option stores ids / emails / logins
	 * (comma/newline separated); unknown entries are ignored. Cached per request.
	 *
	 * @return array<int,true>
	 */
	private static function excluded_user_ids(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( self::split( (string) get_option( self::USERS_OPTION, '' ) ) as $entry ) {
			if ( is_numeric( $entry ) ) {
				$cache[ (int) $entry ] = true;
				continue;
			}
			$user = str_contains( $entry, '@' ) ? get_user_by( 'email', $entry ) : get_user_by( 'login', $entry );
			if ( $user instanceof \WP_User ) {
				$cache[ (int) $user->ID ] = true;
			}
		}
		return $cache;
	}

	/** @return array<int,string> */
	private static function split( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', $raw ) ?: array();
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}
}
