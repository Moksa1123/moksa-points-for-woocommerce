<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use Moksafopoi\Api;

defined( 'ABSPATH' ) || exit;

/**
 * 顯示型短碼家族 — the composition primitives page builders expect (myCred 的
 * [mycred_my_balance] / [mycred_show_if] 對應物),讓店主能在任何頁面嵌入餘額、或依餘額
 * 條件顯示一段內容(全部 server-side 判斷,不洩漏給未達門檻的訪客):
 *
 *   [moksafopoi_balance]                     → 1,234 點
 *   [moksafopoi_balance unit="no"]           → 1,234
 *   [moksafopoi_show_if points="500"]VIP 內容[/moksafopoi_show_if]
 *   [moksafopoi_show_if points="0" max="499"]升級提示[/moksafopoi_show_if]
 *
 * Registered always-on (a shortcode that never appears in content costs nothing); guests
 * render nothing / hidden. Read-only.
 */
final class Shortcodes {

	public static function register(): void {
		add_shortcode( 'moksafopoi_balance', array( self::class, 'balance' ) );
		add_shortcode( 'moksafopoi_show_if', array( self::class, 'show_if' ) );
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public static function balance( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$atts   = shortcode_atts( array( 'unit' => 'yes' ), (array) $atts, 'moksafopoi_balance' );
		$points = Api::get_points( get_current_user_id() );
		$text   = number_format_i18n( $points );
		if ( 'no' !== (string) $atts['unit'] ) {
			$text .= ' ' . Label::unit();
		}
		return esc_html( $text );
	}

	/**
	 * @param array<string,string>|string $atts
	 * @param string|null                 $content
	 */
	public static function show_if( $atts = array(), $content = null ): string {
		if ( null === $content || '' === $content || ! is_user_logged_in() ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'points' => '0',
				'max'    => '',
			),
			(array) $atts,
			'moksafopoi_show_if'
		);

		$balance = Api::get_points( get_current_user_id() );
		$min     = max( 0, (int) $atts['points'] );
		if ( $balance < $min ) {
			return '';
		}
		if ( '' !== (string) $atts['max'] && $balance > (int) $atts['max'] ) {
			return '';
		}
		return do_shortcode( $content );
	}
}
