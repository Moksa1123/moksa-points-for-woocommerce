<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 匯率 / 幣別感知的兌換率 — the ONE place that answers "how many points is one unit of money worth?",
 * in both directions:
 *
 *   • {@see earn_rate()}   — points granted per 1 unit spent.
 *   • {@see redeem_rate()} — points needed to redeem 1 unit.
 *
 * Before this existed, seven surfaces each read the raw option, which is fine in a single-currency
 * shop and quietly wrong in a multi-currency one: with WPML / Aelia / FOX / WOOCS the ORDER TOTAL is
 * in the customer's currency, so applying a rate calibrated for the base currency awards a Japanese
 * customer roughly 30× what a US one gets for the same real spend.
 *
 * Per-currency overrides live in one option (`moksafopoi_currency_rates`, JSON: currency → {earn,
 * redeem}). When a currency has no override, the base rate is converted with the multi-currency
 * plugin's own exchange rate if one is exposed, and only then falls back to the raw base rate — the
 * conversion is always someone's real rate, never one we invented.
 */
final class Rates {

	/** Option holding the per-currency overrides. */
	public const OPTION = 'moksafopoi_currency_rates';

	/**
	 * Points granted per 1 unit of the order's currency.
	 *
	 * @param mixed $order Optional WC_Order, so a historical order uses ITS currency, not today's.
	 */
	public static function earn_rate( $order = null ): float {
		$base     = (float) get_option( 'moksafopoi_points_per_currency', 1 );
		$currency = self::currency( $order );
		$override = self::override( $currency, 'earn' );

		if ( null !== $override ) {
			$rate = $override;
		} else {
			// No override: 1 unit of this currency buys `rate` of the base currency, so it should earn
			// proportionally more (or less) than the base rate.
			$rate = $base * self::exchange_rate( $currency );
		}

		/**
		 * Filter the earn rate (points per 1 currency unit) for a given order / currency.
		 *
		 * @param float  $rate
		 * @param string $currency
		 * @param mixed  $order
		 */
		$rate = (float) apply_filters( 'moksafopoi_earn_rate', $rate, $currency, $order );

		return max( 0.0, $rate );
	}

	/** Points needed to redeem 1 unit of the current currency (never below 1). */
	public static function redeem_rate(): int {
		$base     = max( 1, (int) get_option( 'moksafopoi_redeem_rate', 100 ) );
		$currency = self::currency();
		$override = self::override( $currency, 'redeem' );

		$rate = ( null !== $override ) ? (int) round( $override ) : (int) round( $base * self::exchange_rate( $currency ) );

		/**
		 * Filter the redeem rate (points per 1 currency unit).
		 *
		 * @param int    $rate
		 * @param string $currency
		 */
		$rate = (int) apply_filters( 'moksafopoi_redeem_rate_value', $rate, $currency );

		return max( 1, $rate );
	}

	/**
	 * The per-currency override map.
	 *
	 * @return array<string,array{earn:?float,redeem:?float}>
	 */
	public static function map(): array {
		$raw     = get_option( self::OPTION, '' );
		$decoded = null;
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		}
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $currency => $row ) {
			$currency = strtoupper( sanitize_text_field( (string) $currency ) );
			if ( 3 !== strlen( $currency ) || ! is_array( $row ) ) {
				continue;
			}
			$earn   = isset( $row['earn'] ) && '' !== $row['earn'] ? max( 0.0, (float) $row['earn'] ) : null;
			$redeem = isset( $row['redeem'] ) && '' !== $row['redeem'] ? max( 1.0, (float) $row['redeem'] ) : null;
			if ( null === $earn && null === $redeem ) {
				continue;
			}
			$out[ $currency ] = array(
				'earn'   => $earn,
				'redeem' => $redeem,
			);
		}
		return $out;
	}

	/**
	 * Persist a submitted override map.
	 *
	 * @param array<string,array<string,mixed>> $rows
	 */
	public static function save( array $rows ): void {
		$clean = array();
		foreach ( $rows as $currency => $row ) {
			$currency = strtoupper( sanitize_text_field( (string) $currency ) );
			if ( 3 !== strlen( $currency ) || ! is_array( $row ) ) {
				continue;
			}
			$earn   = isset( $row['earn'] ) ? trim( sanitize_text_field( (string) $row['earn'] ) ) : '';
			$redeem = isset( $row['redeem'] ) ? trim( sanitize_text_field( (string) $row['redeem'] ) ) : '';
			$entry  = array();
			if ( '' !== $earn && is_numeric( $earn ) ) {
				$entry['earn'] = max( 0.0, (float) $earn );
			}
			if ( '' !== $redeem && is_numeric( $redeem ) ) {
				$entry['redeem'] = max( 1.0, (float) $redeem );
			}
			if ( array() !== $entry ) {
				$clean[ $currency ] = $entry;
			}
		}
		update_option( self::OPTION, array() === $clean ? '' : (string) wp_json_encode( $clean ) );
	}

	/** The currency in play: the order's own currency when we have one, otherwise the shop's current. */
	public static function currency( $order = null ): string {
		if ( $order instanceof \WC_Order ) {
			$currency = (string) $order->get_currency();
			if ( '' !== $currency ) {
				return strtoupper( $currency );
			}
		}
		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
	}

	/**
	 * One override value, or null when this currency has none.
	 *
	 * @return float|null
	 */
	private static function override( string $currency, string $which ): ?float {
		$map = self::map();
		if ( '' === $currency || ! isset( $map[ $currency ][ $which ] ) ) {
			return null;
		}
		return $map[ $currency ][ $which ];
	}

	/**
	 * How many BASE-currency units one unit of `$currency` is worth, according to whichever
	 * multi-currency plugin is installed. Returns 1.0 for the base currency, and 1.0 when nothing can
	 * tell us — never a guessed number.
	 */
	private static function exchange_rate( string $currency ): float {
		$base = function_exists( 'get_option' ) ? strtoupper( (string) get_option( 'woocommerce_currency', '' ) ) : '';
		if ( '' === $currency || $currency === $base ) {
			return 1.0;
		}

		// Aelia Currency Switcher exposes its rates through its OWN filter, so the hook name is
		// deliberately theirs, not ours — and it is only consulted when their plugin is loaded.
		if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook owned by Aelia Currency Switcher; we are the caller, not the author.
			$rate = apply_filters( 'wc_aelia_cs_convert', null, 1, $currency, $base );
			if ( is_numeric( $rate ) && (float) $rate > 0 ) {
				return (float) $rate;
			}
		}

		// WOOCS / FOX: 1 base unit = $woocs_rate of this currency, so invert it.
		if ( isset( $GLOBALS['WOOCS'] ) && is_object( $GLOBALS['WOOCS'] ) && method_exists( $GLOBALS['WOOCS'], 'get_currencies' ) ) {
			$currencies = (array) $GLOBALS['WOOCS']->get_currencies();
			if ( isset( $currencies[ $currency ]['rate'] ) && (float) $currencies[ $currency ]['rate'] > 0 ) {
				return 1 / (float) $currencies[ $currency ]['rate'];
			}
		}

		/**
		 * Filter the base-currency value of one unit of `$currency` (for any other multi-currency
		 * plugin). Returning 1.0 means "treat it as the base currency".
		 *
		 * @param float  $rate
		 * @param string $currency
		 * @param string $base
		 */
		return max( 0.0, (float) apply_filters( 'moksafopoi_exchange_rate', 1.0, $currency, $base ) );
	}
}
