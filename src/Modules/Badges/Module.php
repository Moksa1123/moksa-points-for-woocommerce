<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Badges;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 成就徽章 — milestone badges awarded when a member's CUMULATIVE earned points (the running sum
 * of positive ledger deltas) crosses a configured threshold. The ladder lives in the
 * `moksafopoi_badges` JSON option (seeded on activation), e.g. 銅 1000 / 銀 5000 / 金 10000.
 *
 * No CPT, no new table: earned badges are stored in the `_moksafopoi_badges` user-meta as an
 * array of crossed thresholds. Awarding is idempotent — a threshold already in that array is
 * never re-granted, so a replayed earn hook (or a manual recheck) never double-awards. Checks
 * run right after every points credit (the moksafopoi_points_earned action) and, as a cheap
 * safety net, lazily for the current member on page load.
 */
final class Module extends AbstractModule {

	/** User-meta holding the array of already-earned thresholds (ints). */
	public const META_KEY = '_moksafopoi_badges';

	/** Option holding the JSON ladder. */
	public const OPTION = 'moksafopoi_badges';

	public function slug(): string {
		return 'badges';
	}

	public function label(): string {
		return __( 'Achievement badges', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'Award a badge when cumulative earned points cross a threshold (Bronze / Silver / Gold, thresholds configurable); idempotent, no duplicate awards', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Primary path: re-check immediately after any points are credited.
		// Ledger fires: ($user_id, $points_delta, $source, $args).
		add_action( 'moksafopoi_points_earned', array( self::class, 'on_points_earned' ), 10, 1 );

		// Cheap safety net: lazily evaluate the logged-in member once per request so a member
		// whose points predate this module (or arrived via a path that skipped the action) still
		// gets caught. Idempotent, so this can never double-award.
		add_action( 'wp', array( self::class, 'lazy_check_current_user' ) );
	}

	/** Re-evaluate badges for the member who just earned points. */
	public static function on_points_earned( int $user_id ): void {
		if ( $user_id > 0 ) {
			self::evaluate( $user_id );
		}
	}

	/** Lazily evaluate the current member (front-end only, logged-in). */
	public static function lazy_check_current_user(): void {
		if ( is_admin() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			self::evaluate( $user_id );
		}
	}

	/**
	 * Grant every ladder badge whose threshold the member's cumulative earned points now meets but
	 * which they have not yet earned. Idempotent: a threshold already recorded in the user-meta is
	 * skipped, so the same badge is never granted twice. Returns the list of newly-granted badges.
	 *
	 * @return array<int,array{threshold:int,label:string,icon:string}>
	 */
	public static function evaluate( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$ladder = self::ladder();
		if ( array() === $ladder ) {
			return array();
		}

		$earned_total = self::total_earned( $user_id );
		$already      = self::earned_thresholds( $user_id );
		$newly        = array();

		foreach ( $ladder as $badge ) {
			$threshold = (int) $badge['threshold'];
			if ( $threshold <= 0 || $earned_total < $threshold ) {
				continue; // Not reached yet.
			}
			if ( in_array( $threshold, $already, true ) ) {
				continue; // Idempotent: already granted.
			}
			$already[] = $threshold;
			$newly[]   = $badge;
		}

		if ( array() === $newly ) {
			return array();
		}

		sort( $already, SORT_NUMERIC );
		update_user_meta( $user_id, self::META_KEY, array_values( array_unique( $already ) ) );

		foreach ( $newly as $badge ) {
			/**
			 * Fired once when a member first earns a badge.
			 *
			 * @param int                                            $user_id The member.
			 * @param array{threshold:int,label:string,icon:string} $badge   The badge definition.
			 */
			do_action( 'moksafopoi_badge_earned', $user_id, $badge );
		}

		return $newly;
	}

	/**
	 * Cumulative points EARNED by a member (SUM of positive ledger deltas). This is the badge
	 * yardstick — distinct from the current balance, so spending points never revokes a badge.
	 * Tolerant of an empty / missing table.
	 */
	public static function total_earned( int $user_id ): int {
		// One definition of "lifetime earned", shared with the tier ladder (and excluding points the
		// member bought, unless the shop opted in) — the two ladders must never disagree.
		return \Moksafopoi\Support\Tiers::basis( $user_id );
	}

	/**
	 * Thresholds the member has already earned (sorted ascending ints).
	 *
	 * @return array<int,int>
	 */
	public static function earned_thresholds( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $value ) {
			$value = (int) $value;
			if ( $value > 0 ) {
				$out[] = $value;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_NUMERIC );
		return $out;
	}

	/**
	 * Badges the member currently holds, as full definitions, ordered by threshold ascending.
	 *
	 * @return array<int,array{threshold:int,label:string,icon:string}>
	 */
	public static function earned_badges( int $user_id ): array {
		$thresholds = self::earned_thresholds( $user_id );
		if ( array() === $thresholds ) {
			return array();
		}
		$out = array();
		foreach ( self::ladder() as $badge ) {
			if ( in_array( (int) $badge['threshold'], $thresholds, true ) ) {
				$out[] = $badge;
			}
		}
		return $out;
	}

	/**
	 * The next badge a member is working toward, plus how far away it is. Returns null when every
	 * ladder badge is already earned.
	 *
	 * @return array{badge:array{threshold:int,label:string,icon:string},remaining:int,earned_total:int}|null
	 */
	public static function next_badge( int $user_id ): ?array {
		$ladder = self::ladder();
		if ( array() === $ladder ) {
			return null;
		}
		$earned_total = self::total_earned( $user_id );
		foreach ( $ladder as $badge ) {
			$threshold = (int) $badge['threshold'];
			if ( $earned_total < $threshold ) {
				return array(
					'badge'        => $badge,
					'remaining'    => $threshold - $earned_total,
					'earned_total' => $earned_total,
				);
			}
		}
		return null; // Top of the ladder reached.
	}

	/**
	 * The badge ladder, normalised and sorted ascending by threshold. Read from the option (JSON);
	 * falls back to the bundled defaults when the option is empty / malformed. Filterable.
	 *
	 * @return array<int,array{threshold:int,label:string,icon:string}>
	 */
	public static function ladder(): array {
		$raw     = get_option( self::OPTION, '' );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );

		if ( ! is_array( $decoded ) || array() === $decoded ) {
			$decoded = self::default_ladder();
		}

		$ladder = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$threshold = isset( $row['threshold'] ) ? (int) $row['threshold'] : 0;
			if ( $threshold <= 0 ) {
				continue;
			}
			$ladder[] = array(
				'threshold' => $threshold,
				'label'     => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'icon'      => isset( $row['icon'] ) ? sanitize_text_field( (string) $row['icon'] ) : '',
			);
		}

		usort(
			$ladder,
			static function ( array $a, array $b ): int {
				return $a['threshold'] <=> $b['threshold'];
			}
		);

		/**
		 * Filter the badge ladder.
		 *
		 * @param array<int,array{threshold:int,label:string,icon:string}> $ladder Normalised, ascending.
		 */
		return (array) apply_filters( 'moksafopoi_badges_ladder', $ladder );
	}

	/**
	 * Bundled default ladder, also seeded into the option on activation.
	 *
	 * @return array<int,array{threshold:int,label:string,icon:string}>
	 */
	public static function default_ladder(): array {
		return array(
			array(
				'threshold' => 1000,
				'label'     => __( 'Bronze', 'moksa-points-for-woocommerce' ),
				'icon'      => '🥉',
			),
			array(
				'threshold' => 5000,
				'label'     => __( 'Silver', 'moksa-points-for-woocommerce' ),
				'icon'      => '🥈',
			),
			array(
				'threshold' => 10000,
				'label'     => __( 'Gold', 'moksa-points-for-woocommerce' ),
				'icon'      => '🥇',
			),
		);
	}

	/** The JSON seeded into the option on first activation. */
	public static function default_ladder_json(): string {
		// Build with raw (untranslated) labels so the stored option is locale-stable; the live
		// ladder() re-reads labels through __() only for the bundled fallback, while option labels
		// are shown verbatim — acceptable, and an operator can edit them.
		$seed = array(
			array(
				'threshold' => 1000,
				'label'     => 'Bronze',
				'icon'      => '🥉',
			),
			array(
				'threshold' => 5000,
				'label'     => 'Silver',
				'icon'      => '🥈',
			),
			array(
				'threshold' => 10000,
				'label'     => 'Gold',
				'icon'      => '🥇',
			),
		);
		return (string) wp_json_encode( $seed );
	}
}
