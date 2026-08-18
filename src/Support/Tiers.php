<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 會員階梯 — the ladder behind「距金卡還差 Y 點」, and the single place that decides WHERE a tier
 * comes from.
 *
 * Two sources, in priority order:
 *
 *   1. **moformember** (the membership sibling). When it is installed it owns membership tiers, so
 *      moforpoints reads them (`moformember_tiers()` / `moformember_get_tier()`) instead of
 *      inventing a second, conflicting ladder. Whatever the member plugin says a member IS, is what
 *      we display.
 *   2. **Native points ladder.** Standalone, the ladder degrades to one driven by CUMULATIVE EARNED
 *      points (the same basis the badges use) — configured in the `moksafopoi_tier_ladder` option.
 *
 * Read-only: nothing here writes a tier, awards anything, or touches the ledger. The ladder itself
 * is a plain option (never a CPT), normalised and sorted on every read.
 */
final class Tiers {

	/** Option holding the native ladder as JSON. */
	public const OPTION = 'moksafopoi_tier_ladder';

	/** How many rows the ladder editor offers (and the most we will store). */
	public const MAX_ROWS = 6;

	/**
	 * The ladder, ascending by threshold, with an entry-level tier at threshold 0 when the operator
	 * did not define one (so every member is always inside the ladder somewhere).
	 *
	 * @return array<int,array{key:string,label:string,threshold:int}>
	 */
	public static function ladder(): array {
		$rows = self::sibling_ladder();
		if ( null === $rows ) {
			$rows = self::native_rows();
		}

		$ladder = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$threshold = isset( $row['threshold'] ) ? max( 0, (int) $row['threshold'] ) : 0;
			$key       = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( '' === $key ) {
				$key = 'tier_' . $threshold;
			}
			$ladder[] = array(
				'key'       => $key,
				'label'     => $label,
				'threshold' => $threshold,
			);
		}

		usort(
			$ladder,
			static function ( array $a, array $b ): int {
				return $a['threshold'] <=> $b['threshold'];
			}
		);

		/**
		 * Filter the member tier ladder (a sibling plugin may replace it wholesale).
		 *
		 * @param array<int,array{key:string,label:string,threshold:int}> $ladder Ascending by threshold.
		 */
		return (array) apply_filters( 'moksafopoi_tier_ladder', $ladder );
	}

	/**
	 * Where a member stands: their current tier, the next one, and how far away it is.
	 *
	 * `basis` is cumulative EARNED points — spending never demotes anyone, which is what every
	 * loyalty programme customers already understand does (and what the badges use too).
	 *
	 * When a membership sibling NAMES the member's tier, that name wins — but the distance to the
	 * next rung is then left unknown (`remaining`/`progress` are null), because the sibling may rank
	 * on spend, on a manual assignment, or on anything else; quoting "2,600 points to Silver" at a
	 * member the sibling already calls Platinum is worse than saying nothing.
	 *
	 * @return array{basis:int,tier:?array{key:string,label:string,threshold:int},next:?array{key:string,label:string,threshold:int},remaining:?int,progress:?float,top:bool,named:bool,source:string}|null
	 *         Null when no ladder is configured at all.
	 */
	public static function status( int $user_id ): ?array {
		$ladder = self::ladder();
		if ( array() === $ladder ) {
			return null;
		}

		$basis   = self::basis( $user_id );
		$index   = null;

		foreach ( $ladder as $i => $tier ) {
			if ( $basis >= $tier['threshold'] ) {
				$index = $i;
				continue;
			}
			break;
		}

		// A membership sibling is the authority on what a member IS — but ONLY when the ladder is
		// also the sibling's. With a native points ladder its tier names are from another namespace,
		// and matching them by string is a coincidence, not a fact: a sibling tier that happens to be
		// called "platinum" must not promote a 400-point member to our Platinum rung.
		$named       = false;
		$sibling_key = ( null !== self::sibling_ladder() ) ? self::sibling_current_label( $user_id ) : null;
		if ( null !== $sibling_key ) {
			foreach ( $ladder as $i => $tier ) {
				if ( $tier['key'] === $sibling_key || $tier['label'] === $sibling_key ) {
					$index = $i;
					$named = true;
					break;
				}
			}
		}

		$current = ( null !== $index ) ? $ladder[ $index ] : null;
		$next    = $ladder[ ( null !== $index ? $index : -1 ) + 1 ] ?? null;

		$remaining = null;
		$progress  = null;
		if ( ! $named ) {
			$remaining = ( null !== $next ) ? max( 0, $next['threshold'] - $basis ) : 0;

			// Progress across the CURRENT band (from this tier's threshold to the next one), so the bar
			// restarts at each tier instead of creeping asymptotically towards the top.
			$floor    = ( null !== $current ) ? $current['threshold'] : 0;
			$ceiling  = ( null !== $next ) ? $next['threshold'] : $floor;
			$band     = $ceiling - $floor;
			$progress = ( $band > 0 ) ? min( 1.0, max( 0.0, ( $basis - $floor ) / $band ) ) : 1.0;
		}

		return array(
			'basis'     => $basis,
			'tier'      => $current,
			'next'      => $next,
			'remaining' => $remaining,
			'progress'  => $progress,
			'top'       => ( null === $next ),
			'named'     => $named,
			'source'    => ( null === self::sibling_ladder() ) ? 'points' : 'member',
		);
	}

	/**
	 * Cumulative EARNED points — the sum of positive ledger deltas, excluding points the member
	 * BOUGHT (`topup`). Buying your way to Gold devalues the ladder for everyone who earned it, so a
	 * shop must opt in explicitly with `moksafopoi_topup_counts_toward_tier`.
	 *
	 * This is the single definition of "lifetime earned" — the badge ladder reads it too, so the two
	 * gamification surfaces can never disagree about what a member has earned.
	 */
	public static function basis( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = Schema::ledger_table();

		if ( 'yes' === get_option( 'moksafopoi_topup_counts_toward_tier', 'no' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); user id bound via $wpdb->prepare().
			$all = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE user_id = %d AND points_delta > 0",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return (int) $all;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); user id bound via $wpdb->prepare().
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE user_id = %d AND points_delta > 0 AND type <> 'topup'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $sum;
	}

	/**
	 * The ladder as the membership sibling defines it, or null when moformember is absent / returns
	 * nothing usable (in which case the native ladder is used).
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function sibling_ladder(): ?array {
		if ( ! function_exists( 'moformember_tiers' ) ) {
			return null;
		}
		$tiers = moformember_tiers();
		if ( ! is_array( $tiers ) || array() === $tiers ) {
			return null;
		}

		$out = array();
		foreach ( $tiers as $key => $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$label = (string) ( $tier['label'] ?? $tier['name'] ?? ( is_string( $key ) ? $key : '' ) );
			if ( '' === $label ) {
				continue;
			}
			$out[] = array(
				'key'       => (string) ( $tier['key'] ?? ( is_string( $key ) ? $key : '' ) ),
				'label'     => $label,
				'threshold' => (int) ( $tier['threshold'] ?? $tier['min_points'] ?? $tier['min'] ?? 0 ),
			);
		}

		return array() === $out ? null : $out;
	}

	/** The member's tier key/label according to the membership sibling, when it can tell us. */
	private static function sibling_current_label( int $user_id ): ?string {
		if ( $user_id <= 0 || ! function_exists( 'moformember_get_tier' ) ) {
			return null;
		}
		$tier = moformember_get_tier( $user_id );
		if ( is_array( $tier ) ) {
			$tier = (string) ( $tier['key'] ?? $tier['label'] ?? '' );
		}
		$tier = is_string( $tier ) ? trim( $tier ) : '';
		return '' !== $tier ? $tier : null;
	}

	/**
	 * The native ladder rows from the option, falling back to a sensible starter ladder.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function native_rows(): array {
		$raw     = get_option( self::OPTION, '' );
		$decoded = null;
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		}

		if ( ! is_array( $decoded ) || array() === $decoded ) {
			return self::default_rows();
		}
		return $decoded;
	}

	/**
	 * The starter ladder: an entry level everyone is already in, then three milestones.
	 *
	 * @return array<int,array{key:string,label:string,threshold:int}>
	 */
	public static function default_rows(): array {
		return array(
			array(
				'key'       => 'member',
				'label'     => __( 'Member', 'moksa-points-for-woocommerce' ),
				'threshold' => 0,
			),
			array(
				'key'       => 'silver',
				'label'     => __( 'Silver', 'moksa-points-for-woocommerce' ),
				'threshold' => 3000,
			),
			array(
				'key'       => 'gold',
				'label'     => __( 'Gold', 'moksa-points-for-woocommerce' ),
				'threshold' => 10000,
			),
			array(
				'key'       => 'platinum',
				'label'     => __( 'Platinum', 'moksa-points-for-woocommerce' ),
				'threshold' => 30000,
			),
		);
	}
}
