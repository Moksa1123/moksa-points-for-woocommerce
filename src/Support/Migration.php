<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * One-time import of a legacy moforcoupon store-credit balance into the ledger as a
 * single opening-balance row, idempotently (per-user UNIQUE idempotency key + a global
 * sentinel). Safe to re-run: the UNIQUE index makes re-import a no-op. Leaves the old
 * `_moforcoupon_store_credit*` meta untouched for rollback. See ARCHITECTURE.md §8.
 */
final class Migration {

	private const SENTINEL  = 'moksafopoi_migrated_storecredit';
	private const LEGACY_KEY = '_moforcoupon_store_credit';

	/** Run once after upgrades, on admin_init. Cheap sentinel guard. */
	public static function maybe_run(): void {
		if ( 'yes' === get_option( self::SENTINEL ) ) {
			return;
		}
		self::run();
	}

	public static function run(): int {
		global $wpdb;
		$imported = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration scan of a legacy meta key.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' AND meta_value <> '0'",
				self::LEGACY_KEY
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$user_id = (int) ( $row['user_id'] ?? 0 );
			$balance = (float) ( $row['meta_value'] ?? 0 );
			if ( $user_id <= 0 || $balance <= 0 ) {
				continue;
			}
			$ok = Ledger::record_once(
				$user_id,
				0,
				'credit',
				'migration',
				'storecredit:' . $user_id,
				array(
					'amount_delta' => $balance,
					'note'         => __( 'Opening balance transferred in from moforcoupon store credit', 'moksa-points-for-woocommerce' ),
					'meta'         => array( 'legacy' => self::LEGACY_KEY ),
				)
			);
			if ( $ok ) {
				++$imported;
			}
		}

		update_option( self::SENTINEL, 'yes', false );
		return $imported;
	}
}
