<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\RewardHub;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Label;

defined( 'ABSPATH' ) || exit;

/**
 * 前台獎勵中心浮窗 — the storefront's always-visible「怎麼賺點」answer (the WP Swings popup /
 * myCred notification the plugin lacked). Two pieces, both read-only:
 *
 *   1. A floating button (bottom-right) opening a small panel: the member's balance (when
 *      logged in) + a how-to-earn list COMPOSED AUTOMATICALLY from whatever earn features the
 *      operator has switched on (消費集點 / 簽到 / 評論 / 生日 / 推薦好友), plus a link to the
 *      我的點數 page. Nothing is hard-coded — a bonus set to 0 simply doesn't appear.
 *   2. A thank-you page toast (implementation shared with Engage\Toast):「本次消費獲得 +N 點」, summed straight off the ledger rows the
 *      order actually booked (covers every earn module at once, no per-module wiring).
 *
 * Module default OFF (`moksafopoi_rewardhub_enabled`), pure display — no writes anywhere.
 */
final class Module extends AbstractModule {

	private const HANDLE = 'moksafopoi-rewardhub';

	public function slug(): string {
		return 'rewardhub';
	}

	public function label(): string {
		return __( 'Front-end reward hub widget', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'A floating button in the bottom-right corner of the front end + a "How to earn points" popup (its content is assembled automatically from the enabled earning features), plus a "You earned +N points this time" hint on the thank-you page. Display only.', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_footer', array( self::class, 'render_fab' ) );
		add_action( 'woocommerce_thankyou', array( self::class, 'render_thankyou_toast' ) );
	}

	/* ------------------------------------------------------------------ fab + panel */

	public static function render_fab(): void {
		// Keep checkout distraction-free; everywhere else the fab renders.
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$ways = self::earn_ways();
		$unit = Label::unit();

		echo '<div class="mfp-rewardhub" data-mfp-rewardhub>';
		echo '<button type="button" class="mfp-rewardhub__fab" aria-expanded="false" aria-controls="mfp-rewardhub-panel">'
			. esc_html(
				sprintf(
					/* translators: %s: the (customisable) points unit. */
					__( 'Earn %s', 'moksa-points-for-woocommerce' ),
					$unit
				)
			) . '</button>';

		echo '<div class="mfp-rewardhub__panel" id="mfp-rewardhub-panel" hidden>';
		echo '<p class="mfp-rewardhub__head">' . esc_html(
			sprintf(
				/* translators: %s: the (customisable) points unit. */
				__( 'How to earn %s', 'moksa-points-for-woocommerce' ),
				$unit
			)
		) . '</p>';

		if ( is_user_logged_in() ) {
			echo '<p class="mfp-rewardhub__balance">' . esc_html(
				sprintf(
					/* translators: 1: points balance, 2: unit. */
					__( 'You currently have %1$s %2$s', 'moksa-points-for-woocommerce' ),
					number_format_i18n( Api::get_points( get_current_user_id() ) ),
					$unit
				)
			) . '</p>';
		}

		if ( array() === $ways ) {
			echo '<p class="mfp-rewardhub__way">' . esc_html__( 'There are no active earning campaigns right now.', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			echo '<ul class="mfp-rewardhub__ways">';
			foreach ( $ways as $way ) {
				echo '<li><strong>' . esc_html( (string) ( $way['icon'] ?? '' ) . ' ' . (string) ( $way['title'] ?? '' ) ) . '</strong><br>'
					. esc_html( (string) ( $way['desc'] ?? '' ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( function_exists( 'wc_get_account_endpoint_url' ) && class_exists( \Moksafopoi\Modules\MyAccount\Endpoint::class ) ) {
			echo '<a class="mfp-rewardhub__link" href="' . esc_url( wc_get_account_endpoint_url( \Moksafopoi\Modules\MyAccount\Endpoint::SLUG ) ) . '">'
				. esc_html(
					sprintf(
						/* translators: %s: the (customisable) points unit. */
						__( 'View my %s »', 'moksa-points-for-woocommerce' ),
						$unit
					)
				) . '</a>';
		}
		echo '</div></div>';
	}

	/**
	 * The how-to-earn rows, composed from live settings — only switched-on ways appear. The
	 * composer itself lives in {@see \Moksafopoi\Modules\Engage\HowToEarn::items()} so the fab
	 * panel, the [moksafopoi_how_to_earn] shortcode and the「我的點數」panel can never drift apart.
	 *
	 * @return array<int,array{icon:string,title:string,desc:string}>
	 */
	private static function earn_ways(): array {
		return \Moksafopoi\Modules\Engage\HowToEarn::items();
	}

	/* ------------------------------------------------------------------ thankyou toast */

	/**
	 * 感謝頁提示 —— 實作已集中到 Engage\Toast(直接讀帳本加總這張訂單實際入帳的正向點數,
	 * 涵蓋所有賺點模組)。這裡只轉呼叫,兩個模組同時開啟時由 Toast 的 rendered 旗標保證只印一次。
	 *
	 * @param int $order_id
	 */
	public static function render_thankyou_toast( $order_id ): void {
		\Moksafopoi\Modules\Engage\Toast::render_thankyou( $order_id );
	}

	/* ------------------------------------------------------------------ assets */

	public static function enqueue(): void {
		wp_register_style( self::HANDLE, false, array(), MOKSAFOPOI_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::css() );

		wp_register_script( self::HANDLE, false, array(), MOKSAFOPOI_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, self::js() );
	}

	private static function css(): string {
		return '.mfp-rewardhub{position:fixed;right:18px;bottom:18px;z-index:9990;font-size:14px}'
			. '.mfp-rewardhub__fab{background:#f97316;color:#fff;border:0;border-radius:999px;padding:10px 18px;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.18)}'
			. '.mfp-rewardhub__fab:hover{background:#ea580c}'
			. '.mfp-rewardhub__panel{position:absolute;right:0;bottom:52px;width:260px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;box-shadow:0 8px 30px rgba(0,0,0,.16)}'
			. '.mfp-rewardhub__head{font-weight:700;margin:0 0 8px}'
			. '.mfp-rewardhub__balance{margin:0 0 8px;color:#b45309;font-weight:600}'
			. '.mfp-rewardhub__ways{margin:0 0 10px;padding-left:18px}'
			. '.mfp-rewardhub__ways li{margin:2px 0}'
			. '.mfp-rewardhub__link{font-weight:600;text-decoration:none}'
			. '.mfp-rewardhub__toast{margin:0 0 16px}';
	}

	private static function js(): string {
		return '(function(){var w=document.querySelector("[data-mfp-rewardhub]");if(!w){return;}'
			. 'var b=w.querySelector(".mfp-rewardhub__fab"),p=w.querySelector(".mfp-rewardhub__panel");'
			. 'if(!b||!p){return;}'
			. 'b.addEventListener("click",function(){var open=p.hasAttribute("hidden");'
			. 'if(open){p.removeAttribute("hidden");}else{p.setAttribute("hidden","");}'
			. 'b.setAttribute("aria-expanded",open?"true":"false");});'
			. 'document.addEventListener("click",function(e){if(!w.contains(e.target)&&!p.hasAttribute("hidden")){p.setAttribute("hidden","");b.setAttribute("aria-expanded","false");}});'
			. '})();';
	}
}
