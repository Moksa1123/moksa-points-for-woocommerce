<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 設定變更稽核 — a lightweight, append-only change log for the plugin's own settings. Stored as a
 * single non-autoloaded option holding a capped ring buffer (newest first), never a CPT / custom
 * table, so it adds no schema and no autoload weight. Each entry records who changed which option,
 * the before → after value, when, and the source (ui / import / ability).
 *
 * The log is advisory operational history, not a security control: it is written by the same code
 * paths that already hold manage_woocommerce, and read only on the settings screen. Values are
 * truncated so a huge template can never bloat the option.
 */
final class SettingsAudit {

	/** Non-autoloaded option holding the JSON ring buffer. */
	public const OPTION = 'moksafopoi_settings_audit_log';

	/** Keep at most this many entries (oldest dropped). */
	private const MAX = 100;

	/** Truncate stored old/new values to this many chars (keeps the option small). */
	private const VALUE_CAP = 200;

	/**
	 * Record one setting change. No-ops when the value is unchanged (a save that did not move the
	 * needle should not spam the log). Both values are stringified + length-capped before storage.
	 *
	 * @param string $key    Option key that changed.
	 * @param mixed  $old    Value before the change.
	 * @param mixed  $new    Value after the change.
	 * @param string $source One of 'ui' | 'import' | 'ability' (free-form, sanitised to a key).
	 */
	public static function record( string $key, $old, $new, string $source = 'ui' ): void {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return;
		}
		$old_s = self::stringify( $old );
		$new_s = self::stringify( $new );
		if ( $old_s === $new_s ) {
			return; // unchanged — nothing to log.
		}

		$log   = self::read();
		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
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

		update_option( self::OPTION, wp_json_encode( $log ), false );
	}

	/**
	 * The most-recent $limit entries (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		$log = self::read();
		return array_slice( $log, 0, max( 1, $limit ) );
	}

	/**
	 * The full decoded ring buffer (newest first), or an empty array when unset / corrupt.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function read(): array {
		$raw = get_option( self::OPTION, '' );
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
