<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 逐金流點數回饋 — extra points for paying with a particular gateway ("pay by LINE Pay, earn 2×").
 *
 * Why a shop wants this: payment methods do not cost the same. Card fees, COD failure rates and
 * cash-on-pickup handling differ enough that steering customers towards the cheap one is worth real
 * money — and points are the cheapest steering wheel there is.
 *
 * The map lives in ONE option (`moksafopoi_gateway_multipliers`, JSON: gateway id → multiplier) and
 * is applied through the canonical `moksafopoi_earn_multiplier` filter, so it stacks with tier / role
 * / category / campaign multipliers under the same 10× ceiling as everything else.
 */
final class GatewayPoints {

	/** Option holding the gateway → multiplier map. */
	public const OPTION = 'moksafopoi_gateway_multipliers';

	/** Shared ceiling, so a gateway bonus can never be the thing that runs away. */
	private const MAX_MULTIPLIER = 10.0;

	public static function register(): void {
		add_filter( 'moksafopoi_earn_multiplier', array( self::class, 'apply' ), 25, 3 );
	}

	/**
	 * The configured map, normalised: gateway id → multiplier (only entries that actually change
	 * something are kept).
	 *
	 * @return array<string,float>
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
		foreach ( $decoded as $gateway => $multiplier ) {
			$gateway = sanitize_key( (string) $gateway );
			if ( '' === $gateway ) {
				continue;
			}
			$multiplier = (float) $multiplier;
			if ( $multiplier <= 0.0 || 1.0 === $multiplier ) {
				continue; // 1× is "no change" — do not store noise.
			}
			$out[ $gateway ] = min( self::MAX_MULTIPLIER, $multiplier );
		}
		return $out;
	}

	/**
	 * Apply the gateway multiplier for the order being earned on.
	 *
	 * @param mixed $multiplier
	 * @param int   $user_id
	 * @param mixed $order
	 */
	public static function apply( $multiplier, $user_id = 0, $order = null ): float {
		$multiplier = is_numeric( $multiplier ) ? (float) $multiplier : 1.0;

		if ( ! $order instanceof \WC_Order ) {
			return $multiplier;
		}
		$map = self::map();
		if ( array() === $map ) {
			return $multiplier;
		}

		$gateway = sanitize_key( (string) $order->get_payment_method() );
		if ( '' === $gateway || ! isset( $map[ $gateway ] ) ) {
			return $multiplier;
		}

		return min( self::MAX_MULTIPLIER, max( 0.0, $multiplier * $map[ $gateway ] ) );
	}

	/**
	 * Persist a submitted map (gateway id → multiplier).
	 *
	 * @param array<string,mixed> $rows
	 */
	public static function save( array $rows ): void {
		$out = array();
		foreach ( $rows as $gateway => $multiplier ) {
			$gateway = sanitize_key( (string) $gateway );
			if ( '' === $gateway ) {
				continue;
			}
			$multiplier = (float) sanitize_text_field( (string) $multiplier );
			if ( $multiplier <= 0.0 || 1.0 === $multiplier ) {
				continue;
			}
			$out[ $gateway ] = min( self::MAX_MULTIPLIER, $multiplier );
		}
		update_option( self::OPTION, array() === $out ? '' : (string) wp_json_encode( $out ) );
	}

	/**
	 * The gateways this store actually has, for the settings table (id → title).
	 *
	 * @return array<string,string>
	 */
	public static function available_gateways(): array {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return $out;
		}
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( ! is_object( $gateway ) || ! isset( $gateway->id ) ) {
				continue;
			}
			$out[ sanitize_key( (string) $gateway->id ) ] = (string) ( $gateway->get_title() ?: $gateway->id );
		}
		return $out;
	}
}
