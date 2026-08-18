<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use Moksafopoi\Settings\SettingsScreen;

defined( 'ABSPATH' ) || exit;

/**
 * 設定匯入 / 匯出 — serialises the plugin's own settings to a portable JSON envelope and restores
 * them back. This is an OPERATOR backup/restore tool (runs behind manage_woocommerce + a nonce),
 * distinct from the AI settings whitelist: a human admin backing up their own store may legitimately
 * carry every known option (including the module toggles and the MCP gates), so the porter's key set
 * is the full {@see SettingsScreen::known_keys()} list rather than the narrower AI-writable subset.
 *
 * Safety on import:
 *   - only keys present in {@see SettingsScreen::known_keys()} are ever written — an unknown key in
 *     an uploaded file is skipped, so a tampered file cannot inject arbitrary options;
 *   - every value is re-sanitised by its field type ({@see SettingsScreen::sanitize_option_for_import()})
 *     before it reaches update_option — an out-of-range / malformed value is rejected, never stored raw;
 *   - each applied change is written to the {@see SettingsAudit} log with source 'import'.
 */
final class SettingsPorter {

	/** Envelope format tag (guards against importing an unrelated plugin's export). */
	public const FORMAT = 'moksafopoi-settings';

	/**
	 * Build the export envelope: the format tag, plugin + version, an ISO timestamp, and the current
	 * value of every known option key.
	 *
	 * @return array<string,mixed>
	 */
	public static function export_payload(): array {
		$settings = array();
		foreach ( SettingsScreen::known_keys() as $key ) {
			$settings[ $key ] = get_option( $key, null );
		}
		return array(
			'format'      => self::FORMAT,
			'plugin'      => 'moksa-points-for-woocommerce',
			'version'     => defined( 'MOKSAFOPOI_VERSION' ) ? MOKSAFOPOI_VERSION : '',
			'exported_at' => gmdate( 'c' ),
			'settings'    => $settings,
		);
	}

	/** The export as a pretty-printed JSON string, ready for a file download. */
	public static function export_json(): string {
		$json = wp_json_encode( self::export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}

	/** Suggested download filename (date-stamped). */
	public static function export_filename(): string {
		return 'moksafopoi-settings-' . gmdate( 'Ymd-His' ) . '.json';
	}

	/**
	 * Restore settings from a decoded envelope. Validates the format tag, then walks the settings map,
	 * writing only known + re-sanitised values and logging each real change.
	 *
	 * @param mixed $payload Decoded JSON (array expected).
	 * @return array{ok:bool,applied:int,skipped:int,error?:string,changed:array<int,string>}
	 */
	public static function import_payload( $payload ): array {
		if ( ! is_array( $payload ) || ( $payload['format'] ?? '' ) !== self::FORMAT ) {
			return array(
				'ok'      => false,
				'applied' => 0,
				'skipped' => 0,
				'changed' => array(),
				'error'   => __( 'The file format does not match (not this plugin\'s settings export file).', 'moksa-points-for-woocommerce' ),
			);
		}
		$settings = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();
		if ( array() === $settings ) {
			return array(
				'ok'      => false,
				'applied' => 0,
				'skipped' => 0,
				'changed' => array(),
				'error'   => __( 'The import file has no applicable settings.', 'moksa-points-for-woocommerce' ),
			);
		}

		$known   = array_fill_keys( SettingsScreen::known_keys(), true );
		$applied = 0;
		$skipped = 0;
		$changed = array();

		foreach ( $settings as $key => $raw ) {
			$key = (string) $key;
			if ( ! isset( $known[ $key ] ) ) {
				$skipped++;
				continue; // unknown key — never write.
			}
			$result = SettingsScreen::sanitize_option_for_import( $key, $raw );
			if ( true !== ( $result['ok'] ?? false ) ) {
				$skipped++;
				continue; // invalid value — leave the option untouched.
			}
			$new = $result['value'];
			$old = get_option( $key, null );
			update_option( $key, $new );
			if ( (string) maybe_serialize( $old ) !== (string) maybe_serialize( $new ) ) {
				SettingsAudit::record( $key, $old, $new, 'import' );
				$changed[] = $key;
			}
			$applied++;
		}

		return array(
			'ok'      => true,
			'applied' => $applied,
			'skipped' => $skipped,
			'changed' => $changed,
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
		if ( '' === $contents || strlen( $contents ) > 1024 * 512 ) {
			return null; // empty or > 512KB — refuse.
		}
		$decoded = json_decode( $contents, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
