<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Wallet;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\ProductPoints;
use Moksafopoi\Support\UserLock;

defined( 'ABSPATH' ) || exit;

/**
 * 結帳儲值金折抵(錢包) — spend the store-credit balance (fed by {@see \Moksafopoi\Modules\GiftCard\Module}
 * gift cards + Cashback) at checkout. The customer chooses an NT$ amount on the cart; it is applied as a
 * negative cart fee, clamped by balance and the eligible cart total. At order time the amount is debited
 * once through the idempotent ledger (currency lane), stamped to order meta, and restored once on a
 * cancellation / refund. Mirrors the points {@see \Moksafopoi\Modules\CheckoutRedeem\Module}
 * exactly, on the amount_delta lane (1 credit = NT$1, so no conversion rate).
 *
 * No CPT / table: the in-progress choice lives in WC()->session, the committed spend in the ledger +
 * order meta. Works on both the classic [woocommerce_cart] and the block Cart/Checkout (Store API).
 * Honours per-product `_moksafopoi_no_redeem`.
 */
final class Module extends AbstractModule {

	private const SESSION_KEY  = 'moksafopoi_wallet_amount';
	private const PENDING_META = '_moksafopoi_wallet_pending';
	private const ORDER_META   = '_moksafopoi_wallet_amount';
	private const LEDGER_META  = '_moksafopoi_wallet_ledger_ref';
	public const NONCE         = 'moksafopoi_wallet';

	public function slug(): string {
		return 'wallet';
	}

	public function label(): string {
		return __( 'Checkout store credit redemption', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'spend';
	}

	public function tagline(): string {
		return __( 'Use your store credit balance to reduce the amount at checkout (clamped by balance / cart cap, idempotent deduction on order, refunded on refund).', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'woocommerce_cart_collaterals', array( self::class, 'render_panel' ) );
		add_filter( 'render_block', array( self::class, 'inject_after_block' ), 10, 2 );
		add_shortcode( 'moksafopoi_wallet', array( self::class, 'shortcode' ) );

		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply_fee' ) );

		add_action( 'wp_ajax_moksafopoi_apply_wallet', array( self::class, 'ajax_apply' ) );
		add_action( 'wp_ajax_moksafopoi_remove_wallet', array( self::class, 'ajax_remove' ) );

		StoreApi::register();

		add_action( 'woocommerce_checkout_create_order', array( self::class, 'stamp_pending' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'stamp_pending' ), 10, 1 );

		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'commit' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'commit' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'refund' ) );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'refund' ) );

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/* ------------------------------------------------------------------ config */

	public static function max_percent(): float {
		$pct = (float) get_option( 'moksafopoi_wallet_max_percent', 100 );
		return min( 100.0, max( 0.0, $pct ) );
	}

	/* ------------------------------------------------------------------ math */

	/** Cart subtotal store credit may discount: eligible line totals (ex the no-redeem lines), pre-fee. */
	private static function eligible_total(): float {
		if ( ! WC()->cart ) {
			return 0.0;
		}
		$total = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( $product_id > 0 && ProductPoints::no_redeem( $product_id ) ) {
				continue;
			}
			$total += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
		}
		return max( 0.0, $total );
	}

	/** Hard ceiling on the discount: eligible total × max-percent cap. */
	public static function max_discount(): float {
		$eligible = self::eligible_total();
		$cap      = $eligible * ( self::max_percent() / 100.0 );
		return max( 0.0, min( $cap, $eligible ) );
	}

	/**
	 * Clamp a requested NT$ amount to [0 .. min(balance, max_discount)], floored to a whole dollar
	 * (TW whole-NT$ convention, house-safe). Returns the amount that will actually be spent.
	 */
	private static function clamp_amount( float $requested, float $balance ): float {
		if ( $requested <= 0 || $balance <= 0 ) {
			return 0.0;
		}
		$amount = min( $requested, $balance, self::max_discount() );
		return max( 0.0, floor( $amount ) );
	}

	/** The amount currently chosen in this session, re-clamped to the live balance + caps. */
	public static function chosen_amount(): float {
		if ( ! WC()->session ) {
			return 0.0;
		}
		$raw     = (float) WC()->session->get( self::SESSION_KEY, 0 );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || $raw <= 0 ) {
			return 0.0;
		}
		return self::clamp_amount( $raw, Api::get_balance( $user_id ) );
	}

	/** Clamp a requested amount against the CURRENT user's balance + caps (Store API entrypoint). */
	public static function clamp_for_current( float $amount ): float {
		$user_id = get_current_user_id();
		return $user_id > 0 ? self::clamp_amount( $amount, Api::get_balance( $user_id ) ) : 0.0;
	}

	/** Set / clear the chosen wallet amount in session (validated). Used by the Store API callback. */
	public static function set_session_amount( float $amount ): float {
		if ( ! WC()->session ) {
			return 0.0;
		}
		$clamped = self::clamp_for_current( $amount );
		WC()->session->set( self::SESSION_KEY, $clamped );
		return $clamped;
	}

	/* ------------------------------------------------------------------ fee */

	public static function apply_fee( \WC_Cart $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$amount = self::chosen_amount();
		if ( $amount <= 0 ) {
			return;
		}
		$cart->add_fee( __( 'Store credit redemption', 'moksa-points-for-woocommerce' ), -$amount );
	}

	/* ------------------------------------------------------------------ UI */

	public static function render_panel(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! WC()->cart ) {
			return;
		}
		$balance = Api::get_balance( $user_id );
		if ( $balance <= 0 ) {
			return;
		}

		$chosen       = self::chosen_amount();
		$max_discount = self::max_discount();

		echo '<div class="moksafopoi-wallet-panel" id="moksafopoi-wallet-panel">';
		echo '<h2>' . esc_html__( 'Apply store credit', 'moksa-points-for-woocommerce' ) . '</h2>';
		echo '<p class="moksafopoi-wallet-balance">'
			. sprintf(
				/* translators: %s: store-credit balance, formatted as currency. */
				esc_html__( 'Your store credit balance: %s', 'moksa-points-for-woocommerce' ),
				wp_kses_post( wc_price( $balance ) )
			)
			. '</p>';
		echo '<p class="moksafopoi-wallet-max">'
			. sprintf(
				/* translators: %s: max discount amount. */
				esc_html__( 'Maximum redeemable this time: %s', 'moksa-points-for-woocommerce' ),
				wp_kses_post( wc_price( $max_discount ) )
			)
			. '</p>';

		if ( $chosen > 0 ) {
			echo '<p class="moksafopoi-wallet-applied">'
				. sprintf(
					/* translators: %s: applied discount amount. */
					esc_html__( 'Applied %s in store credit.', 'moksa-points-for-woocommerce' ),
					wp_kses_post( wc_price( $chosen ) )
				)
				. '</p>';
			echo '<button type="button" class="button moksafopoi-wallet-remove" '
				. 'data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '">'
				. esc_html__( 'Remove store credit', 'moksa-points-for-woocommerce' ) . '</button>';
		} else {
			$suggest = (int) floor( min( $balance, $max_discount ) );
			echo '<p class="moksafopoi-wallet-form">';
			echo '<label for="moksafopoi-wallet-input">' . esc_html__( 'Amount to apply (NT$)', 'moksa-points-for-woocommerce' ) . '</label> ';
			echo '<input type="number" id="moksafopoi-wallet-input" min="1" step="1" '
				. 'max="' . esc_attr( (string) $suggest ) . '" value="' . esc_attr( (string) $suggest ) . '"> ';
			echo '<button type="button" class="button moksafopoi-wallet-apply" '
				. 'data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '">'
				. esc_html__( 'Apply', 'moksa-points-for-woocommerce' ) . '</button>';
			echo '</p>';
		}
		echo '</div>';
	}

	/** Shortcode form of the panel (return a string, never echo). */
	public static function shortcode( $atts = array() ): string {
		ob_start();
		self::render_panel();
		return (string) ob_get_clean();
	}

	/**
	 * Append the wallet panel right after the WooCommerce Cart OR Checkout block.
	 *
	 * @param string              $content
	 * @param array<string,mixed> $block
	 */
	public static function inject_after_block( $content, $block ) {
		if ( is_admin() || empty( $block['blockName'] ) ) {
			return $content;
		}
		if ( 'woocommerce/cart' !== $block['blockName'] && 'woocommerce/checkout' !== $block['blockName'] ) {
			return $content;
		}
		ob_start();
		self::render_panel();
		return $content . (string) ob_get_clean();
	}

	public static function enqueue(): void {
		$on_cart_or_checkout = function_exists( 'is_cart' ) && ( is_cart() || ( function_exists( 'is_checkout' ) && is_checkout() ) );
		if ( ! $on_cart_or_checkout || get_current_user_id() <= 0 ) {
			return;
		}
		$rel  = 'src/Modules/Wallet/assets/js/wallet.js';
		$path = MOKSAFOPOI_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOPOI_VERSION;
		wp_enqueue_script( 'moksafopoi-wallet', MOKSAFOPOI_PLUGIN_URL . $rel, array( 'jquery' ), $ver, true );
		wp_localize_script(
			'moksafopoi-wallet',
			'moksafopoiWallet',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				/* translators: %s: NT$ amount discounted from store credit. */
				'appliedText' => __( 'Applied NT$%s in store credit.', 'moksa-points-for-woocommerce' ),
			)
		);
	}

	/* ------------------------------------------------------------------ AJAX */

	public static function ajax_apply(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! WC()->session ) {
			wp_send_json_error( array( 'message' => __( 'Please log in first.', 'moksa-points-for-woocommerce' ) ), 403 );
		}
		$requested = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to float.
		$amount    = self::clamp_amount( $requested, Api::get_balance( $user_id ) );
		if ( $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient store credit or invalid amount.', 'moksa-points-for-woocommerce' ) ) );
		}
		WC()->session->set( self::SESSION_KEY, $amount );
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
		wp_send_json_success( array( 'amount' => $amount ) );
	}

	public static function ajax_remove(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( get_current_user_id() <= 0 || ! WC()->session ) {
			wp_send_json_error( array( 'message' => __( 'Please log in first.', 'moksa-points-for-woocommerce' ) ), 403 );
		}
		WC()->session->set( self::SESSION_KEY, 0 );
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
		wp_send_json_success( array( 'amount' => 0 ) );
	}

	/* ------------------------------------------------------------------ commit / refund */

	/**
	 * Stamp the chosen amount onto the order at creation from the (here-reliable) session, so commit is
	 * session-independent.
	 *
	 * @param \WC_Order|mixed $order
	 */
	public static function stamp_pending( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 || ! WC()->session ) {
			return;
		}
		$amount = self::clamp_amount( (float) WC()->session->get( self::SESSION_KEY, 0 ), Api::get_balance( $user_id ) );
		if ( $amount > 0 ) {
			$order->update_meta_data( self::PENDING_META, (string) $amount );
		}
	}

	/**
	 * Debit the chosen store credit once, keyed on the order; persist to order meta. Reads the amount
	 * STAMPED ON THE ORDER (session-independent). Accepts both an int order id (classic) and a WC_Order
	 * (Store API) caller.
	 *
	 * @param \WC_Order|int|mixed $order
	 */
	public static function commit( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order_id = (int) $order->get_id();
		$user_id  = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::ORDER_META ) ) {
			return; // already committed.
		}

		$amount = (float) $order->get_meta( self::PENDING_META );
		if ( $amount <= 0 && WC()->session ) {
			$amount = self::clamp_amount( (float) WC()->session->get( self::SESSION_KEY, 0 ), Api::get_balance( $user_id ) );
		}
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, 0 );
		}
		// Serialise the balance read + debit per user (GET_LOCK). Without this, two concurrent orders
		// for the same customer each read the same balance and each debit their own order (double-spend
		// → negative ledger). The per-order ORDER_META guard above is idempotency for ONE order only.
		UserLock::with(
			$user_id,
			static function () use ( $order, $order_id, $user_id, $amount ) {
				// Never debit more than the live balance; do NOT re-run the cart clamp (cart may be emptied now).
				$amount = min( $amount, Api::get_balance( $user_id ) );
				if ( $amount <= 0 ) {
					return;
				}

				$ref = 'wallet:' . $order_id;
				$ok  = Ledger::record_once(
					$user_id,
					0,
					'redeem',
					'wallet',
					$ref,
					array(
						'amount_delta' => -$amount,
						'order_id'     => $order_id,
						'note'         => __( 'Checkout store credit redemption', 'moksa-points-for-woocommerce' ),
					)
				);
				if ( $ok ) {
					$order->update_meta_data( self::ORDER_META, (string) $amount );
					$order->update_meta_data( self::LEDGER_META, $ref );
					$order->delete_meta_data( self::PENDING_META );
					$order->save();
				}
			}
		);
	}

	/** Restore the spent store credit once when the order is cancelled / refunded (idempotent). */
	public static function refund( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$amount = (float) $order->get_meta( self::ORDER_META );
		if ( $amount <= 0 ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		Ledger::record_once(
			$user_id,
			0,
			'adjust',
			'wallet_refund',
			'wallet_refund:' . $order_id,
			array(
				'amount_delta' => $amount,
				'order_id'     => $order_id,
				'note'         => __( 'Checkout store credit refund', 'moksa-points-for-woocommerce' ),
			)
		);
	}
}
