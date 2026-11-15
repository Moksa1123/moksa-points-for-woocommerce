<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\PointsAdmin;

use Moksafopoi\Support\LedgerStore;
use Moksafopoi\Support\Input;
use Moksafopoi\Support\RedirectNotice;

use Moksafopoi\Api;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 後台「點數紀錄」瀏覽器 — the support desk's answer to「我的點數為什麼變少?」. A read-only,
 * filterable view over the ledger (member / type / source / date range, paginated) plus a
 * per-row 沖正 (reversal) action.
 *
 * The ledger stays append-only: there is NO edit and NO delete here. A reversal writes a NEW
 * counter-row through {@see Api::adjust()} with source_ref `reversal_<row id>`, so the
 * idempotency UNIQUE key guarantees each row can only ever be reversed once (double-clicks and
 * replays are no-ops), and the audit trail keeps both sides. Reversing an earn is clamped to
 * the member's current balance so the ledger never goes negative.
 */
final class LedgerBrowser {

	public const PAGE          = 'moksafopoi-ledger';
	public const ACTION        = 'moksafopoi_ledger_reverse';
	public const EXPORT_ACTION = 'moksafopoi_ledger_export';
	public const CAP           = 'manage_woocommerce';
	public const NONCE         = 'moksafopoi_ledger_reverse';

	private const PER_PAGE = 20;

	/** Hard row cap for the CSV stream, so a runaway export can't exhaust PHP memory/time. */
	private const EXPORT_MAX = 20000;

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/* ------------------------------------------------------------------ render */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksa-points-for-woocommerce">';
		echo '<div class="mowp-intro"><h1>' . esc_html__( 'Points records', 'moksa-points-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'The points / store-credit ledger records for all members. Filter by member, type, source and date; "Reverse" writes a new equal-and-opposite record (the original is never deleted or modified), and each record can be reversed at most once.', 'moksa-points-for-woocommerce' ) . '</p></div>';

		self::maybe_notice();

		$f = self::current_filters();
		self::render_filter_form( $f );

		list( $rows, $total ) = self::query( $f );

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No records match.', 'moksa-points-for-woocommerce' ) . '</p></div></div>';
			return;
		}

		$total_pages = (int) ceil( $total / self::PER_PAGE );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: total row count. */
				__( '%s record(s) in total', 'moksa-points-for-woocommerce' ),
				number_format_i18n( $total )
			)
		) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( '#', __( 'Time (UTC)', 'moksa-points-for-woocommerce' ), __( 'Member', 'moksa-points-for-woocommerce' ), __( 'Type', 'moksa-points-for-woocommerce' ), __( 'Source', 'moksa-points-for-woocommerce' ), __( 'Points', 'moksa-points-for-woocommerce' ), __( 'Store credit', 'moksa-points-for-woocommerce' ), __( 'Balance', 'moksa-points-for-woocommerce' ), __( 'Note', 'moksa-points-for-woocommerce' ), __( 'Order', 'moksa-points-for-woocommerce' ), __( 'Actions', 'moksa-points-for-woocommerce' ) ) as $h ) {
			echo '<th>' . esc_html( (string) $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			self::render_row( $row );
		}
		echo '</tbody></table>';

		self::render_pagination( $f, $total_pages );
		echo '</div></div>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_row( array $row ): void {
		$id     = (int) $row['id'];
		$uid    = (int) $row['user_id'];
		$points = (int) $row['points_delta'];
		$amount = (float) $row['amount_delta'];
		$user   = get_userdata( $uid );
		$uname  = $user instanceof \WP_User ? $user->display_name : ( '#' . $uid );

		echo '<tr>';
		echo '<td>' . esc_html( (string) $id ) . '</td>';
		echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
		echo '<td><a href="' . esc_url( get_edit_user_link( $uid ) ) . '">' . esc_html( $uname ) . '</a></td>';
		echo '<td>' . esc_html( (string) $row['type'] ) . '</td>';
		echo '<td>' . esc_html( (string) $row['source'] ) . ( '' !== (string) $row['source_ref'] ? '<br><code style="font-size:11px">' . esc_html( (string) $row['source_ref'] ) . '</code>' : '' ) . '</td>';
		echo '<td style="font-weight:600;color:' . ( $points >= 0 ? '#1a7f37' : '#b32d2e' ) . '">' . esc_html( ( $points > 0 ? '+' : '' ) . number_format_i18n( $points ) ) . '</td>';
		echo '<td>' . ( 0.0 !== $amount ? esc_html( ( $amount > 0 ? '+' : '' ) . number_format_i18n( $amount, 2 ) ) : '—' ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $row['balance_after'] ) ) . '</td>';
		echo '<td>' . esc_html( (string) $row['note'] ) . '</td>';
		$order_id = (int) $row['order_id'];
		if ( $order_id > 0 ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			$link  = $order ? $order->get_edit_order_url() : '';
			echo '<td>' . ( '' !== $link ? '<a href="' . esc_url( $link ) . '">#' . esc_html( (string) $order_id ) . '</a>' : '#' . esc_html( (string) $order_id ) ) . '</td>';
		} else {
			echo '<td>—</td>';
		}

		echo '<td>';
		if ( 0 !== $points && ! str_starts_with( (string) $row['source_ref'], 'reversal_' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" '
				. 'onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Reverse this record? A new equal-and-opposite record will be written.', 'moksa-points-for-woocommerce' ) ) ) . ');">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			echo '<input type="hidden" name="row_id" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( self::NONCE );
			echo '<button type="submit" class="button-link" style="color:#b32d2e">' . esc_html__( 'Reverse', 'moksa-points-for-woocommerce' ) . '</button>';
			echo '</form>';
		} else {
			echo '—';
		}
		echo '</td>';
		echo '</tr>';
	}

	/* ------------------------------------------------------------------ filters */

	/**
	 * Read + sanitize the GET filters. Display-only routing — no nonce needed (read-only view,
	 * capability-gated).
	 *
	 * @return array{user:string,type:string,source:string,from:string,to:string,paged:int}
	 */
	private static function current_filters(): array {
		return array(
			'user'   => sanitize_text_field( Input::get( 'fuser' ) ),
			'type'   => sanitize_key( Input::get( 'ftype' ) ),
			'source' => sanitize_key( Input::get( 'fsource' ) ),
			'from'   => sanitize_text_field( Input::get( 'ffrom' ) ),
			'to'     => sanitize_text_field( Input::get( 'fto' ) ),
			'paged'  => max( 1, absint( Input::get( 'paged' ) ) ),
		);
	}

	/**
	 * @param array{user:string,type:string,source:string,from:string,to:string,paged:int} $f
	 */
	private static function render_filter_form( array $f ): void {
		echo '<form method="get" style="margin:10px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';

		echo '<label>' . esc_html__( 'Member (ID / email / username)', 'moksa-points-for-woocommerce' ) . '<br><input type="text" name="fuser" value="' . esc_attr( $f['user'] ) . '" style="width:200px"></label>';

		echo '<label>' . esc_html__( 'Type', 'moksa-points-for-woocommerce' ) . '<br><select name="ftype"><option value="">' . esc_html__( 'All', 'moksa-points-for-woocommerce' ) . '</option>';
		foreach ( self::distinct( 'type' ) as $t ) {
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $f['type'], $t, false ) . '>' . esc_html( $t ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>' . esc_html__( 'Source', 'moksa-points-for-woocommerce' ) . '<br><select name="fsource"><option value="">' . esc_html__( 'All', 'moksa-points-for-woocommerce' ) . '</option>';
		foreach ( self::distinct( 'source' ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $f['source'], $s, false ) . '>' . esc_html( $s ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>' . esc_html__( 'From', 'moksa-points-for-woocommerce' ) . '<br><input type="date" name="ffrom" value="' . esc_attr( $f['from'] ) . '"></label>';
		echo '<label>' . esc_html__( 'To', 'moksa-points-for-woocommerce' ) . '<br><input type="date" name="fto" value="' . esc_attr( $f['to'] ) . '"></label>';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'moksa-points-for-woocommerce' ) . '</button>';
		echo '<a class="button-link" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Clear', 'moksa-points-for-woocommerce' ) . '</a>';
		echo '<a class="button" href="' . esc_url( self::export_url( $f ) ) . '">' . esc_html__( 'Export CSV (current filter)', 'moksa-points-for-woocommerce' ) . '</a>';
		echo '</form>';
	}

	/**
	 * The admin-post CSV export URL carrying the CURRENT filters, so what downloads is exactly
	 * what the operator is looking at.
	 *
	 * @param array{user:string,type:string,source:string,from:string,to:string,paged:int} $f
	 */
	private static function export_url( array $f ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::EXPORT_ACTION,
					'fuser'   => rawurlencode( $f['user'] ),
					'ftype'   => $f['type'],
					'fsource' => $f['source'],
					'ffrom'   => $f['from'],
					'fto'     => $f['to'],
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
	}

	/**
	 * Distinct values of a ledger enum-ish column (type / source) for the filter dropdowns.
	 * Cached per request; bounded (LIMIT 50) — these columns hold a small fixed vocabulary.
	 *
	 * @return array<int,string>
	 */
	private static function distinct( string $column ): array {
		static $cache = array();
		$column = 'type' === $column ? 'type' : 'source';
		if ( isset( $cache[ $column ] ) ) {
			return $cache[ $column ];
		}
		$cache[ $column ] = array_values( array_filter( array_map( 'strval', LedgerStore::distinct_values( $column ) ) ) );
		return $cache[ $column ];
	}

	/**
	 * Resolve the member filter text (ID / email / login) to a user id, 0 when it doesn't match
	 * anyone (which then yields an empty result set rather than silently ignoring the filter).
	 */
	private static function resolve_user( string $raw ): int {
		if ( '' === $raw ) {
			return -1; // no filter.
		}
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		$user = str_contains( $raw, '@' ) ? get_user_by( 'email', $raw ) : get_user_by( 'login', $raw );
		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Turn the GET filters into LedgerStore's optional-filter shape (user_id -1 / '' = no filter).
	 * type / source must be a value that actually exists in the ledger; dates must be Y-m-d.
	 *
	 * @param array{user:string,type:string,source:string,from:string,to:string,paged:int} $f
	 * @return array{user_id:int,type:string,source:string,from:string,to:string}
	 */
	private static function store_filters( array $f ): array {
		$out = array(
			'user_id' => -1,
			'type'    => '',
			'source'  => '',
			'from'    => '',
			'to'      => '',
		);

		$uid = self::resolve_user( $f['user'] );
		if ( $uid >= 0 ) {
			$out['user_id'] = $uid;
		}
		if ( '' !== $f['type'] && in_array( $f['type'], self::distinct( 'type' ), true ) ) {
			$out['type'] = $f['type'];
		}
		if ( '' !== $f['source'] && in_array( $f['source'], self::distinct( 'source' ), true ) ) {
			$out['source'] = $f['source'];
		}
		if ( '' !== $f['from'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['from'] ) ) {
			$out['from'] = $f['from'] . ' 00:00:00';
		}
		if ( '' !== $f['to'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['to'] ) ) {
			$out['to'] = $f['to'] . ' 23:59:59';
		}

		return $out;
	}

	/**
	 * @param array{user:string,type:string,source:string,from:string,to:string,paged:int} $f
	 * @return array{0:array<int,array<string,mixed>>,1:int} rows + total count
	 */
	private static function query( array $f ): array {
		$filters = self::store_filters( $f );
		$offset  = ( $f['paged'] - 1 ) * self::PER_PAGE;

		$total = LedgerStore::browse_count( $filters );
		$rows  = LedgerStore::browse_rows( $filters, self::PER_PAGE, $offset );

		return array( $rows, $total );
	}

	/**
	 * @param array{user:string,type:string,source:string,from:string,to:string,paged:int} $f
	 */
	private static function render_pagination( array $f, int $total_pages ): void {
		if ( $total_pages <= 1 ) {
			return;
		}
		$base = add_query_arg(
			array(
				'page'    => self::PAGE,
				'fuser'   => $f['user'],
				'ftype'   => $f['type'],
				'fsource' => $f['source'],
				'ffrom'   => $f['from'],
				'fto'     => $f['to'],
			),
			admin_url( 'admin.php' )
		);
		echo '<p style="margin-top:10px">';
		if ( $f['paged'] > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $f['paged'] - 1, $base ) ) . '">' . esc_html__( '« Previous', 'moksa-points-for-woocommerce' ) . '</a> ';
		}
		echo '<span style="margin:0 8px">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages. */
				__( 'Page %1$d of %2$d', 'moksa-points-for-woocommerce' ),
				$f['paged'],
				$total_pages
			)
		) . '</span>';
		if ( $f['paged'] < $total_pages ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $f['paged'] + 1, $base ) ) . '">' . esc_html__( 'Next page »', 'moksa-points-for-woocommerce' ) . '</a>';
		}
		echo '</p>';
	}

	/* ------------------------------------------------------------------ export */

	/**
	 * admin_post handler: stream the CURRENT filtered view as a CSV download. Read-only
	 * (SELECT + fputcsv), capability + nonce gated, hard-capped at EXPORT_MAX rows.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );

		// Same filters as the screen, so the download always matches what the operator sees.
		$rows = LedgerStore::browse_rows( self::store_filters( self::current_filters() ), self::EXPORT_MAX, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="moksafopoi-ledger-' . gmdate( 'Ymd-His' ) . '.csv"' );

		// 直接串流到回應（php://output），不是寫檔；WP_Filesystem 沒有輸出串流的 API。
		$out = new \SplFileObject( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens 中文 correctly.
		$out->fwrite( "\xEF\xBB\xBF" );
		$out->fputcsv( array( 'id', 'created_at_utc', 'user_id', 'login', 'email', 'type', 'source', 'source_ref', 'points_delta', 'amount_delta', 'balance_after', 'order_id', 'note' ) );

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			$out->fputcsv(
				array(
					(int) $row['id'],
					(string) $row['created_at'],
					(int) $row['user_id'],
					self::csv_cell( $user instanceof \WP_User ? $user->user_login : '' ),
					self::csv_cell( $user instanceof \WP_User ? $user->user_email : '' ),
					self::csv_cell( (string) $row['type'] ),
					self::csv_cell( (string) $row['source'] ),
					self::csv_cell( (string) $row['source_ref'] ),
					(int) $row['points_delta'],
					(float) $row['amount_delta'],
					(int) $row['balance_after'],
					(int) $row['order_id'],
					self::csv_cell( (string) $row['note'] ),
				)
			);
		}
		$out = null; // 釋放即關閉串流。
		exit;
	}

	/* ------------------------------------------------------------------ reverse */

	/**
	 * admin_post handler: write the counter-row for one ledger row. Idempotent — the counter-row's
	 * source_ref is `reversal_<row id>`, so record_once's UNIQUE idempotency key rejects a second
	 * reversal of the same row. Reversing an earn is clamped to the member's current balance
	 * (partial reversal when they already spent some), so the ledger never goes negative.
	 */
	public static function handle_reverse(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE );

		$row_id = isset( $_POST['row_id'] ) ? absint( wp_unslash( $_POST['row_id'] ) ) : 0;
		$row = LedgerStore::row_for_reversal( $row_id );

		if ( ! is_array( $row ) || 0 === (int) $row['points_delta'] ) {
			self::redirect_with( 'norow' );
		}
		if ( str_starts_with( (string) $row['source_ref'], 'reversal_' ) ) {
			self::redirect_with( 'isreversal' ); // A reversal row itself may not be reversed again.
		}

		$uid    = (int) $row['user_id'];
		$delta  = (int) $row['points_delta'];
		$target = -$delta;

		if ( $target < 0 ) {
			// Reversing an earn: clamp to what the member still has so the balance never goes negative.
			$available = Ledger::points( $uid );
			$magnitude = min( $delta, max( 0, $available ) );
			if ( $magnitude <= 0 ) {
				self::redirect_with( 'insufficient' );
			}
			$target = -$magnitude;
		}

		$ok = Api::adjust(
			$uid,
			$target,
			sprintf(
				/* translators: %d: reversed ledger row id. */
				__( 'Reversal record #%d', 'moksa-points-for-woocommerce' ),
				(int) $row['id']
			),
			'reversal_' . (int) $row['id']
		);

		self::redirect_with( $ok ? 'reversed' : 'already' );
	}

	/**
	 * A text cell for the CSV export. A value starting with = + - @ or a tab / carriage return is
	 * prefixed with an apostrophe so a spreadsheet shows it as text instead of running it as a formula
	 * (member-written transfer notes end up here).
	 */
	private static function csv_cell( string $value ): string {
		return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}

	/** Redirect back to the browser with a one-word notice flag. Never returns. */
	private static function redirect_with( string $flag ): void {
		wp_safe_redirect( RedirectNotice::url( array( 'mfp_ledger_msg' => $flag ), self::page_url() ) );
		exit;
	}

	private static function maybe_notice(): void {
		$flag = sanitize_key( (string) RedirectNotice::get( 'mfp_ledger_msg' ) );
		if ( '' === $flag ) {
			return;
		}
		$map = array(
			'reversed'     => array( 'success', __( 'Reversal record written.', 'moksa-points-for-woocommerce' ) ),
			'already'      => array( 'warning', __( 'This record was already reversed; no duplicate was written.', 'moksa-points-for-woocommerce' ) ),
			'insufficient' => array( 'error', __( 'The member\'s current balance is not enough to reverse this issuance (the points may have already been used).', 'moksa-points-for-woocommerce' ) ),
			'norow'        => array( 'error', __( 'No reversible record found.', 'moksa-points-for-woocommerce' ) ),
			'isreversal'   => array( 'error', __( 'A reversal record itself cannot be reversed again.', 'moksa-points-for-woocommerce' ) ),
		);
		if ( ! isset( $map[ $flag ] ) ) {
			return;
		}
		list( $kind, $text ) = $map[ $flag ];
		echo '<div class="notice notice-' . esc_attr( $kind ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
