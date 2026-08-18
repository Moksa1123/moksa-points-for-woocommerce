<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Campaign;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 行銷活動 (Campaign) — the platform-wide marketing-campaign hub. One option holds a list of
 * time-boxed campaigns; while one is live it boosts the points multiplier here, and exposes a
 * commission multiplier + member discount that SIBLING plugins read through the global
 * {@see moksafopoi_active_campaign()} contract (moforaffiliate scales commission, moformember
 * applies the discount). moksafopoi owns the activity window so every plugin agrees on "what is
 * running right now" without inventing its own calendar.
 *
 * Storage is a single `moksafopoi_campaigns` JSON option — NO custom post type. The back-office
 * is one nonce-protected, capability-gated admin-post handler; every output is escaped and every
 * input unslashed + sanitised.
 */
final class Module extends AbstractModule {

	public const PAGE   = 'moksafopoi-campaigns';
	public const CAP    = 'manage_woocommerce';
	public const NONCE  = 'moksafopoi_campaign_admin';
	public const ACTION = 'moksafopoi_save_campaign';

	public function slug(): string {
		return 'campaign';
	}

	public function label(): string {
		return __( 'Marketing campaigns', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'admin';
	}

	public function tagline(): string {
		return __( 'A platform-wide campaign hub: limited-time points multiplier / commission multiplier / member discount (other plugins read the same campaign)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Boost earned points while a campaign is live. Stacks (multiplies) with the existing
		// tier/role/spend factors, clamped ≤ MAX so the combined product can never explode.
		add_filter( 'moksafopoi_earn_multiplier', array( self::class, 'apply_earn_multiplier' ), 20 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
			add_action( 'admin_enqueue_scripts', array( Screen::class, 'enqueue_admin' ) );
			add_action( 'admin_post_' . self::ACTION, array( Screen::class, 'handle' ) );
		} else {
			// Optional storefront badge: "🔥 <活動名稱> 點數 N 倍中".
			add_action( 'woocommerce_before_main_content', array( self::class, 'render_badge' ), 5 );
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_front' ) );
		}

		do_action( 'moksafopoi_campaign_booted' );
	}

	/**
	 * `moksafopoi_earn_multiplier` filter: while a campaign is live, multiply the running multiplier
	 * by its points_mult, clamped to the shared ceiling so a campaign × tier × role × spend product
	 * can never run away.
	 *
	 * @param float $multiplier The multiplier accumulated so far by earlier filters.
	 */
	public static function apply_earn_multiplier( $multiplier ): float {
		$multiplier = is_numeric( $multiplier ) ? (float) $multiplier : 1.0;

		$campaign = Campaigns::active();
		if ( null === $campaign ) {
			return $multiplier;
		}

		$boosted = $multiplier * $campaign['points_mult'];

		// Clamp to the same ceiling the campaign multipliers themselves use, so the stacked product
		// stays house-safe regardless of how many factors multiplied in.
		return min( Campaigns::MAX_MULTIPLIER, max( 0.0, $boosted ) );
	}

	/**
	 * Add the 行銷活動 submenu next to the points settings page. Parent matches whichever menu the
	 * settings screen lives under (the independent "Moksa Points" top-level when the AdminMenu module
	 * is on, otherwise the WooCommerce menu fallback) — same pattern as RewardAdmin.
	 */
	public function register_menu(): void {
		add_submenu_page(
			$this->parent_slug(),
			__( 'Marketing campaigns', 'moksa-points-for-woocommerce' ),
			__( 'Marketing campaigns', 'moksa-points-for-woocommerce' ),
			self::CAP,
			self::PAGE,
			array( Screen::class, 'render' )
		);
	}

	/** Resolve the parent slug shared with the settings screen, so the pages sit together. */
	private function parent_slug(): string {
		/**
		 * Filter the parent menu slug the campaigns page is attached to.
		 *
		 * @param string $parent Default parent slug.
		 */
		$parent = apply_filters( 'moksafopoi_campaign_parent', 'woocommerce' );

		return is_string( $parent ) && '' !== $parent ? $parent : 'woocommerce';
	}

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/* ---------------------------------------------------------------- storefront badge */

	/**
	 * Render the storefront campaign badge before the main shop/product content. No raw <script>;
	 * the markup is escaped and the styling is registered separately via wp_add_inline_style.
	 */
	public static function render_badge(): void {
		$campaign = Campaigns::active();
		if ( null === $campaign ) {
			return;
		}

		$mult = $campaign['points_mult'];
		if ( $mult > 1.0 ) {
			$text = sprintf(
				/* translators: 1: campaign name, 2: points multiplier (e.g. 2). */
				__( '🔥 %1$s points at %2$s×', 'moksa-points-for-woocommerce' ),
				$campaign['name'],
				self::fmt_mult( $mult )
			);
		} else {
			$text = sprintf(
				/* translators: %s: campaign name. */
				__( '🔥 %s campaign in progress', 'moksa-points-for-woocommerce' ),
				$campaign['name']
			);
		}

		echo '<div class="moksafopoi-campaign-badge" role="status">' . esc_html( $text ) . '</div>';
	}

	/** Register the badge styles (no inline <style>); attaches to the front-end. */
	public static function enqueue_front(): void {
		if ( null === Campaigns::active() ) {
			return;
		}
		// Anchor the inline style on a stylesheet handle that is reliably enqueued on the storefront.
		$handle = wp_style_is( 'woocommerce-general', 'enqueued' ) || wp_style_is( 'woocommerce-general', 'registered' )
			? 'woocommerce-general'
			: 'wp-block-library';

		wp_add_inline_style(
			$handle,
			'.moksafopoi-campaign-badge{display:block;margin:0 0 16px;padding:10px 16px;border-radius:8px;'
			. 'background:linear-gradient(90deg,#ff6b6b,#ff9f43);color:#fff;font-weight:600;text-align:center;'
			. 'font-size:15px;line-height:1.4}'
		);
	}

	/** Format a multiplier without a trailing ".0" for whole numbers (2.0 → "2", 1.5 → "1.5"). */
	private static function fmt_mult( float $value ): string {
		return ( floor( $value ) === $value )
			? (string) (int) $value
			: rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
