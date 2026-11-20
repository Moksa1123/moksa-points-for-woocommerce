<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * How much of an order has been refunded so far, as a share between 0 and 1 — the basis for clawing
 * back earned points and giving back spent points in proportion.
 *
 * Normally that is refunded money ÷ order total. An order paid entirely with points has a total of 0,
 * so money cannot measure it: then the share of the item subtotal that the refunds took back is used
 * (refunding one of three items gives back a third, not everything). With no items to measure either,
 * the share is 0 here; a full refund or cancellation still settles the whole order through its own
 * status hook.
 */
final class RefundShare {

	public static function fraction( \WC_Order $order ): float {
		$total = (float) $order->get_total();
		if ( $total > 0 ) {
			return max( 0.0, min( 1.0, (float) $order->get_total_refunded() / $total ) );
		}

		// Weight each line by its original subtotal; a refund line takes back (refunded qty ÷ line qty)
		// of it. Quantities, not refund amounts: on a zero-total order the refund amounts are usually 0.
		$subtotal = 0.0;
		$lines    = array();
		foreach ( $order->get_items() as $id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_quantity() > 0 ) {
				$lines[ (int) $id ] = array( (float) $item->get_subtotal(), (float) $item->get_quantity() );
				$subtotal          += (float) $item->get_subtotal();
			}
		}
		if ( $subtotal <= 0 ) {
			return 0.0;
		}
		$refunded = 0.0;
		foreach ( $order->get_refunds() as $refund ) {
			foreach ( $refund->get_items() as $item ) {
				$orig = (int) $item->get_meta( '_refunded_item_id', true );
				if ( isset( $lines[ $orig ] ) ) {
					$refunded += $lines[ $orig ][0] * min( 1.0, abs( (float) $item->get_quantity() ) / $lines[ $orig ][1] );
				}
			}
		}
		return max( 0.0, min( 1.0, $refunded / $subtotal ) );
	}

	private function __construct() {}
}
