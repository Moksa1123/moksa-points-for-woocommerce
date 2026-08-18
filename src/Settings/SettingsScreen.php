<?php

declare( strict_types=1 );

namespace Moksafopoi\Settings;

use Moksafopoi\Modules\ExpiryReminder\Reminder;
use Moksafopoi\Plugin;
use Moksafopoi\Support\BalanceImporter;
use Moksafopoi\Support\GatewayPoints;
use Moksafopoi\Support\Multipliers;
use Moksafopoi\Support\SettingsAudit;
use Moksafopoi\Support\Rates;
use Moksafopoi\Support\Retention;
use Moksafopoi\Support\SettingsPorter;
use Moksafopoi\Modules\Quests\Quests;
use Moksafopoi\Support\Tiers;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own card-styled settings screen (not a WC settings tab). Renders the
 * module toggles and a few conversion fields, saved through our own admin-post handler.
 * Field id == option key, so writes go straight to the `moksafopoi_*` options every
 * module reads. Mirrors moforcoupon's SettingsScreen.
 */
final class SettingsScreen {

	public const SLUG   = 'moksafopoi-settings';
	public const CAP    = 'manage_woocommerce';
	public const NONCE  = 'moksafopoi_settings';
	public const ACTION = 'moksafopoi_save_settings';

	/** Default tab a group lands in when it declares no 'tab' key. */
	private const DEFAULT_TAB = 'general';

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * The ordered tab list (slug => label). Every group is bucketed into one of these by its 'tab'
	 * key. The tabs are spread across the sub-pages declared in {@see pages()}; within one sub-page
	 * they render as pill tabs whose panes all stay in the DOM (JS only flips visibility).
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'general'  => __( 'General', 'moksa-points-for-woocommerce' ),
			'earn'     => __( 'Earn points', 'moksa-points-for-woocommerce' ),
			'redeem'   => __( 'Redeem', 'moksa-points-for-woocommerce' ),
			'display'  => __( 'Display', 'moksa-points-for-woocommerce' ),
			'email'    => __( 'Emails', 'moksa-points-for-woocommerce' ),
			'advanced' => __( 'Advanced', 'moksa-points-for-woocommerce' ),
			'tools'    => __( 'Tools / History', 'moksa-points-for-woocommerce' ),
		);
	}

	/**
	 * The settings sub-pages (page key => spec). Each is a REAL admin page — its own submenu item
	 * under the independent menu, or a hidden-but-linked page when falling back under WooCommerce —
	 * rendering only its own tabs so every page stays short. Saving is scoped per page: the form
	 * posts a `moksafopoi_scope` field and {@see handle()} only writes the fields belonging to that
	 * page's tabs, so a toggle that lives on ANOTHER page is never misread as "off".
	 *
	 * @return array<string,array{slug:string,label:string,menu:string,desc:string,cb:callable,tabs:array<int,string>}>
	 */
	public static function pages(): array {
		return array(
			'main'    => array(
				'slug'  => self::SLUG,
				'label' => __( 'Basic settings', 'moksa-points-for-woocommerce' ),
				'menu'  => __( 'Settings', 'moksa-points-for-woocommerce' ),
				'desc'  => __( 'Choose the points and member reward features you want to enable. Click each section heading to collapse it and focus on the settings you need.', 'moksa-points-for-woocommerce' ),
				'cb'    => array( self::class, 'render' ),
				'tabs'  => array( 'general', 'earn', 'redeem', 'advanced' ),
			),
			'display' => array(
				'slug'  => self::SLUG . '-display',
				'label' => __( 'Display and emails', 'moksa-points-for-woocommerce' ),
				'menu'  => __( 'Display and emails', 'moksa-points-for-woocommerce' ),
				'desc'  => __( 'Adjust how points appear on the product page, cart, and My Account, and the look and content of notification emails.', 'moksa-points-for-woocommerce' ),
				'cb'    => array( self::class, 'render_display' ),
				'tabs'  => array( 'display', 'email' ),
			),
			'tools'   => array(
				'slug'  => self::SLUG . '-tools',
				'label' => __( 'Tools / History', 'moksa-points-for-woocommerce' ),
				'menu'  => __( 'Tools / History', 'moksa-points-for-woocommerce' ),
				'desc'  => __( 'Export / import settings, and view the settings change log.', 'moksa-points-for-woocommerce' ),
				'cb'    => array( self::class, 'render_tools' ),
				'tabs'  => array( 'tools' ),
			),
		);
	}

	/** Admin URL of one settings sub-page (unknown key falls back to the main page). */
	public static function page_url( string $page ): string {
		$pages = self::pages();
		$slug  = (string) ( $pages[ $page ]['slug'] ?? self::SLUG );
		return admin_url( 'admin.php?page=' . $slug );
	}

	/** Fallback submenu under WooCommerce when the independent menu module is off. */
	public static function register_fallback(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Points settings', 'moksa-points-for-woocommerce' ),
			__( 'Points settings', 'moksa-points-for-woocommerce' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
		// The other sub-pages get their own visible WooCommerce submenu entries too, so the sidebar
		// is the single, authoritative way to move between settings pages — there is no duplicate
		// in-page page-switcher. (With the independent「Moksa …」top-level menu on, they live there.)
		foreach ( self::pages() as $key => $spec ) {
			if ( 'main' === $key ) {
				continue;
			}
			add_submenu_page( 'woocommerce', (string) $spec['label'], (string) $spec['menu'], self::CAP, (string) $spec['slug'], $spec['cb'] );
		}
	}

	/** Used by the independent AdminMenu module as the settings page callback. */
	public static function register(): void {
		self::register_fallback();
	}

	/**
	 * @return array<int,array{tab?:string,title:string,desc:string,fields:array<int,array<string,mixed>>}>
	 */
	public static function groups(): array {
		return array(
			array(
				'tab'    => 'general',
				'title'  => __( 'Core', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'The points ledger is the foundation of all features; we recommend keeping it enabled.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_ledger_enabled', __( 'Points ledger', 'moksa-points-for-woocommerce' ), __( 'Records each member\'s points and store credit balance; the foundation of all points features.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_adminmenu_enabled', __( 'Standalone points menu', 'moksa-points-for-woocommerce' ), __( 'Move points management into a standalone top-level menu "Moksa Points".', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_myaccount_enabled', __( 'My Account "My points"', 'moksa-points-for-woocommerce' ), __( 'Add points balance and history tabs to "My Account".', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_campaign_enabled', __( 'Marketing campaigns', 'moksa-points-for-woocommerce' ), __( 'A site-wide campaign: limited-time double points, commission multiplier, and member discount (other plugins apply the same active campaign).', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'general',
				'title'  => __( 'Gamification (leaderboard / badges)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'The leaderboard ranks by "total points earned" (names can be masked); achievement badges are awarded when total points earned crosses a threshold.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_leaderboard_enabled', __( 'Points leaderboard', 'moksa-points-for-woocommerce' ), __( 'Provides the [moksafopoi_leaderboard] shortcode and shows "My rank" on "My points".', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_leaderboard_mask_names', __( 'Mask names on leaderboard', 'moksa-points-for-woocommerce' ), __( 'Mask members\' display names on the public leaderboard (e.g. "J*hn"). We recommend enabling this.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_badges_enabled', __( 'Achievement badges', 'moksa-points-for-woocommerce' ), __( 'Award badges (bronze / silver / gold) when total points earned crosses a threshold, and show them on "My points".', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_tierladder_enabled', __( 'Member tier ladder', 'moksa-points-for-woocommerce' ), __( 'Show the member\'s current tier and the distance to the next one on "My points", plus the [moksafopoi_tier] shortcode. When the membership plugin (moformember) is installed its tiers are used; otherwise the ladder below applies.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_blocks_enabled', __( 'Editor blocks', 'moksa-points-for-woocommerce' ), __( 'Add the points balance / tier / quests / how-to-earn / leaderboard / redemption mall as blocks in the editor. Each block renders exactly what its shortcode renders.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_quests_enabled', __( 'Quests / achievements', 'moksa-points-for-woocommerce' ), __( 'Missions such as "complete 3 orders" or "leave a review" that pay out points when finished; progress shows on "My points" and via the [moksafopoi_quests] shortcode.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_rewardhub_enabled', __( 'Front-end reward hub widget', 'moksa-points-for-woocommerce' ), __( 'A floating button in the bottom-right on the front end + a "How to earn points" popup (content assembled automatically from the enabled earning features), plus a "You earned +N points" hint on the thank-you page. Display only.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'general',
				'title'  => __( 'Tier ladder', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'The tiers used when the membership plugin is not installed. Tiers are reached on LIFETIME points earned, so spending never demotes a member. Leave a name blank to drop that rung.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::ladder_map( 'moksafopoi_tier_ladder', __( 'Tiers', 'moksa-points-for-woocommerce' ), __( 'Name plus the lifetime points needed. The lowest rung is usually 0 so every member starts inside the ladder.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_topup_counts_toward_tier', __( 'Purchased points count towards tiers', 'moksa-points-for-woocommerce' ), __( 'Off by default: points a customer BOUGHT (points pack) are spendable but do not raise their tier or unlock badges, so nobody can buy their way past members who earned it.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'general',
				'title'  => __( 'Quests', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Each quest pays its points ONCE, the moment a member reaches the target. Leave a name blank to remove that quest. Changing a target does not take points back from members who already finished it.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::quest_map( 'moksafopoi_quests', __( 'Quest list', 'moksa-points-for-woocommerce' ), __( 'Name, what is counted, how many times, and the reward.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'email',
				'title'  => __( 'Email appearance', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'The shared email appearance for all notification emails sent to members (such as expiry reminders): the header background color and header text. A live preview is shown below.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::color( 'moksafopoi_email_accent', __( 'Email header background color', 'moksa-points-for-woocommerce' ), __( 'The background color of the band at the top of the email (default gold #d4af37).', 'moksa-points-for-woocommerce' ), '#d4af37', '#d4af37' ),
					self::text( 'moksafopoi_email_header', __( 'Email header text', 'moksa-points-for-woocommerce' ), __( 'The text shown in the band at the top of the email (empty = site name).', 'moksa-points-for-woocommerce' ), '', __( 'Empty = site name', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'email',
				'title'  => __( 'Points validity / expiry reminder', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'How many months after being earned points expire (0 = never expires), and how many days before expiry to email a reminder to members.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::number( 'moksafopoi_points_expire_months', __( 'Points validity (months)', 'moksa-points-for-woocommerce' ), __( 'How many months after the earned date points expire (0 = never expires).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::toggle( 'moksafopoi_expiryreminder_enabled', __( 'Points expiry reminder', 'moksa-points-for-woocommerce' ), __( 'Email a reminder to members before points expire (automatically disabled when set to never expire).', 'moksa-points-for-woocommerce' ) ),
					self::number( 'moksafopoi_expiry_reminder_days', __( 'Reminder days before expiry', 'moksa-points-for-woocommerce' ), __( 'How many days before points expire to send the reminder email (default 14 days).', 'moksa-points-for-woocommerce' ), 14, '1', '1' ),
				),
			),
			array(
				'tab'    => 'email',
				'title'  => __( 'Event-based notification emails (earn / redeem)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Custom notification emails sent automatically when a member earns / redeems points. Requires "Event-based notification emails" to be enabled. Available variables: {points} {balance} {note} {name} {site}. "Throttle" is the minimum interval between emails for the same event to the same member (in seconds, 0 = no limit).', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_email_earned_enabled', __( 'Earn points notification email', 'moksa-points-for-woocommerce' ), __( 'Sent when a member earns points.', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_email_earned_subject', __( 'Earn email subject', 'moksa-points-for-woocommerce' ), __( 'Leave empty to use the default. The variables above are available.', 'moksa-points-for-woocommerce' ), '' ),
					self::textarea( 'moksafopoi_email_earned_body', __( 'Earn email body', 'moksa-points-for-woocommerce' ), __( 'Leave empty to use the default; one paragraph per line. The variables above are available.', 'moksa-points-for-woocommerce' ), '' ),
					self::select( 'moksafopoi_email_earned_recipient', __( 'Earn email recipient', 'moksa-points-for-woocommerce' ), __( 'Send to member / admin / both.', 'moksa-points-for-woocommerce' ), 'user', array( 'user' => __( 'Member', 'moksa-points-for-woocommerce' ), 'admin' => __( 'Admin', 'moksa-points-for-woocommerce' ), 'both' => __( 'Both', 'moksa-points-for-woocommerce' ) ) ),
					self::number( 'moksafopoi_email_earned_min', __( 'Earn email threshold (points)', 'moksa-points-for-woocommerce' ), __( 'Send only when points earned reach this value (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_email_earned_throttle', __( 'Earn email throttle (seconds)', 'moksa-points-for-woocommerce' ), __( 'Minimum interval between two earn emails to the same member (0 = send every time).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::toggle( 'moksafopoi_email_redeemed_enabled', __( 'Redeem points notification email', 'moksa-points-for-woocommerce' ), __( 'Sent when a member uses / redeems points.', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_email_redeemed_subject', __( 'Redeem email subject', 'moksa-points-for-woocommerce' ), __( 'Leave empty to use the default. The variables above are available.', 'moksa-points-for-woocommerce' ), '' ),
					self::textarea( 'moksafopoi_email_redeemed_body', __( 'Redeem email body', 'moksa-points-for-woocommerce' ), __( 'Leave empty to use the default; one paragraph per line. The variables above are available.', 'moksa-points-for-woocommerce' ), '' ),
					self::select( 'moksafopoi_email_redeemed_recipient', __( 'Redeem email recipient', 'moksa-points-for-woocommerce' ), __( 'Send to member / admin / both.', 'moksa-points-for-woocommerce' ), 'user', array( 'user' => __( 'Member', 'moksa-points-for-woocommerce' ), 'admin' => __( 'Admin', 'moksa-points-for-woocommerce' ), 'both' => __( 'Both', 'moksa-points-for-woocommerce' ) ) ),
					self::number( 'moksafopoi_email_redeemed_min', __( 'Redeem email threshold (points)', 'moksa-points-for-woocommerce' ), __( 'Send only when points used reach this value (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_email_redeemed_throttle', __( 'Redeem email throttle (seconds)', 'moksa-points-for-woocommerce' ), __( 'Minimum interval between two redeem emails to the same member (0 = send every time).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Earn points', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'How customers earn points / store credit.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_spendrules_enabled', __( 'Earn points on purchase', 'moksa-points-for-woocommerce' ), __( 'Add points by rule after order payment (spend N to earn Y points).', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_categorypoints_enabled', __( 'Per-category earning multiplier', 'moksa-points-for-woocommerce' ), __( 'Set "this category\'s points multiplier" on the product category edit page; applied line by line when earning points.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_earncoupon_enabled', __( 'Earn points with coupons', 'moksa-points-for-woocommerce' ), __( 'Award extra points when customers use a coupon (requires the coupon plugin).', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_cashback_enabled', __( 'Purchase cashback (store credit)', 'moksa-points-for-woocommerce' ), __( 'After order payment, give back a configured percentage of the amount as the customer\'s store credit (redeemable at checkout); refunds reverse automatically. See the store credit economy on the "Redeem" tab for the ratio / threshold.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_giftcard_enabled', __( 'Gift card top-up', 'moksa-points-for-woocommerce' ), __( 'Mark a product as a "gift card" on the product edit page; after purchase, its face value is added to the customer\'s store credit (redeemable at checkout); refunds reverse automatically.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'redeem',
				'title'  => __( 'Spend', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'How customers use points / store credit.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_checkoutredeem_enabled', __( 'Checkout redemption with points', 'moksa-points-for-woocommerce' ), __( 'Let customers redeem their points balance as a discount at checkout (classic and block checkout), using the redemption rate and limits below.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_wallet_enabled', __( 'Checkout redemption with wallet', 'moksa-points-for-woocommerce' ), __( 'Redeem using your store credit balance at checkout (takes over checkout redemption; deducted after payment).', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_refund_enabled', __( 'Reclaim points on refund', 'moksa-points-for-woocommerce' ), __( 'When an order is cancelled / refunded, reclaim the points awarded for that purchase (the balance will not go negative); store credit redemption and gift cards / cashback are reversed by their own features.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_redeem_enabled', __( 'Points redemption', 'moksa-points-for-woocommerce' ), __( 'Redeem points for coupons / products / cart discount.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_rewardadmin_enabled', __( 'Redemption catalog management', 'moksa-points-for-woocommerce' ), __( 'Add / edit / delete / list or delist redeemable items in the admin (used by "Points redemption").', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_pointsadmin_enabled', __( 'Batch / manual add or deduct points', 'moksa-points-for-woocommerce' ), __( 'Batch issue / revoke points in the admin (by role or list); support staff can search for a single member to manually add or deduct; quick add / deduct on the member edit page.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_referral_enabled', __( 'Refer a friend to earn points', 'moksa-points-for-woocommerce' ), __( 'Members refer friends with a personal link; both sides earn points after the friend completes their first purchase. Shortcode [moksafopoi_referral].', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_emailnotices_enabled', __( 'Event-based notification emails', 'moksa-points-for-woocommerce' ), __( 'Earn / redeem events trigger custom notification emails (recipient / threshold / throttling). Configure the content on the "Notification emails" tab.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'advanced',
				'title'  => __( 'Points branding', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Customize the unit name for points. Once changed, the My Account area, points store, leaderboard, badge progress, and admin dashboard all display the new unit.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::text( 'moksafopoi_label', __( 'Points unit name', 'moksa-points-for-woocommerce' ), __( 'The points unit shown to customers (default "points"; can be changed to "coins / miles / P", etc.).', 'moksa-points-for-woocommerce' ), __( 'Points', 'moksa-points-for-woocommerce' ), __( 'For example: coins', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'display',
				'title'  => __( 'Product page earning preview', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Show "Earn about X points on purchase" on individual product pages so customers see the reward before adding to cart. Requires "Earn points on purchase" to be enabled first.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_show_earn_preview', __( 'Show earning preview', 'moksa-points-for-woocommerce' ), __( 'Show the estimated points earnable on the product page (based on the product price × rate, applying per-product and per-category settings).', 'moksa-points-for-woocommerce' ) ),
					self::select(
						'moksafopoi_earn_preview_position',
						__( 'Preview display position', 'moksa-points-for-woocommerce' ),
						__( 'Where to place the preview on the product page.', 'moksa-points-for-woocommerce' ),
						'before_add_to_cart',
						array(
							'before_add_to_cart' => __( 'Above the add-to-cart button', 'moksa-points-for-woocommerce' ),
							'after_summary'      => __( 'Bottom of the product summary', 'moksa-points-for-woocommerce' ),
						)
					),
					self::textarea(
						'moksafopoi_disp_single_msg',
						__( 'Product page message template', 'moksa-points-for-woocommerce' ),
						/* translators: do not translate the {tokens}. */
						__( 'Copy for the product page earning preview. Available variables: {points} (points), {points_label} (unit name), {value} (redeemable amount). Basic HTML is allowed.', 'moksa-points-for-woocommerce' ),
						__( 'Earn about {points} {points_label} on purchase', 'moksa-points-for-woocommerce' )
					),
					self::color( 'moksafopoi_disp_single_text_color', __( 'Text color', 'moksa-points-for-woocommerce' ), __( 'Text color of the product page message (default black #000000).', 'moksa-points-for-woocommerce' ), '#000000', '#000000' ),
					self::color( 'moksafopoi_disp_single_bg_color', __( 'Background color', 'moksa-points-for-woocommerce' ), __( 'Background color of the product page message (leave blank = transparent).', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = transparent', 'moksa-points-for-woocommerce' ) ),
					self::color( 'moksafopoi_disp_single_border_color', __( 'Border color', 'moksa-points-for-woocommerce' ), __( 'Message border color (leave blank = no border). Setting a border or background automatically adds padding and turns it into a label style.', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = no border', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_disp_single_icon', __( 'Leading icon', 'moksa-points-for-woocommerce' ), __( 'A small icon shown at the start of the message (you can paste an emoji, e.g. 🎁 ⭐ 💰; up to 8 characters).', 'moksa-points-for-woocommerce' ), '', '🎁' ),
					self::select( 'moksafopoi_disp_single_visible_to', __( 'Audience', 'moksa-points-for-woocommerce' ), __( 'Who this message is shown to. "Members only / non-members only" requires the membership plugin; when it is not installed, they are treated as logged in / logged out respectively.', 'moksa-points-for-woocommerce' ), 'all', array( 'all' => __( 'Everyone', 'moksa-points-for-woocommerce' ), 'logged_in' => __( 'Logged-in users only', 'moksa-points-for-woocommerce' ), 'guests' => __( 'Guests only (not logged in)', 'moksa-points-for-woocommerce' ), 'members' => __( 'Members only', 'moksa-points-for-woocommerce' ), 'non_members' => __( 'Non-members only', 'moksa-points-for-woocommerce' ) ) ),
					self::select(
						'moksafopoi_disp_single_position',
						__( 'Display position', 'moksa-points-for-woocommerce' ),
						__( 'Where the message is placed on the product page (overrides the "Preview display position" above).', 'moksa-points-for-woocommerce' ),
						'before_add_to_cart',
						array(
							'before_add_to_cart' => __( 'Above the add-to-cart button', 'moksa-points-for-woocommerce' ),
							'after_summary'      => __( 'Bottom of the product summary', 'moksa-points-for-woocommerce' ),
							'after_meta'         => __( 'After the product meta', 'moksa-points-for-woocommerce' ),
						)
					),
				),
			),
			array(
				'tab'    => 'display',
				'title'  => __( 'Storefront engagement polish', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'A "how to earn points" guide, an earned-points toast after checkout, and a shareable achievement card. Display only — none of these write to the ledger, and every figure is read from the features you already switched on.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_engage_enabled', __( 'Storefront engagement polish', 'moksa-points-for-woocommerce' ), __( 'Master switch for the three surfaces below.', 'moksa-points-for-woocommerce' ) ),
					self::toggle_on( 'moksafopoi_engage_howto_enabled', __( '"How to earn points" guide', 'moksa-points-for-woocommerce' ), __( 'Show the guide under "My points" and provide the [moksafopoi_how_to_earn] shortcode. Its content is assembled automatically from the enabled earning features.', 'moksa-points-for-woocommerce' ) ),
					self::toggle_on( 'moksafopoi_engage_toast_enabled', __( 'Earned-points toast', 'moksa-points-for-woocommerce' ), __( 'On the order-received page, show the points this order actually booked (read from the ledger; nothing is shown when no points were awarded).', 'moksa-points-for-woocommerce' ) ),
					self::number( 'moksafopoi_share_bonus', __( 'Sharing bonus', 'moksa-points-for-woocommerce' ), __( 'One-off points the first time a member shares on each channel (0 = sharing earns nothing). Sharing cannot be verified from the store, so the bonus is deliberately once per channel for life.', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::toggle_on( 'moksafopoi_engage_share_enabled', __( 'Shareable achievement card', 'moksa-points-for-woocommerce' ), __( 'Let members share their own points / badges to LINE or Facebook, or copy the link. Uses their referral link when the referral feature is on.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'display',
				'title'  => __( 'Shop listing earning hint', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Below each product card in the shop / category listing, show the estimated points earnable on purchase (applies per-product and per-category settings; products that earn no points are not shown).', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_disp_loop_enabled', __( 'Show earning in the shop listing', 'moksa-points-for-woocommerce' ), __( 'Show "Earn X points on purchase" below the product listing card.', 'moksa-points-for-woocommerce' ) ),
					self::textarea(
						'moksafopoi_disp_loop_msg',
						__( 'Listing message template', 'moksa-points-for-woocommerce' ),
						/* translators: do not translate the {tokens}. */
						__( 'Copy for the listing card. Available variables: {points}, {points_label}, {value}. Basic HTML is allowed.', 'moksa-points-for-woocommerce' ),
						__( 'Earn {points} {points_label} on this purchase', 'moksa-points-for-woocommerce' )
					),
					self::color( 'moksafopoi_disp_loop_text_color', __( 'Text color', 'moksa-points-for-woocommerce' ), __( 'Text color of the listing message (default black #000000).', 'moksa-points-for-woocommerce' ), '#000000', '#000000' ),
					self::color( 'moksafopoi_disp_loop_bg_color', __( 'Background color', 'moksa-points-for-woocommerce' ), __( 'Background color of the listing message (leave blank = transparent).', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = transparent', 'moksa-points-for-woocommerce' ) ),
					self::color( 'moksafopoi_disp_loop_border_color', __( 'Border color', 'moksa-points-for-woocommerce' ), __( 'Message border color (leave blank = no border). Setting a border or background automatically adds padding and turns it into a label style.', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = no border', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_disp_loop_icon', __( 'Leading icon', 'moksa-points-for-woocommerce' ), __( 'A small icon shown at the start of the message (you can paste an emoji, e.g. 🎁 ⭐ 💰; up to 8 characters).', 'moksa-points-for-woocommerce' ), '', '🎁' ),
					self::select( 'moksafopoi_disp_loop_visible_to', __( 'Audience', 'moksa-points-for-woocommerce' ), __( 'Who this message is shown to. "Members only / non-members only" requires the membership plugin; when it is not installed, they are treated as logged in / logged out respectively.', 'moksa-points-for-woocommerce' ), 'all', array( 'all' => __( 'Everyone', 'moksa-points-for-woocommerce' ), 'logged_in' => __( 'Logged-in users only', 'moksa-points-for-woocommerce' ), 'guests' => __( 'Guests only (not logged in)', 'moksa-points-for-woocommerce' ), 'members' => __( 'Members only', 'moksa-points-for-woocommerce' ), 'non_members' => __( 'Non-members only', 'moksa-points-for-woocommerce' ) ) ),
					self::select(
						'moksafopoi_disp_loop_position',
						__( 'Display position', 'moksa-points-for-woocommerce' ),
						__( 'Where the message is placed on each product card.', 'moksa-points-for-woocommerce' ),
						'after_title',
						array(
							'after_title'  => __( 'Below the product title', 'moksa-points-for-woocommerce' ),
							'before_title' => __( 'Above the product title', 'moksa-points-for-woocommerce' ),
							'after_price'  => __( 'After the price', 'moksa-points-for-woocommerce' ),
						)
					),
				),
			),
			array(
				'tab'    => 'display',
				'title'  => __( 'Cart earning hint', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Show the estimated points earnable from this purchase in the cart totals area (marked as "approx.", not yet including the spend-threshold multiplier).', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_disp_cart_enabled', __( 'Show earning in the cart', 'moksa-points-for-woocommerce' ), __( 'Show "Earn about X points from this purchase" below the cart totals.', 'moksa-points-for-woocommerce' ) ),
					self::textarea(
						'moksafopoi_disp_cart_msg',
						__( 'Cart message template', 'moksa-points-for-woocommerce' ),
						/* translators: do not translate the {tokens}. */
						__( 'Copy for the cart. Available variables: {points}, {points_label}, {value}. Basic HTML is allowed.', 'moksa-points-for-woocommerce' ),
						__( 'This purchase earns about {points} {points_label}', 'moksa-points-for-woocommerce' )
					),
					self::color( 'moksafopoi_disp_cart_text_color', __( 'Text color', 'moksa-points-for-woocommerce' ), __( 'Text color of the cart message (default black #000000).', 'moksa-points-for-woocommerce' ), '#000000', '#000000' ),
					self::color( 'moksafopoi_disp_cart_bg_color', __( 'Background color', 'moksa-points-for-woocommerce' ), __( 'Background color of the cart message (leave blank = transparent).', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = transparent', 'moksa-points-for-woocommerce' ) ),
					self::color( 'moksafopoi_disp_cart_border_color', __( 'Border color', 'moksa-points-for-woocommerce' ), __( 'Message border color (leave blank = no border). Setting a border or background automatically adds padding and turns it into a label style.', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = no border', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_disp_cart_icon', __( 'Leading icon', 'moksa-points-for-woocommerce' ), __( 'A small icon shown at the start of the message (you can paste an emoji, e.g. 🎁 ⭐ 💰; up to 8 characters).', 'moksa-points-for-woocommerce' ), '', '🎁' ),
					self::select( 'moksafopoi_disp_cart_visible_to', __( 'Audience', 'moksa-points-for-woocommerce' ), __( 'Who this message is shown to. "Members only / non-members only" requires the membership plugin; when it is not installed, they are treated as logged in / logged out respectively.', 'moksa-points-for-woocommerce' ), 'all', array( 'all' => __( 'Everyone', 'moksa-points-for-woocommerce' ), 'logged_in' => __( 'Logged-in users only', 'moksa-points-for-woocommerce' ), 'guests' => __( 'Guests only (not logged in)', 'moksa-points-for-woocommerce' ), 'members' => __( 'Members only', 'moksa-points-for-woocommerce' ), 'non_members' => __( 'Non-members only', 'moksa-points-for-woocommerce' ) ) ),
					self::select(
						'moksafopoi_disp_cart_position',
						__( 'Display position', 'moksa-points-for-woocommerce' ),
						__( 'Where the message is placed in the cart totals area.', 'moksa-points-for-woocommerce' ),
						'after_total',
						array(
							'after_total'  => __( 'Below the order total', 'moksa-points-for-woocommerce' ),
							'before_total' => __( 'Above the order total', 'moksa-points-for-woocommerce' ),
							'after_cart'   => __( 'After the cart table', 'moksa-points-for-woocommerce' ),
						)
					),
				),
			),
			array(
				'tab'    => 'display',
				'title'  => __( 'My Account message', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Add a custom message below the "My points" balance card (optional; leave blank to hide). {points} is replaced with the member\'s current balance.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::textarea(
						'moksafopoi_disp_account_msg',
						__( 'My Account message template', 'moksa-points-for-woocommerce' ),
						/* translators: do not translate the {tokens}. */
						__( 'Additional copy for the My points page. Available variables: {points} (current balance), {points_label}, {value}. Basic HTML is allowed. Leave blank to hide.', 'moksa-points-for-woocommerce' ),
						''
					),
					self::color( 'moksafopoi_disp_account_text_color', __( 'Text color', 'moksa-points-for-woocommerce' ), __( 'Text color of the My Account message (default black #000000).', 'moksa-points-for-woocommerce' ), '#000000', '#000000' ),
					self::color( 'moksafopoi_disp_account_bg_color', __( 'Background color', 'moksa-points-for-woocommerce' ), __( 'Background color of the My Account message (leave blank = transparent).', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = transparent', 'moksa-points-for-woocommerce' ) ),
					self::color( 'moksafopoi_disp_account_border_color', __( 'Border color', 'moksa-points-for-woocommerce' ), __( 'Message border color (leave blank = no border). Setting a border or background automatically adds padding and turns it into a label style.', 'moksa-points-for-woocommerce' ), '', __( 'Leave blank = no border', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_disp_account_icon', __( 'Leading icon', 'moksa-points-for-woocommerce' ), __( 'A small icon shown at the start of the message (you can paste an emoji, e.g. 🎁 ⭐ 💰; up to 8 characters).', 'moksa-points-for-woocommerce' ), '', '🎁' ),
					self::select( 'moksafopoi_disp_account_visible_to', __( 'Audience', 'moksa-points-for-woocommerce' ), __( 'Who this message is shown to. "Members only / non-members only" requires the membership plugin; when it is not installed, they are treated as logged in / logged out respectively.', 'moksa-points-for-woocommerce' ), 'all', array( 'all' => __( 'Everyone', 'moksa-points-for-woocommerce' ), 'logged_in' => __( 'Logged-in users only', 'moksa-points-for-woocommerce' ), 'guests' => __( 'Guests only (not logged in)', 'moksa-points-for-woocommerce' ), 'members' => __( 'Members only', 'moksa-points-for-woocommerce' ), 'non_members' => __( 'Non-members only', 'moksa-points-for-woocommerce' ) ) ),
					self::select(
						'moksafopoi_disp_account_position',
						__( 'Display position', 'moksa-points-for-woocommerce' ),
						__( 'Where the message is placed on the "My points" page.', 'moksa-points-for-woocommerce' ),
						'after_balance',
						array(
							'after_balance' => __( 'Below the balance card', 'moksa-points-for-woocommerce' ),
						)
					),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Earning rate', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'The calculation granularity for earning points on purchase: how many points per unit spent, the rounding method, sale-price exclusion, per-order cap, and whether the earning base includes shipping / tax.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::number( 'moksafopoi_points_per_currency', __( 'Points per NT$1', 'moksa-points-for-woocommerce' ), __( 'How many points to award per 1 unit of the purchase base (decimals allowed, e.g. 0.5).', 'moksa-points-for-woocommerce' ), 1, '0', '0.01' ),
					self::select(
						'moksafopoi_earn_rounding',
						__( 'Points rounding method', 'moksa-points-for-woocommerce' ),
						__( 'How fractional points are rounded. Default is round down (safest for the store).', 'moksa-points-for-woocommerce' ),
						'floor',
						array(
							'floor' => __( 'Round down', 'moksa-points-for-woocommerce' ),
							'round' => __( 'Round half up', 'moksa-points-for-woocommerce' ),
							'ceil'  => __( 'Round up', 'moksa-points-for-woocommerce' ),
						)
					),
					self::toggle( 'moksafopoi_earn_exclude_sale', __( 'No points on sale items', 'moksa-points-for-woocommerce' ), __( 'When checked, products currently on sale are excluded from the earning base.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_earn_include_shipping', __( 'Earning base includes shipping', 'moksa-points-for-woocommerce' ), __( 'When checked, shipping is also included in the earning base (excluded by default; only the product amount counts).', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_earn_include_tax', __( 'Earning base includes tax', 'moksa-points-for-woocommerce' ), __( 'When checked, tax is also included in the earning base (excluded by default; only the product amount counts).', 'moksa-points-for-woocommerce' ) ),
					self::number( 'moksafopoi_earn_max_per_order', __( 'Per-order earning cap', 'moksa-points-for-woocommerce' ), __( 'Maximum points awarded per order (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Role multiplier / spend-threshold multiplier', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Advanced earning multipliers. Final multiplier = membership tier (filter) × role multiplier × spend-threshold multiplier; category multipliers are applied row by row. Each multiplier is capped at 10×.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::role_map( 'moksafopoi_role_multipliers', __( 'Role earning multiplier', 'moksa-points-for-woocommerce' ), __( 'Set an earning multiplier for each user role (default 1). Each customer\'s "highest role multiplier" is multiplied into their total earning.', 'moksa-points-for-woocommerce' ) ),
					self::tier_map( 'moksafopoi_spend_bonus_tiers', __( 'Spend-threshold multiplier tiers', 'moksa-points-for-woocommerce' ), __( 'When the order\'s earnable base amount reaches the threshold, the entire earning is multiplied by the corresponding multiplier. Enter 0 to disable that tier.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'redeem',
				'title'  => __( 'Buy a whole product with points only', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Provide a "Redeem the whole product for X points" button on the product page (creates an NT$0 order). Set the points price for each product on the "General" tab of the product editor. Point deduction uses a per-user lock to prevent concurrent double-spending, and points are automatically refunded on failure.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_buywithpoints_enabled', __( 'Enable buy with points only', 'moksa-points-for-woocommerce' ), __( 'For products with a points price set, members can redeem the whole item directly with points on the product page (NT$0 order).', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'redeem',
				'title'  => __( 'Points transfer (between members)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Let members transfer points to other members. Place the shortcode [moksafopoi_transfer] in the My Account area / any page. Point deduction uses a per-user lock to prevent concurrent double-spending, and the amount is conserved.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_transfer_enabled', __( 'Enable points transfer', 'moksa-points-for-woocommerce' ), __( 'Provide the [moksafopoi_transfer] shortcode so members can transfer points to each other.', 'moksa-points-for-woocommerce' ) ),
					self::number( 'moksafopoi_transfer_min', __( 'Minimum points per transfer', 'moksa-points-for-woocommerce' ), __( 'Minimum points for a single transfer (default 1).', 'moksa-points-for-woocommerce' ), 1, '1', '1' ),
					self::number( 'moksafopoi_transfer_max', __( 'Maximum points per transfer', 'moksa-points-for-woocommerce' ), __( 'Maximum points for a single transfer (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_transfer_max_per_day', __( 'Daily transfer count limit per person', 'moksa-points-for-woocommerce' ), __( 'How many transfers a single member can send per day (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
				),
			),
			array(
				'tab'    => 'redeem',
				'title'  => __( 'Points redemption rate', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'The calculation granularity for checkout points redemption: how many points redeem 1 unit of currency, minimum / step / cap.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::number( 'moksafopoi_redeem_rate', __( 'Points per NT$1 redeemed', 'moksa-points-for-woocommerce' ), __( 'How many points are needed to redeem 1 unit of currency (default 100 points = NT$1).', 'moksa-points-for-woocommerce' ), 100, '1', '1' ),
					self::number( 'moksafopoi_redeem_min_points', __( 'Minimum redeemable points', 'moksa-points-for-woocommerce' ), __( 'Below this number of points, redemption cannot be used (default 100).', 'moksa-points-for-woocommerce' ), 100, '0', '1' ),
					self::number( 'moksafopoi_redeem_step', __( 'Redemption increment (points)', 'moksa-points-for-woocommerce' ), __( 'How many points each redemption applies per unit (default 100).', 'moksa-points-for-woocommerce' ), 100, '1', '1' ),
					self::number( 'moksafopoi_redeem_max_percent', __( 'Maximum cart discount %', 'moksa-points-for-woocommerce' ), __( 'Maximum percentage of the redeemable subtotal that points can offset (default 100).', 'moksa-points-for-woocommerce' ), 100, '0', '1' ),
					self::number( 'moksafopoi_redeem_max_fixed', __( 'Per-order redemption cap (NT$)', 'moksa-points-for-woocommerce' ), __( 'Maximum amount that points can offset per order (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
				),
			),
			array(
				'tab'    => 'redeem',
				'title'  => __( 'Redemption condition restrictions', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Restrict which products / which members can pay with points, and whether it is mutually exclusive with coupons. Leave empty = no limit.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::term_select( 'moksafopoi_redeem_only_cats', __( 'Limit redeemable categories', 'moksa-points-for-woocommerce' ), __( 'Only amounts from these product categories (including subcategories) can be paid with points; multiple selection allowed (leave empty = all products eligible).', 'moksa-points-for-woocommerce' ), 'product_cat', '' ),
					self::text( 'moksafopoi_redeem_min_tier', __( 'Minimum membership tier for redemption', 'moksa-points-for-woocommerce' ), __( 'Only members at this tier (and above) can pay with points; enter the membership tier code (e.g. gold). This restriction is automatically ignored when no member plugin is installed.', 'moksa-points-for-woocommerce' ), '' ),
					self::toggle( 'moksafopoi_redeem_block_with_coupon', __( 'Disable points redemption when a coupon is present', 'moksa-points-for-woocommerce' ), __( 'When a coupon is already applied to the cart, points redemption is not allowed (coupon or points, not both).', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'One-time earning bonuses (signup / first purchase)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Reward members once for registering and once for their first purchase. Off by default (0). Requires the "Earning triggers" module to be enabled.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::number( 'moksafopoi_signup_bonus', __( 'Registration bonus points', 'moksa-points-for-woocommerce' ), __( 'Points awarded once when a member registers (0 = off).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_first_order_bonus', __( 'First-purchase bonus points', 'moksa-points-for-woocommerce' ), __( 'Points awarded once when a member completes their first purchase (0 = off).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Check-in / birthday earning (repeatable trigger)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Daily check-in and birthday gift. Place the check-in shortcode [moksafopoi_checkin] on the member page, limited to once per day; the birthday gift is once per year (requires a member plugin to provide birthday data). Requires the "Earning triggers" module to be enabled.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::number( 'moksafopoi_checkin_bonus', __( 'Daily check-in points', 'moksa-points-for-woocommerce' ), __( 'Points a member can earn for daily check-in (0 = off; shortcode [moksafopoi_checkin]).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_birthday_bonus', __( 'Birthday gift points', 'moksa-points-for-woocommerce' ), __( 'Points automatically given on a member\'s birthday, once per year (0 = off; requires a member plugin installed and birthday data).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_anniversary_bonus', __( 'Membership anniversary gift points', 'moksa-points-for-woocommerce' ), __( 'Points automatically given on a member\'s registration anniversary, once per year (0 = off; based only on the registration date, no birthday data needed).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_review_bonus', __( 'Product review points', 'moksa-points-for-woocommerce' ), __( 'Points a member can earn for each approved product review (default 20; 0 = off).', 'moksa-points-for-woocommerce' ), 20, '0', '1' ),
					self::number( 'moksafopoi_review_max_per_product', __( 'Points-eligible reviews per product (count)', 'moksa-points-for-woocommerce' ), __( 'The maximum number of reviews for which the same member can be rewarded for the same product, preventing review farming for points (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Total earning cap (anti-abuse safeguard)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Limit how many points each member can earn in total from all sources combined within a period, preventing campaign stacking from being abused. This is a "total" cap, a different dimension from the "per-order cap" or "how many times per day per action". Off by default. Purchase-based earning and system sources (transfer in / point purchase / import / scheduled / manual) are always automatically excluded and not subject to this cap.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::select(
						'moksafopoi_earn_cap_period',
						__( 'Cap period', 'moksa-points-for-woocommerce' ),
						__( 'Which period is used to calculate the total earning cap. Off = not applied.', 'moksa-points-for-woocommerce' ),
						'off',
						array(
							'off'   => __( 'Off (no limit)', 'moksa-points-for-woocommerce' ),
							'day'   => __( 'Daily', 'moksa-points-for-woocommerce' ),
							'week'  => __( 'Weekly (starting Monday)', 'moksa-points-for-woocommerce' ),
							'month' => __( 'Monthly', 'moksa-points-for-woocommerce' ),
							'year'  => __( 'Yearly', 'moksa-points-for-woocommerce' ),
							'total' => __( 'Cumulative (lifetime)', 'moksa-points-for-woocommerce' ),
						)
					),
					self::number( 'moksafopoi_earn_cap_amount', __( 'Cap earnable per period (points)', 'moksa-points-for-woocommerce' ), __( 'The maximum points that can be earned from all (non-excluded) sources combined within that period (0 = no limit). The excess is automatically trimmed and not recorded.', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::textarea( 'moksafopoi_earn_cap_exclude_sources', __( 'Excluded sources', 'moksa-points-for-woocommerce' ), __( 'Earning sources not counted toward the cap (one per line or comma-separated). By default, purchase-based earning spend_rule is excluded so high-spending members are not mistakenly blocked. You can enter: spend_rule, coupon_redeemed, signup, review, first_order, checkin, birthday.', 'moksa-points-for-woocommerce' ), 'spend_rule', 'spend_rule' ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Earning exclusion list (staff / wholesale / test accounts)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Roles or accounts on this list will never earn any points (all earning sources are not recorded); spending points, store credit, and manual support adjustments are unaffected. Leaving both fields empty = exclude no one.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::textarea( 'moksafopoi_exclude_roles', __( 'Excluded roles', 'moksa-points-for-woocommerce' ), __( 'Role slugs, one per line or comma-separated, for example: administrator, shop_manager, wholesale_customer.', 'moksa-points-for-woocommerce' ), '', 'shop_manager' ),
					self::textarea( 'moksafopoi_exclude_users', __( 'Excluded accounts', 'moksa-points-for-woocommerce' ), __( 'Member ID / email / username, one per line or comma-separated. Items that cannot be found are automatically ignored.', 'moksa-points-for-woocommerce' ), '', '123, tester@example.com' ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Refer a friend to earn points', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Members refer friends via a dedicated link ?mfp_ref=; after a friend registers and completes their first purchase, both sides each earn points (once per friend, with a configurable per-period cap). Shortcode [moksafopoi_referral]. Requires the "Referral points" module to be enabled.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::number( 'moksafopoi_referral_reward', __( 'Referrer reward points', 'moksa-points-for-woocommerce' ), __( 'Points the referrer can earn when the friend completes their first purchase (0 = none).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_referral_friend_reward', __( 'Referred friend reward points', 'moksa-points-for-woocommerce' ), __( 'Extra points the referred friend can earn when completing their first purchase (0 = none).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_referral_min_order', __( 'Qualifying order threshold (NT$)', 'moksa-points-for-woocommerce' ), __( 'The friend\'s order product subtotal must reach this amount to count as a qualifying referral (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_referral_cap', __( 'Referral cap per period (count)', 'moksa-points-for-woocommerce' ), __( 'The maximum number of referrals for which the same referrer can be rewarded within each period (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::select(
						'moksafopoi_referral_cap_period',
						__( 'Referral cap period', 'moksa-points-for-woocommerce' ),
						__( 'Calculated together with the count cap above.', 'moksa-points-for-woocommerce' ),
						'all',
						array(
							'day'   => __( 'Daily', 'moksa-points-for-woocommerce' ),
							'week'  => __( 'Weekly', 'moksa-points-for-woocommerce' ),
							'month' => __( 'Monthly', 'moksa-points-for-woocommerce' ),
							'year'  => __( 'Yearly', 'moksa-points-for-woocommerce' ),
							'all'   => __( 'No time limit', 'moksa-points-for-woocommerce' ),
						)
					),
				),
			),
			array(
				'tab'    => 'redeem',
				'title'  => __( 'Store credit economy (purchase cashback / redemption)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Cashback of spending into store credit, and the ratio cap for using store credit at checkout. Requires the "Cashback" / "Wallet checkout redemption" module to be enabled.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::number( 'moksafopoi_cashback_rate', __( 'Cashback %', 'moksa-points-for-woocommerce' ), __( 'What percentage of the order amount is returned as store credit (0 = no cashback).', 'moksa-points-for-woocommerce' ), 0, '0', '0.1' ),
					self::select( 'moksafopoi_cashback_basis', __( 'Cashback calculation base', 'moksa-points-for-woocommerce' ), __( 'Calculate cashback based on the product subtotal (excluding tax) or the order total.', 'moksa-points-for-woocommerce' ), 'subtotal', array( 'subtotal' => __( 'Product subtotal (excluding tax)', 'moksa-points-for-woocommerce' ), 'total' => __( 'Order total', 'moksa-points-for-woocommerce' ) ) ),
					self::number( 'moksafopoi_cashback_min_spend', __( 'Cashback threshold (NT$)', 'moksa-points-for-woocommerce' ), __( 'Cashback applies only when the order base reaches this amount (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_cashback_max', __( 'Cashback cap per order (NT$)', 'moksa-points-for-woocommerce' ), __( 'The maximum store credit returned for a single order (0 = no limit).', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
					self::number( 'moksafopoi_wallet_max_percent', __( 'Store credit max cart offset %', 'moksa-points-for-woocommerce' ), __( 'The maximum percentage of the redeemable subtotal that store credit can offset at checkout (default 100).', 'moksa-points-for-woocommerce' ), 100, '0', '1' ),
				),
			),
			array(
				'tab'    => 'advanced',
				'title'  => __( 'Multi-currency rates', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Only needed when your store sells in more than one currency (WPML / Aelia / FOX / WOOCS). Order totals arrive in the CUSTOMER\'s currency, so a rate calibrated for your base currency would award wildly different amounts for the same real spend. Leave a row blank to convert the base rate with your currency plugin\'s own exchange rate.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::currency_map( 'moksafopoi_currency_rates', __( 'Per-currency rates', 'moksa-points-for-woocommerce' ), __( 'Points earned per 1 unit, and points needed to redeem 1 unit, for each currency you sell in.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'Per-payment-method bonus', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Give extra points for paying with a particular method. Payment methods do not cost the store the same, and points are the cheapest way to steer customers towards the cheaper one. 1 = no change; the bonus stacks with the other multipliers under the same 10x ceiling.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::gateway_map( 'moksafopoi_gateway_multipliers', __( 'Payment method multipliers', 'moksa-points-for-woocommerce' ), __( 'One row per payment method installed on this store.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'earn',
				'title'  => __( 'WooCommerce Subscriptions', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Only applies when the WooCommerce Subscriptions extension is installed. Renewal orders already earn like any other paid order; these settings decide whether that is what you want. A renewal never counts as a first purchase.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle_on( 'moksafopoi_subs_renewal_earn', __( 'Renewals earn points', 'moksa-points-for-woocommerce' ), __( 'On by default. Turn off to give no points at all on subscription renewals.', 'moksa-points-for-woocommerce' ) ),
					self::number( 'moksafopoi_subs_renewal_multiplier', __( 'Renewal multiplier', 'moksa-points-for-woocommerce' ), __( 'Multiply earning on renewal orders (1 = same as a normal order, max 10). Staying subscribed is exactly the behaviour worth rewarding.', 'moksa-points-for-woocommerce' ), 1, '0', '0.1' ),
					self::number( 'moksafopoi_subs_signup_bonus', __( 'Subscription sign-up bonus', 'moksa-points-for-woocommerce' ), __( 'One-off points when a subscription first becomes active (0 = off). Paid once per subscription.', 'moksa-points-for-woocommerce' ), 0, '0', '1' ),
				),
			),
			array(
				'tab'    => 'advanced',
				'title'  => __( 'LINE notifications', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Push points messages over the LINE Messaging API (LINE Notify was shut down in 2025 and is not used). Members link their LINE account in the membership plugin; anyone who has not linked is simply skipped. Messages are queued, so LINE can never slow down checkout.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_linenotify_enabled', __( 'LINE notifications', 'moksa-points-for-woocommerce' ), __( 'Enable the LINE sender. Nothing is sent until a channel access token and at least one event are set.', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_line_token', __( 'Channel access token', 'moksa-points-for-woocommerce' ), __( 'The long-lived channel access token of your LINE Messaging API channel.', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_line_meta_key', __( 'User-meta key holding the LINE ID', 'moksa-points-for-woocommerce' ), __( 'Where a member\'s LINE userId is stored. Leave blank for the membership plugin default (_moformember_line_user_id).', 'moksa-points-for-woocommerce' ), '', '_moformember_line_user_id' ),
					self::toggle( 'moksafopoi_line_on_earned', __( 'Send: points earned', 'moksa-points-for-woocommerce' ), __( 'Message the member when points are credited.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_line_on_redeemed', __( 'Send: points redeemed', 'moksa-points-for-woocommerce' ), __( 'Message the member when points are spent.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_line_on_expiring', __( 'Send: points expiring soon', 'moksa-points-for-woocommerce' ), __( 'Message the member alongside the expiry reminder e-mail (same idempotent batch, so no duplicates).', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'advanced',
				'title'  => __( 'Outbound webhooks', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Push points events to an ESP, CRM or automation platform (Klaviyo, Brevo, n8n, Zapier, a LINE bot...). Deliveries are queued, so a slow endpoint never delays checkout, and each one is signed so the receiver can verify it. Internal / loopback addresses are refused.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_webhooks_enabled', __( 'Outbound webhooks', 'moksa-points-for-woocommerce' ), __( 'Enable the webhook sender. Nothing is sent until an endpoint URL and at least one event are set below.', 'moksa-points-for-woocommerce' ) ),
					self::text( 'moksafopoi_webhook_url', __( 'Endpoint URL', 'moksa-points-for-woocommerce' ), __( 'The https:// URL each event is POSTed to as JSON.', 'moksa-points-for-woocommerce' ), '', 'https://hooks.example.com/moksa-points' ),
					self::text( 'moksafopoi_webhook_secret', __( 'Signing secret', 'moksa-points-for-woocommerce' ), __( 'Optional. When set, each delivery carries X-Moksafopoi-Signature: sha256=HMAC(body, secret) so the receiver can verify it came from this site.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_webhook_include_email', __( 'Include the member e-mail', 'moksa-points-for-woocommerce' ), __( 'Off by default: the payload identifies members by user ID only. Turn on when your ESP needs the e-mail address to match a contact.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_webhook_points_earned', __( 'Send: points earned', 'moksa-points-for-woocommerce' ), __( 'POST an event whenever points are credited.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_webhook_points_redeemed', __( 'Send: points redeemed', 'moksa-points-for-woocommerce' ), __( 'POST an event whenever points are spent.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_webhook_points_expiring', __( 'Send: points expiring soon', 'moksa-points-for-woocommerce' ), __( 'POST an event alongside the expiry reminder e-mail (same idempotent batch, so no duplicates).', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_webhook_quest_completed', __( 'Send: quest completed', 'moksa-points-for-woocommerce' ), __( 'POST an event when a member finishes a quest.', 'moksa-points-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'advanced',
				'title'  => __( 'AI and external access (MCP)', 'moksa-points-for-woocommerce' ),
				'desc'   => __( 'Open points features to AI tools. Ability registration is on by default and every ability is capability-checked, so an assistant can never do more than you could by hand. The external MCP server is off until you switch it on.', 'moksa-points-for-woocommerce' ),
				'fields' => array(
					self::toggle( 'moksafopoi_restapi_enabled', __( 'Customer REST API', 'moksa-points-for-woocommerce' ), __( 'Read-only endpoints for a headless storefront or app: points/v1/me and points/v1/me/history. They always serve the logged-in customer only — there is no user_id parameter, so one member can never read another.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_abilities_enabled', __( 'Points AI abilities (Abilities)', 'moksa-points-for-woocommerce' ), __( 'Register points features as WordPress AI abilities (Abilities) for use by AI assistants.', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_mcp_enabled', __( 'MCP module', 'moksa-points-for-woocommerce' ), __( 'Load the MCP server module (still requires the "External MCP server" toggle below to actually open access).', 'moksa-points-for-woocommerce' ) ),
					self::toggle( 'moksafopoi_mcp_server_enabled', __( 'External MCP server', 'moksa-points-for-woocommerce' ), __( 'Open points abilities to external AI tools (MCP). Off by default.', 'moksa-points-for-woocommerce' ) ),
					self::number( 'moksafopoi_mcp_destructive_limit', __( 'Change-making calls per hour (per account)', 'moksa-points-for-woocommerce' ), __( 'Cap on how many change-making MCP calls one account may make per hour (0 = no limit). External MCP calls run unattended, so a cap is what turns a runaway agent into a stopped one. Every call, allowed or refused, is recorded in the audit log on the Tools page.', 'moksa-points-for-woocommerce' ), 20, '0', '1' ),
					self::toggle( 'moksafopoi_mcp_expose_destructive', __( 'Allow external MCP to execute changes', 'moksa-points-for-woocommerce' ), __( 'Expose destructive abilities (add / deduct points, redeem, expire) to external MCP clients. Off by default (read-only). WARNING: external MCP calls run UNATTENDED with no confirmation step, so only administrator-level accounts (manage_options) can execute them, and the settings-change tool is never exposed over MCP. Leave off unless you fully trust the connected agent.', 'moksa-points-for-woocommerce' ) ),
				),
			),
		);
	}

	/**
	 * Every option key this screen owns (field id == option key), across all tabs. This is the single
	 * source of truth for what 匯出 dumps and 匯入 is allowed to write — an uploaded key not in this
	 * list is skipped, so import can never inject an arbitrary option.
	 *
	 * @return array<int,string>
	 */
	public static function known_keys(): array {
		$keys = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( isset( $field['id'] ) && '' !== (string) $field['id'] ) {
					$keys[] = (string) $field['id'];
				}
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Re-sanitise ONE imported value by its declared field type — the same rules {@see handle()} uses
	 * on a normal save, applied to a decoded JSON value instead of $_POST. Returns
	 * ['ok'=>true,'value'=>…] with the safe value to store, or ['ok'=>false] to skip the key.
	 *
	 * @param mixed $raw
	 * @return array{ok:bool,value?:mixed}
	 */
	public static function sanitize_option_for_import( string $key, $raw ): array {
		$field = self::field_for( $key );
		if ( null === $field ) {
			return array( 'ok' => false );
		}
		$type = (string) ( $field['type'] ?? 'toggle' );

		switch ( $type ) {
			case 'text':
				$val = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
				if ( '' === $val ) {
					$val = (string) ( $field['default'] ?? '' );
				}
				return array( 'ok' => true, 'value' => $val );

			case 'textarea':
				$val     = wp_kses_post( is_scalar( $raw ) ? (string) $raw : '' );
				$default = (string) ( $field['default'] ?? '' );
				if ( '' === trim( $val ) && '' !== $default ) {
					$val = $default;
				}
				return array( 'ok' => true, 'value' => $val );

			case 'number':
				if ( ! is_scalar( $raw ) || ! is_numeric( (string) $raw ) ) {
					return array( 'ok' => false );
				}
				$num = max( 0.0, (float) $raw );
				$val = ( floor( $num ) === $num ) ? (string) (int) $num : (string) $num;
				return array( 'ok' => true, 'value' => $val );

			case 'select':
				$choices = (array) ( $field['choices'] ?? array() );
				$val     = is_scalar( $raw ) ? (string) $raw : '';
				if ( ! isset( $choices[ $val ] ) ) {
					$val = (string) ( $field['default'] ?? '' );
				}
				return array( 'ok' => true, 'value' => $val );

			case 'color':
				$default = (string) ( $field['default'] ?? '' );
				$raw_s   = is_scalar( $raw ) ? (string) $raw : '';
				if ( '' === $raw_s ) {
					return array( 'ok' => true, 'value' => $default );
				}
				$hex = sanitize_hex_color( $raw_s );
				return array( 'ok' => true, 'value' => ( is_string( $hex ) && '' !== $hex ) ? $hex : $default );

			case 'role_map':
				return array( 'ok' => true, 'value' => self::import_role_map( $raw ) );

			case 'tier_map':
				return array( 'ok' => true, 'value' => self::import_tier_map( $raw ) );

			case 'ladder_map':
				return array( 'ok' => true, 'value' => self::import_ladder_map( $raw ) );

			case 'quest_map':
				return array( 'ok' => true, 'value' => self::import_quest_map( $raw ) );

			case 'gateway_map':
				return array( 'ok' => true, 'value' => self::import_gateway_map( $raw ) );

			case 'currency_map':
				return array( 'ok' => true, 'value' => self::import_currency_map( $raw ) );

			default: // toggle
				$truthy = ( true === $raw ) || in_array( is_scalar( $raw ) ? strtolower( (string) $raw ) : '', array( 'yes', '1', 'true', 'on' ), true );
				return array( 'ok' => true, 'value' => $truthy ? 'yes' : 'no' );
		}
	}

	/** Validate an imported role=>multiplier map (JSON string or array) back to a clean JSON option. */
	private static function import_role_map( $raw ): string {
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return '';
		}
		$valid = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : array();
		$map   = array();
		foreach ( $data as $role => $value ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role || ! in_array( $role, $valid, true ) ) {
				continue;
			}
			$mult = Multipliers::clamp( (float) $value );
			if ( 1.0 === $mult ) {
				continue;
			}
			$map[ $role ] = $mult;
		}
		return array() === $map ? '' : (string) wp_json_encode( $map );
	}

	/** Validate an imported spend-tier list (JSON string or array) back to a clean JSON option. */
	private static function import_tier_map( $raw ): string {
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return '';
		}
		$list = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$min  = isset( $row['min'] ) ? max( 0.0, (float) $row['min'] ) : 0.0;
			$mult = isset( $row['mult'] ) ? Multipliers::clamp( (float) $row['mult'] ) : 1.0;
			if ( $min <= 0.0 ) {
				continue;
			}
			$list[] = array( 'min' => $min, 'mult' => $mult );
		}
		return array() === $list ? '' : (string) wp_json_encode( $list );
	}

	/**
	 * Look up a field spec by its option key.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function field_for( string $key ): ?array {
		foreach ( self::groups() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( (string) ( $field['id'] ?? '' ) === $key ) {
					return $field;
				}
			}
		}
		return null;
	}

	public static function render(): void {
		self::render_page( 'main' );
	}

	public static function render_display(): void {
		self::render_page( 'display' );
	}

	public static function render_tools(): void {
		self::render_page( 'tools' );
	}

	/** Render one settings sub-page: intro + page switcher + this page's pill tabs / panes + save. */
	private static function render_page( string $page ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$pages = self::pages();
		$spec  = $pages[ $page ] ?? $pages['main'];

		echo '<div class="wrap moksafopoi-settings-screen">';

		if ( isset( $_GET['moksafopoi_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		}

		if ( isset( $_GET['moksafopoi_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$applied = (int) $_GET['moksafopoi_imported']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$skipped = isset( $_GET['moksafopoi_skipped'] ) ? (int) $_GET['moksafopoi_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf( /* translators: 1: applied count, 2: skipped count. */ __( 'Imported %1$d setting(s) (skipped %2$d).', 'moksa-points-for-woocommerce' ), $applied, $skipped ) )
				. '</p></div>';
		}
		if ( isset( $_GET['moksafopoi_import_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Settings import failed: the file is invalid or the format does not match.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		}

		// Only this page's tabs render here; the rest live on the sibling sub-pages.
		$tabs = array_intersect_key( self::tabs(), array_flip( (array) $spec['tabs'] ) );

		// Bucket groups by tab (preserving declaration order within each tab).
		$by_tab = array_fill_keys( array_keys( $tabs ), array() );
		foreach ( self::groups() as $group ) {
			$tab = (string) ( $group['tab'] ?? self::DEFAULT_TAB );
			if ( ! array_key_exists( $tab, self::tabs() ) ) {
				$tab = self::DEFAULT_TAB; // Unknown tab → fold into general so the group is never dropped.
			}
			if ( isset( $by_tab[ $tab ] ) ) {
				$by_tab[ $tab ][] = $group;
			}
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="moksafopoi_scope" value="' . esc_attr( $page ) . '">';
		wp_nonce_field( self::NONCE );

		echo '<div class="mowp-shell" data-ns="moksa-points-for-woocommerce">';
		echo '<div class="mowp-intro"><h1>' . esc_html( (string) $spec['label'] ) . '</h1>'
			. '<p>' . esc_html( (string) $spec['desc'] ) . '</p></div>';

		// Pill tabs (pure presentation; SettingsUi::js toggles which [data-pane] is visible).
		// A page with a single tab needs no pill row.
		if ( count( $tabs ) > 1 ) {
			echo '<div class="mowp-tabs nav-tab-wrapper">';
			$first = true;
			foreach ( $tabs as $slug => $label ) {
				echo '<a href="#" class="nav-tab' . ( $first ? ' nav-tab-active' : '' ) . '" data-tab="' . esc_attr( $slug ) . '">'
					. esc_html( $label ) . '</a>';
				$first = false;
			}
			echo '</div>';
		}

		// One pane per tab of THIS page. Every pane of the page is in the DOM inside the single
		// <form> (CSS hides inactive ones), so a submit posts every field of this page — and
		// handle() saves only this page's fields (scoped by moksafopoi_scope), so toggles that
		// live on other sub-pages are never misread as "off".
		$first = true;
		foreach ( $tabs as $slug => $label ) {
			$style = $first ? '' : ' style="display:none"';
			echo '<div class="mowp-pane" data-pane="' . esc_attr( $slug ) . '"' . $style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style is a fixed literal.
			foreach ( $by_tab[ $slug ] as $group ) {
				self::render_group_card( $group );
			}
			if ( 'email' === $slug ) {
				self::render_email_preview();
			}
			if ( 'tools' === $slug ) {
				self::render_tools_pane();
			}
			echo '</div>';
			$first = false;
		}

		echo '<p class="submit mowp-save"><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save settings', 'moksa-points-for-woocommerce' ) . '</button></p>';
		echo '</div>'; // .mowp-shell
		echo '</form>';
		echo '</div>';
	}

	/** Render one group as a collapsible mowp section-card (toggles → iOS cards, others → field rows). */
	private static function render_group_card( array $group ): void {
		$title  = (string) ( $group['title'] ?? '' );
		$fields = (array) ( $group['fields'] ?? array() );
		// A section with many value fields (the display / appearance walls) starts collapsed so the
		// page reads as a tidy accordion instead of one endless scroll; the state then persists per
		// user. Module-toggle lists (no value fields) stay open. An explicit 'collapsed' flag overrides.
		$value_fields = 0;
		foreach ( $fields as $f ) {
			if ( in_array( (string) ( $f['type'] ?? 'toggle' ), array( 'text', 'textarea', 'number', 'select', 'color' ), true ) ) {
				++$value_fields;
			}
		}
		$collapsed = array_key_exists( 'collapsed', $group ) ? (bool) $group['collapsed'] : ( $value_fields >= 6 );
		echo '<section class="mowp-section-card' . ( $collapsed ? ' is-collapsed' : '' ) . '" data-key="' . esc_attr( $title ) . '">';
		echo '<div class="mowp-section-card__head"><span class="mowp-section-card__title">' . esc_html( $title )
			. '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div>';
		echo '<div class="mowp-section-card__body">';
		if ( '' !== (string) ( $group['desc'] ?? '' ) ) {
			echo '<p class="mowp-section-card__desc">' . esc_html( (string) $group['desc'] ) . '</p>';
		}
		foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
			self::render_field( $field );
		}
		echo '</div></section>';
	}

	/**
	 * Render the live e-mail preview card on the 信件 tab. The sample mail is built entirely
	 * server-side from placeholder data ({@see Reminder::sample_preview()}) — no real member data,
	 * no request input — and dropped into a sandboxed <iframe srcdoc>. The sandbox (no allow-*
	 * tokens) means the document cannot run scripts, submit forms, or navigate the parent, and
	 * srcdoc is attribute-escaped so the HTML cannot break out of the iframe. The preview reflects
	 * the saved 標頭底色 / 標頭文字 / 單位名稱 options (so it updates after each save).
	 */
	private static function render_email_preview(): void {
		$html = Reminder::sample_preview();

		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'Email preview', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div>';
		echo '<div class="mowp-section-card__body">';
		echo '<p class="mowp-section-card__desc">' . esc_html__( 'Below is a sample appearance of the "Points expiry reminder" email (using sample data). After saving, this preview applies the latest header background color and text.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '<div style="padding:4px 22px 16px"><iframe title="' . esc_attr__( 'Email preview', 'moksa-points-for-woocommerce' ) . '" '
			. 'sandbox="" '
			. 'style="width:100%;max-width:640px;height:460px;border:1px solid #dcdcde;border-radius:4px;background:#fff" '
			. 'srcdoc="' . esc_attr( $html ) . '"></iframe></div>';
		echo '</div></section>';
	}

	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE );

		// 匯入分支:上傳了設定檔並按下「上傳並套用」→ 走還原流程(略過一般欄位儲存)。
		if ( isset( $_POST['moksafopoi_do_import'] ) ) {
			self::handle_import();
			return;
		}

		// 餘額 CSV 匯入分支(試算或實際寫入)——同樣略過一般欄位儲存。
		if ( isset( $_POST['moksafopoi_do_balance_import'] ) ) {
			self::handle_balance_import();
			return;
		}

		// 帳本彙總(保留政策)分支。
		if ( isset( $_POST['moksafopoi_do_retention'] ) ) {
			self::handle_retention();
			return;
		}

		// 存檔範圍:表單只送出自己那個子頁的欄位,所以只寫該頁 tabs 底下的欄位 —
		// 其他子頁上「沒被送出的 checkbox」才不會被誤存成關閉。
		$pages = self::pages();
		$scope = isset( $_POST['moksafopoi_scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['moksafopoi_scope'] ) ) : 'main';
		if ( ! isset( $pages[ $scope ] ) ) {
			$scope = 'main';
		}
		$scope_tabs = (array) $pages[ $scope ]['tabs'];

		// 稽核:存檔前快照所有已知 key,存檔後 diff 出真正變動的項目並記錄。
		$audit_before = array();
		foreach ( self::known_keys() as $audit_key ) {
			$audit_before[ $audit_key ] = get_option( $audit_key, null );
		}

		foreach ( self::groups() as $group ) {
			$group_tab = (string) ( $group['tab'] ?? self::DEFAULT_TAB );
			if ( ! array_key_exists( $group_tab, self::tabs() ) ) {
				$group_tab = self::DEFAULT_TAB;
			}
			if ( ! in_array( $group_tab, $scope_tabs, true ) ) {
				continue; // Lives on another sub-page → not part of this submit.
			}
			foreach ( $group['fields'] as $field ) {
				$id   = (string) $field['id'];
				$type = (string) ( $field['type'] ?? 'toggle' );

				switch ( $type ) {
					case 'text':
						$raw   = isset( $_POST[ $id ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
						$value = '' === $raw ? (string) ( $field['default'] ?? '' ) : $raw;
						update_option( $id, $value );
						break;

					case 'textarea':
						// Message templates (顯示客製化): keep newlines + basic markup, strip anything unsafe.
						// An empty submit restores the field default (so a blank box re-seeds the canned copy),
						// EXCEPT when the default is itself blank (an opt-in field that may legitimately be empty).
						$raw     = isset( $_POST[ $id ] ) ? wp_kses_post( wp_unslash( (string) $_POST[ $id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; wp_kses_post is the sanitizer for this rich field.
						$default = (string) ( $field['default'] ?? '' );
						$value   = ( '' === trim( $raw ) && '' !== $default ) ? $default : $raw;
						update_option( $id, $value );
						break;

					case 'number':
						$raw = isset( $_POST[ $id ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
						if ( '' === $raw ) {
							$raw = (string) ( $field['default'] ?? 0 );
						}
						$num = max( 0.0, (float) $raw );
						// Store as int when it is whole, else keep the decimal.
						$value = ( floor( $num ) === $num ) ? (string) (int) $num : (string) $num;
						update_option( $id, $value );
						break;

					case 'select':
						$raw     = isset( $_POST[ $id ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
						$choices = (array) ( $field['choices'] ?? array() );
						$value   = isset( $choices[ $raw ] ) ? $raw : (string) ( $field['default'] ?? '' );
						update_option( $id, $value );
						break;

					case 'color':
						// 顯示客製化 colour pickers (text / background). sanitize_hex_color() returns null for
						// anything that is not a valid #rgb / #rrggbb string, so a tampered POST can never store
						// arbitrary CSS. An empty submit is allowed ONLY when the field's default is itself empty
						// (a背景色 that may legitimately be transparent); otherwise it falls back to the default.
						$raw     = isset( $_POST[ $id ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
						$default = (string) ( $field['default'] ?? '' );
						if ( '' === $raw ) {
							$value = $default; // Empty allowed → '' when the default is '' (transparent), else default colour.
						} else {
							$hex   = sanitize_hex_color( $raw );
							$value = ( is_string( $hex ) && '' !== $hex ) ? $hex : $default; // Illegal → default.
						}
						update_option( $id, $value );
						break;

					case 'term_select':
						// Multi-picker posts an array of term ids; store the same comma-separated ID
						// string the plain textbox used, so downstream readers are unchanged.
						$arr   = isset( $_POST[ $id ] ) ? (array) wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; each element is absint()'d on the next line.
						$ids   = array_values( array_unique( array_filter( array_map( 'absint', $arr ) ) ) );
						update_option( $id, implode( ',', $ids ) );
						break;

					case 'role_map':
						self::save_role_map( $id );
						break;

					case 'ladder_map':
						self::save_ladder_map( $id );
						break;

					case 'quest_map':
						self::save_quest_map( $id );
						break;

					case 'gateway_map':
						self::save_gateway_map( $id );
						break;

					case 'currency_map':
						self::save_currency_map( $id );
						break;

					case 'tier_map':
						self::save_tier_map( $id );
						break;

					default: // toggle — plain yes/no.
						$value = isset( $_POST[ $id ] ) && 'yes' === $_POST[ $id ] ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- compared to a literal; nonce checked above.
						update_option( $id, $value );
						break;
				}
			}
		}

		foreach ( self::known_keys() as $audit_key ) {
			$audit_new = get_option( $audit_key, null );
			if ( (string) maybe_serialize( $audit_before[ $audit_key ] ?? null ) !== (string) maybe_serialize( $audit_new ) ) {
				SettingsAudit::record( $audit_key, $audit_before[ $audit_key ] ?? null, $audit_new, 'ui' );
			}
		}
		wp_safe_redirect( add_query_arg( 'moksafopoi_saved', '1', self::page_url( $scope ) ) );
		exit;
	}

	/**
	 * Save the role => multiplier map as a JSON option. Only known WP roles are kept; each value is
	 * clamped to (0..MAX]. The neutral 1.0 is dropped so the stored map stays lean. Nonce verified
	 * by the caller ({@see handle()}).
	 */
	private static function save_role_map( string $id ): void {
		$raw = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle(); each value sanitized below.
		$valid = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : array();
		$map   = array();
		foreach ( (array) $raw as $role => $value ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role || ! in_array( $role, $valid, true ) ) {
				continue;
			}
			$mult = Multipliers::clamp( (float) sanitize_text_field( (string) $value ) );
			if ( 1.0 === $mult ) {
				continue; // neutral — no need to store.
			}
			$map[ $role ] = $mult;
		}
		update_option( $id, array() === $map ? '' : (string) wp_json_encode( $map ) );
	}

	/**
	 * Save the spend-tier rows as a JSON list of {min,mult}. Rows with min<=0 are dropped; mult is
	 * clamped to (0..MAX]. Nonce verified by the caller ({@see handle()}).
	 */
	private static function save_tier_map( string $id ): void {
		$raw  = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle(); each value sanitized below.
		$list = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$min  = isset( $row['min'] ) ? max( 0.0, (float) sanitize_text_field( (string) $row['min'] ) ) : 0.0;
			$mult = isset( $row['mult'] ) ? Multipliers::clamp( (float) sanitize_text_field( (string) $row['mult'] ) ) : 1.0;
			if ( $min <= 0.0 ) {
				continue;
			}
			$list[] = array(
				'min'  => $min,
				'mult' => $mult,
			);
		}
		update_option( $id, array() === $list ? '' : (string) wp_json_encode( $list ) );
	}

	/**
	 * Render one settings field by its declared type (toggle / number / select).
	 *
	 * @param array<string,mixed> $field
	 */
	private static function render_field( array $field ): void {
		$id    = (string) $field['id'];
		$type  = (string) ( $field['type'] ?? 'toggle' );
		$title = (string) $field['title'];
		$desc  = (string) $field['desc'];

		// Composite custom fields keep their own renderer; wrap so they inherit the field-row rhythm.
		if ( 'role_map' === $type ) {
			echo '<div class="mowp-field">';
			self::render_role_map( $id, $title, $desc );
			echo '</div>';
			return;
		}
		if ( 'tier_map' === $type ) {
			echo '<div class="mowp-field">';
			self::render_tier_map( $id, $title, $desc, (int) ( $field['rows'] ?? 3 ) );
			echo '</div>';
			return;
		}
		if ( 'ladder_map' === $type ) {
			echo '<div class="mowp-field">';
			self::render_ladder_map( $id, $title, $desc );
			echo '</div>';
			return;
		}
		if ( 'quest_map' === $type ) {
			echo '<div class="mowp-field">';
			self::render_quest_map( $id, $title, $desc );
			echo '</div>';
			return;
		}
		if ( 'gateway_map' === $type ) {
			echo '<div class="mowp-field">';
			self::render_gateway_map( $id, $title, $desc );
			echo '</div>';
			return;
		}
		if ( 'currency_map' === $type ) {
			echo '<div class="mowp-field">';
			self::render_currency_map( $id, $title, $desc );
			echo '</div>';
			return;
		}

		// Taxonomy-term multi-picker (a prettified multi-select of every term) — replaces a raw
		// category-ID textbox. Stored as a comma-separated list of term IDs (the same shape the plain
		// textbox used), so downstream readers are unchanged.
		if ( 'term_select' === $type ) {
			$raw = (string) get_option( $id, (string) ( $field['default'] ?? '' ) );
			$sel = array_filter( array_map( 'trim', (array) preg_split( '/[\s,]+/', $raw ) ) );
			$tax = (string) ( $field['taxonomy'] ?? 'product_cat' );
			echo '<div class="mowp-field mowp-field--term_select">';
			echo '<label class="mowp-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</label>';
			if ( '' !== $desc ) {
				echo '<span class="mowp-field__desc">' . esc_html( $desc ) . '</span>';
			}
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" multiple class="wc-enhanced-select" style="width:100%;max-width:640px" '
				. 'data-placeholder="' . esc_attr__( 'Select categories (multiple allowed)…', 'moksa-points-for-woocommerce' ) . '">';
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$is_sel = in_array( (string) $term->term_id, $sel, true ) || in_array( $term->slug, $sel, true );
					echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . ( $is_sel ? ' selected' : '' ) . '>' . esc_html( $term->name ) . '</option>';
				}
			}
			echo '</select>';
			echo '</div>';
			return;
		}

		// Simple value fields → one consistent mowp field row.
		if ( in_array( $type, array( 'text', 'textarea', 'number', 'select', 'color' ), true ) ) {
			echo '<div class="mowp-field mowp-field--' . esc_attr( $type ) . '">';
			echo '<label class="mowp-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</label>';
			if ( '' !== $desc ) {
				echo '<span class="mowp-field__desc">' . esc_html( $desc ) . '</span>';
			}
			if ( 'text' === $type ) {
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) get_option( $id, (string) ( $field['default'] ?? '' ) ) ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '">';
			} elseif ( 'textarea' === $type ) {
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" rows="3" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '' ) ) . '">' . esc_textarea( (string) get_option( $id, (string) ( $field['default'] ?? '' ) ) ) . '</textarea>';
			} elseif ( 'number' === $type ) {
				echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) get_option( $id, (string) ( $field['default'] ?? '' ) ) ) . '" min="' . esc_attr( (string) ( $field['min'] ?? '0' ) ) . '" step="' . esc_attr( (string) ( $field['step'] ?? '1' ) ) . '">';
			} elseif ( 'select' === $type ) {
				$current = (string) get_option( $id, (string) ( $field['default'] ?? '' ) );
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
				foreach ( (array) ( $field['choices'] ?? array() ) as $val => $label ) {
					echo '<option value="' . esc_attr( (string) $val ) . '"' . selected( $current, (string) $val, false ) . '>' . esc_html( (string) $label ) . '</option>';
				}
				echo '</select>';
			} else { // color — native well paired with a hex box (kept in sync by enqueue_admin JS).
				$default = (string) ( $field['default'] ?? '' );
				$value   = (string) get_option( $id, $default );
				$swatch  = sanitize_hex_color( '' !== $value ? $value : $default );
				// An unset optional colour (empty value + empty default) shows a neutral grey well, not
			// black — a black swatch reads as「已選黑色」when nothing is actually set. The named hex box
			// below stays blank, so save still stores「空 = 透明 / 沿用佈景主題」.
			$swatch  = ( is_string( $swatch ) && '' !== $swatch ) ? $swatch : '#c9ced6';
				echo '<span style="display:inline-flex;gap:8px;align-items:center">';
				echo '<input type="color" id="' . esc_attr( $id ) . '_picker" value="' . esc_attr( $swatch ) . '" data-target="' . esc_attr( $id ) . '" class="moksafopoi-color-picker">';
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( (string) ( $field['placeholder'] ?? '#000000' ) ) . '" class="moksafopoi-color-hex" style="max-width:120px">';
				echo '</span>';
			}
			echo '</div>';
			return;
		}

		// toggle → iOS-toggle module card.
		$enabled = 'yes' === get_option( $id, (string) ( $field['default'] ?? 'no' ) );
		echo '<div class="mowp-card' . ( $enabled ? ' is-on' : '' ) . '">';
		echo '<div class="mowp-card__main"><div class="mowp-card__head"><span class="mowp-card__name">' . esc_html( $title ) . '</span></div>';
		if ( '' !== $desc ) {
			echo '<div class="mowp-card__tagline">' . esc_html( $desc ) . '</div>';
		}
		echo '</div>';
		echo '<div class="mowp-card__action"><label class="mowp-toggle">'
			. '<input type="checkbox" name="' . esc_attr( $id ) . '" value="yes"' . checked( $enabled, true, false ) . '>'
			. '<span class="mowp-toggle__slider"></span></label></div>';
		echo '</div>';
	}

	/** Render one numeric multiplier input per editable WP role (name = id[role]). */
	private static function render_role_map( string $id, string $title, string $desc ): void {
		$map   = Multipliers::role_map();
		$roles = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();

		echo '<p><strong>' . esc_html( $title ) . '</strong><br>';
		echo '<span class="description">' . esc_html( $desc ) . '</span></p>';
		echo '<table class="widefat striped" style="max-width:420px"><tbody>';
		foreach ( $roles as $role => $info ) {
			$role  = sanitize_key( (string) $role );
			$name  = isset( $info['name'] ) ? translate_user_role( (string) $info['name'] ) : $role;
			$value = isset( $map[ $role ] ) ? (float) $map[ $role ] : 1.0;
			$field = $id . '[' . $role . ']';
			echo '<tr><td>' . esc_html( $name ) . '</td><td>';
			echo '<input type="number" name="' . esc_attr( $field ) . '" '
				. 'value="' . esc_attr( self::fmt_mult( $value ) ) . '" step="0.1" min="0" '
				. 'max="' . esc_attr( (string) Multipliers::MAX_MULTIPLIER ) . '" style="max-width:90px">';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Render a fixed set of {min,mult} tier rows (name = id[i][min] / id[i][mult]). */
	private static function render_tier_map( string $id, string $title, string $desc, int $rows ): void {
		$tiers = Multipliers::tiers();

		echo '<p><strong>' . esc_html( $title ) . '</strong><br>';
		echo '<span class="description">' . esc_html( $desc ) . '</span></p>';
		echo '<table class="widefat striped" style="max-width:420px"><thead><tr>';
		echo '<th>' . esc_html__( 'Amount reached (NT$)', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Multiplier', 'moksa-points-for-woocommerce' ) . '</th></tr></thead><tbody>';
		for ( $i = 0; $i < max( 1, $rows ); $i++ ) {
			$min  = isset( $tiers[ $i ]['min'] ) ? (float) $tiers[ $i ]['min'] : 0.0;
			$mult = isset( $tiers[ $i ]['mult'] ) ? (float) $tiers[ $i ]['mult'] : 1.0;
			echo '<tr><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $i . '][min]' ) . '" '
				. 'value="' . esc_attr( $min > 0 ? self::fmt_mult( $min ) : '' ) . '" step="1" min="0" style="max-width:120px">';
			echo '</td><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $i . '][mult]' ) . '" '
				. 'value="' . esc_attr( self::fmt_mult( $mult ) ) . '" step="0.1" min="0" '
				. 'max="' . esc_attr( (string) Multipliers::MAX_MULTIPLIER ) . '" style="max-width:90px">';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the tier-ladder editor: {@see Tiers::MAX_ROWS} rows of name + lifetime-points threshold,
	 * prefilled from the stored ladder (or the starter ladder on a fresh install).
	 */
	private static function render_ladder_map( string $id, string $title, string $desc ): void {
		$ladder = Tiers::ladder();

		echo '<p><strong>' . esc_html( $title ) . '</strong><br>';
		echo '<span class="description">' . esc_html( $desc ) . '</span></p>';
		echo '<table class="widefat striped" style="max-width:520px"><thead><tr>';
		echo '<th>' . esc_html__( 'Tier name', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Lifetime points needed', 'moksa-points-for-woocommerce' ) . '</th></tr></thead><tbody>';
		for ( $i = 0; $i < Tiers::MAX_ROWS; $i++ ) {
			$label     = isset( $ladder[ $i ]['label'] ) ? (string) $ladder[ $i ]['label'] : '';
			$threshold = isset( $ladder[ $i ]['threshold'] ) ? (int) $ladder[ $i ]['threshold'] : 0;
			echo '<tr><td>';
			echo '<input type="text" name="' . esc_attr( $id . '[' . $i . '][label]' ) . '" '
				. 'value="' . esc_attr( $label ) . '" style="width:100%;max-width:220px">';
			echo '</td><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $i . '][threshold]' ) . '" '
				. 'value="' . esc_attr( '' !== $label ? (string) $threshold : '' ) . '" step="1" min="0" style="max-width:140px">';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Sanitise + persist the submitted tier ladder (rows without a name are dropped). */
	private static function save_ladder_map( string $id ): void {
		$raw  = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle(); each value sanitized below.
		update_option( $id, self::normalise_ladder_rows( (array) $raw ) );
	}

	/**
	 * Re-sanitise an imported ladder value (array or JSON string) into the stored JSON shape.
	 *
	 * @param mixed $raw
	 */
	private static function import_ladder_map( $raw ): string {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		return self::normalise_ladder_rows( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Shared normaliser for both save paths: name required, threshold >= 0, capped at
	 * {@see Tiers::MAX_ROWS} rows, encoded as JSON ('' clears the option back to the starter ladder).
	 *
	 * @param array<int|string,mixed> $rows
	 */
	private static function normalise_ladder_rows( array $rows ): string {
		$list = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $list ) >= Tiers::MAX_ROWS ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			if ( '' === $label ) {
				continue; // A blank name drops the rung.
			}
			$list[] = array(
				'key'       => sanitize_key( (string) ( $row['key'] ?? '' ) ) ?: 'tier_' . count( $list ),
				'label'     => $label,
				'threshold' => isset( $row['threshold'] ) ? max( 0, (int) sanitize_text_field( (string) $row['threshold'] ) ) : 0,
			);
		}
		return array() === $list ? '' : (string) wp_json_encode( $list );
	}

	/**
	 * Render the quest editor: {@see Quests::MAX_QUESTS} rows of name / counted event / target /
	 * reward, prefilled from the stored list.
	 */
	private static function render_quest_map( string $id, string $title, string $desc ): void {
		$quests = Quests::all();
		$events = Quests::events();

		echo '<p><strong>' . esc_html( $title ) . '</strong><br>';
		echo '<span class="description">' . esc_html( $desc ) . '</span></p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Quest name', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'What is counted', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Target', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Reward points', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'moksa-points-for-woocommerce' ) . '</th></tr></thead><tbody>';

		for ( $i = 0; $i < Quests::MAX_QUESTS; $i++ ) {
			$quest = $quests[ $i ] ?? null;
			$label = $quest ? (string) $quest['label'] : '';
			echo '<tr><td>';
			echo '<input type="text" name="' . esc_attr( $id . '[' . $i . '][label]' ) . '" value="' . esc_attr( $label ) . '" style="width:100%;max-width:220px">';
			if ( $quest ) {
				echo '<input type="hidden" name="' . esc_attr( $id . '[' . $i . '][key]' ) . '" value="' . esc_attr( (string) $quest['key'] ) . '">';
			}
			echo '</td><td><select name="' . esc_attr( $id . '[' . $i . '][event]' ) . '">';
			foreach ( $events as $value => $event_label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $quest ? $quest['event'] : 'order', false ) . '>' . esc_html( $event_label ) . '</option>';
			}
			echo '</select></td><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $i . '][target]' ) . '" value="' . esc_attr( $quest ? (string) $quest['target'] : '' ) . '" step="1" min="1" style="max-width:110px">';
			echo '</td><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $i . '][reward]' ) . '" value="' . esc_attr( $quest ? (string) $quest['reward'] : '' ) . '" step="1" min="0" max="' . esc_attr( (string) Quests::MAX_REWARD ) . '" style="max-width:110px">';
			echo '</td><td>';
			echo '<input type="checkbox" name="' . esc_attr( $id . '[' . $i . '][enabled]' ) . '" value="1"' . checked( true, $quest ? (bool) $quest['enabled'] : false, false ) . '>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Sanitise + persist the submitted quest list (rows without a name are dropped). */
	private static function save_quest_map( string $id ): void {
		$raw = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle(); each value sanitized below.
		Quests::save_all( self::normalise_quest_rows( (array) $raw ) );
	}

	/**
	 * Re-sanitise an imported quest list (array or JSON string) into the stored JSON shape.
	 *
	 * @param mixed $raw
	 */
	private static function import_quest_map( $raw ): string {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		$rows = self::normalise_quest_rows( is_array( $raw ) ? $raw : array() );
		return array() === $rows ? '' : (string) wp_json_encode( $rows );
	}

	/**
	 * Shared normaliser for both quest save paths. A quest KEY is preserved when the row already had
	 * one — the key is what member progress is filed under, so renaming a quest must not silently
	 * reset everybody's progress or re-pay a finished quest.
	 *
	 * @param array<int|string,mixed> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalise_quest_rows( array $rows ): array {
		$list   = array();
		$events = Quests::events();
		$used   = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) || count( $list ) >= Quests::MAX_QUESTS ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			if ( '' === $label ) {
				continue; // A blank name removes the quest.
			}
			$event = isset( $row['event'] ) ? sanitize_key( (string) $row['event'] ) : '';
			if ( ! isset( $events[ $event ] ) ) {
				continue;
			}

			$key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( '' === $key || isset( $used[ $key ] ) ) {
				$key = 'q' . substr( md5( $label . '|' . $event . '|' . (string) $index ), 0, 10 );
			}
			$used[ $key ] = true;

			$list[] = array(
				'key'     => $key,
				'label'   => $label,
				'desc'    => isset( $row['desc'] ) ? sanitize_text_field( (string) $row['desc'] ) : '',
				'event'   => $event,
				'target'  => max( 1, (int) sanitize_text_field( (string) ( $row['target'] ?? 1 ) ) ),
				'reward'  => min( Quests::MAX_REWARD, max( 0, (int) sanitize_text_field( (string) ( $row['reward'] ?? 0 ) ) ) ),
				'enabled' => ! empty( $row['enabled'] ),
			);
		}

		return $list;
	}

	/** Render one multiplier input per payment method this store actually has installed. */
	private static function render_gateway_map( string $id, string $title, string $desc ): void {
		$gateways = GatewayPoints::available_gateways();
		$map      = GatewayPoints::map();

		echo '<p><strong>' . esc_html( $title ) . '</strong><br>';
		echo '<span class="description">' . esc_html( $desc ) . '</span></p>';

		if ( array() === $gateways ) {
			echo '<p class="description">' . esc_html__( 'No payment methods are installed yet.', 'moksa-points-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:520px"><thead><tr>';
		echo '<th>' . esc_html__( 'Payment method', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Multiplier', 'moksa-points-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $gateways as $gateway => $label ) {
			$value = isset( $map[ $gateway ] ) ? (float) $map[ $gateway ] : 1.0;
			echo '<tr><td>' . esc_html( $label ) . ' <code>' . esc_html( $gateway ) . '</code></td><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $gateway . ']' ) . '" value="' . esc_attr( self::fmt_mult( $value ) ) . '" '
				. 'step="0.1" min="0" max="10" style="max-width:90px">';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Sanitise + persist the submitted payment-method multipliers. */
	private static function save_gateway_map( string $id ): void {
		$raw = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle(); each value sanitized in GatewayPoints::save().
		GatewayPoints::save( (array) $raw );
	}

	/**
	 * Re-sanitise an imported payment-method map into the stored JSON shape.
	 *
	 * @param mixed $raw
	 */
	private static function import_gateway_map( $raw ): string {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}
		GatewayPoints::save( $raw );
		return (string) get_option( GatewayPoints::OPTION, '' );
	}

	/**
	 * Render one earn/redeem pair per currency the store sells in. Currencies come from the
	 * multi-currency plugin when it exposes them, and always include the shop's own.
	 */
	private static function render_currency_map( string $id, string $title, string $desc ): void {
		$map        = Rates::map();
		$currencies = array();

		$base = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
		if ( '' !== $base ) {
			$currencies[] = $base;
		}
		/**
		 * Filter the currencies offered in the per-currency rate table.
		 *
		 * @param array<int,string> $currencies
		 */
		$currencies = array_values( array_unique( array_merge( $currencies, array_keys( $map ), (array) apply_filters( 'moksafopoi_settings_currencies', array() ) ) ) );

		echo '<p><strong>' . esc_html( $title ) . '</strong><br>';
		echo '<span class="description">' . esc_html( $desc ) . '</span></p>';
		echo '<table class="widefat striped" style="max-width:560px"><thead><tr>';
		echo '<th>' . esc_html__( 'Currency', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Points earned per 1 unit', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Points to redeem 1 unit', 'moksa-points-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $currencies as $currency ) {
			$currency = strtoupper( (string) $currency );
			$earn     = isset( $map[ $currency ]['earn'] ) ? (string) $map[ $currency ]['earn'] : '';
			$redeem   = isset( $map[ $currency ]['redeem'] ) ? (string) $map[ $currency ]['redeem'] : '';
			echo '<tr><td><code>' . esc_html( $currency ) . '</code></td><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $currency . '][earn]' ) . '" value="' . esc_attr( $earn ) . '" step="0.01" min="0" style="max-width:120px" placeholder="' . esc_attr__( 'auto', 'moksa-points-for-woocommerce' ) . '">';
			echo '</td><td>';
			echo '<input type="number" name="' . esc_attr( $id . '[' . $currency . '][redeem]' ) . '" value="' . esc_attr( $redeem ) . '" step="1" min="1" style="max-width:120px" placeholder="' . esc_attr__( 'auto', 'moksa-points-for-woocommerce' ) . '">';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Sanitise + persist the submitted per-currency rates. */
	private static function save_currency_map( string $id ): void {
		$raw = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle(); each value sanitized in Rates::save().
		Rates::save( (array) $raw );
	}

	/**
	 * Re-sanitise an imported per-currency map into the stored JSON shape.
	 *
	 * @param mixed $raw
	 */
	private static function import_currency_map( $raw ): string {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}
		Rates::save( $raw );
		return (string) get_option( Rates::OPTION, '' );
	}

	/** Format a multiplier/amount without a trailing ".0" for whole numbers. */
	private static function fmt_mult( float $value ): string {
		return ( floor( $value ) === $value ) ? (string) (int) $value : rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Stream the current settings as a downloadable JSON file (匯出). Its own nonce; manage_woocommerce
	 * enforced. Wired to admin_post_moksafopoi_export_settings by Plugin.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( 'moksafopoi_export_settings' );

		$json = SettingsPorter::export_json();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . SettingsPorter::export_filename() . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw JSON file download, not HTML.
		exit;
	}

	/**
	 * Restore settings from the uploaded JSON (匯入). Called from {@see handle()} AFTER the nonce +
	 * capability are verified. Reads the uploaded temp file, decodes, and delegates to SettingsPorter,
	 * then redirects back with a result notice. Only known keys are written (see SettingsPorter).
	 */
	private static function handle_import(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- reached only from handle(), which verifies the nonce (check_admin_referer( self::NONCE )) and the manage_woocommerce capability before delegating here; the upload path is validated with is_uploaded_file().
		if ( empty( $_FILES['moksafopoi_import_file']['tmp_name'] ) || ! is_uploaded_file( sanitize_text_field( wp_unslash( (string) $_FILES['moksafopoi_import_file']['tmp_name'] ) ) ) ) {
			wp_safe_redirect( add_query_arg( 'moksafopoi_import_error', 'nofile', self::page_url( 'tools' ) ) );
			exit;
		}
		$tmp      = sanitize_text_field( wp_unslash( (string) $_FILES['moksafopoi_import_file']['tmp_name'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$contents = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading an uploaded temp file, not a remote URL.
		$payload  = SettingsPorter::decode_upload( $contents );
		if ( null === $payload ) {
			wp_safe_redirect( add_query_arg( 'moksafopoi_import_error', 'parse', self::page_url( 'tools' ) ) );
			exit;
		}
		$res = SettingsPorter::import_payload( $payload );
		if ( true !== ( $res['ok'] ?? false ) ) {
			wp_safe_redirect( add_query_arg( 'moksafopoi_import_error', 'format', self::page_url( 'tools' ) ) );
			exit;
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'moksafopoi_imported' => (int) $res['applied'],
					'moksafopoi_skipped'  => (int) $res['skipped'],
				),
				self::page_url( 'tools' )
			)
		);
		exit;
	}

	/**
	 * 餘額 CSV 匯入 handler. Called from {@see handle()} AFTER the nonce + capability are verified.
	 * Delegates parsing / booking to {@see BalanceImporter}, stashes the report in a short-lived
	 * transient for the current operator, and redirects back to 工具.
	 */
	private static function handle_balance_import(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- reached only from handle(), which verifies check_admin_referer( self::NONCE ) and the manage_woocommerce capability first; the upload path is validated with is_uploaded_file().
		$batch   = isset( $_POST['moksafopoi_balance_batch'] ) ? sanitize_key( wp_unslash( (string) $_POST['moksafopoi_balance_batch'] ) ) : '';
		$dry_run = isset( $_POST['moksafopoi_balance_dry_run'] );
		$tmp     = isset( $_FILES['moksafopoi_balance_file']['tmp_name'] )
			? sanitize_text_field( wp_unslash( (string) $_FILES['moksafopoi_balance_file']['tmp_name'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$result = array(
				'ok'    => false,
				'error' => __( 'Please choose a CSV file to upload.', 'moksa-points-for-woocommerce' ),
			);
		} else {
			$result = BalanceImporter::run( $tmp, $batch, $dry_run );
		}
		$result['dry_run'] = $dry_run;

		set_transient( self::balance_report_key(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'moksafopoi_balance_import', $dry_run ? 'preview' : 'done', self::page_url( 'tools' ) ) );
		exit;
	}

	/**
	 * 帳本彙總 handler. Called from {@see handle()} after the nonce + capability check.
	 */
	private static function handle_retention(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- reached only from handle(), which verifies check_admin_referer( self::NONCE ) and the manage_woocommerce capability first.
		$days    = isset( $_POST['moksafopoi_retention_days'] ) ? absint( wp_unslash( $_POST['moksafopoi_retention_days'] ) ) : 0;
		$dry_run = isset( $_POST['moksafopoi_retention_dry_run'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = Retention::run( $days, $dry_run );
		set_transient( self::retention_report_key(), $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'moksafopoi_retention', $dry_run ? 'preview' : 'done', self::page_url( 'tools' ) ) );
		exit;
	}

	/** Transient key holding the last retention report for the current operator. */
	private static function retention_report_key(): string {
		return 'moksafopoi_retention_' . get_current_user_id();
	}

	/**
	 * The 帳本彙總 card: what compaction does, what it deliberately refuses to touch, and the form.
	 */
	private static function render_retention_card(): void {
		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'Compact old ledger entries', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div><div class="mowp-section-card__body">';

		echo '<p class="mowp-section-card__desc">'
			. esc_html__( 'Replace each member\'s entries older than the cutoff with a single opening-balance row. Balances stay exactly the same — the sum is preserved and verified per member before anything is deleted — but the individual old rows are gone for good.', 'moksa-points-for-woocommerce' )
			. '</p>';
		echo '<p class="mowp-section-card__desc">'
			. esc_html(
				sprintf(
					/* translators: %s: the minimum age in days that may be compacted. */
					__( 'Entries newer than %s days are never compacted: deleting a row also deletes the duplicate-protection key that stops a replayed payment notification from awarding the same points twice. Entries that have not expired yet are left alone too, because they are still live inventory for the expiry engine.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( Retention::MIN_AGE_DAYS )
				)
			)
			. '</p>';

		self::render_retention_report();

		echo '<div class="mowp-field">';
		echo '<label class="mowp-field__label" for="moksafopoi_retention_days">' . esc_html__( 'Compact entries older than (days)', 'moksa-points-for-woocommerce' ) . '</label>';
		echo '<input type="number" id="moksafopoi_retention_days" name="moksafopoi_retention_days" class="small-text" value="730" min="' . esc_attr( (string) Retention::MIN_AGE_DAYS ) . '" step="1">';
		echo '</div>';

		echo '<div class="mowp-field">';
		echo '<label><input type="checkbox" name="moksafopoi_retention_dry_run" value="1" checked> '
			. esc_html__( 'Preview only (write nothing)', 'moksa-points-for-woocommerce' ) . '</label><br><br>';
		echo '<button type="submit" name="moksafopoi_do_retention" value="1" class="button button-secondary" onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Old entries will be replaced by one opening-balance row per member. Balances do not change, but the old rows cannot be recovered. Continue?', 'moksa-points-for-woocommerce' ) ) ) . ');">'
			. esc_html__( 'Run', 'moksa-points-for-woocommerce' ) . '</button>';
		echo '</div>';

		echo '</div></section>';
	}

	/** Render (and consume) the last retention report. */
	private static function render_retention_report(): void {
		$report = get_transient( self::retention_report_key() );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( self::retention_report_key() );

		$head = empty( $report['dry_run'] )
			? __( 'Compaction complete.', 'moksa-points-for-woocommerce' )
			: __( 'Preview only — nothing was written.', 'moksa-points-for-woocommerce' );

		$summary = sprintf(
			/* translators: 1: members affected; 2: entries compacted; 3: the cutoff datetime. */
			__( '%1$s members, %2$s entries older than %3$s.', 'moksa-points-for-woocommerce' ),
			number_format_i18n( (int) $report['users'] ),
			number_format_i18n( (int) $report['rows'] ),
			(string) $report['cutoff']
		);

		echo '<div class="notice notice-info inline"><p><strong>' . esc_html( $head ) . '</strong><br>' . esc_html( $summary );
		if ( ! empty( $report['skipped_users'] ) ) {
			echo '<br>' . esc_html(
				sprintf(
					/* translators: %s: number of members skipped. */
					__( '%s members were skipped and left untouched.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( (int) $report['skipped_users'] )
				)
			);
		}
		echo '</p></div>';
	}

	/** Transient key holding the last CSV import report for the current operator. */
	private static function balance_report_key(): string {
		return 'moksafopoi_bal_import_' . get_current_user_id();
	}

	/**
	 * The 餘額 CSV 匯入 card: the last report (if any), the format help, and the upload form. Lives
	 * inside the settings <form> (already multipart), like the settings import above it.
	 */
	private static function render_balance_import_card(): void {
		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'Import points / store credit (CSV)', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div><div class="mowp-section-card__body">';

		echo '<p class="mowp-section-card__desc">'
			. esc_html__( 'Seed existing balances when moving from another plugin or a spreadsheet. The first row must be a header; the "user" column accepts a user ID, e-mail or username, and the optional columns are points, credit, note and expires_at (YYYY-MM-DD).', 'moksa-points-for-woocommerce' )
			. '</p>';
		echo '<p class="mowp-section-card__desc"><code>user,points,credit,expires_at</code><br><code>vip@example.com,1200,50,2027-12-31</code></p>';
		echo '<p class="mowp-section-card__desc">'
			. esc_html__( 'Every row is booked once per batch code, so re-uploading the same file with the same batch code adds nothing a second time. Always run the preview first — it reports exactly what would happen and writes nothing.', 'moksa-points-for-woocommerce' )
			. '</p>';

		self::render_balance_report();

		echo '<div class="mowp-field">';
		echo '<label class="mowp-field__label" for="moksafopoi_balance_batch">' . esc_html__( 'Batch code', 'moksa-points-for-woocommerce' ) . '</label>';
		echo '<span class="mowp-field__desc">' . esc_html__( 'A short code identifying this import, e.g. migration-2026. Reusing a code makes the import idempotent; a NEW code books the rows again.', 'moksa-points-for-woocommerce' ) . '</span>';
		echo '<input type="text" id="moksafopoi_balance_batch" name="moksafopoi_balance_batch" class="regular-text" value="" placeholder="migration-2026">';
		echo '</div>';

		echo '<div class="mowp-field">';
		echo '<input type="file" name="moksafopoi_balance_file" accept="text/csv,.csv"><br><br>';
		echo '<label><input type="checkbox" name="moksafopoi_balance_dry_run" value="1" checked> '
			. esc_html__( 'Preview only (write nothing)', 'moksa-points-for-woocommerce' ) . '</label><br><br>';
		echo '<button type="submit" name="moksafopoi_do_balance_import" value="1" class="button button-secondary">'
			. esc_html__( 'Upload CSV', 'moksa-points-for-woocommerce' ) . '</button>';
		echo '</div>';

		echo '</div></section>';
	}

	/**
	 * The recent webhook deliveries, so an operator can see at a glance whether their endpoint is
	 * actually accepting events (the question every webhook integration ends up asking).
	 */
	private static function render_webhook_log_card(): void {
		if ( 'yes' !== get_option( 'moksafopoi_webhooks_enabled', 'no' ) ) {
			return;
		}
		$log = \Moksafopoi\Modules\Webhooks\Module::recent_log();

		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'Webhook deliveries', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div><div class="mowp-section-card__body">';

		if ( array() === $log ) {
			echo '<p class="mowp-section-card__desc">' . esc_html__( 'Nothing delivered yet.', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			echo '<div style="padding:4px 22px 14px"><table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Time (UTC)', 'moksa-points-for-woocommerce' ), __( 'Event', 'moksa-points-for-woocommerce' ), __( 'Response', 'moksa-points-for-woocommerce' ) ) as $header ) {
				echo '<th>' . esc_html( (string) $header ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $log as $row ) {
				$code  = (int) ( $row['code'] ?? 0 );
				$error = (string) ( $row['error'] ?? '' );
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['t'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['event'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( 0 === $code ? $error : (string) $code ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div></section>';
	}

	/**
	 * The MCP audit log: every external tool call, whether it succeeded, was denied, or hit the rate
	 * limit. Arguments are shown for change-making calls only, with anything credential-shaped masked.
	 */
	private static function render_mcp_audit_card(): void {
		if ( 'yes' !== get_option( 'moksafopoi_mcp_enabled', 'no' ) ) {
			return;
		}
		$log = \Moksafopoi\Mcp\CallGuard::recent();

		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'External AI (MCP) call log', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div><div class="mowp-section-card__body">';

		if ( array() === $log ) {
			echo '<p class="mowp-section-card__desc">' . esc_html__( 'No external tool calls yet.', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			echo '<div style="padding:4px 22px 14px"><table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Time (UTC)', 'moksa-points-for-woocommerce' ), __( 'Account', 'moksa-points-for-woocommerce' ), __( 'Tool', 'moksa-points-for-woocommerce' ), __( 'Type', 'moksa-points-for-woocommerce' ), __( 'Result', 'moksa-points-for-woocommerce' ), __( 'Arguments', 'moksa-points-for-woocommerce' ) ) as $header ) {
				echo '<th>' . esc_html( (string) $header ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $log as $row ) {
				$user = get_userdata( (int) ( $row['user'] ?? 0 ) );
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['t'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $user instanceof \WP_User ? $user->user_login : (string) ( $row['user'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['tool'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( empty( $row['destr'] ) ? __( 'Read', 'moksa-points-for-woocommerce' ) : __( 'Change', 'moksa-points-for-woocommerce' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['outcome'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['args'] ?? '' ) ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div></section>';
	}

	/** Render (and consume) the last CSV import report. */
	private static function render_balance_report(): void {
		$report = get_transient( self::balance_report_key() );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( self::balance_report_key() );

		if ( empty( $report['ok'] ) ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html( (string) ( $report['error'] ?? __( 'The import failed.', 'moksa-points-for-woocommerce' ) ) )
				. '</p></div>';
			return;
		}

		$summary = sprintf(
			/* translators: 1: rows read; 2: rows applied; 3: rows skipped as already imported; 4: rows rejected. */
			__( 'Read %1$s rows: %2$s to apply, %3$s already imported, %4$s rejected.', 'moksa-points-for-woocommerce' ),
			number_format_i18n( (int) $report['rows'] ),
			number_format_i18n( (int) $report['applied'] ),
			number_format_i18n( (int) $report['skipped'] ),
			number_format_i18n( (int) $report['failed'] )
		);
		$totals = sprintf(
			/* translators: 1: total points; 2: total store credit. */
			__( 'Totals: %1$s points and %2$s store credit.', 'moksa-points-for-woocommerce' ),
			number_format_i18n( (int) $report['points'] ),
			number_format_i18n( (float) $report['credit'], 2 )
		);
		$head = empty( $report['dry_run'] )
			? __( 'Import complete.', 'moksa-points-for-woocommerce' )
			: __( 'Preview only — nothing was written.', 'moksa-points-for-woocommerce' );

		echo '<div class="notice notice-info inline"><p><strong>' . esc_html( $head ) . '</strong><br>'
			. esc_html( $summary ) . '<br>' . esc_html( $totals ) . '</p>';
		if ( ! empty( $report['messages'] ) && is_array( $report['messages'] ) ) {
			echo '<ul style="margin:0 0 10px 18px;list-style:disc">';
			foreach ( $report['messages'] as $message ) {
				echo '<li>' . esc_html( (string) $message ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Render the 工具 / 紀錄 pane: 匯出 download link, 匯入 file upload (posts through the main form's
	 * import branch), and the recent 設定變更紀錄 table. Lives inside the single settings <form>, which
	 * is multipart so the file input transmits; the export link is a plain nonce'd GET.
	 */
	private static function render_tools_pane(): void {
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=moksafopoi_export_settings' ), 'moksafopoi_export_settings' );

		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'Export settings', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div><div class="mowp-section-card__body">';
		echo '<p class="mowp-section-card__desc">' . esc_html__( 'Download all current points settings as a JSON file, for backup or moving to another site.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '<div class="mowp-field"><a class="button button-secondary" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download settings JSON', 'moksa-points-for-woocommerce' ) . '</a></div>';
		echo '</div></section>';

		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'Import settings', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div><div class="mowp-section-card__body">';
		echo '<p class="mowp-section-card__desc">' . esc_html__( 'Upload a previously exported JSON file to restore settings. Only settings this plugin recognizes are applied; unknown items are automatically skipped; every change is recorded in the change log below.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '<div class="mowp-field"><input type="file" name="moksafopoi_import_file" accept="application/json,.json"><br><br>';
		echo '<button type="submit" name="moksafopoi_do_import" value="1" class="button button-secondary" onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Are you sure you want to overwrite the current settings with the uploaded file?', 'moksa-points-for-woocommerce' ) ) ) . ');">' . esc_html__( 'Upload and apply', 'moksa-points-for-woocommerce' ) . '</button></div>';
		echo '</div></section>';

		self::render_balance_import_card();
		self::render_webhook_log_card();
		self::render_mcp_audit_card();
		self::render_retention_card();

		$log = SettingsAudit::recent( 50 );
		echo '<section class="mowp-section-card"><div class="mowp-section-card__head"><span class="mowp-section-card__title">'
			. esc_html__( 'Settings change log', 'moksa-points-for-woocommerce' ) . '</span><span class="mowp-section-card__chev" aria-hidden="true"></span></div><div class="mowp-section-card__body">';
		if ( array() === $log ) {
			echo '<p class="mowp-section-card__desc">' . esc_html__( 'There is no change log yet.', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			echo '<div style="padding:4px 22px 14px"><table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Time (UTC)', 'moksa-points-for-woocommerce' ), __( 'User', 'moksa-points-for-woocommerce' ), __( 'Settings', 'moksa-points-for-woocommerce' ), __( 'Old value → new value', 'moksa-points-for-woocommerce' ), __( 'Source', 'moksa-points-for-woocommerce' ) ) as $h ) {
				echo '<th>' . esc_html( (string) $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $log as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['t'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['user'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['key'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $row['old'] ?? '' ) ) . ' → ' . esc_html( (string) ( $row['new'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['source'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div></section>';
	}

	public static function enqueue_admin( string $hook = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}

		wp_add_inline_style( 'common', SettingsUi::css() );
		wp_register_script( 'moksafopoi-settings-ui', false, array(), '1.0.0', true );
		wp_enqueue_script( 'moksafopoi-settings-ui' );
		wp_add_inline_script( 'moksafopoi-settings-ui', SettingsUi::js() );

		// WooCommerce enhanced (select2) — powers the 限定可折抵分類 picker. WC auto-inits any
		// .wc-enhanced-select in the DOM on ready, so no custom init is needed.
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		// Colour pickers: keep the native well and its paired hex text box in sync (plugin-specific).
		// Typing a blank hex (透明) leaves the box empty; the well just keeps its last swatch.
		wp_add_inline_script(
			'moksafopoi-settings-ui',
			'(function(){function r(f){if(document.readyState!=="loading"){f();}else{document.addEventListener("DOMContentLoaded",f);}}r(function(){'
			. 'document.querySelectorAll(".moksafopoi-color-picker").forEach(function(pk){var id=pk.getAttribute("data-target");var hex=document.getElementById(id);if(!hex){return;}'
			. 'pk.addEventListener("input",function(){hex.value=pk.value;});'
			. 'hex.addEventListener("input",function(){if(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(hex.value)){pk.value=hex.value;}});});});})();'
		);
	}

	/**
	 * Module enable/disable checkbox (id == moksafopoi_<key>_enabled). yes/no option.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * A per-currency earn/redeem rate table, stored as JSON in one option.
	 *
	 * @return array<string,mixed>
	 */
	private static function currency_map( string $id, string $title, string $desc ): array {
		return array(
			'id'    => $id,
			'title' => $title,
			'desc'  => $desc,
			'type'  => 'currency_map',
		);
	}

	/**
	 * A per-payment-method multiplier table, stored as JSON in one option.
	 *
	 * @return array<string,mixed>
	 */
	private static function gateway_map( string $id, string $title, string $desc ): array {
		return array(
			'id'    => $id,
			'title' => $title,
			'desc'  => $desc,
			'type'  => 'gateway_map',
		);
	}

	/**
	 * A quest editor (name + event + target + reward rows), stored as JSON in one option.
	 *
	 * @return array<string,mixed>
	 */
	private static function quest_map( string $id, string $title, string $desc ): array {
		return array(
			'id'    => $id,
			'title' => $title,
			'desc'  => $desc,
			'type'  => 'quest_map',
		);
	}

	/**
	 * A tier-ladder editor (name + threshold rows), stored as JSON in one option.
	 *
	 * @return array<string,mixed>
	 */
	private static function ladder_map( string $id, string $title, string $desc ): array {
		return array(
			'id'    => $id,
			'title' => $title,
			'desc'  => $desc,
			'type'  => 'ladder_map',
		);
	}

	/**
	 * A toggle that is ON out of the box (the field's own default is 'yes'), for display polish a
	 * shop expects to be there rather than to have to find.
	 *
	 * @return array<string,mixed>
	 */
	private static function toggle_on( string $id, string $title, string $desc ): array {
		return self::toggle( $id, $title, $desc, 'yes' );
	}

	private static function toggle( string $id, string $title, string $desc, string $default = 'no' ): array {
		return array(
			'id'      => $id,
			'title'   => $title,
			'desc'    => $desc,
			'type'    => 'toggle',
			// A toggle that is ON until the operator says otherwise passes 'yes' here, so the card
			// reflects what the code actually does on a fresh install instead of showing "off".
			'default' => 'yes' === $default ? 'yes' : 'no',
		);
	}

	/**
	 * A plain text option field (sanitised with sanitize_text_field on save).
	 *
	 * @return array<string,mixed>
	 */
	private static function text( string $id, string $title, string $desc, string $default = '', string $placeholder = '' ): array {
		return array(
			'id'          => $id,
			'title'       => $title,
			'desc'        => $desc,
			'type'        => 'text',
			'default'     => $default,
			'placeholder' => $placeholder,
		);
	}

	/**
	 * A multi-line message-template field (顯示客製化 copy). Saved with wp_kses_post so basic inline
	 * markup survives while anything unsafe is stripped; rendered with esc_textarea.
	 *
	 * @return array<string,mixed>
	 */
	private static function textarea( string $id, string $title, string $desc, string $default = '', string $placeholder = '' ): array {
		return array(
			'id'          => $id,
			'title'       => $title,
			'desc'        => $desc,
			'type'        => 'textarea',
			'default'     => $default,
			'placeholder' => $placeholder,
		);
	}

	/**
	 * A numeric option field.
	 *
	 * @return array<string,mixed>
	 */
	private static function number( string $id, string $title, string $desc, float|int $default, string $min = '0', string $step = '1' ): array {
		return array(
			'id'      => $id,
			'title'   => $title,
			'desc'    => $desc,
			'type'    => 'number',
			'default' => $default,
			'min'     => $min,
			'step'    => $step,
		);
	}

	/**
	 * A role => multiplier map field (one numeric input per WP role). Stored as a JSON option.
	 *
	 * @return array<string,mixed>
	 */
	private static function role_map( string $id, string $title, string $desc ): array {
		return array(
			'id'    => $id,
			'title' => $title,
			'desc'  => $desc,
			'type'  => 'role_map',
		);
	}

	/**
	 * A spend-tier map field (a fixed set of {min,mult} rows). Stored as a JSON option.
	 *
	 * @return array<string,mixed>
	 */
	private static function tier_map( string $id, string $title, string $desc ): array {
		return array(
			'id'    => $id,
			'title' => $title,
			'desc'  => $desc,
			'type'  => 'tier_map',
			'rows'  => 3, // number of fixed tier rows rendered.
		);
	}

	/**
	 * A select option field.
	 *
	 * @param array<string,string> $choices
	 * @return array<string,mixed>
	 */
	private static function select( string $id, string $title, string $desc, string $default, array $choices ): array {
		return array(
			'id'      => $id,
			'title'   => $title,
			'desc'    => $desc,
			'type'    => 'select',
			'default' => $default,
			'choices' => $choices,
		);
	}

	/**
	 * A colour-picker option field (顯示客製化 文字色 / 底色). Rendered as a native <input type="color">
	 * paired with a hex text box; saved with sanitize_hex_color (illegal → default). An empty $default
	 * means the colour is optional (e.g. a transparent background that may stay blank).
	 *
	 * @return array<string,mixed>
	 */
	private static function color( string $id, string $title, string $desc, string $default = '', string $placeholder = '' ): array {
		return array(
			'id'          => $id,
			'title'       => $title,
			'desc'        => $desc,
			'type'        => 'color',
			'default'     => $default,
			'placeholder' => $placeholder,
		);
	}

	/**
	 * A taxonomy-term multi-picker (prettified multi-select of every term in $taxonomy). Stored as a
	 * comma-separated list of term IDs — the same shape the plain-ID textbox used, so downstream
	 * readers are unchanged.
	 *
	 * @return array<string,mixed>
	 */
	private static function term_select( string $id, string $title, string $desc, string $taxonomy = 'product_cat', string $default = '' ): array {
		return array(
			'id'       => $id,
			'title'    => $title,
			'desc'     => $desc,
			'type'     => 'term_select',
			'taxonomy' => $taxonomy,
			'default'  => $default,
		);
	}
}
