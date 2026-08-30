<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Engage;

use Moksafopoi\Modules\Campaign\Campaigns;
use Moksafopoi\Support\Label;

defined( 'ABSPATH' ) || exit;

/**
 * 「如何賺點」引導 — the storefront explainer that answers the one question every loyalty programme
 * fails at: "so how do I actually earn points here?".
 *
 * Every line is DERIVED from the live settings of the modules that are actually switched on, so the
 * guide can never promise a bonus the engine will not pay: a zero-valued or disabled trigger simply
 * produces no line. Nothing is stored — this is a pure read of the same options the earn engine uses.
 *
 * Surfaces: the `[moksafopoi_how_to_earn]` shortcode (any page) and an optional panel under the
 * 「我的點數」hero (hooked by {@see Module}).
 */
final class HowToEarn {

	/**
	 * The guide rows for the current shop configuration.
	 *
	 * @return array<int,array{icon:string,title:string,desc:string}>
	 */
	public static function items(): array {
		$items = array();
		$unit  = Label::unit();

		// Base earning rate (the spend engine).
		if ( self::module_on( 'spendrules' ) ) {
			$rate = \Moksafopoi\Support\Rates::earn_rate();
			if ( $rate > 0 ) {
				$desc = sprintf(
					/* translators: 1: points earned per NT$1 spent; 2: the points unit, e.g.「點」. */
					__( 'Every NT$1 you spend earns %1$s %2$s, credited once the order is paid.', 'moksa-points-for-woocommerce' ),
					self::trim_number( $rate ),
					$unit
				);
				$max = (int) get_option( 'moksafopoi_earn_max_per_order', 0 );
				if ( $max > 0 ) {
					$desc .= ' ' . sprintf(
						/* translators: %s: maximum points earnable from a single order (with unit). */
						__( 'Up to %s per order.', 'moksa-points-for-woocommerce' ),
						Label::format( $max )
					);
				}
				$items[] = array(
					'icon'  => '🛒',
					'title' => __( 'Earn on every purchase', 'moksa-points-for-woocommerce' ),
					'desc'  => $desc,
				);
			}
		}

		// One-off + recurring triggers.
		if ( self::module_on( 'earntriggers' ) ) {
			$triggers = array(
				array(
					'moksafopoi_signup_bonus',
					'🎉',
					__( 'Sign-up bonus', 'moksa-points-for-woocommerce' ),
					/* translators: %s: a points amount with its unit, e.g.「100 點」. */
					__( 'Get %s just for creating an account.', 'moksa-points-for-woocommerce' ),
				),
				array(
					'moksafopoi_first_order_bonus',
					'🥇',
					__( 'First-order bonus', 'moksa-points-for-woocommerce' ),
					/* translators: %s: a points amount with its unit, e.g.「100 點」. */
					__( 'Get an extra %s on your first paid order.', 'moksa-points-for-woocommerce' ),
				),
				array(
					'moksafopoi_checkin_bonus',
					'📅',
					__( 'Daily check-in', 'moksa-points-for-woocommerce' ),
					/* translators: %s: a points amount with its unit, e.g.「100 點」. */
					__( 'Check in once a day for %s.', 'moksa-points-for-woocommerce' ),
				),
				array(
					'moksafopoi_review_bonus',
					'✍️',
					__( 'Write a review', 'moksa-points-for-woocommerce' ),
					/* translators: %s: a points amount with its unit, e.g.「100 點」. */
					__( 'Get %s for an approved product review.', 'moksa-points-for-woocommerce' ),
				),
				array(
					'moksafopoi_birthday_bonus',
					'🎂',
					__( 'Birthday gift', 'moksa-points-for-woocommerce' ),
					/* translators: %s: a points amount with its unit, e.g.「100 點」. */
					__( 'Get %s in your birthday month.', 'moksa-points-for-woocommerce' ),
				),
				array(
					'moksafopoi_anniversary_bonus',
					'💝',
					__( 'Membership anniversary', 'moksa-points-for-woocommerce' ),
					/* translators: %s: a points amount with its unit, e.g.「100 點」. */
					__( 'Get %s each year you stay a member.', 'moksa-points-for-woocommerce' ),
				),
			);
			foreach ( $triggers as $trigger ) {
				list( $option, $icon, $title, $template ) = $trigger;
				$points = (int) get_option( $option, 0 );
				if ( $points <= 0 ) {
					continue; // Not configured → never promised.
				}
				$items[] = array(
					'icon'  => $icon,
					'title' => $title,
					// $template is one of the already-translated strings above; only the amount is filled in.
					'desc'  => sprintf( $template, Label::format( $points ) ),
				);
			}
		}

		// Referral (both sides of the reward, when each is configured).
		if ( self::module_on( 'referral' ) ) {
			$referrer = (int) get_option( 'moksafopoi_referral_reward', 0 );
			$friend   = (int) get_option( 'moksafopoi_referral_friend_reward', 0 );
			if ( $referrer > 0 || $friend > 0 ) {
				$parts = array();
				if ( $referrer > 0 ) {
					$parts[] = sprintf(
						/* translators: %s: points the referrer receives (with unit). */
						__( 'You get %s when a friend you invited completes a qualifying order.', 'moksa-points-for-woocommerce' ),
						Label::format( $referrer )
					);
				}
				if ( $friend > 0 ) {
					$parts[] = sprintf(
						/* translators: %s: points the invited friend receives (with unit). */
						__( 'Your friend gets %s too.', 'moksa-points-for-woocommerce' ),
						Label::format( $friend )
					);
				}
				$items[] = array(
					'icon'  => '🤝',
					'title' => __( 'Invite a friend', 'moksa-points-for-woocommerce' ),
					'desc'  => implode( ' ', $parts ),
				);
			}
		}

		// Per-product / per-category boosts: the amounts are per item, so we describe the mechanism.
		if ( self::module_on( 'productpoints' ) || self::module_on( 'categorypoints' ) ) {
			$items[] = array(
				'icon'  => '⭐',
				'title' => __( 'Bonus-point products', 'moksa-points-for-woocommerce' ),
				'desc'  => __( 'Selected products and categories earn extra points — look for the earning hint on the product page.', 'moksa-points-for-woocommerce' ),
			);
		}

		// A live campaign is the most actionable line there is, so it goes last and loudest.
		if ( self::module_on( 'campaign' ) && class_exists( Campaigns::class ) ) {
			$campaign = Campaigns::active();
			if ( null !== $campaign && $campaign['points_mult'] > 1.0 ) {
				$items[] = array(
					'icon'  => '🔥',
					'title' => (string) $campaign['name'],
					'desc'  => sprintf(
						/* translators: %s: the points multiplier currently running, e.g. "2". */
						__( 'Running now: earn %s× points on every purchase.', 'moksa-points-for-woocommerce' ),
						self::trim_number( (float) $campaign['points_mult'] )
					),
				);
			}
		}

		/**
		 * Filter the「如何賺點」guide rows (e.g. to add a channel a sibling plugin owns).
		 *
		 * @param array<int,array{icon:string,title:string,desc:string}> $items
		 */
		return (array) apply_filters( 'moksafopoi_how_to_earn_items', $items );
	}

	/**
	 * Render the guide. Returns '' when the shop has nothing configured, so an empty panel never
	 * appears on the storefront.
	 */
	public static function html( bool $with_title = true ): string {
		$items = self::items();
		if ( array() === $items ) {
			return '';
		}

		$out = '<div class="moksafopoi-howto">';
		if ( $with_title ) {
			$out .= '<h3 class="moksafopoi-howto__title">' . esc_html__( 'How to earn points', 'moksa-points-for-woocommerce' ) . '</h3>';
		}
		$out .= '<ul class="moksafopoi-howto__list">';
		foreach ( $items as $item ) {
			$out .= '<li class="moksafopoi-howto__item">'
				. '<span class="moksafopoi-howto__icon" aria-hidden="true">' . esc_html( (string) ( $item['icon'] ?? '' ) ) . '</span>'
				. '<span class="moksafopoi-howto__body">'
				. '<strong class="moksafopoi-howto__name">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</strong>'
				. '<span class="moksafopoi-howto__desc">' . esc_html( (string) ( $item['desc'] ?? '' ) ) . '</span>'
				. '</span></li>';
		}
		$out .= '</ul></div>';

		return $out;
	}

	/**
	 * `[moksafopoi_how_to_earn]` — the guide anywhere. `title="no"` drops the heading so it can sit
	 * under a block heading of the page's own.
	 *
	 * @param array<string,string>|string $atts
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'title' => 'yes' ), (array) $atts, 'moksafopoi_how_to_earn' );
		return wp_kses_post( self::html( 'no' !== $atts['title'] ) );
	}

	/** Echo the guide under the「我的點數」hero. */
	public static function render_account(): void {
		echo wp_kses_post( self::html() );
	}

	/** Is a module switched on? (same option contract as ModuleRegistry). */
	private static function module_on( string $key ): bool {
		return 'yes' === get_option( sprintf( 'moksafopoi_%s_enabled', $key ), 'no' );
	}

	/** Format a rate without a trailing ".0" for whole numbers (1.0 → "1", 1.5 → "1.5"). */
	private static function trim_number( float $value ): string {
		return ( floor( $value ) === $value )
			? number_format_i18n( (int) $value )
			: rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
