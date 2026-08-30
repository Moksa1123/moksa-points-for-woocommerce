<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\EarnTriggers;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\EarnThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * 賺點觸發 — the milestone bonuses every mature loyalty plugin ships beyond per-order earning:
 * 註冊禮 (account creation), 評論回饋 (approved product review), 首購禮 (first paid order). Each
 * is awarded through the idempotent ledger keyed on a stable ref, so a replayed hook / re-approved
 * review / second order never double-pays. Amounts are options (0 = that trigger off). Spend-based
 * earning lives in SpendRules; coupon-redeem earning in EarnOnCoupon — this is the "events" lane.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'earntriggers';
	}

	public function label(): string {
		return __( 'Earning triggers (registration / review / first-purchase gift)', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'Points for registering, a reward for approved product reviews, and a first-purchase gift (each amount configurable, 0 = off)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'user_register', array( self::class, 'on_signup' ) );
		add_action( 'comment_post', array( self::class, 'on_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( self::class, 'on_comment_transition' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'on_first_order' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_first_order' ) );

		// 可重複的觸發(需頻率節流):每日簽到 + 生日(掛既有每日 heartbeat)。
		add_shortcode( 'moksafopoi_checkin', array( self::class, 'shortcode_checkin' ) );
		add_action( 'wp_ajax_moksafopoi_checkin', array( self::class, 'ajax_checkin' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_checkin_script' ) );
		add_action( 'moksafopoi_daily', array( self::class, 'on_daily_birthday' ) );
		add_action( 'moksafopoi_daily', array( self::class, 'on_daily_anniversary' ) );
	}

	/** 註冊禮 — once per user. */
	public static function on_signup( int $user_id ): void {
		$points = (int) get_option( 'moksafopoi_signup_bonus', 0 );
		if ( $user_id <= 0 || $points <= 0 ) {
			return;
		}
		Ledger::record_once(
			$user_id,
			$points,
			'earn',
			'signup',
			'signup_' . $user_id,
			array( 'note' => __( 'Registration gift', 'moksa-points-for-woocommerce' ) )
		);
	}

	/** A directly-approved comment ($approved === 1). */
	public static function on_comment_post( int $comment_id, $approved ): void {
		if ( 1 === (int) $approved ) {
			self::award_review( $comment_id );
		}
	}

	/** A comment later moved to approved. */
	public static function on_comment_transition( $new_status, $old_status, $comment ): void {
		if ( 'approved' === $new_status && $comment instanceof \WP_Comment ) {
			self::award_review( (int) $comment->comment_ID );
		}
	}

	/** 評論回饋 — once per approved product review by a logged-in customer. */
	private static function award_review( int $comment_id ): void {
		$points = (int) get_option( 'moksafopoi_review_bonus', 20 );
		if ( $comment_id <= 0 || $points <= 0 ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$user_id    = (int) $comment->user_id;
		$product_id = (int) $comment->comment_post_ID;
		if ( $user_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return; // only credited, logged-in product reviews.
		}

		// Per-product review cap (0 = unlimited): count already-rewarded reviews of THIS product for the
		// user straight from the ledger (source is product-scoped), so a member cannot farm points by
		// posting many reviews on the same product. The per-comment ref still blocks double-crediting one
		// review; this adds the per-(user,product) ceiling.
		$max = (int) get_option( 'moksafopoi_review_max_per_product', 0 );
		if ( $max > 0 && ! EarnThrottle::allow( $user_id, 'review_' . $product_id, $max, 'all' ) ) {
			return;
		}

		Ledger::record_once(
			$user_id,
			$points,
			'earn',
			'review_' . $product_id,
			'review_' . $comment_id,
			array(
				'note' => sprintf(
					/* translators: %s: reviewed product title. */
					__( 'Product review reward: %s', 'moksa-points-for-woocommerce' ),
					(string) get_the_title( $product_id )
				),
			)
		);
	}

	/** 首購禮 — once per customer, on their first paid order. */
	public static function on_first_order( int $order_id ): void {
		$points = (int) get_option( 'moksafopoi_first_order_bonus', 0 );
		if ( $points <= 0 ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return; // guest checkout has no account to credit.
		}

		/**
		 * Whether this order may trigger the one-off first-purchase gift. The Subscriptions layer
		 * vetoes renewal orders here — the second year of a subscription is not a first purchase.
		 *
		 * @param bool $eligible
		 * @param int  $order_id
		 */
		if ( ! apply_filters( 'moksafopoi_first_order_bonus_eligible', true, $order_id ) ) {
			return;
		}

		// The stable ref is per-user, so whichever paid order fires first wins and the rest no-op.
		Ledger::record_once(
			$user_id,
			$points,
			'earn',
			'first_order',
			'firstorder_' . $user_id,
			array( 'note' => __( 'First-purchase gift', 'moksa-points-for-woocommerce' ) )
		);
	}

	public const CHECKIN_NONCE = 'moksafopoi_checkin';

	/** Register and enqueue the check-in handler script. */
	public static function enqueue_checkin_script(): void {
		$bonus = (int) get_option( 'moksafopoi_checkin_bonus', 0 );
		if ( $bonus <= 0 || ! is_user_logged_in() ) {
			return;
		}
		wp_enqueue_script(
			'moksafopoi-checkin',
			false,
			array(),
			MOKSAFOPOI_VERSION,
			array( 'in_footer' => true )
		);
		wp_add_inline_script(
			'moksafopoi-checkin',
			'(function(){document.addEventListener("click",function(e){var b=e.target.closest(".moksafopoi-checkin-btn");if(!b)return;b.disabled=true;var fd=new FormData();fd.append("action","moksafopoi_checkin");fd.append("nonce",b.getAttribute("data-nonce"));fetch(b.getAttribute("data-ajaxurl"),{method:"POST",credentials:"same-origin",body:fd}).then(function(r){return r.json();}).then(function(res){if(res&&res.success){document.getElementById("moksafopoi-checkin").innerHTML=res.data.html;}else{window.alert((res&&res.data&&res.data.message)||"Error");b.disabled=false;}}).catch(function(){b.disabled=false;});});})();'
		);
	}

	/** 每日簽到 shortcode: a button that awards the check-in bonus once per UTC day (throttled). */
	public static function shortcode_checkin( $atts = array() ): string {
		$bonus = (int) get_option( 'moksafopoi_checkin_bonus', 0 );
		if ( $bonus <= 0 ) {
			return '';
		}
		if ( ! is_user_logged_in() ) {
			return '<div class="moksafopoi-checkin" id="moksafopoi-checkin"><p>' . esc_html__( 'Please log in to check in daily and earn points.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		}
		return '<div class="moksafopoi-checkin" id="moksafopoi-checkin">' . self::checkin_inner( get_current_user_id(), $bonus ) . '</div>';
	}

	/** Inner HTML of the check-in widget for a user: the claim button, or a "claimed" note. */
	private static function checkin_inner( int $user_id, int $bonus ): string {
		if ( EarnThrottle::allow( $user_id, 'checkin', 1, 'day' ) ) {
			return '<button type="button" class="button moksafopoi-checkin-btn" data-nonce="' . esc_attr( wp_create_nonce( self::CHECKIN_NONCE ) ) . '" data-ajaxurl="' . esc_attr( admin_url( 'admin-ajax.php' ) ) . '">'
				. sprintf(
					/* translators: %s: check-in bonus points. */
					esc_html__( 'Check in daily to earn %s point(s)', 'moksa-points-for-woocommerce' ),
					esc_html( number_format( $bonus ) )
				) . '</button>';
		}
		return '<p class="moksafopoi-checkin-done">' . esc_html__( 'Already checked in today, come back tomorrow!', 'moksa-points-for-woocommerce' ) . '</p>';
	}

	/** AJAX: award the daily check-in bonus once per UTC day. Idempotent (date-keyed ledger ref). */
	public static function ajax_checkin(): void {
		check_ajax_referer( self::CHECKIN_NONCE, 'nonce' );
		$user_id = get_current_user_id();
		$bonus   = (int) get_option( 'moksafopoi_checkin_bonus', 0 );
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please log in first.', 'moksa-points-for-woocommerce' ) ), 403 );
		}
		if ( $bonus <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Daily check-in is not enabled.', 'moksa-points-for-woocommerce' ) ) );
		}
		if ( ! EarnThrottle::allow( $user_id, 'checkin', 1, 'day' ) ) {
			wp_send_json_error( array( 'message' => __( 'You have already checked in today.', 'moksa-points-for-woocommerce' ) ) );
		}
		Ledger::record_once(
			$user_id,
			$bonus,
			'earn',
			'checkin',
			'checkin_' . $user_id . '_' . gmdate( 'Ymd' ),
			array( 'note' => __( 'Daily check-in', 'moksa-points-for-woocommerce' ) )
		);
		wp_send_json_success(
			array(
				'html' => '<p class="moksafopoi-checkin-done">' . esc_html__( 'Check-in successful! Come back tomorrow.', 'moksa-points-for-woocommerce' ) . '</p>',
			)
		);
	}

	/**
	 * 生日賺點 — awarded once per calendar year to each member whose birthday is today. Runs on the
	 * shared daily heartbeat and relies on moformember for birthday data (function_exists-guarded, so a
	 * store without moformember simply awards nothing). Throttled to once per year + idempotent ledger ref.
	 */
	public static function on_daily_birthday(): void {
		$bonus = (int) get_option( 'moksafopoi_birthday_bonus', 0 );
		if ( $bonus <= 0 || ! function_exists( 'moformember_birthdays_today' ) ) {
			return;
		}
		$ids = (array) moformember_birthdays_today();
		foreach ( $ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 || ! EarnThrottle::allow( $user_id, 'birthday', 1, 'year' ) ) {
				continue;
			}
			Ledger::record_once(
				$user_id,
				$bonus,
				'earn',
				'birthday',
				'birthday_' . $user_id . '_' . gmdate( 'Y' ),
				array( 'note' => __( 'Birthday gift', 'moksa-points-for-woocommerce' ) )
			);
		}
	}

	/**
	 * 入會週年禮 — awarded once per calendar year on each member's registration anniversary. Zero
	 * personal-data dependency (只用 wp_users.user_registered), so unlike the birthday bonus it
	 * needs no sibling plugin. Registration-YEAR itself is skipped (day 0 isn't an anniversary).
	 * Same double rail as birthday: EarnThrottle once/year + idempotent ledger ref per (user, year).
	 */
	public static function on_daily_anniversary(): void {
		$bonus = (int) get_option( 'moksafopoi_anniversary_bonus', 0 );
		if ( $bonus <= 0 ) {
			return;
		}

		global $wpdb;
		// Match today's month-day against user_registered; bounded, runs once a day.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily cron; core users table.
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users}
				WHERE DATE_FORMAT(user_registered, '%%m-%%d') = %s AND YEAR(user_registered) < %d
				ORDER BY ID ASC LIMIT 2000",
				gmdate( 'm-d' ),
				(int) gmdate( 'Y' )
			)
		);

		foreach ( $ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id <= 0 || ! EarnThrottle::allow( $user_id, 'anniversary', 1, 'year' ) ) {
				continue;
			}
			Ledger::record_once(
				$user_id,
				$bonus,
				'earn',
				'anniversary',
				'anniversary_' . $user_id . '_' . gmdate( 'Y' ),
				array( 'note' => __( 'Membership anniversary gift', 'moksa-points-for-woocommerce' ) )
			);
		}
	}
}
