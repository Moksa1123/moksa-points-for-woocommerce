<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Redeem;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 點數兌換 — spend points on a catalog reward (a moforcoupon coupon, etc.). The work lives in
 * {@see Service}, reached via Api::redeem() (and the points/redeem-points ability + the My
 * Account redeem UI). This module just gates the feature on its opt-in toggle.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'redeem';
	}

	public function label(): string {
		return __( 'Points redemption', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'spend';
	}

	public function tagline(): string {
		return __( 'Redeem coupons / products / cart discounts with points (redemption coupons are issued by moforcoupon)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Redemption is invoked on demand (Api::redeem / ability / My Account form), so there is
		// no always-on hook to wire here; presence of the class + the toggle is the gate.
		do_action( 'moksafopoi_redeem_booted' );
	}
}
