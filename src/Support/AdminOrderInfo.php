<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單編輯畫面「點數」一覽 — a read-only line under the order details (HPOS-safe hook) showing
 * what THIS order did on the points ledger: 賺點 / 折抵扣點 / 儲值金增減, summed straight off
 * the ledger's order_id index so every module's rows are covered without per-module wiring.
 * Renders nothing when the order never touched the ledger.
 */
final class AdminOrderInfo {

	public static function register(): void {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'render' ) );
	}

	/** @param \WC_Order $order */
	public static function render( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		global $wpdb;
		$table = Schema::ledger_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, order id bound via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END),0) AS earned,
					COALESCE(SUM(CASE WHEN points_delta < 0 THEN -points_delta ELSE 0 END),0) AS spent,
					COALESCE(SUM(amount_delta),0) AS credit
				FROM {$table} WHERE order_id = %d",
				$order->get_id()
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$earned = (int) ( $row['earned'] ?? 0 );
		$spent  = (int) ( $row['spent'] ?? 0 );
		$credit = (float) ( $row['credit'] ?? 0 );
		if ( 0 === $earned && 0 === $spent && 0.0 === $credit ) {
			return;
		}

		$unit  = Label::unit();
		$parts = array();
		if ( $earned > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: earned points, 2: unit. */
				__( 'Earn points +%1$s %2$s', 'moksa-points-for-woocommerce' ),
				number_format_i18n( $earned ),
				$unit
			);
		}
		if ( $spent > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: spent points, 2: unit. */
				__( 'Deduct points −%1$s %2$s', 'moksa-points-for-woocommerce' ),
				number_format_i18n( $spent ),
				$unit
			);
		}
		if ( 0.0 !== $credit ) {
			$parts[] = sprintf(
				/* translators: %s: signed store-credit amount. */
				__( 'Store credit %s', 'moksa-points-for-woocommerce' ),
				( $credit > 0 ? '+' : '' ) . number_format_i18n( $credit, 2 )
			);
		}

		echo '<p class="form-field form-field-wide" style="margin-top:12px">'
			. '<strong>' . esc_html__( 'Points activity:', 'moksa-points-for-woocommerce' ) . '</strong> '
			. esc_html( implode( ' · ', $parts ) )
			. '</p>';
	}
}
