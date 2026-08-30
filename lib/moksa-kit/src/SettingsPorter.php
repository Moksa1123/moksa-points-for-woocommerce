<?php

declare( strict_types=1 );

namespace Moksa\Kit;

defined( 'ABSPATH' ) || exit;

/**
 * 設定匯入 / 匯出 — serialises a plugin's settings to a portable JSON envelope and restores them.
 * This is an OPERATOR backup/restore tool (the caller runs it behind its management capability and a
 * nonce), distinct from any AI settings whitelist: a human admin backing up their own store may
 * legitimately carry every known scalar option, so the key set is the caller's full known-keys list
 * rather than a narrower writable subset.
 *
 * Safety on import (enforced here, not left to the caller):
 *   - only keys the caller declares as known are ever written — an unknown key in an uploaded file is
 *     skipped, so a tampered file cannot inject arbitrary options;
 *   - every value is re-sanitised by the caller's own field-type sanitiser before it reaches
 *     update_option — an out-of-range / malformed value is rejected, never stored raw;
 *   - each applied change is handed back to the caller's audit callback.
 *
 * Everything plugin-specific arrives in a context array, so the kit never needs to know which plugin
 * it is serving. Failures come back as CODES ('bad_format' | 'empty'), never sentences: the wording a
 * human reads belongs to the plugin, which owns the textdomain.
 */
final class SettingsPorter {

	/** Refuse to decode an upload larger than this (bytes). */
	private const MAX_UPLOAD = 524288;

	/**
	 * Build the export envelope: the format tag, plugin + version, an ISO timestamp, and the current
	 * value of every known option key.
	 *
	 * @param array{format:string,plugin:string,version:string,keys:array<int,string>} $ctx
	 * @return array<string,mixed>
	 */
	public static function export_payload( array $ctx ): array {
		$settings = array();
		foreach ( (array) ( $ctx['keys'] ?? array() ) as $key ) {
			$key = (string) $key;
			if ( '' !== $key ) {
				$settings[ $key ] = get_option( $key, null );
			}
		}
		return array(
			'format'      => (string) ( $ctx['format'] ?? '' ),
			'plugin'      => (string) ( $ctx['plugin'] ?? '' ),
			'version'     => (string) ( $ctx['version'] ?? '' ),
			'exported_at' => gmdate( 'c' ),
			'settings'    => $settings,
		);
	}

	/**
	 * The export as a pretty-printed JSON string, ready for a file download.
	 *
	 * @param array{format:string,plugin:string,version:string,keys:array<int,string>} $ctx
	 */
	public static function export_json( array $ctx ): string {
		$json = wp_json_encode( self::export_payload( $ctx ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}

	/** Suggested download filename (date-stamped), e.g. `moksafomem-settings-20260820-041530.json`. */
	public static function export_filename( string $prefix ): string {
		$prefix = trim( $prefix );
		return ( '' !== $prefix ? $prefix : 'settings' ) . '-' . gmdate( 'Ymd-His' ) . '.json';
	}

	/**
	 * Restore settings from a decoded envelope. Validates the format tag, then walks the settings map,
	 * writing only known + re-sanitised values and reporting each real change.
	 *
	 * @param mixed                                                                                  $payload Decoded JSON (array expected).
	 * @param array{format:string,keys:array<int,string>,sanitize:callable,audit?:callable|null}      $ctx
	 *        sanitize( string $key, mixed $raw ): array{ok:bool,value:mixed}
	 *        audit( string $key, mixed $old, mixed $new ): void   (optional)
	 * @return array{ok:bool,applied:int,skipped:int,error_code:string,changed:array<int,string>}
	 */
	public static function import_payload( $payload, array $ctx ): array {
		$fail = static function ( string $code ): array {
			return array(
				'ok'         => false,
				'applied'    => 0,
				'skipped'    => 0,
				'changed'    => array(),
				'error_code' => $code,
			);
		};

		$format = (string) ( $ctx['format'] ?? '' );
		if ( ! is_array( $payload ) || '' === $format || ( $payload['format'] ?? '' ) !== $format ) {
			return $fail( 'bad_format' );
		}

		$settings = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();
		if ( array() === $settings ) {
			return $fail( 'empty' );
		}

		$sanitize = $ctx['sanitize'] ?? null;
		if ( ! is_callable( $sanitize ) ) {
			return $fail( 'bad_format' ); // a caller with no sanitiser must never reach update_option().
		}
		$audit = ( isset( $ctx['audit'] ) && is_callable( $ctx['audit'] ) ) ? $ctx['audit'] : null;

		$known   = array_fill_keys( array_map( 'strval', (array) ( $ctx['keys'] ?? array() ) ), true );
		$applied = 0;
		$skipped = 0;
		$changed = array();

		foreach ( $settings as $key => $raw ) {
			$key = (string) $key;
			if ( ! isset( $known[ $key ] ) ) {
				++$skipped;
				continue; // unknown key — never write.
			}
			$result = (array) call_user_func( $sanitize, $key, $raw );
			if ( true !== ( $result['ok'] ?? false ) ) {
				++$skipped;
				continue; // invalid value — leave the option untouched.
			}
			$new = $result['value'];
			$old = get_option( $key, null );
			update_option( $key, $new );
			if ( (string) maybe_serialize( $old ) !== (string) maybe_serialize( $new ) ) {
				if ( null !== $audit ) {
					call_user_func( $audit, $key, $old, $new );
				}
				$changed[] = $key;
			}
			++$applied;
		}

		return array(
			'ok'         => true,
			'applied'    => $applied,
			'skipped'    => $skipped,
			'changed'    => $changed,
			'error_code' => '',
		);
	}

	/**
	 * Parse an uploaded file's raw contents into a decoded payload. Bounded so a giant upload can't be
	 * decoded into memory; returns null on any parse failure.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function decode_upload( string $contents ): ?array {
		$contents = trim( $contents );
		if ( '' === $contents || strlen( $contents ) > self::MAX_UPLOAD ) {
			return null; // empty or too large — refuse.
		}
		$decoded = json_decode( $contents, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
