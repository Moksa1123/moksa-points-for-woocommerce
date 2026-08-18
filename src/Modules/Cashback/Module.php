<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Cashback;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * 消費回饋(儲值金) — a native "spend and get NT$ back" rebate. When an order is paid, a configurable
 * percentage of the order value is booked as store credit (the wallet lane) through the idempotent
 * ledger, so it stacks cleanly with loyalty points (which are a separate lane). A later refund reverses
 * the rebate once, proportionally.
 *
 * Native + self-contained: unlike the original design note (which assumed a moforcoupon cashback event
 * that does not exist), this computes the rebate itself from the order total. No CPT, no custom table —
 * config lives in options, the value lives in {@see Ledger} (amount_delta), idempotency is keyed on the
 * order id so a status flip / cron replay never double-credits.
 */
final class Module extends AbstractModule {

	private const ORDER_MARK = '_moksafopoi_cashback_credited';

	public function slug(): string {
		return 'cashback';
	}

	public function label(): string {
		return __( 'Purchase cashback (store credit)', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'After an order is paid, give back a fixed % of the amount as customer store credit (usable at checkout); refunds reverse it proportionally.', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'credit_order' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'credit_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'reverse_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'reverse_order' ) );
	}

	/* ------------------------------------------------------------------ config */

	public static function rate(): float {
		return max( 0.0, (float) get_option( 'moksafopoi_cashback_rate', 0 ) );
	}

	private static function basis_is_total(): bool {
		return 'total' === (string) get_option( 'moksafopoi_cashback_basis', 'subtotal' );
	}

	private static function min_spend(): float {
		return max( 0.0, (float) get_option( 'moksafopoi_cashback_min_spend', 0 ) );
	}

	private static function max_cashback(): float {
		return max( 0.0, (float) get_option( 'moksafopoi_cashback_max', 0 ) );
	}

	/* ------------------------------------------------------------------ math */

	/**
	 * The rebate an order earns: basis × rate%, floored (house-safe), clamped by min-spend + max-cap.
	 * Basis is the ex-tax subtotal by default (excludes shipping/tax), or the grand total when so
	 * configured.
	 */
	public static function cashback_for( \WC_Order $order ): float {
		$rate = self::rate();
		if ( $rate <= 0 ) {
			return 0.0;
		}
		// Base cashback on the REAL money paid, not the pre-discount subtotal. get_total() already
		// reflects coupon discounts and the negative store-credit/points cart fee, so an order paid
		// with credit/points or a heavy coupon no longer mints cashback on money that was never spent
		// (F2). Capped at the product subtotal so shipping/tax can't inflate it above merchandise value.
		$paid  = max( 0.0, (float) $order->get_total() );
		$basis = min( $paid, (float) $order->get_subtotal() );
		if ( $basis < self::min_spend() ) {
			return 0.0;
		}
		$value = floor( $basis * ( $rate / 100.0 ) );
		$max   = self::max_cashback();
		if ( $max > 0 ) {
			$value = min( $value, $max );
		}
		return max( 0.0, $value );
	}

	/* ------------------------------------------------------------------ credit / reverse */

	/** @param int $order_id */
	public static function credit_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$value = self::cashback_for( $order );
		if ( $value <= 0 ) {
			return;
		}
		$ok = Ledger::record_once(
			$user_id,
			0,
			'cashback',
			'cashback',
			'cashback:' . $order_id,
			array(
				'amount_delta' => $value,
				'order_id'     => $order_id,
				'note'         => __( 'Purchase cashback store credit', 'moksa-points-for-woocommerce' ),
			)
		);
		if ( $ok ) {
			$order->update_meta_data( self::ORDER_MARK, (string) $value );
			$order->save();
		}
	}

	/** @param int $order_id */
	public static function reverse_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$value = (float) $order->get_meta( self::ORDER_MARK );
		if ( $value <= 0 ) {
			return; // nothing credited.
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		Ledger::record_once(
			$user_id,
			0,
			'cashback_reverse',
			'cashback_refund',
			'cashback_refund:' . $order_id,
			array(
				'amount_delta' => -$value,
				'order_id'     => $order_id,
				'note'         => __( 'Purchase cashback store credit reversal', 'moksa-points-for-woocommerce' ),
			)
		);
	}
}
