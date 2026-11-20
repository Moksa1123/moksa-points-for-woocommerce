<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 外部來源訂單 — orders that mirror a sale made elsewhere (a POS, an ERP, a marketplace). A shop names
 * them by order meta and/or `created_via`, then chooses how points behave on them:
 *
 *   moksafopoi_external_order_match       one rule per line: `meta_key=value`, `meta_key` (any
 *                                         non-empty value), or `created_via:value`
 *   moksafopoi_external_order_earn        yes (default) | no — earn on these orders
 *   moksafopoi_external_order_clawback    explicit (default) | all | none — which refunds claw back the
 *                                         points the order earned:
 *                                           explicit: refunds someone actually created (admin refund
 *                                             box, REST /refunds), NOT the automatic full refund
 *                                             WooCommerce adds when the status is changed to Refunded
 *                                           all: every refund (same as a normal order)
 *                                           none: never
 *   moksafopoi_external_order_restore     no (default) | yes — give back points spent on the order
 *                                         when it is refunded (the other system usually does this)
 *
 * Nothing matches until rules are entered, so existing shops see no change.
 */
final class ExternalOrders {

	/** @var array<int,true> Orders whose status is being set to Refunded in this request. */
	private static array $status_refund = array();

	/** Hooked before WooCommerce adds its automatic full refund (wc_order_fully_refunded, priority 10). */
	public static function init(): void {
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'mark_status_refund' ), 1 );
	}

	/**
	 * Remember an order whose status was set to Refunded while money was still unrefunded: WooCommerce
	 * is about to add the automatic full refund for it. A real full refund also ends in status Refunded,
	 * but by then nothing is left to refund, so it is not marked.
	 *
	 * @param int $order_id
	 */
	public static function mark_status_refund( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order && (float) $order->get_remaining_refund_amount() > 0 ) {
			self::$status_refund[ (int) $order_id ] = true;
		}
	}

	/** Whether the refund now being processed came from a status change to Refunded, not a real refund. */
	public static function is_status_refund( int $order_id ): bool {
		return isset( self::$status_refund[ $order_id ] );
	}

	/** @return array<int,array{kind:string,key:string,value:?string}> */
	public static function rules(): array {
		$rules = array();
		// The textarea is stored through wp_kses_post, which turns "&" into "&amp;".
		$raw = html_entity_decode( (string) get_option( 'moksafopoi_external_order_match', '' ), ENT_QUOTES, 'UTF-8' );
		foreach ( preg_split( '/\R/', $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === stripos( $line, 'created_via:' ) ) {
				$value = trim( substr( $line, strlen( 'created_via:' ) ) );
				if ( '' !== $value ) {
					$rules[] = array(
						'kind'  => 'created_via',
						'key'   => '',
						'value' => $value,
					);
				}
				continue;
			}
			$parts = explode( '=', $line, 2 );
			$key   = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $parts[0] );
			if ( '' === $key || self::customer_controlled( $key ) ) {
				continue;
			}
			$rules[] = array(
				'kind'  => 'meta',
				'key'   => $key,
				'value' => isset( $parts[1] ) ? trim( $parts[1] ) : null,
			);
		}
		return $rules;
	}

	/**
	 * Meta a shopper can set from the storefront (order attribution comes from URL parameters). A rule
	 * on it would let a customer mark their own order as external and keep points after a refund, so
	 * such rules are ignored.
	 */
	public static function customer_controlled( string $key ): bool {
		$key = strtolower( $key );
		foreach ( array( '_wc_order_attribution_', '_billing_', '_shipping_', 'billing_', 'shipping_', '_wc_other', '_wc_billing', '_wc_shipping' ) as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		// WC_Order::get_meta() answers these from order properties the shopper sets at checkout.
		return in_array( $key, array( '_customer_note', 'customer_note', '_customer_user_agent', '_customer_ip_address', '_payment_method', '_payment_method_title', '_transaction_id', '_order_currency' ), true );
	}

	public static function is_external( \WC_Order $order ): bool {
		foreach ( self::rules() as $rule ) {
			if ( 'created_via' === $rule['kind'] ) {
				if ( (string) $order->get_created_via() === $rule['value'] ) {
					return true;
				}
				continue;
			}
			$value = $order->get_meta( $rule['key'], true );
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			if ( null === $rule['value'] || (string) $value === $rule['value'] ) {
				return true;
			}
		}
		return false;
	}

	/** Whether this order should earn points here. */
	public static function earns( \WC_Order $order ): bool {
		return 'no' !== get_option( 'moksafopoi_external_order_earn', 'yes' ) || ! self::is_external( $order );
	}

	/**
	 * Whether a refund / cancellation of this order should claw back the points it earned.
	 *
	 * @param \WC_Order|int $order
	 */
	public static function claws_back( $order ): bool {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order instanceof \WC_Order || ! self::is_external( $order ) ) {
			return true;
		}
		$mode = (string) get_option( 'moksafopoi_external_order_clawback', 'explicit' );
		if ( 'all' === $mode ) {
			return true;
		}
		if ( 'none' === $mode ) {
			return false;
		}
		return ! self::is_status_refund( (int) $order->get_id() );
	}

	/**
	 * Whether a refund / cancellation of this order should give back the points spent on it.
	 *
	 * @param \WC_Order|int $order
	 */
	public static function restores_spent( $order ): bool {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order instanceof \WC_Order || ! self::is_external( $order ) ) {
			return true;
		}
		return 'yes' === get_option( 'moksafopoi_external_order_restore', 'no' );
	}

	private function __construct() {}
}
