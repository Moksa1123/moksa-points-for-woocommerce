<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * First-run defaults. Every module is opt-in (off); on the very first activation we seed
 * the safe, additive core so a fresh install is immediately useful: the ledger itself,
 * the admin menu, the "My points" account page, and the earn-on-coupon + spend earn engines.
 * Value-moving modules that take over checkout (wallet) or absorb another plugin's state
 * stay OFF until the operator runs the takeover sequence (see ARCHITECTURE.md §8).
 *
 * Uses add_option (never overwrites a prior choice) gated by a one-time sentinel.
 */
final class Activation {

	private const SENTINEL = 'moksafopoi_defaults_seeded';

	/** @var array<int,string> */
	private const DEFAULTS = array( 'ledger', 'adminmenu', 'myaccount', 'engage', 'tierladder', 'leaderboard', 'badges', 'expiryreminder', 'abilities', 'earncoupon', 'earntriggers', 'spendrules', 'productpoints', 'categorypoints', 'rewardadmin', 'pointsadmin', 'campaign' );

	public static function on_activate(): void {
		if ( 'yes' === get_option( self::SENTINEL ) ) {
			return;
		}

		/**
		 * Filter the modules enabled on first activation.
		 *
		 * @param array<int,string> $defaults Module keys.
		 */
		$defaults = (array) apply_filters( 'moksafopoi_default_modules', self::DEFAULTS );
		foreach ( $defaults as $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				add_option( 'moksafopoi_' . $key . '_enabled', 'yes' );
			}
		}

		// Seed the achievement-badge ladder (銅 / 銀 / 金). add_option never overwrites an
		// operator's edited ladder on a re-activation.
		add_option( \Moksafopoi\Modules\Badges\Module::OPTION, \Moksafopoi\Modules\Badges\Module::default_ladder_json() );

		// Public leaderboard masks member names by default (privacy-first).
		add_option( 'moksafopoi_leaderboard_mask_names', 'yes' );

		update_option( self::SENTINEL, 'yes', false );
	}
}
