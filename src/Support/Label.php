<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 點數品牌化 — the single source of truth for the points UNIT NAME shown to customers and the
 * dashboard. By default points read「N 點」, but a store can rebrand the unit to 金幣 / 哩程 / P /
 * Coins through the `moksafopoi_label` option, and every user-facing surface picks it up at once.
 *
 * Why a central helper: before this existed each surface (My Account, Mall, Leaderboard, Badges,
 * Dashboard) hand-rolled `sprintf( __( '%s point(s)' ), … )`, so rebranding meant editing every string.
 * Now they all call {@see Label::format()} / {@see Label::unit()} and stay in sync.
 *
 * Stored as a plain option (never a CPT). The value is sanitised on save and again on read.
 */
final class Label {

	/** Option holding the customised unit name (e.g.「金幣」「哩程」「P」). */
	public const OPTION = 'moksafopoi_label';

	/** Built-in default unit when no override is configured. */
	public const DEFAULT_UNIT = 'Points';

	/**
	 * The configured points unit name (default「點數」). Trimmed; an empty / unset option falls back
	 * to the default, so the UI never renders a bare number with no unit. Filterable so a sibling can
	 * override per-context if ever needed.
	 */
	public static function unit(): string {
		$raw  = get_option( self::OPTION, '' );
		$unit = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $unit ) {
			// Locale-aware default: an unset label reads "Points" (en) / "點數" (zh_TW) etc.,
			// via the bundled translation, so a non-English store is not stuck with an English unit.
			$unit = __( 'Points', 'moksa-points-for-woocommerce' );
		}

		/**
		 * Filter the points unit name shown to customers.
		 *
		 * @param string $unit The configured (or default) unit, e.g.「點」「金幣」.
		 */
		return (string) apply_filters( 'moksafopoi_label_unit', $unit );
	}

	/**
	 * Format a points amount as「N <unit>」, thousands-grouped (e.g.「1,250 點」/「1,250 金幣」).
	 * This is the canonical points label used across every customer-facing surface.
	 */
	public static function format( int $points ): string {
		return sprintf(
			/* translators: 1: integer points amount, thousands-grouped; 2: the (customisable) points unit, e.g.「點」. */
			__( '%1$s %2$s', 'moksa-points-for-woocommerce' ),
			number_format( $points ),
			self::unit()
		);
	}
}
