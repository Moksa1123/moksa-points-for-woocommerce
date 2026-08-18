<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Referral;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\EarnThrottle;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 推薦好友得點 — the myCred / WP-Swings "refer a friend, both earn" loop the points plugin lacked (the
 * casual customer-to-customer viral primitive, distinct from moforaffiliate's KOL commission lane). Each
 * member gets a referral link `?mfp_ref=<code>`; a friend who lands, registers and then makes a qualifying
 * first purchase earns the referrer (and optionally the friend) points — once per friend, idempotently,
 * capped per period so it cannot be farmed. Bound to user-meta only (NO CPT). moforaffiliate/moforcoupon
 * are untouched; this is points-only and self-contained.
 */
final class Module extends AbstractModule {

	public const COOKIE          = 'moksafopoi_ref';
	private const META_CODE      = '_moksafopoi_ref_code';
	private const META_REFERRED  = '_moksafopoi_referred_by';
	private const META_REWARDED  = '_moksafopoi_referral_rewarded';
	private const META_REVERSED  = '_moksafopoi_referral_reversed';
	private const SHORTCODE      = 'moksafopoi_referral';

	public function slug(): string {
		return 'referral';
	}

	public function label(): string {
		return __( 'Refer a friend to earn points', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'Members refer friends with a dedicated link; after a friend completes their first purchase, both sides earn points (once per friend, with an optional cap). Shortcode [moksafopoi_referral].', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'init', array( self::class, 'maybe_set_cookie' ) );
		add_action( 'user_register', array( self::class, 'on_register' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_order_paid' ) );
		// Claw back the REFERRER reward when the qualifying order is cancelled/refunded (F6). The
		// friend-side reward carries order_id + the order's customer, so the Refund module claws it;
		// the referrer is a different user and is reversed here.
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'reverse_referrer' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'reverse_referrer' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
	}

	/**
	 * Reverse the referrer's referral reward for a cancelled/refunded order (idempotent via an order
	 * marker). Books the full reversal even if it drives the referrer negative — a farmed reward tied
	 * to a refunded order cannot be kept.
	 *
	 * @param int $order_id
	 */
	public static function reverse_referrer( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '1' === (string) $order->get_meta( self::META_REVERSED ) ) {
			return; // already reversed.
		}
		$friend_id = (int) $order->get_customer_id();
		$referrer  = $friend_id > 0 ? (int) get_user_meta( $friend_id, self::META_REFERRED, true ) : 0;
		if ( $referrer <= 0 ) {
			$order->update_meta_data( self::META_REVERSED, '1' );
			$order->save();
			return;
		}

		global $wpdb;
		$table = Schema::ledger_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		$granted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE user_id = %d AND source = 'referral' AND source_ref = %s AND points_delta > 0",
				$referrer,
				'referral_' . $friend_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $granted > 0 ) {
			Ledger::record_once(
				$referrer,
				-$granted,
				'earn_clawback',
				'referral_reverse',
				'referral_reverse:' . (int) $order_id . ':' . $friend_id,
				array(
					'order_id' => (int) $order_id,
					'note'     => __( 'Claw back referral reward on refund', 'moksa-points-for-woocommerce' ),
					'meta'     => array( 'friend_id' => $friend_id ),
				)
			);
		}
		$order->update_meta_data( self::META_REVERSED, '1' );
		$order->save();
	}

	/* ------------------------------------------------------------------ code */

	/** A member's referral code, generated + stored on first use. */
	public static function code_for( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$code = (string) get_user_meta( $user_id, self::META_CODE, true );
		if ( '' !== $code ) {
			return $code;
		}
		// Short, non-sequential, collision-checked.
		do {
			$code = strtolower( wp_generate_password( 8, false ) );
		} while ( null !== self::resolve_code( $code ) );
		update_user_meta( $user_id, self::META_CODE, $code );
		return $code;
	}

	/** Resolve a referral code to its owner user id, or null. */
	public static function resolve_code( string $code ): ?int {
		$code = strtolower( trim( $code ) );
		if ( '' === $code ) {
			return null;
		}
		$users = get_users(
			array(
				'meta_key'   => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed usermeta lookup, admin-scale.
				'meta_value' => $code,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
				'fields'     => 'ID',
				'number'     => 1,
			)
		);
		return ! empty( $users ) ? (int) $users[0] : null;
	}

	/** The full referral URL for a member. */
	public static function link_for( int $user_id ): string {
		$code = self::code_for( $user_id );
		return '' !== $code ? add_query_arg( 'mfp_ref', $code, home_url( '/' ) ) : '';
	}

	/* ------------------------------------------------------------------ capture + bind */

	/** Seed the referral cookie when a valid `?mfp_ref=<code>` lands (guests only — a member has an account). */
	public static function maybe_set_cookie(): void {
		if ( is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public landing param, not a state change.
		$raw = isset( $_GET['mfp_ref'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mfp_ref'] ) ) : '';
		$code = strtolower( trim( $raw ) );
		if ( '' === $code || is_user_logged_in() ) {
			return;
		}
		if ( null === self::resolve_code( $code ) ) {
			return; // only for a real code.
		}
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$code,
				array(
					'expires'  => time() + 30 * DAY_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $code;
	}

	/** On registration, bind the new user to the referrer from the cookie (first-touch, never overwritten). */
	public static function on_register( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$code = isset( $_COOKIE[ self::COOKIE ] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$referrer = self::resolve_code( $code );
		if ( null === $referrer || $referrer === $user_id ) {
			return; // no self-referral.
		}
		if ( '' !== (string) get_user_meta( $user_id, self::META_REFERRED, true ) ) {
			return; // already bound.
		}
		update_user_meta( $user_id, self::META_REFERRED, $referrer );
	}

	/* ------------------------------------------------------------------ reward */

	/** Reward the referrer (and optionally the friend) when a referred friend makes a qualifying purchase. */
	public static function on_order_paid( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$friend_id = (int) $order->get_customer_id();
		if ( $friend_id <= 0 ) {
			return;
		}
		if ( '' !== (string) get_user_meta( $friend_id, self::META_REWARDED, true ) ) {
			return; // this friend already triggered a referral reward.
		}
		$referrer = (int) get_user_meta( $friend_id, self::META_REFERRED, true );
		if ( $referrer <= 0 || $referrer === $friend_id ) {
			return;
		}

			// Block mutual (A<->B) referral farming: if this buyer is the referrer's OWN referrer,
			// the pair are referring each other, so neither side may be rewarded.
			if ( (int) get_user_meta( $referrer, self::META_REFERRED, true ) === $friend_id ) {
				return;
			}

		// Qualifying order threshold — on the amount actually paid (post-discount), so a large coupon
		// cannot make a near-zero order "qualify" (F6).
		$min = (float) get_option( 'moksafopoi_referral_min_order', 0 );
		if ( $min > 0 && (float) $order->get_total() < $min ) {
			return;
		}

		// Per-period cap on how many referrals a referrer may be rewarded for.
		$cap    = (int) get_option( 'moksafopoi_referral_cap', 0 );
		$period = (string) get_option( 'moksafopoi_referral_cap_period', 'all' );
		if ( $cap > 0 && ! EarnThrottle::allow( $referrer, 'referral', $cap, $period ) ) {
			return; // over cap — retry on a later order (no sentinel set), or simply not rewarded.
		}

		$referrer_reward = (int) get_option( 'moksafopoi_referral_reward', 0 );
		$friend_reward   = (int) get_option( 'moksafopoi_referral_friend_reward', 0 );

		$did = false;
		if ( $referrer_reward > 0 ) {
			$did = Ledger::record_once(
				$referrer,
				$referrer_reward,
				'earn',
				'referral',
				'referral_' . $friend_id,
				array(
					'order_id' => $order_id,
					'note'     => __( 'Referral reward', 'moksa-points-for-woocommerce' ),
					'meta'     => array( 'friend_id' => $friend_id, 'order_id' => $order_id ),
				)
			) || $did;
		}
		if ( $friend_reward > 0 ) {
			$did = Ledger::record_once(
				$friend_id,
				$friend_reward,
				'earn',
				'referral_friend',
				'referralfriend_' . $friend_id,
				array(
					'order_id' => $order_id,
					'note'     => __( 'Referred friend reward', 'moksa-points-for-woocommerce' ),
					'meta'     => array( 'referrer_id' => $referrer, 'order_id' => $order_id ),
				)
			) || $did;
		}

		if ( $did || ( 0 === $referrer_reward && 0 === $friend_reward ) ) {
			update_user_meta( $friend_id, self::META_REWARDED, (string) time() );
			do_action( 'moksafopoi_referral_rewarded', $referrer, $friend_id, $order_id );
		}
	}

	/** Count of friends a referrer has successfully brought in (rewarded). */
	public static function referral_count( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = \Moksafopoi\Support\Schema::ledger_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); values bound via $wpdb->prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND source = %s AND points_delta > 0", $user_id, 'referral' ) );
	}

	/* ------------------------------------------------------------------ shortcode */

	/** `[moksafopoi_referral]` — the member's referral link + copy button + how many friends they've earned from. */
	public static function render(): string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<div class="moksafopoi-referral"><p>' . esc_html__( 'Please log in first to get your referral link.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		}
		$link  = self::link_for( $user_id );
		$count = self::referral_count( $user_id );
		$reward = (int) get_option( 'moksafopoi_referral_reward', 0 );

		$out  = '<div class="moksafopoi-referral">';
		$out .= '<p><strong>' . esc_html__( 'Your dedicated referral link', 'moksa-points-for-woocommerce' ) . '</strong></p>';
		$out .= '<p><input type="text" readonly value="' . esc_attr( $link ) . '" onclick="this.select()" style="width:100%;max-width:420px"></p>';
		if ( $reward > 0 ) {
			$out .= '<p>' . esc_html(
				sprintf(
					/* translators: %d: points reward. */
					__( 'When a friend registers through this link and completes their first purchase, you earn %d point(s).', 'moksa-points-for-woocommerce' ),
					$reward
				)
			) . '</p>';
		}
		$out .= '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of referred friends. */
				__( 'Friends successfully referred: %d', 'moksa-points-for-woocommerce' ),
				$count
			)
		) . '</p>';
		$out .= '</div>';
		return $out;
	}
}
