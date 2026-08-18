<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Quests;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\Label;

defined( 'ABSPATH' ) || exit;

/**
 * 任務／成就引擎 — 「完成 3 筆訂單拿 500 點」「寫 1 則評價拿 50 點」: the missions competitors
 * charge for, with zero new tables (definitions in one option, progress in one user-meta).
 *
 * This module only LISTENS to events that already exist (order paid, review approved, referral
 * rewarded, daily check-in) and bumps progress; the reward is booked through the ledger like any
 * other earn, so the audit trail, the earn cap and the refund machinery all apply unchanged.
 *
 * Idempotency has two layers: a completed quest is stamped in user-meta AND the ledger write uses
 * the stable ref `quest:<key>:<user_id>`. Either one alone would prevent a double payout.
 */
final class Module extends AbstractModule {

	/** Handle for the module's own inline style. */
	private const HANDLE = 'moksafopoi-quests';

	public function slug(): string {
		return 'quests';
	}

	public function label(): string {
		return __( 'Quests / achievements', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'Missions such as "complete 3 orders" or "leave a review" that pay out points when finished; progress is shown on "My points"', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Orders: count them and accumulate spend. Both paid statuses fire; the per-order guard below
		// makes the duplicate harmless.
		add_action( 'woocommerce_order_status_processing', array( self::class, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_order_paid' ) );

		// Reviews: only when the comment is (or becomes) approved.
		add_action( 'comment_post', array( self::class, 'on_comment_post' ), 20, 2 );
		add_action( 'transition_comment_status', array( self::class, 'on_comment_transition' ), 20, 3 );

		// Referral fires its own event. Check-in has no dedicated hook, so we watch the ledger's
		// generic earn event and pick out the 'checkin' source — that also keeps working if the
		// check-in award ever moves to another module.
		add_action( 'moksafopoi_referral_rewarded', array( self::class, 'on_referral' ), 10, 1 );
		add_action( 'moksafopoi_points_earned', array( self::class, 'on_points_earned' ), 10, 3 );

		add_shortcode( 'moksafopoi_quests', array( self::class, 'shortcode' ) );

		if ( is_admin() ) {
			return;
		}
		// Priority 25: under the tier card (20), above the「如何賺點」guide (30).
		add_action( 'moksafopoi_after_account_hero', array( self::class, 'render_account' ), 25 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/* ---------------------------------------------------------------- event listeners */

	/**
	 * An order reached a paid status: +1 order and +total spend, once per order.
	 *
	 * @param int $order_id
	 */
	public static function on_order_paid( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return; // Guest checkout has no member to credit.
		}

		// One order must only ever count once, however many status hooks fire for it.
		if ( '' !== (string) $order->get_meta( '_moksafopoi_quest_counted' ) ) {
			return;
		}
		$order->update_meta_data( '_moksafopoi_quest_counted', '1' );
		$order->save();

		self::advance( $user_id, 'order', 1 );
		self::advance( $user_id, 'spend_total', (int) floor( (float) $order->get_total() ) );
	}

	/**
	 * A new comment was posted: count it when it is already approved.
	 *
	 * @param int      $comment_id
	 * @param int|string $approved
	 */
	public static function on_comment_post( $comment_id, $approved ): void {
		if ( 1 === (int) $approved ) {
			self::count_review( (int) $comment_id );
		}
	}

	/**
	 * A comment changed status: count it the moment it becomes approved.
	 *
	 * @param string      $new_status
	 * @param string      $old_status
	 * @param \WP_Comment $comment
	 */
	public static function on_comment_transition( $new_status, $old_status, $comment ): void {
		if ( 'approved' === $new_status && $comment instanceof \WP_Comment ) {
			self::count_review( (int) $comment->comment_ID );
		}
	}

	/** Count an approved product review for its author, once per comment. */
	private static function count_review( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$user_id = (int) $comment->user_id;
		if ( $user_id <= 0 || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}
		if ( '' !== (string) get_comment_meta( $comment_id, '_moksafopoi_quest_counted', true ) ) {
			return;
		}
		update_comment_meta( $comment_id, '_moksafopoi_quest_counted', '1' );

		self::advance( $user_id, 'review', 1 );
	}

	/**
	 * A referral paid out (fired by the referral module with the REFERRER's id).
	 *
	 * @param int $referrer_id
	 */
	public static function on_referral( $referrer_id ): void {
		self::advance( (int) $referrer_id, 'referral', 1 );
	}

	/**
	 * Any points were earned: the only source we track here is the daily check-in (orders, reviews
	 * and referrals have their own, more precise listeners above).
	 *
	 * @param int    $user_id
	 * @param int    $points
	 * @param string $source
	 */
	public static function on_points_earned( $user_id, $points, $source = '' ): void {
		if ( 'checkin' === (string) $source ) {
			self::advance( (int) $user_id, 'checkin', 1 );
		}
	}

	/* ---------------------------------------------------------------- engine */

	/**
	 * Bump every enabled quest listening for `$event` and pay out the ones that just finished.
	 *
	 * @param int $amount 1 for a countable event, the order total for `spend_total`.
	 */
	public static function advance( int $user_id, string $event, int $amount ): void {
		if ( $user_id <= 0 || $amount <= 0 ) {
			return;
		}
		$done = Quests::completed( $user_id );

		foreach ( Quests::for_event( $event ) as $quest ) {
			if ( in_array( $quest['key'], $done, true ) ) {
				continue; // Already finished — stop counting.
			}
			$total = Quests::bump( $user_id, $quest['key'], $amount );
			if ( $total < $quest['target'] ) {
				continue;
			}
			self::complete( $user_id, $quest );
		}
	}

	/**
	 * Finish a quest: stamp it, then book the reward. The stamp comes FIRST so a failure to write the
	 * ledger cannot leave a loop that retries the payout on every subsequent event; the ledger's own
	 * idempotency ref is the second guard.
	 *
	 * @param array{key:string,label:string,reward:int} $quest
	 */
	private static function complete( int $user_id, array $quest ): void {
		if ( ! Quests::mark_done( $user_id, $quest['key'] ) ) {
			return; // Raced / already done.
		}

		if ( $quest['reward'] > 0 ) {
			Ledger::record_once(
				$user_id,
				(int) $quest['reward'],
				'earn',
				'quest',
				'quest:' . $quest['key'] . ':' . $user_id,
				array(
					'note' => sprintf(
						/* translators: %s: the quest name. */
						__( 'Quest completed: %s', 'moksa-points-for-woocommerce' ),
						$quest['label']
					),
				)
			);
		}

		/**
		 * Fires when a member completes a quest.
		 *
		 * @param int    $user_id
		 * @param string $key     The quest key.
		 * @param int    $reward  Points awarded.
		 */
		do_action( 'moksafopoi_quest_completed', $user_id, $quest['key'], (int) $quest['reward'] );
	}

	/* ---------------------------------------------------------------- display */

	/**
	 * `[moksafopoi_quests]` — the member's quest board.
	 *
	 * @param array<string,string>|string $atts
	 */
	public static function shortcode( $atts = array() ): string {
		unset( $atts );
		return self::html( get_current_user_id() );
	}

	/** Echo the board on the「我的點數」page. */
	public static function render_account(): void {
		echo wp_kses_post( self::html( get_current_user_id() ) );
	}

	/** The board markup, or '' when the member is a guest / no quests are defined. */
	public static function html( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$quests = Quests::status( $user_id );
		if ( array() === $quests ) {
			return '';
		}

		$out = '<div class="moksafopoi-quests">';
		$out .= '<h3 class="moksafopoi-quests__title">' . esc_html__( 'My quests', 'moksa-points-for-woocommerce' ) . '</h3>';
		$out .= '<ul class="moksafopoi-quests__list">';

		foreach ( $quests as $quest ) {
			$class = 'moksafopoi-quest' . ( $quest['done'] ? ' is-done' : '' );
			$out  .= '<li class="' . esc_attr( $class ) . '">';
			$out  .= '<div class="moksafopoi-quest__head"><strong>' . esc_html( $quest['label'] ) . '</strong>';
			if ( $quest['reward'] > 0 ) {
				$out .= ' <span class="moksafopoi-quest__reward">+' . esc_html( Label::format( (int) $quest['reward'] ) ) . '</span>';
			}
			$out .= '</div>';

			if ( '' !== $quest['desc'] ) {
				$out .= '<div class="moksafopoi-quest__desc">' . esc_html( $quest['desc'] ) . '</div>';
			}

			if ( $quest['done'] ) {
				$out .= '<div class="moksafopoi-quest__state">' . esc_html__( 'Completed', 'moksa-points-for-woocommerce' ) . '</div>';
			} else {
				$out .= '<div class="moksafopoi-quest__state">' . esc_html(
					sprintf(
						/* translators: 1: progress so far; 2: the target. */
						__( 'Progress %1$s / %2$s', 'moksa-points-for-woocommerce' ),
						number_format_i18n( $quest['progress'] ),
						number_format_i18n( $quest['target'] )
					)
				) . '</div>';
				$out .= '<div class="moksafopoi-quest__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" '
					. 'aria-valuenow="' . esc_attr( (string) $quest['percent'] ) . '">'
					. '<span class="moksafopoi-quest__fill" style="width:' . esc_attr( (string) $quest['percent'] ) . '%"></span></div>';
			}
			$out .= '</li>';
		}

		$out .= '</ul></div>';
		return $out;
	}

	/** Inline styles on the module's own handle. */
	public static function enqueue(): void {
		wp_register_style( self::HANDLE, false, array(), MOKSAFOPOI_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style(
			self::HANDLE,
			'.moksafopoi-quests{margin:20px 0}'
			. '.moksafopoi-quests__list{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:10px}'
			. '.moksafopoi-quest{padding:10px 12px;border:1px solid #e6e8eb;border-radius:8px;background:#fff}'
			. '.moksafopoi-quest.is-done{border-color:#cfe8d6;background:#f6fbf7}'
			. '.moksafopoi-quest__reward{color:#b45309;font-weight:600}'
			. '.moksafopoi-quest__desc{color:#555;font-size:14px;margin-top:2px}'
			. '.moksafopoi-quest__state{color:#666;font-size:13px;margin:6px 0 4px}'
			. '.moksafopoi-quest__bar{height:8px;border-radius:999px;background:#eceff3;overflow:hidden}'
			. '.moksafopoi-quest__fill{display:block;height:100%;background:linear-gradient(90deg,#34d399,#10b981)}'
		);
	}
}
