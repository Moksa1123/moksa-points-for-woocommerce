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
	 * Manual admin credit/debit (idempotent on a caller-supplied bucket).
	 */
	public static function adjust( int $user_id, int $points, string $note, string $bucket = '' ): bool {
		return Ledger::record_once(
			$user_id,
			$points,
			$points >= 0 ? 'adjust' : 'debit',
			'manual',
			'' !== $bucket ? $bucket : (string) time(),
			array(
				'note'       => $note,
				'created_by' => get_current_user_id(),
				'bucket'     => $bucket,
			)
		);
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
