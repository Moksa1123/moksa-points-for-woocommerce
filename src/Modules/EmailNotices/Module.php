<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\EmailNotices;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\EmailTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * 事件式通知信 — the myCred "Email Notices" engine the plugin lacked (it only sent the one fixed
 * expiry-reminder mail). An operator turns on a mail per points EVENT (賺點 / 兌點) and writes its own
 * subject + body with placeholders; when that event fires we render + send it through the shared
 * {@see EmailTemplate} shell, gated by a minimum amount and throttled per member so a burst of writes
 * cannot spam. Bound to options (NO CPT); reuses the existing `moksafopoi_points_earned/redeemed`
 * signals — no new firing points, no ledger changes.
 *
 * Placeholders: {points} {balance} {note} {name} {site}.
 */
final class Module extends AbstractModule {

	/**
	 * Event key => the do_action it listens on. Adding an event is a one-line extension.
	 *
	 * @var array<string,string>
	 */
	private const EVENTS = array(
		'earned'   => 'moksafopoi_points_earned',
		'redeemed' => 'moksafopoi_points_redeemed',
	);

	public function slug(): string {
		return 'emailnotices';
	}

	public function label(): string {
		return __( 'Event-based notification emails', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'email';
	}

	public function tagline(): string {
		return __( 'Custom notification emails triggered by earning / redemption events (configure recipients, amount thresholds, throttling). Placeholders: {points} {balance} {note} {name} {site}.', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		foreach ( self::EVENTS as $key => $hook ) {
			add_action(
				$hook,
				static function ( $user_id, $points, $source = '', $args = array() ) use ( $key ) {
					self::handle( $key, (int) $user_id, (int) $points, is_array( $args ) ? $args : array() );
				},
				20,
				4
			);
		}
	}

	/**
	 * Dispatch the mail configured for an event, when enabled, above its threshold, and not throttled.
	 *
	 * @param array<string,mixed> $args The ledger event args (may carry a 'note').
	 */
	public static function handle( string $event_key, int $user_id, int $points, array $args ): void {
		if ( $user_id <= 0 || ! isset( self::EVENTS[ $event_key ] ) ) {
			return;
		}
		$rule = self::rule( $event_key );
		if ( ! $rule['enabled'] ) {
			return;
		}
		if ( $points < $rule['min'] ) {
			return;
		}
		if ( self::throttled( $event_key, $user_id, $rule['throttle'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$replacements = array(
			'{points}'  => number_format_i18n( $points ),
			'{balance}' => number_format_i18n( Ledger::points( $user_id ) ),
			'{note}'    => (string) ( $args['note'] ?? '' ),
			'{name}'    => $user->display_name ? $user->display_name : $user->user_login,
			'{site}'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		$subject = self::fill( $rule['subject'], $replacements, false );
		$body    = wp_kses_post( nl2br( self::fill( $rule['body'], $replacements, true ) ) );
		$html    = EmailTemplate::wrap( $subject, '<p>' . $body . '</p>' );

		$recipients = self::recipients( $rule['recipient'], (string) $user->user_email );
		if ( empty( $recipients ) ) {
			return;
		}

		wp_mail( $recipients, $subject, $html, EmailTemplate::html_headers() );
		self::mark_sent( $event_key, $user_id, $rule['throttle'] );

		do_action( 'moksafopoi_email_notice_sent', $event_key, $user_id, $points );
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * The configured rule for an event.
	 *
	 * @return array{enabled:bool,subject:string,body:string,recipient:string,min:int,throttle:int}
	 */
	public static function rule( string $event_key ): array {
		$p = 'moksafopoi_email_' . $event_key . '_';
		return array(
			'enabled'   => 'yes' === (string) get_option( $p . 'enabled', 'no' ),
			'subject'   => self::subject_or_default( $event_key, (string) get_option( $p . 'subject', '' ) ),
			'body'      => self::body_or_default( $event_key, (string) get_option( $p . 'body', '' ) ),
			'recipient' => self::valid_recipient( (string) get_option( $p . 'recipient', 'user' ) ),
			'min'       => max( 0, (int) get_option( $p . 'min', 0 ) ),
			'throttle'  => max( 0, (int) get_option( $p . 'throttle', 0 ) ),
		);
	}

	/** Replace placeholders; values escaped for the target context (attr/subject vs body HTML). */
	private static function fill( string $template, array $replacements, bool $html_context ): string {
		$escaped = array();
		foreach ( $replacements as $k => $v ) {
			$escaped[ $k ] = $html_context ? esc_html( (string) $v ) : sanitize_text_field( (string) $v );
		}
		return strtr( $template, $escaped );
	}

	/** True when a mail for (event,user) was sent within the throttle window. */
	private static function throttled( string $event_key, int $user_id, int $throttle ): bool {
		if ( $throttle <= 0 ) {
			return false;
		}
		return false !== get_transient( self::throttle_key( $event_key, $user_id ) );
	}

	private static function mark_sent( string $event_key, int $user_id, int $throttle ): void {
		if ( $throttle > 0 ) {
			set_transient( self::throttle_key( $event_key, $user_id ), 1, $throttle );
		}
	}

	private static function throttle_key( string $event_key, int $user_id ): string {
		return 'mfp_mail_' . $event_key . '_' . $user_id;
	}

	/**
	 * Resolve the recipient spec to a list of addresses.
	 *
	 * @return array<int,string>
	 */
	private static function recipients( string $spec, string $user_email ): array {
		$admin = (string) get_option( 'admin_email', '' );
		switch ( $spec ) {
			case 'admin':
				return '' !== $admin ? array( $admin ) : array();
			case 'both':
				return array_values( array_filter( array( $user_email, $admin ) ) );
			case 'user':
			default:
				return '' !== $user_email ? array( $user_email ) : array();
		}
	}

	private static function valid_recipient( string $spec ): string {
		return in_array( $spec, array( 'user', 'admin', 'both' ), true ) ? $spec : 'user';
	}

	private static function subject_or_default( string $event_key, string $stored ): string {
		if ( '' !== trim( $stored ) ) {
			return $stored;
		}
		return 'redeemed' === $event_key
			? __( 'You have used {points} points', 'moksa-points-for-woocommerce' )
			: __( 'You earned {points} points', 'moksa-points-for-woocommerce' );
	}

	private static function body_or_default( string $event_key, string $stored ): string {
		if ( '' !== trim( $stored ) ) {
			return $stored;
		}
		return 'redeemed' === $event_key
			? __( "Hi {name},\\nYou just used {points} points and now have {balance} points left.\\nThank you for your support!", 'moksa-points-for-woocommerce' )
			: __( "Hi {name},\\nYou just earned {points} points ({note}) and now have {balance} points total.\\nThank you for your support!", 'moksa-points-for-woocommerce' );
	}
}
