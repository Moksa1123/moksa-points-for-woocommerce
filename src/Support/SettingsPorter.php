<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use Moksafopoi\Settings\SettingsScreen;

defined( 'ABSPATH' ) || exit;

/**
 * 設定匯入 / 匯出 — this plugin's adapter over the shared {@see \Moksa\Kit\SettingsPorter}.
 *
 * The envelope shape and the import safety rules (only known keys are written, every value is
 * re-sanitised by its field type before it reaches update_option, each real change is logged) are
 * identical across the suite and now live once in the bundled kit. What stays here is what is
 * actually ours: the format tag, the plugin identity, the key set, the field-type sanitiser, the
 * audit sink — and the wording, because a bundled library cannot own a textdomain.
 */
final class SettingsPorter {

	/** Envelope format tag (guards against importing an unrelated plugin's export). */
	public const FORMAT = 'moksafopoi-settings';

	/**
	 * Everything the kit needs to serve THIS plugin.
	 *
	 * @return array<string,mixed>
	 */
	private static function context(): array {
		return array(
			'format'   => self::FORMAT,
			'plugin'   => 'moksa-points-for-woocommerce',
			'version'  => defined( 'MOKSAFOPOI_VERSION' ) ? MOKSAFOPOI_VERSION : '',
			'keys'     => SettingsScreen::known_keys(),
			'sanitize' => array( SettingsScreen::class, 'sanitize_option_for_import' ),
			'audit'    => static function ( string $key, $old, $new ): void {
				SettingsAudit::record( $key, $old, $new, 'import' );
			},
		);
	}

	/**
	 * The export envelope: format tag, plugin + version, an ISO timestamp, and every known option.
	 *
	 * @return array<string,mixed>
	 */
	public static function export_payload(): array {
		return \Moksa\Kit\SettingsPorter::export_payload( self::context() );
	}

	/** The export as a pretty-printed JSON string, ready for a file download. */
	public static function export_json(): string {
		return \Moksa\Kit\SettingsPorter::export_json( self::context() );
	}

	/** Suggested download filename (date-stamped). */
	public static function export_filename(): string {
		return \Moksa\Kit\SettingsPorter::export_filename( 'moksafopoi-settings' );
	}

	/**
	 * Restore settings from a decoded envelope.
	 *
	 * @param mixed $payload Decoded JSON (array expected).
	 * @return array{ok:bool,applied:int,skipped:int,error?:string,changed:array<int,string>}
	 */
	public static function import_payload( $payload ): array {
		$result = \Moksa\Kit\SettingsPorter::import_payload( $payload, self::context() );

		// Translate the kit's failure CODE into this plugin's own wording (the kit owns no textdomain).
		if ( true !== $result['ok'] ) {
			$result['error'] = 'empty' === $result['error_code']
				? __( 'The import file has no applicable settings.', 'moksa-points-for-woocommerce' )
				: __( 'The file format does not match (not this plugin\'s settings export file).', 'moksa-points-for-woocommerce' );
		}
		unset( $result['error_code'] );

		return $result;
	}

	/**
	 * Parse an uploaded file's raw contents into a decoded payload (bounded; null on any failure).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function decode_upload( string $contents ): ?array {
		return \Moksa\Kit\SettingsPorter::decode_upload( $contents );
	}
}
