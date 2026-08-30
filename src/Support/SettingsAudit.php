<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 設定變更稽核 — this plugin's adapter over the shared {@see \Moksa\Kit\SettingsAudit}.
 *
 * The ring-buffer logic (capping, stringifying, who/when/source) is identical in every Moksa plugin
 * and now lives once in the bundled kit. The only thing that is genuinely ours is WHICH option holds
 * the buffer, so that is all this class carries — call sites keep their short, domain-shaped API.
 */
final class SettingsAudit {

	/** Non-autoloaded option holding the JSON ring buffer. */
	public const OPTION = 'moksafopoi_settings_audit_log';

	/**
	 * Record one setting change (no-op when nothing actually changed).
	 *
	 * @param string $key    Option key that changed.
	 * @param mixed  $old    Value before the change.
	 * @param mixed  $new    Value after the change.
	 * @param string $source One of 'ui' | 'import' | 'ability' (free-form, sanitised to a key).
	 */
	public static function record( string $key, $old, $new, string $source = 'ui' ): void {
		\Moksa\Kit\SettingsAudit::record( self::OPTION, $key, $old, $new, $source );
	}

	/**
	 * The most-recent $limit entries (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		return \Moksa\Kit\SettingsAudit::recent( self::OPTION, $limit );
	}
}
