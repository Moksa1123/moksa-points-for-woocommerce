<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\LineNotify;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Label;

defined( 'ABSPATH' ) || exit;

/**
 * LINE 通知 — push points events to a member's LINE via the **Messaging API**.
 *
 * Deliberately NOT LINE Notify: that service was shut down in 2025, and any plugin still shipping it
 * is shipping a dead integration. The Messaging API needs a channel access token and pushes to a
 * LINE `userId`.
 *
 * Who owns the binding: **moformember**. A member links their LINE account there (or through any
 * other plugin); we only READ the stored id, from a configurable user-meta key or the
 * `moksafopoi_line_user_id` filter. moforpoints never asks for a LINE login, never stores a LINE
 * profile, and silently sends nothing for members who have not linked — no binding, no message.
 *
 * Delivery is queued to cron for the same reason as the webhooks: a messaging API must never be able
 * to slow a checkout down, and it is sent with `wp_safe_remote_post`.
 */
final class Module extends AbstractModule {

	/** Cron hook one queued push runs on. */
	public const CRON_HOOK = 'moksafopoi_line_push';

	/** LINE Messaging API push endpoint. */
	private const ENDPOINT = 'https://api.line.me/v2/bot/message/push';

	/** Default user-meta key holding the member's LINE userId (moformember's binding). */
	private const DEFAULT_META = '_moformember_line_user_id';

	/** LINE hard-caps a text message at 5000 characters. */
	private const MAX_TEXT = 5000;

	public function slug(): string {
		return 'linenotify';
	}

	public function label(): string {
		return __( 'LINE notifications', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'admin';
	}

	public function tagline(): string {
		return __( 'Push points earned / redeemed / expiring messages to members over the LINE Messaging API (members link their LINE account in the membership plugin; unlinked members are skipped)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'push' ), 10, 2 );

		if ( '' === self::token() ) {
			return; // No channel token → the integration is not configured; listen to nothing.
		}

		add_action( 'moksafopoi_points_earned', array( self::class, 'on_earned' ), 30, 3 );
		add_action( 'moksafopoi_points_redeemed', array( self::class, 'on_redeemed' ), 30, 3 );
		add_action( 'moksafopoi_points_expiring_soon', array( self::class, 'on_expiring' ), 30, 3 );
	}

	/* ---------------------------------------------------------------- listeners */

	/**
	 * @param int $user_id
	 * @param int $points
	 */
	public static function on_earned( $user_id, $points, $source = '' ): void {
		if ( 'yes' !== get_option( 'moksafopoi_line_on_earned', 'no' ) ) {
			return;
		}
		$user_id = (int) $user_id;
		self::queue(
			$user_id,
			sprintf(
				/* translators: 1: points just earned, with unit; 2: the member's new balance, with unit. */
				__( 'You earned %1$s. Your balance is now %2$s.', 'moksa-points-for-woocommerce' ),
				Label::format( (int) $points ),
				Label::format( Api::get_points( $user_id ) )
			)
		);
	}

	/**
	 * @param int $user_id
	 * @param int $points
	 */
	public static function on_redeemed( $user_id, $points, $source = '' ): void {
		if ( 'yes' !== get_option( 'moksafopoi_line_on_redeemed', 'no' ) ) {
			return;
		}
		$user_id = (int) $user_id;
		self::queue(
			$user_id,
			sprintf(
				/* translators: 1: points just spent, with unit; 2: the member's remaining balance, with unit. */
				__( 'You redeemed %1$s. Your balance is now %2$s.', 'moksa-points-for-woocommerce' ),
				Label::format( (int) $points ),
				Label::format( Api::get_points( $user_id ) )
			)
		);
	}

	/**
	 * @param int    $user_id
	 * @param int    $points      Points expiring in this batch.
	 * @param string $next_expiry Soonest expiry datetime.
	 */
	public static function on_expiring( $user_id, $points, $next_expiry = '' ): void {
		if ( 'yes' !== get_option( 'moksafopoi_line_on_expiring', 'no' ) ) {
			return;
		}
		$date = '' !== (string) $next_expiry ? mysql2date( 'Y-m-d', (string) $next_expiry ) : '';
		self::queue(
			(int) $user_id,
			sprintf(
				/* translators: 1: points about to expire, with unit; 2: the expiry date. */
				__( '%1$s expire on %2$s — use them before they are gone.', 'moksa-points-for-woocommerce' ),
				Label::format( (int) $points ),
				$date
			)
		);
	}

	/* ---------------------------------------------------------------- queue + push */

	/**
	 * Queue a push for a member. Silently does nothing when the shop has no token or the member has
	 * not linked LINE — an unlinked member is the normal case, not an error.
	 */
	public static function queue( int $user_id, string $text ): void {
		if ( $user_id <= 0 || '' === $text || '' === self::token() ) {
			return;
		}
		$to = self::line_id( $user_id );
		if ( '' === $to ) {
			return;
		}

		wp_schedule_single_event( time(), self::CRON_HOOK, array( $to, mb_substr( $text, 0, self::MAX_TEXT ) ) );
	}

	/**
	 * Deliver one queued push. Runs on cron, never inside a customer request.
	 *
	 * @param string $to   LINE userId.
	 * @param string $text Message body.
	 */
	public static function push( $to, $text ): void {
		$token = self::token();
		$to    = (string) $to;
		if ( '' === $token || '' === $to ) {
			return;
		}

		wp_safe_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'        => (string) wp_json_encode(
					array(
						'to'       => $to,
						'messages' => array(
							array(
								'type' => 'text',
								'text' => mb_substr( (string) $text, 0, self::MAX_TEXT ),
							),
						),
					)
				),
			)
		);
	}

	/* ---------------------------------------------------------------- config */

	/** The channel access token, trimmed ('' when unset). */
	public static function token(): string {
		return trim( (string) get_option( 'moksafopoi_line_token', '' ) );
	}

	/**
	 * A member's LINE userId, from the configured user-meta key (moformember's binding by default).
	 * Filterable so any plugin that owns the binding can supply it instead.
	 */
	public static function line_id( int $user_id ): string {
		$key = trim( (string) get_option( 'moksafopoi_line_meta_key', '' ) );
		if ( '' === $key ) {
			$key = self::DEFAULT_META;
		}
		$id = (string) get_user_meta( $user_id, $key, true );

		/**
		 * Filter the LINE userId used for a member (the binding is owned by another plugin).
		 *
		 * @param string $id      The id read from user-meta ('' when unlinked).
		 * @param int    $user_id
		 */
		$id = (string) apply_filters( 'moksafopoi_line_user_id', $id, $user_id );

		return trim( $id );
	}
}
