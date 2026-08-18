<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Transfer;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * 點數轉贈 — the `[moksafopoi_transfer]` front-end form letting a member send points to another member.
 * A PRG submit (nonce + rate-limited) hands off to {@see Service::transfer}, where the debit is serialised
 * per user with a GET_LOCK so concurrent transfers cannot double-spend. Destructive by nature, so it is a
 * front-end form only — it is deliberately NOT registered as an ability / exposed to the MCP surface.
 */
final class Module extends AbstractModule {

	private const SHORTCODE    = 'moksafopoi_transfer';
	private const NONCE        = 'moksafopoi_transfer';
	private const FLASH_PREFIX = 'moksafopoi_transfer_flash_';
	private const RL_PREFIX    = 'moksafopoi_transfer_rl_';
	private const RL_MAX       = 30;                 // submits…
	private const RL_WINDOW    = HOUR_IN_SECONDS;    // …per hour, per member (brute-force / spam guard).

	public function slug(): string {
		return 'transfer';
	}

	public function label(): string {
		return __( 'Points transfer', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'redeem';
	}

	public function tagline(): string {
		return __( 'Transfer points between members (shortcode [moksafopoi_transfer]). Deduction uses a per-user lock to prevent concurrent double-spending, conserves the amount, and supports per-transfer min/max limits and a daily count.', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_handle' ) );
	}

	/** Handle a submitted transfer form (PRG: process then redirect to avoid resubmission). */
	public static function maybe_handle(): void {
		if ( ! isset( $_POST['mfp_transfer_submit'] ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$nonce = isset( $_POST['_mfp_transfer_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_mfp_transfer_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		$redirect = wp_get_referer() ? wp_get_referer() : home_url( add_query_arg( array() ) );

		// Rate limit (spam / brute-force on recipient guessing).
		$rl_key   = self::RL_PREFIX . $user_id;
		$attempts = (int) get_transient( $rl_key );
		if ( $attempts >= self::RL_MAX ) {
			self::flash( $user_id, false, __( 'Too many actions; please try again later.', 'moksa-points-for-woocommerce' ) );
			wp_safe_redirect( $redirect );
			exit;
		}
		set_transient( $rl_key, $attempts + 1, self::RL_WINDOW );

		$recipient_raw = isset( $_POST['mfp_transfer_recipient'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mfp_transfer_recipient'] ) ) : '';
		$points        = isset( $_POST['mfp_transfer_points'] ) ? (int) wp_unslash( $_POST['mfp_transfer_points'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int.
		$note          = isset( $_POST['mfp_transfer_note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mfp_transfer_note'] ) ) : '';

		$to     = Service::resolve_recipient( $recipient_raw );
		$result = Service::transfer( $user_id, $to, $points, $note );

		if ( $result instanceof WP_Error ) {
			self::flash( $user_id, false, $result->get_error_message() );
		} else {
			self::flash(
				$user_id,
				true,
				sprintf(
					/* translators: 1: points, 2: recipient identifier. */
					__( 'Successfully transferred %1$d point(s) to %2$s.', 'moksa-points-for-woocommerce' ),
					(int) $result['points'],
					$recipient_raw
				)
			);
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Render the transfer form (+ any flash message + current balance). */
	public static function render(): string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '<div class="moksafopoi-transfer"><p>' . esc_html__( 'Please log in to transfer points.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		}

		$balance = Api::get_points( $user_id );
		$out     = '<div class="moksafopoi-transfer">';

		$flash = get_transient( self::FLASH_PREFIX . $user_id );
		if ( is_array( $flash ) ) {
			delete_transient( self::FLASH_PREFIX . $user_id );
			$ok   = ! empty( $flash['ok'] );
			$out .= '<p class="moksafopoi-transfer-flash" style="padding:8px 12px;border-radius:4px;background:'
				. esc_attr( $ok ? '#e6f4ea' : '#fce8e6' ) . ';color:' . esc_attr( $ok ? '#116329' : '#8a1f11' ) . '">'
				. esc_html( (string) ( $flash['message'] ?? '' ) ) . '</p>';
		}

		$out .= '<p class="moksafopoi-transfer-balance">'
			. esc_html( sprintf( /* translators: %s: points balance. */ __( 'You currently have %s point(s)', 'moksa-points-for-woocommerce' ), number_format_i18n( $balance ) ) )
			. '</p>';

		$out .= '<form method="post" class="moksafopoi-transfer-form">';
		$out .= wp_nonce_field( self::NONCE, '_mfp_transfer_nonce', true, false );
		$out .= '<p><label for="mfp_transfer_recipient">' . esc_html__( 'Recipient member (email or username)', 'moksa-points-for-woocommerce' ) . '</label><br>'
			. '<input type="text" name="mfp_transfer_recipient" id="mfp_transfer_recipient" class="input-text" autocomplete="off" required></p>';
		$out .= '<p><label for="mfp_transfer_points">' . esc_html__( 'Transfer points', 'moksa-points-for-woocommerce' ) . '</label><br>'
			. '<input type="number" name="mfp_transfer_points" id="mfp_transfer_points" min="' . esc_attr( (string) Service::min_points() ) . '" step="1" required></p>';
		$out .= '<p><label for="mfp_transfer_note">' . esc_html__( 'Note (optional)', 'moksa-points-for-woocommerce' ) . '</label><br>'
			. '<input type="text" name="mfp_transfer_note" id="mfp_transfer_note" maxlength="120" class="input-text"></p>';
		$out .= '<p><button type="submit" name="mfp_transfer_submit" value="1" class="button">' . esc_html__( 'Confirm transfer', 'moksa-points-for-woocommerce' ) . '</button></p>';
		$out .= '</form></div>';

		return $out;
	}

	private static function flash( int $user_id, bool $ok, string $message ): void {
		set_transient(
			self::FLASH_PREFIX . $user_id,
			array( 'ok' => $ok, 'message' => $message ),
			MINUTE_IN_SECONDS
		);
	}
}
