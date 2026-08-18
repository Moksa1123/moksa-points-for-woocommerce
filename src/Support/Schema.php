<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Custom-table schema for the value ledger + earn rules + redeem catalog. A financial
 * ledger is append-only, indexed and aggregated — the textbook wrong fit for CPT/meta —
 * so it lives in proper tables created via dbDelta. Idempotent: safe to run on every
 * activation and on db-version upgrades.
 */
final class Schema {

	public static function ledger_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafopoi_ledger';
	}

	public static function rules_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafopoi_rules';
	}

	public static function rewards_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafopoi_rewards';
	}

	/** Create / upgrade the tables. Called on activation and when the db-version option lags. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$ledger  = self::ledger_table();
		$rules   = self::rules_table();
		$rewards = self::rewards_table();

		// dbDelta is whitespace-sensitive and wants exactly this formatting.
		$sql = array();

		$sql[] = "CREATE TABLE {$ledger} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			points_delta BIGINT NOT NULL,
			amount_delta DECIMAL(18,4) NOT NULL DEFAULT 0,
			balance_after BIGINT NOT NULL DEFAULT 0,
			type VARCHAR(32) NOT NULL,
			source VARCHAR(40) NOT NULL,
			source_ref VARCHAR(64) NOT NULL DEFAULT '',
			idempotency_key VARCHAR(191) NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idem (idempotency_key),
			KEY user_time (user_id, created_at),
			KEY user_expiry (user_id, expires_at),
			KEY source_ref (source, source_ref),
			KEY order_id (order_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$rules} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(120) NOT NULL DEFAULT '',
			kind VARCHAR(24) NOT NULL DEFAULT 'spend',
			rate DECIMAL(12,4) NOT NULL DEFAULT 0,
			target_ids LONGTEXT NULL,
			min_spend DECIMAL(18,4) NOT NULL DEFAULT 0,
			multiplier DECIMAL(6,3) NOT NULL DEFAULT 1,
			priority INT NOT NULL DEFAULT 10,
			active TINYINT(1) NOT NULL DEFAULT 1,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY active_priority (active, priority)
		) {$charset};";

		$sql[] = "CREATE TABLE {$rewards} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(120) NOT NULL DEFAULT '',
			kind VARCHAR(24) NOT NULL DEFAULT 'coupon',
			cost_points BIGINT NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			stock INT NOT NULL DEFAULT -1,
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'moksafopoi_db_version', MOKSAFOPOI_DB_VERSION, false );
	}

	/** Run install() when the stored db-version is behind the code (cheap idempotent guard). */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( 'moksafopoi_db_version', '0' ) !== MOKSAFOPOI_DB_VERSION ) {
			self::install();
		}
	}
}
