<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Order-level earn multipliers that are NOT per-line: the member's role multiplier and the
 * spend-tier (滿額加倍) bonus. Both are stored as plain options (site-wide rules → options, never
 * term meta, never a CPT):
 *
 *  moksafopoi_role_multipliers   JSON map role-slug => float (default 1.0). The customer's HIGHEST
 *                                 role multiplier applies (a VIP+wholesale member gets the better one).
 *  moksafopoi_spend_bonus_tiers  JSON list [{min:float, mult:float}, ...]. The earn BASE (rate_base
 *                                 + override points, before any multiplier) is matched against the
 *                                 highest tier whose `min` it reaches; that tier's `mult` applies.
 *
 * Each factor is clamped to a sane ceiling (≤ MAX) so no combination explodes the award. Stacking
 * order is documented in SpendRules: final = tier-filter × role × spend-bonus, with the category
 * multiplier applied per line. Every factor is individually filterable.
 */
final class Multipliers {

	public const OPTION_ROLE_MULTIPLIERS = 'moksafopoi_role_multipliers';
	public const OPTION_SPEND_TIERS      = 'moksafopoi_spend_bonus_tiers';

	/** Hard ceiling on any single multiplier factor (defence against runaway awards). */
	public const MAX_MULTIPLIER = 10.0;

	/* ---------------------------------------------------------------- role */

	/**
	 * The configured role => multiplier map (clamped). Empty array when nothing is configured.
	 *
	 * @return array<string,float>
	 */
	public static function role_map(): array {
		$raw = get_option( self::OPTION_ROLE_MULTIPLIERS, '' );
		$map = array();
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$map = $decoded;
			}
		} elseif ( is_array( $raw ) ) {
			$map = $raw;
		}

		$clean = array();
		foreach ( $map as $role => $mult ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role ) {
				continue;
			}
			$clean[ $role ] = self::clamp( (float) $mult );
		}
		return $clean;
	}

	/**
	 * The HIGHEST role multiplier for a user (default 1.0 when no role is configured / matches).
	 * Filterable so a sibling (e.g. moformember tiers) can override.
	 */
	public static function role_multiplier( int $user_id ): float {
		$mult = 1.0;
		$map  = self::role_map();
		if ( $user_id > 0 && array() !== $map ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				foreach ( (array) $user->roles as $role ) {
					$role = sanitize_key( (string) $role );
					if ( isset( $map[ $role ] ) ) {
						$mult = max( $mult, (float) $map[ $role ] );
					}
				}
			}
		}

		/**
		 * Filter the resolved role multiplier for a user.
		 *
		 * @param float $mult    The highest matching role multiplier (>=1.0, clamped).
		 * @param int   $user_id The customer id.
		 */
		return self::clamp( (float) apply_filters( 'moksafopoi_role_multiplier', $mult, $user_id ) );
	}

	/* ---------------------------------------------------------------- spend tiers */

	/**
	 * The configured spend-bonus tiers, normalised to a list of {min,mult} sorted by `min` ASC.
	 *
	 * @return array<int,array{min:float,mult:float}>
	 */
	public static function tiers(): array {
		$raw  = get_option( self::OPTION_SPEND_TIERS, '' );
		$list = array();
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$list = $decoded;
			}
		} elseif ( is_array( $raw ) ) {
			$list = $raw;
		}

		$tiers = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$min  = isset( $row['min'] ) ? max( 0.0, (float) $row['min'] ) : 0.0;
			$mult = isset( $row['mult'] ) ? self::clamp( (float) $row['mult'] ) : 1.0;
			if ( $min <= 0.0 ) {
				continue; // A tier with no spend floor is meaningless.
			}
			$tiers[] = array(
				'min'  => $min,
				'mult' => $mult,
			);
		}

		usort(
			$tiers,
			static function ( array $a, array $b ): int {
				return $a['min'] <=> $b['min'];
			}
		);
		return $tiers;
	}

	/**
	 * The spend-tier (滿額加倍) bonus multiplier for a given earn base amount: the `mult` of the
	 * highest tier whose `min` the base reaches. Default 1.0 (no tier reached / none configured).
	 *
	 * @param float $base The earn base (rate_base + override points), pre-multiplier.
	 */
	public static function spend_bonus( float $base ): float {
		$mult = 1.0;
		foreach ( self::tiers() as $tier ) {
			if ( $base >= $tier['min'] ) {
				$mult = $tier['mult']; // tiers are sorted ASC, so the last match is the highest reached.
			}
		}

		/**
		 * Filter the resolved spend-tier bonus multiplier.
		 *
		 * @param float $mult The matched tier multiplier (>=1.0, clamped).
		 * @param float $base The earn base used to match.
		 */
		return self::clamp( (float) apply_filters( 'moksafopoi_spend_bonus_multiplier', $mult, $base ) );
	}

	/* ---------------------------------------------------------------- util */

	/** Clamp a multiplier to (0 .. MAX_MULTIPLIER]; non-positive / invalid falls back to 1.0. */
	public static function clamp( float $value ): float {
		if ( $value <= 0.0 ) {
			return 1.0;
		}
		return min( self::MAX_MULTIPLIER, $value );
	}
}
