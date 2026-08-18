<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\MyAccount;

use Moksafopoi\Api;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 點數商城前台 — the [moksafopoi_mall] shortcode. Renders every active reward as a storefront
 * grid of cards (label, points cost, "Redeem now" button). A logged-in member redeems straight
 * from a card; the button posts to the SAME admin-post handler the My Account page uses
 * ({@see Endpoint::handle_redeem}) with the SAME per-reward nonce, so there is exactly one
 * deduction path and one idempotency story — this view never re-implements point spending.
 *
 * Guests see a "Please log in first" prompt instead of buttons. All output is escaped; no CPT, no new table
 * — the grid is a bounded, prepared read over the existing rewards table.
 */
final class Mall {

	/** Hard ceiling on cards a single shortcode renders (defence against a runaway catalog). */
	private const MAX_CARDS = 100;

	/**
	 * Render the mall grid.
	 *
	 * Attributes:
	 *  - limit (int, default 50, clamped 1..MAX_CARDS)
	 *  - columns (int, default 3, clamped 1..6)
	 *
	 * @param array<string,mixed>|string $atts
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit'   => 50,
				'columns' => 3,
			),
			is_array( $atts ) ? $atts : array(),
			'moksafopoi_mall'
		);

		$limit   = max( 1, min( self::MAX_CARDS, (int) $atts['limit'] ) );
		$columns = max( 1, min( 6, (int) $atts['columns'] ) );

		$user_id  = get_current_user_id();
		$points   = $user_id > 0 ? Api::get_points( $user_id ) : 0;
		$rewards  = self::active_rewards( $limit );

		// Pull in the shared card stylesheet wherever the shortcode is placed (not only the account page).
		Endpoint::enqueue_card_styles();

		ob_start();

		echo '<div class="moksafopoi-mall" style="--moksafopoi-mall-cols:' . esc_attr( (string) $columns ) . '">';
		echo '<div class="moksafopoi-mall__head">';
		echo '<h3 class="moksafopoi-mall__title">' . esc_html__( 'Points store', 'moksa-points-for-woocommerce' ) . '</h3>';
		if ( $user_id > 0 ) {
			echo '<p class="moksafopoi-mall__balance">' . esc_html(
				sprintf(
					/* translators: %s: the member's current points balance. */
					__( 'You currently have %s', 'moksa-points-for-woocommerce' ),
					self::points_label( $points )
				)
			) . '</p>';
		}
		echo '</div>';

		self::render_notice( $user_id );

		if ( array() === $rewards ) {
			echo '<p class="moksafopoi-mall__empty">' . esc_html__( 'No redeemable items right now.', 'moksa-points-for-woocommerce' ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		echo '<div class="moksafopoi-mall__grid">';
		foreach ( $rewards as $reward ) {
			self::render_card( $reward, $user_id, $points );
		}
		echo '</div>';

		echo '</div>';

		return (string) ob_get_clean();
	}

	/** One reward card: label, cost, and a redeem button (or a login prompt for guests). */
	private static function render_card( array $reward, int $user_id, int $points ): void {
		$id     = (int) ( $reward['id'] ?? 0 );
		$cost   = (int) ( $reward['cost_points'] ?? 0 );
		$out    = 0 === (int) ( $reward['stock'] ?? -1 );
		$afford = $points >= $cost;

		$disabled = $out || ( $user_id > 0 && ! $afford );

		echo '<div class="moksafopoi-mall__card' . ( $disabled ? ' is-disabled' : '' ) . '">';
		echo '<div class="moksafopoi-mall__card-label">' . esc_html( (string) ( $reward['label'] ?? '' ) ) . '</div>';
		echo '<div class="moksafopoi-mall__card-kind">' . esc_html( self::kind_label( (string) ( $reward['kind'] ?? '' ) ) ) . '</div>';
		echo '<div class="moksafopoi-mall__card-cost">' . esc_html( self::points_label( $cost ) ) . '</div>';

		if ( $user_id <= 0 ) {
			echo '<a class="button moksafopoi-mall__login" href="' . esc_url( self::login_url() ) . '">'
				. esc_html__( 'Please log in first', 'moksa-points-for-woocommerce' ) . '</a>';
			echo '</div>';
			return;
		}

		// Logged-in: reuse the EXACT admin-post action + per-reward nonce of the account-page form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="moksafopoi_redeem">';
		echo '<input type="hidden" name="reward_id" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( self::current_url() ) . '">';
		wp_nonce_field( 'moksafopoi_redeem_' . $id );

		if ( $out ) {
			$label = __( 'Out of stock', 'moksa-points-for-woocommerce' );
		} elseif ( ! $afford ) {
			$label = __( 'Insufficient points', 'moksa-points-for-woocommerce' );
		} else {
			$label = __( 'Redeem now', 'moksa-points-for-woocommerce' );
		}
		echo '<button type="submit" class="button"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Active reward catalog rows, cheapest first, LIMIT-bounded. Tolerant of a missing table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_rewards( int $limit ): array {
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); interpolated part is static SQL, LIMIT bound via $wpdb->prepare(), no other user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, label, kind, cost_points, stock FROM {$table} WHERE active = 1 AND ( stock = -1 OR stock > 0 ) ORDER BY cost_points ASC, id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $rows ) ? $rows : array();
	}

	/** One-shot redeem result notice (set by Endpoint::handle_redeem, per-user transient). */
	private static function render_notice( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$key    = 'moksafopoi_redeem_notice_' . $user_id;
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$is_err = ! empty( $notice['error'] );
		echo '<div class="woocommerce-message' . ( $is_err ? ' woocommerce-error' : '' ) . '" role="alert">'
			. esc_html( (string) ( $notice['message'] ?? '' ) ) . '</div>';
	}

	/** The current request URL (used so the redeem handler can return the member to this page). */
	private static function current_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: building a same-page return URL; validated with wp_safe_redirect on use.
		$request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return $request !== '' ? home_url( $request ) : home_url( '/' );
	}

	/** Login URL that returns the guest to the mall page after authenticating. */
	private static function login_url(): string {
		return wp_login_url( self::current_url() );
	}

	private static function kind_label( string $kind ): string {
		$map = array(
			'coupon'  => __( 'Coupon', 'moksa-points-for-woocommerce' ),
			'product' => __( 'Physical gift', 'moksa-points-for-woocommerce' ),
		);
		return $map[ $kind ] ?? $kind;
	}

	private static function points_label( int $points ): string {
		// Central brand helper so a rebranded unit (金幣 / 哩程 / P) shows on mall cards too.
		return \Moksafopoi\Support\Label::format( $points );
	}
}
