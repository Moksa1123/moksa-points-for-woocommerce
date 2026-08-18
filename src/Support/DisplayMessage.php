<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 顯示客製化訊息範本引擎 — the single place that turns an operator-editable message template
 * (stored as a plain option, never a CPT) into safe, escaped HTML for a storefront surface
 * (shop loop / single product / cart / my-account).
 *
 * A template may contain three placeholders:
 *
 *   {points}        the estimated points amount (thousands-grouped integer, e.g. 1,250)
 *   {points_label}  the customised unit name from {@see Label::unit()} (點 / 金幣 / 哩程 / P)
 *   {value}         the cash value those points redeem to, formatted as money (e.g. NT$12)
 *
 * Escaping contract (defence in depth):
 *   • each substituted VALUE is run through esc_html() before it is injected, so a points
 *     amount or unit can never carry markup;
 *   • the whole template is then passed through wp_kses_post(), so an operator may use basic
 *     inline tags (<strong>, <em>, <a>…) in the copy but nothing dangerous survives.
 *
 * The {value} figure reuses the SAME redeem ratio the checkout wallet / account summary use
 * (`moksafopoi_redeem_rate`, "Points per NT$1 redeemed"), so the number a shopper is teased with matches
 * what they can actually redeem. floor() keeps it house-safe (never over-promises).
 */
final class DisplayMessage {

	/**
	 * Render a message template for a given points figure into fully-escaped HTML.
	 *
	 * @param string $template The raw operator template (may contain the placeholders + basic HTML).
	 * @param int    $points   The points amount to substitute for {points} / drive {value}.
	 * @return string Safe HTML ready to echo (already esc + wp_kses_post). Empty when blank.
	 */
	public static function render( string $template, int $points ): string {
		$template = trim( $template );
		if ( '' === $template ) {
			return '';
		}

		$points = max( 0, $points );

		$replacements = array(
			'{points}'       => esc_html( number_format( $points ) ),
			'{points_label}' => esc_html( Label::unit() ),
			'{value}'        => esc_html( self::value_label( $points ) ),
		);

		$rendered = strtr( $template, $replacements );

		// Allow basic post markup in the operator copy; strip anything unsafe. The substituted
		// values were individually esc_html()'d above, so they cannot smuggle in tags.
		return wp_kses_post( $rendered );
	}

	/**
	 * Build the inline `style="…"` attribute (incl. the leading space, ready to drop into a tag) for a
	 * display location's customised colours, or an empty string when nothing is customised. 顯示客製化:
	 * each location stores a text colour (moksafopoi_disp_{loc}_text_color) and a background colour
	 * (moksafopoi_disp_{loc}_bg_color). Both are read through sanitize_hex_color so a tampered option can
	 * never inject anything but a #rrggbb value, and the whole thing is esc_attr()'d before output.
	 *
	 * @param string $loc One of loop|single|cart|account (the display location slug).
	 * @return string Either '' or a string like ' style="color:#000000;background-color:#fff8e1"'.
	 */
	public static function style_attr( string $loc ): string {
		$text   = sanitize_hex_color( (string) get_option( 'moksafopoi_disp_' . $loc . '_text_color', '' ) );
		$bg     = sanitize_hex_color( (string) get_option( 'moksafopoi_disp_' . $loc . '_bg_color', '' ) );
		$border = sanitize_hex_color( (string) get_option( 'moksafopoi_disp_' . $loc . '_border_color', '' ) );

		$rules   = array();
		$has_bg  = is_string( $bg ) && '' !== $bg;
		$has_brd = is_string( $border ) && '' !== $border;
		if ( is_string( $text ) && '' !== $text ) {
			$rules[] = 'color:' . $text;
		}
		if ( $has_bg ) {
			$rules[] = 'background-color:' . $bg;
		}
		if ( $has_brd ) {
			$rules[] = 'border:1px solid ' . $border;
		}
		// A box colour (background or border) turns the line into a badge, so give it padding + rounding.
		if ( $has_bg || $has_brd ) {
			$rules[] = 'display:inline-block';
			$rules[] = 'padding:4px 10px';
			$rules[] = 'border-radius:4px';
		}
		if ( array() === $rules ) {
			return '';
		}

		return ' style="' . esc_attr( implode( ';', $rules ) ) . '"';
	}

	/**
	 * The operator's optional leading icon for a display location (顯示客製化), escaped and with a
	 * trailing space, or '' when none. Stored as a short free-text option — an emoji (🎁 / ⭐) or a couple
	 * of characters; capped at 8 chars and esc_html'd so it can never carry markup.
	 *
	 * @param string $loc One of single|loop|cart|account (the display location slug).
	 */
	public static function icon( string $loc ): string {
		$icon = trim( (string) get_option( 'moksafopoi_disp_' . $loc . '_icon', '' ) );
		if ( '' === $icon ) {
			return '';
		}
		$icon = function_exists( 'mb_substr' ) ? mb_substr( $icon, 0, 8 ) : substr( $icon, 0, 8 );
		return '<span class="moksafopoi-disp-icon" aria-hidden="true">' . esc_html( $icon ) . '</span> ';
	}

	/**
	 * Whether a display location should render for the CURRENT visitor, per its 條件顯隱 option
	 * (moksafopoi_disp_{loc}_visible_to): all | logged_in | guests | members | non_members. Membership
	 * is resolved through moformember when installed; without it, 'members' ≈ logged-in and
	 * 'non_members' ≈ guests (best effort, never fatal).
	 *
	 * @param string $loc One of single|loop|cart|account.
	 */
	public static function is_visible( string $loc ): bool {
		$cond = (string) get_option( 'moksafopoi_disp_' . $loc . '_visible_to', 'all' );
		switch ( $cond ) {
			case 'logged_in':
				return is_user_logged_in();
			case 'guests':
				return ! is_user_logged_in();
			case 'members':
				return self::is_member();
			case 'non_members':
				return ! self::is_member();
			case 'all':
			default:
				return true;
		}
	}

	/** Best-effort「目前訪客是不是會員」: moformember when present, else falls back to logged-in. */
	private static function is_member(): bool {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		if ( function_exists( 'moformember_is_member' ) ) {
			return (bool) moformember_is_member( $uid );
		}
		return is_user_logged_in();
	}

	/**
	 * The cash value `$points` redeem to, as a plain money string (e.g.「NT$12」), using the configured
	 * redeem rate ("Points per NT$1 redeemed"). floor() so we never over-state the value. Kept as plain "NT$N" text
	 * (the TW whole-dollar convention used by My Account / Mall) so render()'s esc_html() never has to
	 * double-encode wc_price() currency entities.
	 */
	public static function value_label( int $points ): string {
		$rate  = Rates::redeem_rate();
		$worth = (int) floor( max( 0, $points ) / $rate );

		return 'NT$' . number_format( $worth );
	}
}
