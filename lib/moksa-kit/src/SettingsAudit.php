<?php

declare( strict_types=1 );

namespace Moksa\Kit;

defined( 'ABSPATH' ) || exit;

/**
 * 設定變更稽核 — an append-only change log for a plugin's own settings. Stored as a single
 * non-autoloaded option holding a capped ring buffer (newest first): never a CPT, never a custom
 * table, so it adds no schema and no autoload weight. Each entry records who changed which option,
 * the before → after value, when, and the source (ui / import / ability).
 *
 * The log is advisory operational history, not a security control: it is written by the same code
 * paths that already hold the plugin's management capability, and read only on the settings screen.
 * Values are truncated so a huge template can never bloat the option.
 *
 * The only thing that differs per plugin is WHICH option holds the buffer, so the option name is a
 * parameter rather than a constant — each plugin keeps a three-line adapter that supplies its own.
 */
final class SettingsAudit {

	/** Keep at most this many entries (oldest dropped). */
	private const MAX = 100;

	/** Truncate stored old/new values to this many chars (keeps the option small). */
	private const VALUE_CAP = 200;

	/**
	 * Record one setting change. No-ops when the value is unchanged (a save that did not move the
	 * needle should not spam the log). Both values are stringified + length-capped before storage.
	 *
	 * @param string $option Option name holding this plugin's ring buffer.
	 * @param string $key    Option key that changed.
	 * @param mixed  $old    Value before the change.
	 * @param mixed  $new    Value after the change.
	 * @param string $source One of 'ui' | 'import' | 'ability' (free-form, sanitised to a key).
	 */
	public static function record( string $option, string $key, $old, $new, string $source = 'ui' ): void {
		$option = sanitize_key( $option );
		$key    = sanitize_key( $key );
		if ( '' === $option || '' === $key ) {
			return;
		}
		$old_s = self::stringify( $old );
		$new_s = self::stringify( $new );
		if ( $old_s === $new_s ) {
			return; // unchanged — nothing to log.
		}

		$log  = self::read( $option );
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		array_unshift(
			$log,
			array(
				't'      => gmdate( 'Y-m-d H:i:s' ),
				'user'   => ( $user && $user->ID ) ? (string) $user->user_login : '—',
				'uid'    => ( $user && $user->ID ) ? (int) $user->ID : 0,
				'key'    => $key,
				'old'    => $old_s,
				'new'    => $new_s,
				'source' => sanitize_key( $source ),
			)
		);

		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, 0, self::MAX );
		}

		update_option( $option, wp_json_encode( $log ), false );
	}

	/**
	 * The most-recent $limit entries (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( string $option, int $limit = 50 ): array {
		return array_slice( self::read( $option ), 0, max( 1, $limit ) );
	}

	/** Wipe the log. */
	public static function clear( string $option ): void {
		$option = sanitize_key( $option );
		if ( '' !== $option ) {
			delete_option( $option );
		}
	}

	/**
	 * The full decoded ring buffer (newest first), or an empty array when unset / corrupt.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function read( string $option ): array {
		$raw = get_option( sanitize_key( $option ), '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Stringify + length-cap a stored value so the option can never balloon. */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			$value = (string) wp_json_encode( $value );
		} else {
			$value = (string) $value;
		}
		$value = trim( $value );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > self::VALUE_CAP ) {
			return mb_substr( $value, 0, self::VALUE_CAP ) . '…';
		}
		if ( strlen( $value ) > self::VALUE_CAP ) {
			return substr( $value, 0, self::VALUE_CAP ) . '…';
		}
		return $value;
	}
}
