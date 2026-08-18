<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\CheckoutRedeem;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\ProductPoints;
use Moksafopoi\Support\UserLock;

defined( 'ABSPATH' ) || exit;

/**
 * 結帳點數折抵 — the YITH-style "spend your points at checkout" wallet. A member chooses how many
 * points to apply on the cart page; the matching NT$ amount is added as a negative cart fee
 * (clamped by min/step/balance and by the max-percent / max-fixed / cart-eligible-total caps).
 * At order time the *actual* discounted points are debited once through the idempotent ledger
 * (keyed on the order), written to order meta, and refunded once if the order is cancelled/refunded.
 *
 * No CPT, no custom table — the in-progress choice lives in WC()->session; the committed spend
 * lives in the ledger + order meta. Honours per-product `_moksafopoi_no_redeem`.
 */
final class Module extends AbstractModule {

	private const SESSION_KEY = 'moksafopoi_redeem_points';
	private const PENDING_META = '_moksafopoi_redeem_pending';
	private const ORDER_META  = '_moksafopoi_redeem_points';
	private const LEDGER_META = '_moksafopoi_redeem_ledger_ref';
	public const NONCE        = 'moksafopoi_checkout_redeem';

	public function slug(): string {
		return 'checkoutredeem';
	}

	public function label(): string {
		return __( 'Checkout points redemption', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'spend';
	}

	public function tagline(): string {
		return __( 'Redeem points for an amount at cart / checkout (clamped by min/step/balance/cap; idempotent deduction on order, refunded on refund)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Classic [woocommerce_cart] shortcode cart.
		add_action( 'woocommerce_cart_collaterals', array( self::class, 'render_panel' ) );
		// Modern Cart / Checkout blocks (the WooCommerce default): append the panel after the rendered
		// block, so block-based stores — the majority of new installs — get the redeem UI on both the
		// cart AND the checkout page.
		add_filter( 'render_block', array( self::class, 'inject_after_block' ), 10, 2 );
		// And a placeable shortcode for any layout.
		add_shortcode( 'moksafopoi_redeem', array( self::class, 'shortcode' ) );

		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'apply_fee' ) );

		// Apply / remove via WC AJAX (logged-in customers) — the classic-cart path.
		add_action( 'wp_ajax_moksafopoi_apply_redeem', array( self::class, 'ajax_apply' ) );
		add_action( 'wp_ajax_moksafopoi_remove_redeem', array( self::class, 'ajax_remove' ) );

		// Native block Cart/Checkout: Store API endpoint data + update callback (no full reload).
		StoreApi::register();

		// Land the chosen points ON THE ORDER at creation (session is reliable here, and Block
		// checkout only hands us the order object) so commit never depends on a late/lost session.
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'stamp_pending' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'stamp_pending' ), 10, 1 );

		// Commit (debit) + refund. commit() normalises both the classic int order-id and the
		// Store-API WC_Order caller, and reads the points from order meta — not the session.
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'commit' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'commit' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'refund' ) );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'refund' ) );

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/* ------------------------------------------------------------------ config */

	public static function redeem_rate(): int {
		return \Moksafopoi\Support\Rates::redeem_rate();
	}

	public static function min_points(): int {
		return max( 0, (int) get_option( 'moksafopoi_redeem_min_points', 100 ) );
	}

	public static function step(): int {
		return max( 1, (int) get_option( 'moksafopoi_redeem_step', 100 ) );
	}

	public static function max_percent(): float {
		$pct = (float) get_option( 'moksafopoi_redeem_max_percent', 100 );
		return min( 100.0, max( 0.0, $pct ) );
	}

	public static function max_fixed(): float {
		return max( 0.0, (float) get_option( 'moksafopoi_redeem_max_fixed', 0 ) );
	}

	/* ------------------------------------------------------------------ math */

	/**
	 * The cart subtotal that points are allowed to discount: line totals minus any line whose
	 * product is flagged `_moksafopoi_no_redeem`. Computed pre-fee so our own fee never feeds back.
	 */
	private static function eligible_total(): float {
		if ( ! WC()->cart ) {
			return 0.0;
		}
		// Redemption gates: block while a coupon is applied (option), and require a minimum member tier
		// (option, only enforced when moformember is present). A failed gate makes NOTHING eligible, so
		// the discount clamps to 0 — self-correcting even if points were chosen earlier in the session.
		if ( ! self::redemption_allowed( get_current_user_id() ) ) {
			return 0.0;
		}

		$only_cats = self::redeem_only_cats();
		$total     = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( $product_id > 0 && ProductPoints::no_redeem( $product_id ) ) {
				continue;
			}
			// Category whitelist: when set, only line items in one of those categories (or a subcategory)
			// count toward the redeemable total.
			if ( array() !== $only_cats && ! self::product_in_cats( $product_id, $only_cats ) ) {
				continue;
			}
			$total += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
		}
		return max( 0.0, $total );
	}

	/**
	 * Whether points redemption is permitted right now for a user: not blocked by an applied coupon
	 * (option) and meeting the minimum member tier (option; a no-op when moformember is absent).
	 */
	public static function redemption_allowed( int $user_id ): bool {
		if ( 'yes' === get_option( 'moksafopoi_redeem_block_with_coupon', 'no' )
			&& WC()->cart && ! empty( WC()->cart->get_applied_coupons() ) ) {
			return false;
		}
		$min_tier = sanitize_key( (string) get_option( 'moksafopoi_redeem_min_tier', '' ) );
		if ( '' !== $min_tier && function_exists( 'moformember_meets_tier' ) && ! moformember_meets_tier( $user_id, $min_tier ) ) {
			return false;
		}
		return true;
	}

	/** The configured redeem-only category term ids (CSV option), or [] when unrestricted. */
	private static function redeem_only_cats(): array {
		$raw = (string) get_option( 'moksafopoi_redeem_only_cats', '' );
		$ids = array();
		foreach ( preg_split( '/[\s,]+/', $raw ) ?: array() as $tok ) {
			$tok = (int) trim( $tok );
			if ( $tok > 0 ) {
				$ids[] = $tok;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Whether a product belongs to any of $cats (product_cat), including via a parent (ancestor) term. */
	public static function product_in_cats( int $product_id, array $cats ): bool {
		if ( $product_id <= 0 || array() === $cats ) {
			return false;
		}
		$terms = wc_get_product_term_ids( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		$all = array();
		foreach ( $terms as $tid ) {
			$tid   = (int) $tid;
			$all[] = $tid;
			foreach ( (array) get_ancestors( $tid, 'product_cat' ) as $anc ) {
				$all[] = (int) $anc;
			}
		}
		return array() !== array_intersect( $all, array_map( 'intval', $cats ) );
	}

	/** Hard ceiling on the discount amount given the caps + the eligible cart total. */
	public static function max_discount(): float {
		$eligible = self::eligible_total();
		$cap      = $eligible * ( self::max_percent() / 100.0 );
		$fixed    = self::max_fixed();
		if ( $fixed > 0 ) {
			$cap = min( $cap, $fixed );
		}
		return max( 0.0, min( $cap, $eligible ) );
	}

	/**
	 * Clamp a requested point count to [0 | min..balance], snapped DOWN to the step, then capped so
	 * the resulting discount never exceeds max_discount(). Returns the points that will actually burn.
	 */
	private static function clamp_points( int $requested, int $balance ): int {
		if ( $requested <= 0 || $balance <= 0 ) {
			return 0;
		}
		$points = min( $requested, $balance );

		$step = self::step();
		$points = intdiv( $points, $step ) * $step; // snap down to a whole step

		if ( $points < self::min_points() ) {
			return 0;
		}

		// Cap by the maximum allowed discount (convert back to points, snapped down to a step).
		$max_discount     = self::max_discount();
		$max_points_by_cap = (int) floor( $max_discount * self::redeem_rate() );
		if ( $points > $max_points_by_cap ) {
			$points = intdiv( $max_points_by_cap, $step ) * $step;
		}

		return max( 0, $points );
	}

	/** NT$ discount produced by burning $points (floor — house-safe). */
	public static function discount_for( int $points ): float {
		if ( $points <= 0 ) {
			return 0.0;
		}
		return floor( (float) $points / self::redeem_rate() );
	}

	/** The points currently chosen in this session, re-clamped to live balance + caps. */
	public static function chosen_points(): int {
		if ( ! WC()->session ) {
			return 0;
		}
		$raw     = (int) WC()->session->get( self::SESSION_KEY, 0 );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || $raw <= 0 ) {
			return 0;
		}
		return self::clamp_points( $raw, Api::get_points( $user_id ) );
	}

	/** Clamp a requested point count against the CURRENT user's balance + caps (Store API entrypoint). */
	public static function clamp_for_current( int $points ): int {
		$user_id = get_current_user_id();
		return $user_id > 0 ? self::clamp_points( $points, Api::get_points( $user_id ) ) : 0;
	}

	/** Set / clear the chosen redeem points in session (validated). Used by the Store API callback. */
	public static function set_session_points( int $points ): int {
		if ( ! WC()->session ) {
			return 0;
		}
		$clamped = self::clamp_for_current( $points );
		WC()->session->set( self::SESSION_KEY, $clamped );
		return $clamped;
	}

	/* ------------------------------------------------------------------ fee */

	public static function apply_fee( \WC_Cart $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$points = self::chosen_points();
		if ( $points <= 0 ) {
			return;
		}
		$discount = self::discount_for( $points );
		if ( $discount <= 0 ) {
			return;
		}
		$cart->add_fee( __( 'Points redemption', 'moksa-points-for-woocommerce' ), -$discount );
	}

	/* ------------------------------------------------------------------ UI */

	public static function render_panel(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! WC()->cart ) {
			return;
		}
		$balance = Api::get_points( $user_id );
		if ( $balance <= 0 ) {
			return;
		}

		$chosen        = self::chosen_points();
		$chosen_amount = self::discount_for( $chosen );
		$max_discount  = self::max_discount();
		$rate          = self::redeem_rate();

		echo '<div class="moksafopoi-redeem-panel" id="moksafopoi-redeem-panel">';
		echo '<h2>' . esc_html__( 'Use points redemption', 'moksa-points-for-woocommerce' ) . '</h2>';

		echo '<p class="moksafopoi-redeem-balance">'
			. sprintf(
				/* translators: 1: points balance, 2: redeem rate (points per NT$1). */
				esc_html__( 'You have %1$s point(s) (every %2$s point(s) is worth NT$1).', 'moksa-points-for-woocommerce' ),
				esc_html( number_format( $balance ) ),
				esc_html( number_format( $rate ) )
			)
			. '</p>';
		echo '<p class="moksafopoi-redeem-max">'
			. sprintf(
				/* translators: %s: max discount amount, formatted as currency. */
				esc_html__( 'Maximum redeemable this time: %s', 'moksa-points-for-woocommerce' ),
				wp_kses_post( wc_price( $max_discount ) )
			)
			. '</p>';

		if ( $chosen > 0 ) {
			echo '<p class="moksafopoi-redeem-applied">'
				. sprintf(
					/* translators: 1: points used, 2: discount amount. */
					esc_html__( 'Used %1$s point(s), redeemed %2$s.', 'moksa-points-for-woocommerce' ),
					esc_html( number_format( $chosen ) ),
					wp_kses_post( wc_price( $chosen_amount ) )
				)
				. '</p>';
			echo '<button type="button" class="button moksafopoi-redeem-remove" '
				. 'data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '">'
				. esc_html__( 'Remove points redemption', 'moksa-points-for-woocommerce' ) . '</button>';
		} else {
			echo '<p class="moksafopoi-redeem-form">';
			echo '<label for="moksafopoi-redeem-input">' . esc_html__( 'Points to use', 'moksa-points-for-woocommerce' ) . '</label> ';
			echo '<input type="number" id="moksafopoi-redeem-input" min="' . esc_attr( (string) self::min_points() ) . '" '
				. 'step="' . esc_attr( (string) self::step() ) . '" max="' . esc_attr( (string) $balance ) . '" '
				. 'value="' . esc_attr( (string) self::min_points() ) . '"> ';
			echo '<button type="button" class="button moksafopoi-redeem-apply" '
				. 'data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '">'
				. esc_html__( 'Apply', 'moksa-points-for-woocommerce' ) . '</button>';
			echo '</p>';
			echo '<p class="description">'
				. sprintf(
					/* translators: 1: minimum points, 2: step. */
					esc_html__( 'Minimum %1$s point(s), in increments of %2$s point(s).', 'moksa-points-for-woocommerce' ),
					esc_html( number_format( self::min_points() ) ),
					esc_html( number_format( self::step() ) )
				)
				. '</p>';
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
	 * Append the redeem panel right after the WooCommerce Cart OR Checkout block so block-based
	 * stores get the same UI on both pages. Only touches those two blocks; everything else passes
	 * through untouched.
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
		$rel  = 'src/Modules/CheckoutRedeem/assets/js/redeem.js';
		$path = MOKSAFOPOI_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOPOI_VERSION;
		wp_enqueue_script( 'moksafopoi-redeem', MOKSAFOPOI_PLUGIN_URL . $rel, array( 'jquery' ), $ver, true );
		wp_localize_script(
			'moksafopoi-redeem',
			'moksafopoiRedeem',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'rate'        => self::redeem_rate(),
				/* translators: %1$s: points used; %2$s: NT$ amount discounted. */
				'appliedText' => __( 'Used %1$s point(s), redeemed NT$%2$s.', 'moksa-points-for-woocommerce' ),
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
		$requested = isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : 0;
		$points    = self::clamp_points( $requested, Api::get_points( $user_id ) );
		if ( $points <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient points or usage threshold not met.', 'moksa-points-for-woocommerce' ) ) );
		}
		WC()->session->set( self::SESSION_KEY, $points );
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
		wp_send_json_success(
			array(
				'points'   => $points,
				'discount' => self::discount_for( $points ),
			)
		);
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
		wp_send_json_success( array( 'points' => 0 ) );
	}

	/* ------------------------------------------------------------------ commit / refund */

	/**
	 * Stamp the chosen points onto the order at creation, from the (here-reliable) session, so the
	 * commit step is session-independent. Already clamped against caps here, while the cart is live.
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
		$points = self::clamp_points( (int) WC()->session->get( self::SESSION_KEY, 0 ), Api::get_points( $user_id ) );
		if ( $points > 0 ) {
			$order->update_meta_data( self::PENDING_META, (string) $points );
		}
	}

	/**
	 * Debit the chosen points once, keyed on the order; persist to order meta. Reads the points
	 * STAMPED ON THE ORDER at creation (session-independent), so Store-API / deferred-payment flows
	 * still debit. Accepts both an int order id (classic) and a WC_Order (Store API) caller.
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
		// Idempotent: already committed (order-meta marker, complementing the ledger UNIQUE ref).
		if ( '' !== (string) $order->get_meta( self::ORDER_META ) ) {
			return;
		}

		$points = (int) $order->get_meta( self::PENDING_META );
		if ( $points <= 0 && WC()->session ) {
			// Fallback for any flow that skipped a create-order hook (cart still present then).
			$points = self::clamp_points( (int) WC()->session->get( self::SESSION_KEY, 0 ), Api::get_points( $user_id ) );
		}
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, 0 );
		}
		// Serialise the balance read + debit per user (GET_LOCK) so two concurrent orders for the same
		// customer cannot each spend the same points (double-spend → negative ledger). The per-order
		// ORDER_META guard above is idempotency for ONE order only.
		UserLock::with(
			$user_id,
			static function () use ( $order, $order_id, $user_id, $points ) {
				// Never debit more than the live balance; do NOT re-run the cart-based clamp (the cart may
				// already be emptied at this point — the value was clamped against caps at stamp time).
				$points = min( $points, Api::get_points( $user_id ) );
				if ( $points <= 0 ) {
					return;
				}

				$ref = 'checkout:' . $order_id;
				$ok  = Ledger::record_once(
					$user_id,
					-$points,
					'redeem',
					'checkout_redeem',
					$ref,
					array(
						'order_id' => $order_id,
						'note'     => __( 'Checkout points redemption', 'moksa-points-for-woocommerce' ),
						'meta'     => array( 'discount' => self::discount_for( $points ) ),
					)
				);
				if ( $ok ) {
					$order->update_meta_data( self::ORDER_META, (string) $points );
					$order->update_meta_data( self::LEDGER_META, $ref );
					$order->delete_meta_data( self::PENDING_META );
					$order->save();
				}
			}
		);
	}

	/** Refund the committed points once when the order is cancelled/refunded (idempotent). */
	public static function refund( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$points = (int) $order->get_meta( self::ORDER_META );
		if ( $points <= 0 ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		Ledger::record_once(
			$user_id,
			$points,
			'adjust',
			'checkout_redeem_refund',
			'checkout_refund:' . $order_id,
			array(
				'order_id' => $order_id,
				'note'     => __( 'Checkout points redemption reversal', 'moksa-points-for-woocommerce' ),
			)
		);
	}
}
