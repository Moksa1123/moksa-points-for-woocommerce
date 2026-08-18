<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Engage;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 前台互動打磨 (Engage) — the customer-facing polish layer that turns a working points engine into
 * a programme members can actually follow:
 *
 *   •「如何賺點」引導  — a settings-derived guide on「我的點數」and via [moksafopoi_how_to_earn].
 *   • 即時賺點吐司      — a "you earned N points" toast on the order-received page.
 *   • 可分享的成就卡    — LINE / Facebook / copy-link sharing of the member's own achievement.
 *
 * Read-only by construction: this module never writes to the ledger and never invents a number —
 * every figure comes from the same options/meta the engine itself uses. Each of the three surfaces
 * has its own toggle so a shop can take only the parts it wants.
 */
final class Module extends AbstractModule {

	/** Handle for the module's own (dependency-free) inline style + script. */
	private const HANDLE = 'moksafopoi-engage';

	/** admin-ajax action used by the share-bonus claim (mirrors Share::ACTION). */
	private const CLAIM_ACTION = 'moksafopoi_share_claim';

	public function slug(): string {
		return 'engage';
	}

	public function label(): string {
		return __( 'Storefront engagement polish', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'A "how to earn points" guide, an earned-points toast after checkout, and a shareable achievement card', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// The share-bonus claim handler must exist for admin-ajax requests too, so it is registered
		// before the is_admin() early return below.
		if ( 'no' !== get_option( 'moksafopoi_engage_share_enabled', 'yes' ) ) {
			Share::register();
		}

		// The shortcode is registered in every context (it costs nothing when unused) so a page
		// builder preview in the admin resolves it too.
		if ( 'no' !== get_option( 'moksafopoi_engage_howto_enabled', 'yes' ) ) {
			add_shortcode( 'moksafopoi_how_to_earn', array( HowToEarn::class, 'shortcode' ) );
		}

		if ( is_admin() ) {
			return;
		}

		if ( 'no' !== get_option( 'moksafopoi_engage_howto_enabled', 'yes' ) ) {
			// Priority 30: after the Display layer's own account message (10) so the guide reads as
			// the block below it, not above.
			add_action( 'moksafopoi_after_account_hero', array( HowToEarn::class, 'render_account' ), 30 );
		}

		if ( 'no' !== get_option( 'moksafopoi_engage_toast_enabled', 'yes' ) ) {
			add_action( 'woocommerce_thankyou', array( Toast::class, 'render_thankyou' ), 20 );
		}

		if ( 'no' !== get_option( 'moksafopoi_engage_share_enabled', 'yes' ) ) {
			add_action( 'moksafopoi_after_account_hero', array( Share::class, 'render_account' ), 40 );
		}

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * All three surfaces' CSS/JS, registered here on `wp_enqueue_scripts`.
	 *
	 * It MUST happen here rather than inside the renderers: a wp_add_inline_style() call made while
	 * the page body is being rendered arrives after <head> is printed and is silently dropped (the
	 * bug that left the share card unstyled and its copy button dead).
	 */
	public static function enqueue(): void {
		wp_register_style( self::HANDLE, false, array(), MOKSAFOPOI_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::css() );

		wp_register_script( self::HANDLE, false, array(), MOKSAFOPOI_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, self::js() );
	}

	/**
	 * Claim the one-off sharing bonus when a share button is used. The button still does its normal
	 * job (open LINE / Facebook, copy the link); the claim is a separate, nonce-protected POST whose
	 * result is shown next to the buttons. A member who is not eligible simply sees the reason.
	 */
	private static function claim_js(): string {
		return '(function(){var w=document.querySelector(".moksafopoi-share[data-share-bonus]");if(!w){return;}'
			. 'var msg=document.createElement("p");msg.className="moksafopoi-share__claim";w.appendChild(msg);'
			. 'w.addEventListener("click",function(e){'
			. 'var b=e.target.closest("[data-claim][data-channel]");if(!b){return;}'
			. 'var fd=new FormData();fd.append("action","' . self::CLAIM_ACTION . '");'
			. 'fd.append("nonce",b.getAttribute("data-nonce"));fd.append("channel",b.getAttribute("data-channel"));'
			. 'fetch(b.getAttribute("data-ajaxurl"),{method:"POST",credentials:"same-origin",body:fd})'
			. '.then(function(r){return r.json();}).then(function(res){'
			. 'msg.textContent=(res&&res.data&&res.data.message)?res.data.message:"";})'
			. '.catch(function(){});'
			. '});'
			. '})();';
	}

	private static function css(): string {
		return '.moksafopoi-howto{margin:20px 0}'
			. '.moksafopoi-howto__list{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:10px}'
			. '.moksafopoi-howto__item{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;'
			. 'border:1px solid #e6e8eb;border-radius:8px;background:#fff}'
			. '.moksafopoi-howto__icon{font-size:20px;line-height:1.3}'
			. '.moksafopoi-howto__body{display:block}'
			. '.moksafopoi-howto__name{display:block;font-weight:600}'
			. '.moksafopoi-howto__desc{display:block;color:#555;font-size:14px;line-height:1.5}'
			. '.moksafopoi-share{margin:20px 0}'
			. '.moksafopoi-share__actions{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 0}'
			. '.moksafopoi-share__btn{display:inline-block;padding:7px 14px;border:1px solid #d5d8dc;border-radius:6px;'
			. 'background:#fff;color:#1e1e1e;font-size:14px;line-height:1.4;text-decoration:none;cursor:pointer}'
			. '.moksafopoi-share__btn--line{background:#06c755;border-color:#06c755;color:#fff}'
			. '.moksafopoi-share__btn--fb{background:#1877f2;border-color:#1877f2;color:#fff}'
			. '.moksafopoi-share__bonus{margin:8px 0 0;color:#b45309;font-size:14px}'
			. '.moksafopoi-share__claim{margin:6px 0 0;color:#137333;font-size:14px}'
			. '.moksafopoi-toast{margin:0 0 16px;font-weight:600}';
	}

	/**
	 * Copy-to-clipboard for the share card. Uses the async Clipboard API where it is available
	 * (HTTPS) and falls back to a hidden textarea + execCommand — never a blocking window.prompt.
	 */
	private static function js(): string {
		return self::claim_js()
			. '(function(){var b=document.querySelector(".moksafopoi-share__btn--copy");if(!b){return;}'
			. 'function flash(){var done=b.getAttribute("data-copied")||"OK";var old=b.textContent;'
			. 'b.textContent=done;window.setTimeout(function(){b.textContent=old;},2000);}'
			. 'function legacy(url){var t=document.createElement("textarea");t.value=url;t.setAttribute("readonly","");'
			. 't.style.position="fixed";t.style.left="-9999px";document.body.appendChild(t);t.select();'
			. 'try{document.execCommand("copy");flash();}catch(e){}document.body.removeChild(t);}'
			. 'b.addEventListener("click",function(){var url=b.getAttribute("data-url")||"";'
			. 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(flash,function(){legacy(url);});}'
			. 'else{legacy(url);}});'
			. '})();';
	}
}
