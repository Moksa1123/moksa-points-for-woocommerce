<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * TEMPORARY: the last direct reads of storage this plugin is moving away from. Owner-approved
 * intermediate exception (2026-11-15, docs/批2-資料庫改寫設計.md §2.B/C/G, §4); this whole file and its
 * phpcs.xml exclusion are deleted in the following release.
 *
 * - The old redemption-catalog table: read once by RewardMigration to copy every row into the CPT,
 *   fingerprinted to notice a downgrade-then-upgrade, and dropped on uninstall. Nothing else reads it.
 * - The never-written earn-rules table: dropped on uninstall.
 * - Referral codes: until RefCodeIndex has back-filled the taxonomy, a code that is not indexed yet is
 *   looked up by its user meta (the former query).
 */
final class LegacyStorage {

	public static function rewards_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafopoi_rewards';
	}

	public static function rules_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafopoi_rules';
	}

	public static function rewards_table_exists(): bool {
		global $wpdb;
		$table = self::rewards_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** @return array<int,array<string,mixed>> Every row of the old catalog table, oldest first. */
	public static function reward_rows(): array {
		global $wpdb;
		if ( ! self::rewards_table_exists() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, label, kind, cost_points, payload, active, stock FROM %i ORDER BY id ASC', self::rewards_table() ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Fingerprint of the old catalog table ('' when the table is gone). */
	public static function reward_fingerprint(): string {
		if ( ! self::rewards_table_exists() ) {
			return '';
		}
		return md5( (string) wp_json_encode( self::reward_rows() ) );
	}

	/** Owner of a referral code by user meta (fallback while the code index is still being built). */
	public static function user_by_ref_code_meta( string $meta_key, string $code ): ?int {
		$users = get_users(
			array(
				'meta_key'   => $meta_key,
				'meta_value' => $code,
				'fields'     => 'ID',
				'number'     => 1,
			)
		);
		return ! empty( $users ) ? (int) $users[0] : null;
	}

	/** Uninstall: drop the old catalog and rules tables. */
	public static function drop_tables(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::rewards_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::rules_table() ) );
	}

	private function __construct() {}
}
