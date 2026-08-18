<?php
/**
 * Global read-API wrappers. Loaded always-on so a sibling plugin can call them behind a
 * `function_exists()` probe regardless of which modules are toggled on, and never has to
 * require a moksafopoi class. Thin pass-throughs to {@see \Moksafopoi\Api}.
 *
 * @package Moksafopoi
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Moksafopoi\Api;

if ( ! function_exists( 'moksafopoi_get_points' ) ) {
	/** Integer loyalty-points balance for a user. */
	function moksafopoi_get_points( int $user_id ): int {
		return Api::get_points( $user_id );
	}
}

if ( ! function_exists( 'moksafopoi_get_balance' ) ) {
	/** Currency store-credit balance (wallet / gift-card lane) for a user. */
	function moksafopoi_get_balance( int $user_id ): float {
		return Api::get_balance( $user_id );
	}
}

if ( ! function_exists( 'moksafopoi_can_afford' ) ) {
	function moksafopoi_can_afford( int $user_id, int $cost_points ): bool {
		return Api::can_afford( $user_id, $cost_points );
	}
}

if ( ! function_exists( 'moksafopoi_active_campaign' ) ) {
	/**
	 * The currently-active platform marketing campaign, or null when none is live.
	 *
	 * The single shared activity calendar: companion plugins (affiliate commission, member discount)
	 * read this instead of inventing their own. When several campaigns overlap, the one ending SOONEST
	 * (smallest ends_at) is returned. Shape:
	 *   array{
	 *     id:string, name:string, points_mult:float, commission_mult:float,
	 *     member_discount_pct:float, ends_at:int   // ends_at = unix seconds
	 *   }
	 *
	 * @return array{id:string,name:string,points_mult:float,commission_mult:float,member_discount_pct:float,ends_at:int}|null
	 */
	function moksafopoi_active_campaign(): ?array {
		return Api::active_campaign();
	}
}

if ( ! function_exists( 'moksafopoi_redeem' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>|WP_Error
	 */
	function moksafopoi_redeem( int $user_id, string $reward_kind, array $args = array() ) {
		return Api::redeem( $user_id, $reward_kind, $args );
	}
}
