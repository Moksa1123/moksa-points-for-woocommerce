<?php

declare( strict_types=1 );

namespace Moksafopoi;

use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * The public read/redeem API — the ONLY way another plugin or the unified My Account
 * reads platform value. Canonical class methods here, plus always-on global wrappers
 * (registered by Plugin) so a sibling calls them behind a stable `function_exists`
 * probe without ever requiring a moksafopoi class.
 */
final class Api {

	/** Longest caller-supplied bucket / reference (the ledger's source_ref column is VARCHAR(64)). */
	public const REF_MAX = 64;

	public static function get_points( int $user_id ): int {
		return $user_id > 0 ? Ledger::points( $user_id ) : 0;
	}

	public static function get_balance( int $user_id ): float {
		return $user_id > 0 ? Ledger::credit( $user_id ) : 0.0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_history( int $user_id, int $limit = 50, int $offset = 0 ): array {
		return $user_id > 0 ? Ledger::history( $user_id, $limit, $offset ) : array();
	}

	public static function can_afford( int $user_id, int $cost_points ): bool {
		return $cost_points >= 0 && self::get_points( $user_id ) >= $cost_points;
	}

	/**
	 * Manual admin credit/debit (idempotent on a caller-supplied bucket). True only when a new row
	 * was written; a repeat of the same bucket, a deduction larger than the balance, or a busy lock
	 * all return false. Use {@see adjust_detailed()} to tell those apart.
	 */
	public static function adjust( int $user_id, int $points, string $note, string $bucket = '' ): bool {
		return 'applied' === self::adjust_detailed( $user_id, $points, $note, $bucket )['status'];
	}

	/**
	 * Manual credit/debit with a result a caller can act on.
	 *
	 * A deduction never takes the balance below zero: the balance check and the write happen under the
	 * member's lock, so two concurrent deductions cannot both pass the check. A repeat of the same
	 * bucket reports the row the first call wrote, even if the balance has changed since.
	 *
	 * status: applied | duplicate | conflict | insufficient | invalid | busy | error
	 *   conflict — the bucket was already booked with a DIFFERENT number of points (nothing written)
	 *   error    — the write failed (nothing written; safe to retry)
	 *
	 * A bucket is at most REF_MAX characters (the ledger column is 64; a longer one is refused as
	 * invalid rather than silently cut, which would break duplicate detection).
	 *
	 * @param array<string,mixed> $args Optional: order_id, meta (stored on the ledger row).
	 * @return array{status:string,points:int,balance:int,row_id:int}
	 */
	public static function adjust_detailed( int $user_id, int $points, string $note, string $bucket = '', array $args = array() ): array {
		$result = static function ( string $status, int $pts = 0, int $row_id = 0 ) use ( $user_id ): array {
			return array(
				'status'  => $status,
				'points'  => $pts,
				'balance' => $user_id > 0 ? self::get_points( $user_id ) : 0,
				'row_id'  => $row_id,
			);
		};
		if ( $user_id <= 0 || 0 === $points || mb_strlen( $bucket ) > self::REF_MAX ) {
			return $result( 'invalid' );
		}
		$ref = '' !== $bucket ? $bucket : (string) time();

		$out = Support\UserLock::with(
			$user_id,
			static function () use ( $user_id, $points, $note, $bucket, $ref, $args, $result ): array {
				if ( '' !== $bucket ) {
					$prior = Support\LedgerStore::row_for_ref( $user_id, 'manual', $ref );
					if ( null !== $prior ) {
						$status = (int) $prior['points_delta'] === $points ? 'duplicate' : 'conflict';
						return $result( $status, (int) $prior['points_delta'], (int) $prior['id'] );
					}
				}
				if ( $points < 0 && Ledger::points( $user_id ) < -$points ) {
					return $result( 'insufficient' );
				}
				$ok = Ledger::record_once(
					$user_id,
					$points,
					$points >= 0 ? 'adjust' : 'debit',
					'manual',
					$ref,
					array(
						'note'       => $note,
						'created_by' => get_current_user_id(),
						'bucket'     => $bucket,
						'order_id'   => (int) ( $args['order_id'] ?? 0 ),
						'meta'       => isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : null,
					)
				);
				$row = Support\LedgerStore::row_for_ref( $user_id, 'manual', $ref );
				if ( ! $ok ) {
					// Only a row that is really there makes this a repeat; otherwise the write failed.
					return null !== $row && '' !== $bucket
						? $result( 'duplicate', (int) $row['points_delta'], (int) $row['id'] )
						: $result( 'error' );
				}
				return $result( 'applied', $points, null !== $row ? (int) $row['id'] : 0 );
			}
		);

		return $out instanceof \WP_Error ? $result( 'busy' ) : $out;
	}

	/**
	 * Idempotent programmatic credit. Returns true when a new row was written.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function credit( int $user_id, int $points, string $source, string $source_ref, array $args = array() ): bool {
		return Ledger::record_once( $user_id, $points, ( $points >= 0 ? 'earn' : 'debit' ), $source, $source_ref, $args );
	}

	/**
	 * Spend points on a reward. Delegates to the redeem module when enabled.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function redeem( int $user_id, string $reward_kind, array $args = array() ) {
		if ( ! class_exists( Modules\Redeem\Service::class ) || ! Plugin::instance()->modules()->is_enabled( 'redeem' ) ) {
			return new \WP_Error( 'moksafopoi_redeem_off', __( 'The redeem feature is not enabled.', 'moksa-points-for-woocommerce' ) );
		}
		return Modules\Redeem\Service::redeem( $user_id, $reward_kind, $args );
	}

	/**
	 * The currently-active marketing campaign in the platform contract shape, or null when none is
	 * live. The single platform calendar every sibling reads (points/commission/member-discount
	 * boosts). Delegates to the Campaign module when enabled; null when the module is off so callers
	 * behave exactly as "no campaign running".
	 *
	 * @return array{id:string,name:string,points_mult:float,commission_mult:float,member_discount_pct:float,ends_at:int}|null
	 */
	public static function active_campaign(): ?array {
		if ( ! class_exists( Modules\Campaign\Campaigns::class ) || ! Plugin::instance()->modules()->is_enabled( 'campaign' ) ) {
			return null;
		}
		return Modules\Campaign\Campaigns::active();
	}

	/** Register the global `function_exists`-detectable wrappers siblings call. */
	public static function register_wrappers(): void {
		if ( function_exists( 'moksafopoi_get_points' ) ) {
			return;
		}
		require_once MOKSAFOPOI_PLUGIN_DIR . 'src/api-wrappers.php';
	}
}
