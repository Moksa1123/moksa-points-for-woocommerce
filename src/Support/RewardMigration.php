<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Moves the redemption catalog from the old `{prefix}moksafopoi_rewards` table into the RewardStore CPT
 * (owner-approved 2026-11-15, docs/批2-資料庫改寫設計.md §2.C and §5b-3).
 *
 * - Runs once, row by row, under a lock. Each migrated post is named reward-legacy-{old id} and keeps the
 *   old id in meta, so a re-run never duplicates a row and ledger refs keep using the old id
 *   (per-member limits and redeem-once codes carry on unchanged).
 * - Every row is read back and compared field by field (label, kind, points, payload, active, stock).
 *   Only when all rows match is the catalog marked as moved; otherwise the mismatches are kept for the
 *   admin notice and the run is retried at most once an hour.
 * - The old table is NOT deleted, so downgrading to 1.0.x still finds the catalog as it was at migration
 *   time. If the old table changes afterwards (someone ran the old version), the admin is asked whether to
 *   import it again (overwriting the migrated rows) or keep the current catalog — never automatically.
 */
final class RewardMigration {

	public const STATE = 'moksafopoi_rewards_storage';

	private const FINGERPRINT = 'moksafopoi_rewards_legacy_fp';
	private const ERRORS      = 'moksafopoi_rewards_migration_errors';
	private const CHANGED     = 'moksafopoi_rewards_legacy_changed';
	private const RETRY_GATE  = 'moksafopoi_rewards_migration_retry';
	private const CHECK_GATE  = 'moksafopoi_rewards_legacy_check';
	private const ACTION      = 'moksafopoi_rewards_reimport';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'maybe_migrate' ), 20 );
		add_action( 'admin_init', array( self::class, 'maybe_detect_change' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function migrated(): bool {
		return 'cpt' === FreshOption::get( self::STATE );
	}

	public static function maybe_migrate(): void {
		if ( self::migrated() || false !== get_transient( self::RETRY_GATE ) ) {
			return;
		}
		self::run( false );
	}

	/**
	 * Copy (or, with $overwrite, re-copy) every old row into the CPT and verify it.
	 *
	 * @return array{rows:int,created:int,updated:int,errors:array<int,string>}|null Null when another request holds the lock.
	 */
	public static function run( bool $overwrite ): ?array {
		if ( ! class_exists( 'WP_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! \WP_Upgrader::create_lock( 'moksafopoi_reward_migration', 300 ) ) {
			return null;
		}

		$out = array(
			'rows'    => 0,
			'created' => 0,
			'updated' => 0,
			'errors'  => array(),
		);
		try {
			foreach ( LegacyStorage::reward_rows() as $old ) {
				++$out['rows'];
				$legacy_id = (int) $old['id'];
				$data      = array(
					'label'       => (string) $old['label'],
					'kind'        => (string) $old['kind'],
					'cost_points' => (int) $old['cost_points'],
					'payload'     => (string) ( $old['payload'] ?? '' ),
					'stock'       => (int) $old['stock'],
					'active'      => (int) $old['active'] ? 1 : 0,
				);

				$existing = RewardStore::find_by_legacy_id( $legacy_id );
				if ( null === $existing ) {
					if ( RewardStore::save( 0, $data, $legacy_id ) > 0 ) {
						++$out['created'];
					}
				} elseif ( $overwrite || '' !== self::compare( $data, $existing ) ) {
					// A re-import, or an earlier attempt that left this row incomplete / different: write it again.
					RewardStore::save( (int) $existing['id'], $data, $legacy_id );
					++$out['updated'];
				}

				$mismatch = self::compare( $data, RewardStore::find_by_legacy_id( $legacy_id ) );
				if ( '' !== $mismatch ) {
					$out['errors'][] = sprintf( '#%d %s', $legacy_id, $mismatch );
				}
			}

			if ( array() === $out['errors'] ) {
				update_option( self::STATE, 'cpt', false );
				update_option( self::FINGERPRINT, LegacyStorage::reward_fingerprint(), false );
				delete_option( self::ERRORS );
				delete_option( self::CHANGED );
				delete_transient( self::RETRY_GATE );
			} else {
				update_option( self::ERRORS, $out['errors'], false );
				set_transient( self::RETRY_GATE, 1, HOUR_IN_SECONDS );
			}
		} finally {
			\WP_Upgrader::release_lock( 'moksafopoi_reward_migration' );
		}
		return $out;
	}

	/**
	 * '' when the migrated post matches the old row, else the first differing field.
	 *
	 * @param array<string,mixed>      $want
	 * @param array<string,mixed>|null $got
	 */
	private static function compare( array $want, ?array $got ): string {
		if ( null === $got ) {
			return 'missing';
		}
		foreach ( array( 'label', 'kind', 'cost_points', 'payload', 'stock', 'active' ) as $field ) {
			if ( (string) $want[ $field ] !== (string) $got[ $field ] ) {
				return $field;
			}
		}
		return '';
	}

	/** Admin, at most twice a day: did the old table change after migration (a downgrade was used)? */
	public static function maybe_detect_change(): void {
		if ( ! self::migrated() || ! current_user_can( 'manage_woocommerce' ) || false !== get_transient( self::CHECK_GATE ) ) {
			return;
		}
		set_transient( self::CHECK_GATE, 1, 12 * HOUR_IN_SECONDS );
		$now = LegacyStorage::reward_fingerprint();
		if ( '' !== $now && (string) get_option( self::FINGERPRINT, '' ) !== $now ) {
			update_option( self::CHANGED, 'yes', false );
		}
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$done = RedirectNotice::get( 'moksafopoi_rewards_reimport' );
		if ( null !== $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Redemption catalog updated.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		}

		$errors = get_option( self::ERRORS );
		if ( is_array( $errors ) && array() !== $errors ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Moksa Points could not move every redemption item to the new storage. It will try again within an hour; until then the items listed here may be missing or outdated:', 'moksa-points-for-woocommerce' )
				. ' ' . esc_html( implode( ', ', array_slice( array_map( 'strval', $errors ), 0, 20 ) ) ) . '</p></div>';
		}

		if ( 'yes' !== get_option( self::CHANGED ) ) {
			return;
		}
		$reimport = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'op' => 'reimport',
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
		$keep     = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'op' => 'keep',
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'The redemption catalog in the old storage changed after it was moved (an older version of Moksa Points was probably used in between). Import it again, overwriting the moved items, or keep the current catalog?', 'moksa-points-for-woocommerce' )
			. '</p><p><a class="button button-primary" href="' . esc_url( $reimport ) . '">' . esc_html__( 'Import from old storage again', 'moksa-points-for-woocommerce' ) . '</a> '
			. '<a class="button" href="' . esc_url( $keep ) . '">' . esc_html__( 'Keep current catalog', 'moksa-points-for-woocommerce' ) . '</a></p></div>';
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( self::ACTION );
		$op = sanitize_key( Input::get( 'op' ) );
		if ( 'reimport' === $op ) {
			self::run( true );
		} elseif ( 'keep' === $op ) {
			update_option( self::FINGERPRINT, LegacyStorage::reward_fingerprint(), false );
			delete_option( self::CHANGED );
		}
		wp_safe_redirect( RedirectNotice::url( array( 'moksafopoi_rewards_reimport' => $op ), admin_url( 'admin.php?page=moksafopoi-rewards' ) ) );
		exit;
	}

	private function __construct() {}
}
