<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\PointsAdmin;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\BulkPoints;

defined( 'ABSPATH' ) || exit;

/**
 * 批次 / 手動加扣點(客服操作台)— the human UI myCred ships (Bulk Assign + Adjust Points) that the plugin
 * only had as an AI/MCP ability. Two operations, both through the idempotent {@see BulkPoints} engine:
 *
 *   1. 批次發放 / 撤銷 — pick a whole role, or paste a list of ids / emails / logins, then grant or revoke
 *      a點數 amount to every one (chunked over Cron for big sends; revoke never goes negative).
 *   2. 客服手動加扣 — look up a single user and adjust their balance with a reason.
 *
 * Plus a compact balance + quick-adjust box on the WP user-edit screen, so first-line support can fix a
 * balance without leaving the profile. All actions are cap-gated (manage_woocommerce) + nonce-verified.
 */
final class Module extends AbstractModule {

	private const PAGE          = 'moksafopoi-bulk';
	private const CAP           = 'manage_woocommerce';
	private const BATCH_ACTION  = 'moksafopoi_bulk_apply';
	private const SINGLE_ACTION = 'moksafopoi_single_apply';
	private const NONCE         = 'moksafopoi_pointsadmin';
	private const USER_NONCE    = 'moksafopoi_user_adjust';

	public function slug(): string {
		return 'pointsadmin';
	}

	public function label(): string {
		return __( 'Batch / manual add or deduct points', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'admin';
	}

	public function tagline(): string {
		return __( 'Batch issue / revoke points in the admin (by role or list), let support search a single member to manually add or deduct points, and query and reverse site-wide points history; idempotent, revoking will not go negative.', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
		add_action( 'admin_post_' . self::BATCH_ACTION, array( self::class, 'handle_batch' ) );
		add_action( 'admin_post_' . self::SINGLE_ACTION, array( self::class, 'handle_single' ) );
		add_action( 'admin_post_' . LedgerBrowser::ACTION, array( LedgerBrowser::class, 'handle_reverse' ) );
		add_action( 'admin_post_' . LedgerBrowser::EXPORT_ACTION, array( LedgerBrowser::class, 'handle_export' ) );

		// Cron continuation for large batches.
		add_action( BulkPoints::CRON_HOOK, array( BulkPoints::class, 'run_batch' ), 10, 1 );

		// User-edit screen balance + quick adjust.
		add_action( 'edit_user_profile', array( self::class, 'render_user_box' ) );
		add_action( 'show_user_profile', array( self::class, 'render_user_box' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_user_box' ) );
		add_action( 'personal_options_update', array( self::class, 'save_user_box' ) );
	}

	private static function parent_slug(): string {
		$parent = apply_filters( 'moksafopoi_rewardadmin_parent', 'woocommerce' );
		return is_string( $parent ) && '' !== $parent ? $parent : 'woocommerce';
	}

	public function register_menu(): void {
		add_submenu_page(
			self::parent_slug(),
			__( 'Batch / manual add or deduct points', 'moksa-points-for-woocommerce' ),
			__( 'Batch / manual add or deduct points', 'moksa-points-for-woocommerce' ),
			self::CAP,
			self::PAGE,
			array( self::class, 'render_page' )
		);
		add_submenu_page(
			self::parent_slug(),
			__( 'Points records', 'moksa-points-for-woocommerce' ),
			__( 'Points records', 'moksa-points-for-woocommerce' ),
			LedgerBrowser::CAP,
			LedgerBrowser::PAGE,
			array( LedgerBrowser::class, 'render' )
		);
	}

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/* ------------------------------------------------------------------ page */

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksa-points-for-woocommerce">';
		echo '<div class="mowp-intro"><h1>' . esc_html__( 'Batch / manual add or deduct points', 'moksa-points-for-woocommerce' ) . '</h1></div>';
		self::maybe_notice();

		// --- Batch ---
		echo '<div class="mowp-panel mowp-panel--wide"><div class="mowp-panel__head">' . esc_html__( 'Batch issue / revoke', 'moksa-points-for-woocommerce' ) . '</div><div class="mowp-panel__body">';
		echo '<p class="description">' . esc_html__( 'Issue or revoke points for an entire role, or paste a list of member IDs / emails / usernames to do it all at once. Revoking will not make points go negative; large lists are processed in batches automatically.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::BATCH_ACTION ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th>' . esc_html__( 'Target', 'moksa-points-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="radio" name="mode" value="role" checked> ' . esc_html__( 'Entire role', 'moksa-points-for-woocommerce' ) . '</label> ';
		echo '<select name="role">';
		foreach ( self::roles() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select><br><br>';
		echo '<label><input type="radio" name="mode" value="list"> ' . esc_html__( 'List (ID / email / username, separated by commas or line breaks)', 'moksa-points-for-woocommerce' ) . '</label><br>';
		echo '<textarea name="user_list" rows="4" cols="60" placeholder="123, user@example.com, someuser"></textarea>';
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Action', 'moksa-points-for-woocommerce' ) . '</th><td>';
		echo '<select name="direction"><option value="grant">' . esc_html__( 'Issue (add points)', 'moksa-points-for-woocommerce' ) . '</option>'
			. '<option value="revoke">' . esc_html__( 'Revoke (deduct points)', 'moksa-points-for-woocommerce' ) . '</option></select> ';
		echo '<input type="number" name="amount" min="1" step="1" value="0" required> ' . esc_html__( 'point', 'moksa-points-for-woocommerce' );
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Reason / note', 'moksa-points-for-woocommerce' ) . '</th><td><input type="text" name="note" size="50" maxlength="200"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Run batch', 'moksa-points-for-woocommerce' ) );
		echo '</form>';
		echo '</div></div>';

		// --- Single ---
		echo '<div class="mowp-panel mowp-panel--wide"><div class="mowp-panel__head">' . esc_html__( 'Support manual add / deduct (single member)', 'moksa-points-for-woocommerce' ) . '</div><div class="mowp-panel__body">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SINGLE_ACTION ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Member (ID / email / username)', 'moksa-points-for-woocommerce' ) . '</th><td><input type="text" name="user" size="40" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Action', 'moksa-points-for-woocommerce' ) . '</th><td>';
		echo '<select name="direction"><option value="grant">' . esc_html__( 'Add points', 'moksa-points-for-woocommerce' ) . '</option>'
			. '<option value="revoke">' . esc_html__( 'Deduct points', 'moksa-points-for-woocommerce' ) . '</option></select> ';
		echo '<input type="number" name="amount" min="1" step="1" value="0" required> ' . esc_html__( 'point', 'moksa-points-for-woocommerce' );
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Reason / note', 'moksa-points-for-woocommerce' ) . '</th><td><input type="text" name="note" size="50" maxlength="200"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Apply', 'moksa-points-for-woocommerce' ), 'secondary' );
		echo '</form>';
		echo '</div></div>';

		echo '</div></div>';
	}

	private static function maybe_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		$done = isset( $_GET['moksafopoi_bulk'] ) ? sanitize_key( wp_unslash( (string) $_GET['moksafopoi_bulk'] ) ) : '';
		if ( '' === $done ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
		$n = isset( $_GET['n'] ) ? max( 0, (int) $_GET['n'] ) : 0;
		if ( 'batch' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of members changed in the first pass. */
					__( 'Batch submitted; %d member(s) processed this time (if the list is large, the rest will continue via the scheduler).', 'moksa-points-for-woocommerce' ),
					$n
				)
			) . '</p></div>';
		} elseif ( 'single' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Single-member add / deduct applied.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		} elseif ( 'none' === $done ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No matching members or the amount was 0; no changes were made.', 'moksa-points-for-woocommerce' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ handlers */

	public static function handle_batch(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE );

		$mode      = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) : 'role';
		$role      = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role'] ) ) : '';
		$list      = isset( $_POST['user_list'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['user_list'] ) ) : '';
		$amount    = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( (string) $_POST['direction'] ) ) : 'grant';
		$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '';

		$delta = ( 'revoke' === $direction ? -1 : 1 ) * $amount;
		$uids  = BulkPoints::resolve_targets( $mode, $role, $list );

		if ( 0 === $delta || empty( $uids ) ) {
			wp_safe_redirect( add_query_arg( 'moksafopoi_bulk', 'none', self::page_url() ) );
			exit;
		}

		$note    = '' !== $note ? $note : __( 'Batch add / deduct points', 'moksa-points-for-woocommerce' );
		$applied = BulkPoints::dispatch( $uids, $delta, $note, BulkPoints::new_batch_id() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'moksafopoi_bulk' => 'batch',
					'n'                => $applied,
				),
				self::page_url()
			)
		);
		exit;
	}

	public static function handle_single(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE );

		$user      = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['user'] ) ) : '';
		$amount    = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( (string) $_POST['direction'] ) ) : 'grant';
		$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '';

		$uids  = BulkPoints::resolve_targets( 'list', '', $user );
		$delta = ( 'revoke' === $direction ? -1 : 1 ) * $amount;

		if ( 0 === $delta || empty( $uids ) ) {
			wp_safe_redirect( add_query_arg( 'moksafopoi_bulk', 'none', self::page_url() ) );
			exit;
		}

		BulkPoints::apply_one( (int) $uids[0], $delta, '' !== $note ? $note : __( 'Support manual add / deduct', 'moksa-points-for-woocommerce' ), BulkPoints::new_batch_id() );

		wp_safe_redirect( add_query_arg( 'moksafopoi_bulk', 'single', self::page_url() ) );
		exit;
	}

	/* ------------------------------------------------------------------ user-edit box */

	/**
	 * Show the member's points balance + a quick-adjust control on the WP user-edit screen.
	 *
	 * @param \WP_User $user
	 */
	public static function render_user_box( $user ): void {
		if ( ! $user instanceof \WP_User || ! current_user_can( self::CAP ) ) {
			return;
		}
		$points = Api::get_points( (int) $user->ID );

		echo '<h2>' . esc_html__( 'Points', 'moksa-points-for-woocommerce' ) . '</h2>';
		wp_nonce_field( self::USER_NONCE, '_moksafopoi_user_nonce' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Current points', 'moksa-points-for-woocommerce' ) . '</th><td><strong>' . esc_html( number_format_i18n( $points ) ) . '</strong></td></tr>';
		echo '<tr><th>' . esc_html__( 'Quick add / deduct', 'moksa-points-for-woocommerce' ) . '</th><td>';
		echo '<select name="moksafopoi_quick_dir"><option value="grant">' . esc_html__( 'Add points', 'moksa-points-for-woocommerce' ) . '</option>'
			. '<option value="revoke">' . esc_html__( 'Deduct points', 'moksa-points-for-woocommerce' ) . '</option></select> ';
		echo '<input type="number" name="moksafopoi_quick_amount" min="0" step="1" value="0" style="width:100px"> ';
		echo '<input type="text" name="moksafopoi_quick_note" placeholder="' . esc_attr__( 'Reason (optional)', 'moksa-points-for-woocommerce' ) . '" style="width:240px">';
		echo '<p class="description">' . esc_html__( 'Enter the points and save the profile to apply; deducting will not make points go negative.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
	}

	/** Persist a quick-adjust from the user-edit screen. */
	public static function save_user_box( int $user_id ): void {
		if ( $user_id <= 0 || ! current_user_can( self::CAP ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST['_moksafopoi_user_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_moksafopoi_user_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::USER_NONCE ) ) {
			return;
		}
		$amount = isset( $_POST['moksafopoi_quick_amount'] ) ? absint( wp_unslash( $_POST['moksafopoi_quick_amount'] ) ) : 0;
		if ( $amount <= 0 ) {
			return;
		}
		$dir   = isset( $_POST['moksafopoi_quick_dir'] ) ? sanitize_key( wp_unslash( (string) $_POST['moksafopoi_quick_dir'] ) ) : 'grant';
		$note  = isset( $_POST['moksafopoi_quick_note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['moksafopoi_quick_note'] ) ) : '';
		$delta = ( 'revoke' === $dir ? -1 : 1 ) * $amount;

		BulkPoints::apply_one( $user_id, $delta, '' !== $note ? $note : __( 'Profile page quick add / deduct', 'moksa-points-for-woocommerce' ), BulkPoints::new_batch_id() );
	}

	/** @return array<string,string> role slug => localized name */
	private static function roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		$names = wp_roles()->get_names();
		$out   = array();
		foreach ( (array) $names as $slug => $name ) {
			$out[ (string) $slug ] = translate_user_role( (string) $name );
		}
		return $out;
	}
}
