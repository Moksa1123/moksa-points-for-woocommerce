<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\ExpiryReminder;

use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\EmailTemplate;
use Moksafopoi\Support\Label;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The expiry-reminder sweep. Once a day (off the shared heartbeat) it finds every member who
 * holds a positive balance and has earn rows expiring inside the reminder window, then mails
 * each one a heads-up that lists the soon-to-expire amount and its date.
 *
 * Idempotency: each user's soonest in-window expiry timestamp is the batch fingerprint; once a
 * reminder for that bucket is sent we stamp it in user_meta, so a later daily run for the same
 * batch is a no-op. A *new*, sooner expiry (or a later batch) yields a different fingerprint and
 * re-arms the reminder.
 */
final class Reminder {

	/** user_meta key holding the fingerprint of the last expiry batch we mailed about. */
	private const META_SENT = '_moksafopoi_expiry_reminded';

	/** Default lead time (days) before expiry that we warn at. */
	private const DEFAULT_DAYS = 14;

	/** Safety cap on how many users we mail per daily run (avoids a mail storm on huge stores). */
	private const MAX_PER_RUN = 500;

	public static function run(): void {
		$months = (int) get_option( 'moksafopoi_points_expire_months', 0 );
		if ( $months <= 0 ) {
			return; // Points never expire — nothing to remind about.
		}

		$days = self::reminder_days();
		if ( $days <= 0 ) {
			return;
		}

		global $wpdb;
		$table  = Schema::ledger_table();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );

		// Per user: the soonest in-window expiry, and the sum of points expiring within the window.
		// Only earn rows (points_delta > 0) carry an expires_at; we bound it to (now, cutoff].
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, MIN(expires_at) AS next_expiry, COALESCE(SUM(points_delta),0) AS expiring
					FROM {$table}
					WHERE expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND points_delta > 0
					GROUP BY user_id
					LIMIT %d",
				$now,
				$cutoff,
				self::MAX_PER_RUN
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $user_id <= 0 ) {
				continue;
			}

			// Never remind about more than the member actually holds (some may already be spent).
			$balance  = Ledger::points( $user_id );
			$expiring = min( $balance, (int) ( $row['expiring'] ?? 0 ) );
			if ( $expiring <= 0 ) {
				continue;
			}

			$next_expiry = (string) ( $row['next_expiry'] ?? '' );
			$fingerprint = self::fingerprint( $next_expiry, $expiring );

			// Idempotent: same batch already mailed → skip.
			if ( (string) get_user_meta( $user_id, self::META_SENT, true ) === $fingerprint ) {
				continue;
			}

			if ( self::notify( $user_id, $expiring, $next_expiry ) ) {
				update_user_meta( $user_id, self::META_SENT, $fingerprint );

				/**
				 * Fires once per member per expiry batch, right after the reminder is sent. Outbound
				 * webhooks / messaging channels hook this so they reuse the SAME idempotent batch the
				 * e-mail uses, instead of inventing a second "expiring soon" definition.
				 *
				 * @param int    $user_id
				 * @param int    $expiring    Points expiring inside the reminder window.
				 * @param string $next_expiry Soonest expiry datetime in the batch.
				 */
				do_action( 'moksafopoi_points_expiring_soon', $user_id, $expiring, $next_expiry );
			}
		}
	}

	/** Configured reminder lead time in days (option, default 14, never below 1). */
	private static function reminder_days(): int {
		$days = (int) get_option( 'moksafopoi_expiry_reminder_days', self::DEFAULT_DAYS );
		return $days > 0 ? $days : self::DEFAULT_DAYS;
	}

	/** Stable fingerprint of an expiry batch: soonest date + the (capped) expiring amount. */
	private static function fingerprint( string $next_expiry, int $expiring ): string {
		return sha1( $next_expiry . '|' . $expiring );
	}

	/**
	 * Compose and send the reminder e-mail for one member. Subject and body are translatable and
	 * filterable; the body is a branded HTML document built via {@see EmailTemplate::wrap()} and
	 * sent with an HTML Content-Type. The points figure and human date are interpolated with
	 * translator comments; every interpolated value is esc_html-escaped.
	 *
	 * @return bool True when wp_mail accepted the message.
	 */
	private static function notify( int $user_id, int $expiring, string $next_expiry_gmt ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email || ! is_email( $user->user_email ) ) {
			return false;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Your points are about to expire', 'moksa-points-for-woocommerce' ),
			$site
		);

		$body = EmailTemplate::wrap(
			$subject,
			self::body_html( (string) $user->display_name, $expiring, self::format_date( $next_expiry_gmt ) )
		);

		/**
		 * Filter the expiry-reminder e-mail subject.
		 *
		 * @param string $subject         Subject line.
		 * @param int    $user_id         Recipient user id.
		 * @param int    $expiring        Points expiring within the window.
		 * @param string $next_expiry_gmt Soonest expiry datetime (GMT, Y-m-d H:i:s).
		 */
		$subject = (string) apply_filters( 'moksafopoi_expiry_reminder_subject', $subject, $user_id, $expiring, $next_expiry_gmt );

		/**
		 * Filter the expiry-reminder e-mail body (HTML document).
		 *
		 * @param string $body            Message body (full HTML document).
		 * @param int    $user_id         Recipient user id.
		 * @param int    $expiring        Points expiring within the window.
		 * @param string $next_expiry_gmt Soonest expiry datetime (GMT, Y-m-d H:i:s).
		 */
		$body = (string) apply_filters( 'moksafopoi_expiry_reminder_body', $body, $user_id, $expiring, $next_expiry_gmt );

		/**
		 * Filter the expiry-reminder e-mail headers. Defaults to an HTML Content-Type so the
		 * branded template renders; a sibling may override to plain text.
		 *
		 * @param array<int,string> $headers wp_mail headers.
		 * @param int               $user_id Recipient user id.
		 */
		$headers = (array) apply_filters( 'moksafopoi_expiry_reminder_headers', EmailTemplate::html_headers(), $user_id );

		return (bool) wp_mail( $user->user_email, $subject, $body, $headers );
	}

	/**
	 * Build the white-body HTML fragment for the reminder mail (the part {@see EmailTemplate::wrap()}
	 * drops into the branded shell). Every interpolated value (display name, points figure, date)
	 * is esc_html-escaped here; the points figure runs through {@see Label::format()} so it honours
	 * any custom unit name. Shared by the live send and the admin preview so both stay identical.
	 *
	 * @param string $display_name Recipient display name (raw; escaped here).
	 * @param int    $expiring     Points expiring within the window.
	 * @param string $date_label   Human, localised expiry date (raw; escaped here).
	 */
	public static function body_html( string $display_name, int $expiring, string $date_label ): string {
		$greeting = sprintf(
			/* translators: %s: customer display name. */
			esc_html__( 'Hi %s,', 'moksa-points-for-woocommerce' ),
			esc_html( $display_name )
		);

		$notice = sprintf(
			/* translators: 1: expiring points (e.g. "300 點"), 2: expiry date (e.g. "2026-07-14"). */
			esc_html__( 'This is a reminder that the %1$s in your account will expire on %2$s and be forfeited automatically after that.', 'moksa-points-for-woocommerce' ),
			'<strong>' . esc_html( Label::format( $expiring ) ) . '</strong>',
			'<strong>' . esc_html( $date_label ) . '</strong>'
		);

		$cta      = esc_html__( 'Use your points to pay or redeem a reward before they expire!', 'moksa-points-for-woocommerce' );
		$autonote = esc_html__( 'This is an automated notification; please do not reply directly.', 'moksa-points-for-woocommerce' );

		$html  = '<p style="margin:0 0 16px;">' . $greeting . '</p>';
		$html .= '<p style="margin:0 0 16px;">' . $notice . '</p>';
		$html .= '<p style="margin:0 0 16px;">' . $cta . '</p>';
		$html .= '<p style="margin:24px 0 0;color:#999;font-size:13px;">' . $autonote . '</p>';

		return $html;
	}

	/**
	 * Build a full sample reminder e-mail (branded shell + body) for the admin preview. Uses
	 * server-side placeholder data only — a fixed sample name, a sample amount, and a date a few
	 * days out — so the admin sees exactly what a real recipient would, with zero real member data.
	 * The result is the same wrapped HTML the live send produces, so the preview never drifts from
	 * the actual mail.
	 */
	public static function sample_preview(): string {
		$days       = self::reminder_days();
		$sample_gmt = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Your points are about to expire', 'moksa-points-for-woocommerce' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		);

		return EmailTemplate::wrap(
			$subject,
			self::body_html(
				/* translators: a placeholder member name shown only in the admin e-mail preview. */
				__( 'John Doe', 'moksa-points-for-woocommerce' ),
				300,
				self::format_date( $sample_gmt )
			)
		);
	}

	/** Render a GMT datetime in the site's timezone using the site date format. */
	private static function format_date( string $gmt ): string {
		if ( '' === $gmt ) {
			return '—';
		}
		$ts = strtotime( $gmt . ' UTC' );
		if ( false === $ts ) {
			return $gmt;
		}
		return wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
	}
}
