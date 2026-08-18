<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Webhooks;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 對外 Webhooks — push points events to an ESP / CRM / automation platform (Klaviyo, Brevo, n8n,
 * Zapier, a LINE Messaging bot…) so the loyalty programme can drive e-mail and messaging flows the
 * shop already runs elsewhere.
 *
 * Design decisions that matter:
 *
 *   • **Never blocking.** Events are queued to a one-off cron tick, so a slow (or dead) endpoint can
 *     never delay checkout — the single worst way to ship webhooks.
 *   • **Signed.** Each delivery carries `X-Moksafopoi-Signature: sha256=<hmac>` over the exact body,
 *     so the receiver can verify it really came from this site.
 *   • **SSRF-safe.** Delivery uses `wp_safe_remote_post()`, so an endpoint pointed (by mistake or by
 *     an attacker who got into the settings) at an internal address is refused by WordPress itself.
 *   • **PDPA-aware.** The payload is keyed by `user_id`; the member's e-mail is included ONLY when
 *     the operator explicitly ticks it, because most ESPs need it but no law requires us to leak it.
 *
 * No CPT, no table: configuration is plain options and the recent-delivery log is one capped option.
 */
final class Module extends AbstractModule {

	/** The cron hook a queued delivery runs on. */
	public const CRON_HOOK = 'moksafopoi_webhook_send';

	/** Option holding the recent delivery log (capped). */
	public const LOG_OPTION = 'moksafopoi_webhook_log';

	/** How many deliveries the log keeps. */
	private const LOG_MAX = 20;

	/**
	 * The events a shop can subscribe to. Key = option suffix + payload `event`.
	 *
	 * @return array<string,string>
	 */
	public static function events(): array {
		return array(
			'points_earned'   => __( 'Points earned', 'moksa-points-for-woocommerce' ),
			'points_redeemed' => __( 'Points redeemed', 'moksa-points-for-woocommerce' ),
			'points_expiring' => __( 'Points expiring soon', 'moksa-points-for-woocommerce' ),
			'quest_completed' => __( 'Quest completed', 'moksa-points-for-woocommerce' ),
		);
	}

	public function slug(): string {
		return 'webhooks';
	}

	public function label(): string {
		return __( 'Outbound webhooks', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'admin';
	}

	public function tagline(): string {
		return __( 'Push points events (earned / redeemed / expiring / quest completed) to an ESP, CRM or automation platform; signed, queued, and never blocking checkout', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'deliver' ), 10, 2 );

		if ( '' === self::endpoint() ) {
			return; // Nothing configured → listen to nothing.
		}

		add_action( 'moksafopoi_points_earned', array( self::class, 'on_earned' ), 20, 4 );
		add_action( 'moksafopoi_points_redeemed', array( self::class, 'on_redeemed' ), 20, 4 );
		add_action( 'moksafopoi_points_expiring_soon', array( self::class, 'on_expiring' ), 20, 2 );
		add_action( 'moksafopoi_quest_completed', array( self::class, 'on_quest' ), 20, 3 );
	}

	/* ---------------------------------------------------------------- listeners */

	/**
	 * @param int    $user_id
	 * @param int    $points
	 * @param string $source
	 * @param array<string,mixed> $args
	 */
	public static function on_earned( $user_id, $points, $source = '', $args = array() ): void {
		self::queue(
			'points_earned',
			(int) $user_id,
			array(
				'points'   => (int) $points,
				'source'   => (string) $source,
				'order_id' => isset( $args['order_id'] ) ? (int) $args['order_id'] : 0,
			)
		);
	}

	/**
	 * @param int    $user_id
	 * @param int    $points
	 * @param string $source
	 * @param array<string,mixed> $args
	 */
	public static function on_redeemed( $user_id, $points, $source = '', $args = array() ): void {
		self::queue(
			'points_redeemed',
			(int) $user_id,
			array(
				'points'   => (int) $points,
				'source'   => (string) $source,
				'order_id' => isset( $args['order_id'] ) ? (int) $args['order_id'] : 0,
			)
		);
	}

	/**
	 * @param int $user_id
	 * @param int $points Points about to expire.
	 */
	public static function on_expiring( $user_id, $points ): void {
		self::queue( 'points_expiring', (int) $user_id, array( 'points' => (int) $points ) );
	}

	/**
	 * @param int    $user_id
	 * @param string $key    Quest key.
	 * @param int    $reward
	 */
	public static function on_quest( $user_id, $key = '', $reward = 0 ): void {
		self::queue(
			'quest_completed',
			(int) $user_id,
			array(
				'quest'  => (string) $key,
				'points' => (int) $reward,
			)
		);
	}

	/* ---------------------------------------------------------------- queue + deliver */

	/**
	 * Queue one event for asynchronous delivery. Nothing is sent inline: the caller (a checkout, a
	 * cron sweep) must never wait on a third party.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function queue( string $event, int $user_id, array $data ): void {
		if ( $user_id <= 0 || '' === self::endpoint() || ! self::subscribed( $event ) ) {
			return;
		}

		$payload = array(
			'event'   => $event,
			'site'    => home_url( '/' ),
			'user_id' => $user_id,
			'balance' => Api::get_points( $user_id ),
			'credit'  => Api::get_balance( $user_id ),
			'ts'      => time(),
			'data'    => $data,
		);

		if ( 'yes' === get_option( 'moksafopoi_webhook_include_email', 'no' ) ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				$payload['email'] = $user->user_email;
			}
		}

		/**
		 * Filter a webhook payload before it is queued.
		 *
		 * @param array<string,mixed> $payload
		 * @param string              $event
		 */
		$payload = (array) apply_filters( 'moksafopoi_webhook_payload', $payload, $event );

		// A single-event schedule in the past runs on the very next cron tick — as close to "now" as
		// WordPress gets without blocking the current request.
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $event, $payload ) );
	}

	/**
	 * Deliver one queued event. Runs on cron, never in a customer request.
	 *
	 * @param string              $event
	 * @param array<string,mixed> $payload
	 */
	public static function deliver( $event, $payload ): void {
		$url = self::endpoint();
		if ( '' === $url || ! is_array( $payload ) ) {
			return;
		}

		$body    = (string) wp_json_encode( $payload );
		$headers = array(
			'Content-Type'      => 'application/json; charset=utf-8',
			'X-Moksafopoi-Event' => (string) $event,
		);

		$secret = (string) get_option( 'moksafopoi_webhook_secret', '' );
		if ( '' !== $secret ) {
			$headers['X-Moksafopoi-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		// wp_safe_remote_post refuses internal / loopback hosts, so a mistyped or malicious endpoint
		// cannot be turned into a request against this server's own network.
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'headers'     => $headers,
				'body'        => $body,
				'redirection' => 0,
			)
		);

		$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$error = is_wp_error( $response ) ? $response->get_error_message() : '';

		self::log( (string) $event, $code, $error );
	}

	/* ---------------------------------------------------------------- config + log */

	/** The configured endpoint, or '' when unset / not a valid http(s) URL. */
	public static function endpoint(): string {
		$url = trim( (string) get_option( 'moksafopoi_webhook_url', '' ) );
		if ( '' === $url ) {
			return '';
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		return is_string( $url ) ? $url : '';
	}

	/** Is the shop subscribed to this event? */
	private static function subscribed( string $event ): bool {
		if ( ! array_key_exists( $event, self::events() ) ) {
			return false;
		}
		return 'yes' === get_option( 'moksafopoi_webhook_' . $event, 'no' );
	}

	/** Append one delivery result to the capped log. */
	private static function log( string $event, int $code, string $error ): void {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				't'     => gmdate( 'Y-m-d H:i:s' ),
				'event' => $event,
				'code'  => $code,
				'error' => mb_substr( $error, 0, 200 ),
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/**
	 * The recent deliveries, newest first (for the settings screen).
	 *
	 * @return array<int,array{t:string,event:string,code:int,error:string}>
	 */
	public static function recent_log(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}
}
