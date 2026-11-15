<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers every option this plugin has written, so uninstall can delete them one by one through the
 * options API (WordPress has no API for "options starting with moksafopoi_", and wp_load_alloptions()
 * only returns autoloaded ones). Same approach as Moksa Coupons' and Moksa for WooCommerce's registry.
 *
 * Core fires added_option for every new option; the name is recorded when it is ours. Sites that already
 * had options before this existed are back-filled once (autoloaded ones from wp_load_alloptions(), the
 * rest from known_unautoloaded()).
 *
 * Not recorded: transients (they expire on their own), core lock options ("<name>.lock", deleted on
 * release) and the registry itself.
 */
final class OptionRegistry {

	public const OPTION = 'moksafopoi_option_registry';

	private const BACKFILLED = 'moksafopoi_option_registry_backfilled';

	private const PREFIX = 'moksafopoi_';

	public static function boot(): void {
		add_action( 'added_option', array( self::class, 'track' ) );
		add_action( 'init', array( self::class, 'maybe_backfill' ), 20 );
	}

	/**
	 * Record a newly added option when it is ours.
	 *
	 * @param mixed $name Option name.
	 */
	public static function track( $name ): void {
		if ( is_string( $name ) && self::ours( $name ) ) {
			self::add( array( $name ) );
		}
	}

	/** Is this option name one of ours (and worth recording)? */
	public static function ours( string $name ): bool {
		return self::OPTION !== $name
			&& str_starts_with( $name, self::PREFIX )
			&& ! str_contains( $name, '_transient_' )
			&& ! str_ends_with( $name, '.lock' );
	}

	/**
	 * Recorded option names.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		$names = get_option( self::OPTION, array() );
		return is_array( $names ) ? array_values( array_filter( $names, 'is_string' ) ) : array();
	}

	/** One-time back-fill for sites that had options before the registry existed. */
	public static function maybe_backfill(): void {
		if ( 'yes' === get_option( self::BACKFILLED ) ) {
			return;
		}
		$found = array();
		foreach ( array_keys( wp_load_alloptions() ) as $name ) {
			if ( is_string( $name ) && self::ours( $name ) ) {
				$found[] = $name;
			}
		}
		foreach ( self::known_unautoloaded() as $name ) {
			if ( null !== get_option( $name, null ) ) {
				$found[] = $name;
			}
		}
		self::add( $found );
		update_option( self::BACKFILLED, 'yes', false );
	}

	/**
	 * Options this plugin writes with autoload off (invisible to wp_load_alloptions()), plus the
	 * registry's own flag. Only needed for the back-fill and as uninstall's safety net.
	 *
	 * @return array<int,string>
	 */
	public static function known_unautoloaded(): array {
		return array(
			'moksafopoi_db_version',
			'moksafopoi_defaults_seeded',
			'moksafopoi_walkthrough_dismissed',
			'moksafopoi_migrated_storecredit',
			'moksafopoi_mcp_audit',
			'moksafopoi_webhook_log',
			'moksafopoi_settings_audit_log',
			'moksafopoi_rewards_storage',
			'moksafopoi_rewards_legacy_fp',
			'moksafopoi_rewards_migration_errors',
			'moksafopoi_rewards_legacy_changed',
			'moksafopoi_ref_index_ready',
			'moksafopoi_ref_index_page',
			self::BACKFILLED,
		);
	}

	/**
	 * Merge names into the registry.
	 *
	 * @param array<int,string> $names Option names.
	 */
	private static function add( array $names ): void {
		$current = self::names();
		$merged  = array_values( array_unique( array_merge( $current, $names ) ) );
		if ( count( $merged ) !== count( $current ) ) {
			update_option( self::OPTION, $merged, false );
		}
	}

	private function __construct() {}
}
