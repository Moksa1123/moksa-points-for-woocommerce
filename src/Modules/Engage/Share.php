<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Engage;

use Moksafopoi\Api;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\Label;

defined( 'ABSPATH' ) || exit;

/**
 * 可分享的成就卡 — a small share card under the「我的點數」hero: what the member has achieved
 * (points, badges, rank) plus LINE / Facebook / copy-link buttons.
 *
 * The shared URL is the member's REFERRAL link when the referral module is on and they have a code,
 * so bragging is also acquisition; otherwise it degrades to the site home URL. Nothing about another
 * member is ever exposed — the card only reads the current user.
 *
 * All URLs are built with rawurlencode + esc_url; the copy button uses a data attribute, never an
 * inline handler.
 *
 * A shop may reward sharing (`moksafopoi_share_bonus`, off by default). Sharing cannot be verified
 * from our side — the member could close the LINE dialog — so the reward is deliberately ONCE PER
 * CHANNEL FOR LIFE, booked on an idempotent ledger ref, and nonce-gated. That makes the worst case
 * "a member collected the small bonus three times" instead of a farmable loop.
 */
final class Share {

	/** admin-ajax action for claiming the share bonus. */
	public const ACTION = 'moksafopoi_share_claim';

	/** Nonce name for that action. */
	public const NONCE = 'moksafopoi_share';

	/** The channels a bonus can be claimed for. */
	private const CHANNELS = array( 'line', 'facebook', 'copy' );

	/** Register the claim handler (logged-in members only). */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle_claim' ) );
	}

	/** The configured one-off bonus per channel (0 = sharing earns nothing). */
	public static function bonus(): int {
		return max( 0, (int) get_option( 'moksafopoi_share_bonus', 0 ) );
	}

	/**
	 * Claim the share bonus for one channel. Idempotent for life on `share:<channel>:<user_id>`, so
	 * clicking the button again — or replaying the request — books nothing.
	 */
	public static function handle_claim(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please log in first.', 'moksa-points-for-woocommerce' ) ), 403 );
		}

		$bonus = self::bonus();
		if ( $bonus <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Sharing does not earn points right now.', 'moksa-points-for-woocommerce' ) ) );
		}

		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown share channel.', 'moksa-points-for-woocommerce' ) ) );
		}

		$written = Ledger::record_once(
			$user_id,
			$bonus,
			'earn',
			'share',
			'share:' . $channel . ':' . $user_id,
			array( 'note' => __( 'Sharing bonus', 'moksa-points-for-woocommerce' ) )
		);

		if ( ! $written ) {
			wp_send_json_error( array( 'message' => __( 'You have already claimed the bonus for this channel.', 'moksa-points-for-woocommerce' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: the points just earned, with unit. */
					__( 'Thanks for sharing! You earned %s.', 'moksa-points-for-woocommerce' ),
					Label::format( $bonus )
				),
			)
		);
	}

	/** Render the card for the logged-in member (no-op for guests / an empty achievement set). */
	public static function render_account(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$points = Api::get_points( $user_id );
		$badges = self::badge_count( $user_id );
		if ( $points <= 0 && $badges <= 0 ) {
			return; // Nothing to brag about yet.
		}

		$url  = self::share_url( $user_id );
		$text = self::share_text( $points, $badges );

		// The claim attributes are emitted per element (each value escaped at the echo site) rather
		// than assembled into one pre-escaped blob — a blob is unreviewable, by a human or a scanner.
		$bonus = self::bonus();
		$nonce = ( $bonus > 0 ) ? wp_create_nonce( self::NONCE ) : '';
		$ajax  = ( $bonus > 0 ) ? admin_url( 'admin-ajax.php' ) : '';

		echo '<div class="moksafopoi-share"';
		if ( $bonus > 0 ) {
			echo ' data-share-bonus="1"';
		}
		echo '>';
		echo '<h3 class="moksafopoi-share__title">' . esc_html__( 'Share my achievement', 'moksa-points-for-woocommerce' ) . '</h3>';
		echo '<p class="moksafopoi-share__text">' . esc_html( $text ) . '</p>';
		echo '<p class="moksafopoi-share__actions">';

		echo '<a class="moksafopoi-share__btn moksafopoi-share__btn--line" data-channel="line"';
		self::claim_attrs( $bonus, $nonce, $ajax );
		echo ' target="_blank" rel="noopener noreferrer" href="'
			. esc_url( 'https://social-plugins.line.me/lineit/share?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $text ) )
			. '">' . esc_html__( 'Share on LINE', 'moksa-points-for-woocommerce' ) . '</a>';

		echo '<a class="moksafopoi-share__btn moksafopoi-share__btn--fb" data-channel="facebook"';
		self::claim_attrs( $bonus, $nonce, $ajax );
		echo ' target="_blank" rel="noopener noreferrer" href="'
			. esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) )
			. '">' . esc_html__( 'Share on Facebook', 'moksa-points-for-woocommerce' ) . '</a>';

		echo '<button type="button" class="moksafopoi-share__btn moksafopoi-share__btn--copy" data-channel="copy"';
		self::claim_attrs( $bonus, $nonce, $ajax );
		echo ' data-url="' . esc_attr( $url ) . '" '
			. 'data-copied="' . esc_attr__( 'Copied', 'moksa-points-for-woocommerce' ) . '">'
			. esc_html__( 'Copy link', 'moksa-points-for-woocommerce' ) . '</button>';

		echo '</p>';
		if ( $bonus > 0 ) {
			echo '<p class="moksafopoi-share__bonus">' . esc_html(
				sprintf(
					/* translators: %s: the one-off sharing bonus, with unit. */
					__( 'Share for the first time on each channel and earn %s.', 'moksa-points-for-woocommerce' ),
					Label::format( $bonus )
				)
			) . '</p>';
		}
		echo '</div>';
	}

	/** Echo the share-bonus claim attributes for one button (nothing when sharing earns nothing). */
	private static function claim_attrs( int $bonus, string $nonce, string $ajax ): void {
		if ( $bonus <= 0 ) {
			return;
		}
		echo ' data-claim="1" data-nonce="' . esc_attr( $nonce ) . '" data-ajaxurl="' . esc_attr( $ajax ) . '"';
	}

	/** The line the member shares: their points and, when they have any, their badge count. */
	private static function share_text( int $points, int $badges ): string {
		$site = get_bloginfo( 'name' );

		if ( $badges > 0 ) {
			return sprintf(
				/* translators: 1: shop name; 2: points with unit, e.g.「1,200 點」; 3: number of badges. */
				_n( 'I have %2$s and %3$s badge at %1$s!', 'I have %2$s and %3$s badges at %1$s!', $badges, 'moksa-points-for-woocommerce' ),
				$site,
				Label::format( $points ),
				number_format_i18n( $badges )
			);
		}

		return sprintf(
			/* translators: 1: shop name; 2: points with unit, e.g.「1,200 點」. */
			__( 'I have %2$s at %1$s!', 'moksa-points-for-woocommerce' ),
			$site,
			Label::format( $points )
		);
	}

	/** The member's referral link when available, otherwise the site home page. */
	private static function share_url( int $user_id ): string {
		$referral = \Moksafopoi\Modules\Referral\Module::class;
		if ( 'yes' === get_option( 'moksafopoi_referral_enabled', 'no' ) && class_exists( $referral ) ) {
			$link = $referral::link_for( $user_id );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		return home_url( '/' );
	}

	/** How many badges the member has earned (0 when the badges module is off). */
	private static function badge_count( int $user_id ): int {
		$badges = \Moksafopoi\Modules\Badges\Module::class;
		if ( 'yes' !== get_option( 'moksafopoi_badges_enabled', 'no' ) || ! class_exists( $badges ) ) {
			return 0;
		}
		return count( (array) $badges::earned_badges( $user_id ) );
	}
}
