<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\MyAccount;

use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 兌點碼(輸入代碼領點)— the offline→online bridge coupons can't cover: 包裹小卡印碼、直播
 * 口令、市集發碼、LINE 群限定碼。Codes live as rewards-table rows (kind='code', zero CPT):
 * payload = {code, points, expires}; the `stock` column doubles as the GLOBAL redemption cap
 * (-1 = unlimited).
 *
 * Safety rails, in order:
 *   1. rate limit — 10 attempts / 10 minutes per user (transient), so codes can't be brute-guessed;
 *   2. global cap — atomic `UPDATE … SET stock = stock - 1 WHERE stock > 0` (0 rows = 兌完);
 *   3. per-user once — the credit's `record_once` ref `code_<id>_<uid>` hits the ledger UNIQUE
 *      key, so the same member can never redeem the same code twice (the stock unit taken in
 *      step 2 is handed back when step 3 turns out to be a replay).
 * The credit is a normal `earn`, so EarnCap and the earn-exclusion list both apply.
 */
final class RedeemCode {

	public const ACTION = 'moksafopoi_redeem_code';
	private const NONCE = 'moksafopoi_redeem_code';

	private const RATE_LIMIT_TRIES = 10;
	private const RATE_LIMIT_WIN   = 10 * MINUTE_IN_SECONDS;

	/** The「輸入兌點碼」card on the我的點數 page. Renders only when an active code exists. */
	public static function render( int $user_id ): void {
		if ( $user_id <= 0 || ! self::any_active_code() ) {
			return;
		}

		self::render_flash( $user_id );

		echo '<h3 class="moksafopoi-code__title">' . esc_html__( 'Enter a redemption code', 'moksa-points-for-woocommerce' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="text" name="mfp_code" required maxlength="60" placeholder="' . esc_attr__( 'Enter a code, e.g. SUMMER100', 'moksa-points-for-woocommerce' ) . '" style="min-width:220px">';
		echo '<button type="submit" class="button">' . esc_html__( 'Redeem points', 'moksa-points-for-woocommerce' ) . '</button>';
		echo '</form>';
	}

	/** admin_post handler (logged-in only). */
	public static function handle(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in before redeeming.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE );

		$user_id = get_current_user_id();
		$raw     = isset( $_POST['mfp_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['mfp_code'] ) ) ) : '';

		self::finish( $user_id, self::attempt( $user_id, $raw ) );
	}

	/**
	 * One redemption attempt → [ok, message].
	 *
	 * @return array{0:bool,1:string}
	 */
	private static function attempt( int $user_id, string $code ): array {
		if ( '' === $code ) {
			return array( false, __( 'Please enter a code.', 'moksa-points-for-woocommerce' ) );
		}

		// 1) Rate limit (anti brute-guess).
		$tkey  = 'moksafopoi_code_try_' . $user_id;
		$tries = (int) get_transient( $tkey );
		if ( $tries >= self::RATE_LIMIT_TRIES ) {
			return array( false, __( 'Too many attempts; please try again in 10 minutes.', 'moksa-points-for-woocommerce' ) );
		}
		set_transient( $tkey, $tries + 1, self::RATE_LIMIT_WIN );

		$row = self::find_code( $code );
		if ( null === $row ) {
			return array( false, __( 'The code is invalid or does not exist.', 'moksa-points-for-woocommerce' ) );
		}

		$payload = json_decode( (string) $row['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();
		$points  = max( 0, (int) ( $payload['points'] ?? 0 ) );
		$expires = (string) ( $payload['expires'] ?? '' );

		if ( $points <= 0 ) {
			return array( false, __( 'The code is invalid or does not exist.', 'moksa-points-for-woocommerce' ) );
		}
		if ( '' !== $expires && gmdate( 'Y-m-d' ) > $expires ) {
			return array( false, __( 'This code\'s redemption period has ended.', 'moksa-points-for-woocommerce' ) );
		}

		global $wpdb;
		$table = Schema::rewards_table();
		$id    = (int) $row['id'];

		// 2) Global cap — atomic decrement; 0 rows hit = sold out. -1 (unlimited) skips this.
		$capped = (int) $row['stock'] >= 0;
		if ( $capped ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); id bound via $wpdb->prepare().
			$took = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET stock = stock - 1 WHERE id = %d AND stock > 0", $id ) );
			if ( $took <= 0 ) {
				return array( false, __( 'This code has been fully redeemed.', 'moksa-points-for-woocommerce' ) );
			}
		}

		// 3) Per-user once — the UNIQUE ledger ref makes a second redemption a no-op.
		$credited = Ledger::record_once(
			$user_id,
			$points,
			'earn',
			'redeem_code',
			'code_' . $id . '_' . $user_id,
			array(
				'note' => sprintf(
					/* translators: %s: the redeem code. */
					__( 'Redemption code: %s', 'moksa-points-for-woocommerce' ),
					$code
				),
			)
		);

		if ( ! $credited ) {
			// Replay (already redeemed) or earn refused (cap / exclusion) — hand the stock unit back.
			if ( $capped ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); values bound via $wpdb->prepare().
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET stock = stock + 1 WHERE id = %d AND stock >= 0", $id ) );
			}
			return array( false, __( 'You have already redeemed this code, or points cannot be credited to this account right now.', 'moksa-points-for-woocommerce' ) );
		}

		delete_transient( $tkey ); // A successful redemption clears the strike counter.

		return array(
			true,
			sprintf(
				/* translators: %s: granted points. */
				__( 'Redemption successful — %s credited!', 'moksa-points-for-woocommerce' ),
				number_format_i18n( $points )
			),
		);
	}

	/** Look up an ACTIVE code row by its payload code (case-insensitive; codes stored uppercase). */
	private static function find_code( string $code ): ?array {
		global $wpdb;
		$table = Schema::rewards_table();
		// Bounded scan: code rows are few (an operator hand-creates them). JSON payload match is
		// verified in PHP, so a substring collision can't leak a different code.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); interpolated part is static SQL, value bound via $wpdb->prepare().
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE kind = %s AND active = 1 ORDER BY id DESC LIMIT 200", 'code' ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( is_array( $payload ) && strtoupper( (string) ( $payload['code'] ?? '' ) ) === $code ) {
				return $row;
			}
		}
		return null;
	}

	/** Any active kind='code' row? (Gates the whole card so stores without codes see nothing.) */
	private static function any_active_code(): bool {
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); values bound via $wpdb->prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE kind = %s AND active = 1", 'code' ) ) > 0;
	}

	/* ------------------------------------------------------------------ flash */

	/** Stash the result and bounce back to the account page. Never returns. */
	private static function finish( int $user_id, array $result ): void {
		set_transient(
			'moksafopoi_code_flash_' . $user_id,
			array(
				'ok'      => (bool) $result[0],
				'message' => (string) $result[1],
			),
			MINUTE_IN_SECONDS
		);
		$fallback = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( Endpoint::SLUG ) : home_url( '/' );
		$referer  = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : $fallback );
		exit;
	}

	private static function render_flash( int $user_id ): void {
		$flash = get_transient( 'moksafopoi_code_flash_' . $user_id );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( 'moksafopoi_code_flash_' . $user_id );
		$ok = (bool) ( $flash['ok'] ?? false );
		echo '<div class="woocommerce-message' . ( $ok ? '' : ' woocommerce-error' ) . '" role="alert">'
			. esc_html( (string) ( $flash['message'] ?? '' ) ) . '</div>';
	}
}
