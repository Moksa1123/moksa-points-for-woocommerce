<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 統一 HTML 信件範本 — the single wrapper every customer-facing e-mail this plugin sends is
 * piped through, so every message shares one branded shell (header band / white body / muted
 * footer) and one Content-Type. Structurally aligned with moformember's mail so a store running
 * both plugins gets a visually consistent inbox.
 *
 * All styling is INLINE (mail clients drop <style> blocks) and the markup stays deliberately
 * table-free-but-simple: a 600px centred column with three stacked <div>s. The brand colour and
 * header text are plain options ({@see self::accent()} / {@see self::header_text()}); never a CPT.
 *
 * Callers pass an already-escaped/whitelisted $body_html fragment to {@see self::wrap()} — this
 * class does NOT escape $body_html (that is the caller's job, since the body is HTML by design),
 * but it DOES escape every value it itself interpolates (accent colour, header text, footer).
 */
final class EmailTemplate {

	/** Option holding the brand accent colour used for the header band. */
	public const OPTION_ACCENT = 'moksafopoi_email_accent';

	/** Option holding the header band text (defaults to the site name). */
	public const OPTION_HEADER = 'moksafopoi_email_header';

	/** Built-in accent when the option is unset / invalid (Moksa gold). */
	public const DEFAULT_ACCENT = '#d4af37';

	/**
	 * Wrap a body HTML fragment in the branded shell.
	 *
	 * @param string $subject   Plain-text subject, used as the document <title> (escaped here).
	 * @param string $body_html Caller-supplied, already-safe HTML fragment for the white body.
	 * @return string Full HTML document ready for wp_mail (Content-Type text/html).
	 */
	public static function wrap( string $subject, string $body_html ): string {
		$accent      = self::accent();
		$header_text = self::header_text();
		$site        = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$home        = home_url( '/' );

		$footer = sprintf(
			/* translators: 1: site name; 2: site home URL. */
			esc_html__( '%1$s · %2$s', 'moksa-points-for-woocommerce' ),
			esc_html( $site ),
			esc_html( $home )
		);

		$html  = '<!DOCTYPE html><html lang="' . esc_attr( str_replace( '_', '-', (string) get_locale() ) ) . '"><head>';
		$html .= '<meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		$html .= '<title>' . esc_html( $subject ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f0f0f0;">';
		$html .= '<div style="max-width:600px;margin:0 auto;font-family:-apple-system,\'Segoe UI\',\'Helvetica Neue\',Arial,\'PingFang TC\',\'Microsoft JhengHei\',sans-serif;">';

		// Header band.
		$html .= '<div style="background:' . esc_attr( $accent ) . ';color:#fff;padding:20px;font-weight:600;font-size:18px;">'
			. esc_html( $header_text ) . '</div>';

		// White body (caller-supplied, already-safe HTML).
		$html .= '<div style="background:#fff;padding:24px;color:#333;line-height:1.7;font-size:15px;">'
			. $body_html . '</div>';

		// Muted footer.
		$html .= '<div style="background:#f5f5f5;color:#888;font-size:12px;text-align:center;padding:16px;">'
			. $footer . '</div>';

		$html .= '</div></body></html>';

		return $html;
	}

	/**
	 * The wp_mail headers that make a message render as HTML.
	 *
	 * @return array<int,string>
	 */
	public static function html_headers(): array {
		return array( 'Content-Type: text/html; charset=UTF-8' );
	}

	/**
	 * The configured header band text. Empty / unset falls back to the site name so the band is
	 * never blank. Decoded so an ampersand in the site name reads correctly (it is re-escaped at
	 * render time in {@see self::wrap()}).
	 */
	public static function header_text(): string {
		$raw  = (string) get_option( self::OPTION_HEADER, '' );
		$text = trim( $raw );
		if ( '' === $text ) {
			$text = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return $text;
	}

	/**
	 * The configured accent colour for the header band. Validated with sanitize_hex_color so a
	 * tampered / blank option can never inject arbitrary CSS; an invalid value falls back to the
	 * built-in gold default.
	 */
	public static function accent(): string {
		$raw   = (string) get_option( self::OPTION_ACCENT, self::DEFAULT_ACCENT );
		$accent = sanitize_hex_color( trim( $raw ) );
		if ( ! is_string( $accent ) || '' === $accent ) {
			$accent = self::DEFAULT_ACCENT;
		}
		return $accent;
	}
}
