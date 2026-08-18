<?php

declare( strict_types=1 );

namespace Moksafopoi\Compatibility;

use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions 相容層 — makes recurring revenue behave sensibly in the points engine.
 *
 * Renewal orders already reach `woocommerce_order_status_processing/completed`, so without this layer
 * they earn silently at the normal rate AND look like ordinary orders to every one-off bonus. Both of
 * those are wrong often enough to be worth controlling:
 *
 *   • **Renewals can be excluded, or boosted.** `moksafopoi_subs_renewal_earn` turns earning on
 *     renewals off entirely; `moksafopoi_subs_renewal_multiplier` rewards the loyalty of staying
 *     subscribed (a renewal is exactly the behaviour a loyalty programme wants to reinforce).
 *   • **A renewal is not a first order.** The one-off "first order" bonus must not fire on the second
 *     year of a subscription, so this layer vetoes it for renewal orders.
 *   • **Optional sign-up bonus** when a subscription first becomes active.
 *
 * Every entry point is `function_exists()` guarded, so a store WITHOUT the Subscriptions extension
 * loads this class, hooks nothing that can misfire, and behaves exactly as before.
 */
final class Subscriptions {

	/** Ceiling on the renewal multiplier, matching the rest of the engine. */
	private const MAX_MULTIPLIER = 10.0;

	/** Is WooCommerce Subscriptions present? */
	public static function active(): bool {
		return function_exists( 'wcs_order_contains_renewal' );
	}

	public static function init(): void {
		if ( ! self::active() ) {
			return;
		}

		add_filter( 'moksafopoi_earn_multiplier', array( self::class, 'renewal_multiplier' ), 30, 3 );
		add_filter( 'moksafopoi_first_order_bonus_eligible', array( self::class, 'veto_first_order' ), 10, 2 );

		add_action( 'woocommerce_subscription_status_active', array( self::class, 'on_subscription_active' ) );
	}

	/** Is this order a subscription renewal? */
	public static function is_renewal( int $order_id ): bool {
		return self::active() && $order_id > 0 && (bool) wcs_order_contains_renewal( $order_id );
	}

	/**
	 * Scale (or zero) the earn multiplier on a renewal order.
	 *
	 * The order under evaluation arrives as the filter's third argument (the earn engine already
	 * passes it), so this never has to guess and can never affect a normal order.
	 *
	 * @param mixed $multiplier
	 * @param int   $user_id
	 * @param mixed $order
	 */
	public static function renewal_multiplier( $multiplier, $user_id = 0, $order = null ): float {
		$multiplier = is_numeric( $multiplier ) ? (float) $multiplier : 1.0;

		$order_id = ( $order instanceof \WC_Order ) ? (int) $order->get_id() : 0;
		if ( $order_id <= 0 || ! self::is_renewal( $order_id ) ) {
			return $multiplier;
		}

		if ( 'yes' !== get_option( 'moksafopoi_subs_renewal_earn', 'yes' ) ) {
			return 0.0; // Renewals earn nothing.
		}

		$extra = (float) get_option( 'moksafopoi_subs_renewal_multiplier', 1 );
		$extra = max( 0.0, min( self::MAX_MULTIPLIER, $extra ) );

		return min( self::MAX_MULTIPLIER, $multiplier * $extra );
	}

	/**
	 * A renewal is never a "first order": the customer signed up years ago.
	 *
	 * @param bool $eligible
	 * @param int  $order_id
	 */
	public static function veto_first_order( $eligible, $order_id ): bool {
		if ( self::is_renewal( (int) $order_id ) ) {
			return false;
		}
		return (bool) $eligible;
	}

	/**
	 * Award the optional sign-up bonus when a subscription first becomes active. Idempotent on the
	 * subscription id, so a status flapping between active/on-hold/active never pays twice.
	 *
	 * @param mixed $subscription
	 */
	public static function on_subscription_active( $subscription ): void {
		$bonus = (int) get_option( 'moksafopoi_subs_signup_bonus', 0 );
		if ( $bonus <= 0 || ! is_object( $subscription ) || ! method_exists( $subscription, 'get_user_id' ) ) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		$sub_id  = method_exists( $subscription, 'get_id' ) ? (int) $subscription->get_id() : 0;
		if ( $user_id <= 0 || $sub_id <= 0 ) {
			return;
		}

		Ledger::record_once(
			$user_id,
			$bonus,
			'earn',
			'subscription',
			'subscription:' . $sub_id,
			array(
				'note' => __( 'Subscription sign-up bonus', 'moksa-points-for-woocommerce' ),
			)
		);
	}
}
