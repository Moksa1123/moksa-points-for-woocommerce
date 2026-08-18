<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Leaderboard;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 點數排行榜 — a gamification surface that ranks members by total points EARNED (the sum of
 * positive ledger deltas), not by current balance, so spending points never drops a member's
 * standing. Exposed as a [moksafopoi_leaderboard] shortcode and a "我的排名" line on the
 * 「我的點數」account page.
 *
 * No CPT, no new table: the ranking is a GROUP BY aggregate over the existing ledger, always
 * LIMIT-bounded and prepared. Display names can be masked (王*明) so a public board never
 * leaks full member names. Degrades gracefully — an empty / missing ledger yields an empty
 * board, never a fatal.
 */
final class Module extends AbstractModule {

	/** Hard ceiling on rows a single shortcode may request (defence against a huge LIMIT). */
	private const MAX_LIMIT = 100;

	public function slug(): string {
		return 'leaderboard';
	}

	public function label(): string {
		return __( 'Points leaderboard', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'Rank members by total points earned (names can be masked), for all-time or the current month, with a shortcode and a "My rank" display', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_shortcode( 'moksafopoi_leaderboard', array( self::class, 'shortcode' ) );
	}

	/**
	 * Render the leaderboard.
	 *
	 * Attributes:
	 *  - limit  (int, default 10, clamped 1..MAX_LIMIT)
	 *  - period (string, "all" | "month", default "all")
	 *
	 * @param array<string,mixed>|string $atts
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit'         => 10,
				'period'        => 'all',
				'exclude_roles' => '',
				'exclude_ids'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			'moksafopoi_leaderboard'
		);

		$limit         = self::clamp_limit( (int) $atts['limit'] );
		$period        = self::sanitize_period( (string) $atts['period'] );
		$exclude_roles = self::split_csv( (string) $atts['exclude_roles'] );
		$exclude_ids   = array_map( 'intval', self::split_csv( (string) $atts['exclude_ids'] ) );

		$rows = self::top_earners( $limit, $period, $exclude_roles, $exclude_ids );

		ob_start();
		echo '<div class="moksafopoi-leaderboard">';
		echo '<h3 class="moksafopoi-leaderboard__title">'
			. esc_html( self::period_title( $period ) )
			. '</h3>';

		if ( array() === $rows ) {
			echo '<p class="moksafopoi-leaderboard__empty">' . esc_html__( 'No ranking data yet.', 'moksa-points-for-woocommerce' ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		echo '<table class="moksafopoi-leaderboard__table shop_table shop_table_responsive">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Rank', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Member', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Total points', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		$rank = 0;
		foreach ( $rows as $row ) {
			++$rank;
			$user_id = (int) ( $row['user_id'] ?? 0 );
			$earned  = (int) ( $row['earned'] ?? 0 );
			echo '<tr>';
			echo '<td data-title="' . esc_attr__( 'Rank', 'moksa-points-for-woocommerce' ) . '" class="moksafopoi-leaderboard__rank">' . esc_html( self::rank_label( $rank ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Member', 'moksa-points-for-woocommerce' ) . '">' . esc_html( self::display_name( $user_id ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Total points', 'moksa-points-for-woocommerce' ) . '">' . esc_html( self::points_label( $earned ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Top earners by SUM of positive points deltas, grouped per user, DESC, LIMIT-bounded.
	 * Returns rows of { user_id, earned }. Tolerant of an empty / missing table.
	 *
	 * @return array<int,array{user_id:int,earned:int}>
	 */
	public static function top_earners( int $limit, string $period = 'all', array $exclude_roles = array(), array $exclude_ids = array() ): array {
		global $wpdb;

		$limit = self::clamp_limit( $limit );
		$table = Schema::ledger_table();

		[ $where, $params ] = self::period_where( self::sanitize_period( $period ) );

		$excluded = self::excluded_ids( $exclude_roles, $exclude_ids );
		if ( array() !== $excluded ) {
			$placeholders = implode( ',', array_fill( 0, count( $excluded ), '%d' ) );
			$where       .= " AND user_id NOT IN ({$placeholders})";
			foreach ( $excluded as $eid ) {
				$params[] = $eid;
			}
		}
		$params[] = $limit;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated parts are static SQL, user values bound via $wpdb->prepare( ..., $params ); LIMIT bounded.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, SUM(points_delta) AS earned FROM {$table} WHERE {$where}
					GROUP BY user_id ORDER BY earned DESC, user_id ASC LIMIT %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return array(); // NULL on db error → empty board, never fatal.
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'user_id' => (int) ( $row['user_id'] ?? 0 ),
				'earned'  => (int) ( $row['earned'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * The viewer's own rank (1-based) by total earned points, or 0 when they have earned nothing
	 * or are logged out. Computed with a single bounded aggregate + COUNT — no full-table fetch.
	 */
	public static function rank_for_user( int $user_id, string $period = 'all' ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$table = Schema::ledger_table();

		[ $where, $params ] = self::period_where( self::sanitize_period( $period ) );

		// My earned total in-period.
		$mine_params = array_merge( array( $user_id ), $params );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated parts are static SQL, user values bound via $wpdb->prepare( ..., $mine_params ).
		$mine = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT SUM(points_delta) FROM {$table} WHERE user_id = %d AND {$where}", $mine_params )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $mine <= 0 ) {
			return 0;
		}

		// Number of members strictly ahead of me; my rank is that + 1.
		$ahead_params = array_merge( $params, array( $mine ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated parts are static SQL, user values bound via $wpdb->prepare( ..., $ahead_params ); aggregated subquery.
		$ahead = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT user_id FROM {$table} WHERE {$where}
					GROUP BY user_id HAVING SUM(points_delta) > %d ) t",
				$ahead_params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $ahead + 1;
	}

	/* ------------------------------------------------------------------ helpers */

	/** Clamp a requested row count into [1, MAX_LIMIT] so no shortcode can scan the whole table. */
	private static function clamp_limit( int $limit ): int {
		return max( 1, min( self::MAX_LIMIT, $limit ) );
	}

	/** Valid periods; anything else falls back to "all". */
	private static function sanitize_period( string $period ): string {
		$period = sanitize_key( $period );
		return in_array( $period, array( 'all', 'today', 'week', 'month', 'last_month' ), true ) ? $period : 'all';
	}

	/**
	 * Build the shared WHERE fragment + bound params for a period (points_delta > 0 + created_at window).
	 * Datetime bounds are UTC to match the ledger's GMT created_at.
	 *
	 * @return array{0:string,1:array<int,mixed>} [where, params]
	 */
	private static function period_where( string $period ): array {
		[ $start, $end ] = self::period_bounds( $period );
		$where  = 'points_delta > 0';
		$params = array();
		if ( null !== $start ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $start;
		}
		if ( null !== $end ) {
			$where   .= ' AND created_at < %s';
			$params[] = $end;
		}
		return array( $where, $params );
	}

	/**
	 * UTC [start, end] datetimes for a period; null = unbounded on that side. Week honours the site's
	 * start_of_week; last_month is a closed [firstOfLastMonth, firstOfThisMonth) range.
	 *
	 * @return array{0:?string,1:?string}
	 */
	private static function period_bounds( string $period ): array {
		$now = time();
		switch ( $period ) {
			case 'today':
				return array( gmdate( 'Y-m-d 00:00:00', $now ), null );
			case 'week':
				$sow  = (int) get_option( 'start_of_week', 1 ); // 0=Sun … 6=Sat.
				$dow  = (int) gmdate( 'w', $now );              // 0=Sun … 6=Sat.
				$diff = ( $dow - $sow + 7 ) % 7;
				return array( gmdate( 'Y-m-d 00:00:00', $now - $diff * DAY_IN_SECONDS ), null );
			case 'month':
				return array( gmdate( 'Y-m-01 00:00:00', $now ), null );
			case 'last_month':
				$first_this = gmdate( 'Y-m-01 00:00:00', $now );
				$last_start = gmdate( 'Y-m-01 00:00:00', (int) strtotime( $first_this . ' UTC -1 month' ) );
				return array( $last_start, $first_this );
			case 'all':
			default:
				return array( null, null );
		}
	}

	/**
	 * Resolve exclude-roles + exclude-ids to a de-duplicated user-id list to drop from the board.
	 *
	 * @param array<int,string> $roles
	 * @param array<int,int>    $ids
	 * @return array<int,int>
	 */
	private static function excluded_ids( array $roles, array $ids ): array {
		$out = array_map( 'intval', $ids );
		foreach ( $roles as $role ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role ) {
				continue;
			}
			$users = get_users(
				array(
					'role'   => $role,
					'fields' => 'ID',
					'number' => 1000,
				)
			);
			$out = array_merge( $out, array_map( 'intval', (array) $users ) );
		}
		return array_values( array_unique( array_filter( $out, static function ( $v ) { return $v > 0; } ) ) );
	}

	/** Split a comma / space separated attribute into trimmed non-empty tokens. */
	private static function split_csv( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', trim( $raw ) ) ?: array();
		return array_values( array_filter( array_map( 'trim', $parts ), static function ( $v ) { return '' !== $v; } ) );
	}

	/** The board title for a period. */
	private static function period_title( string $period ): string {
		switch ( $period ) {
			case 'today':
				return __( 'Today\'s points leaderboard', 'moksa-points-for-woocommerce' );
			case 'week':
				return __( 'This week\'s points leaderboard', 'moksa-points-for-woocommerce' );
			case 'month':
				return __( 'This month\'s points leaderboard', 'moksa-points-for-woocommerce' );
			case 'last_month':
				return __( 'Last month\'s points leaderboard', 'moksa-points-for-woocommerce' );
			default:
				return __( 'Points leaderboard', 'moksa-points-for-woocommerce' );
		}
	}

	/**
	 * A member's public display name, optionally masked (王*明). Masking is the default for a
	 * public board; an operator can disable it via the moksafopoi_leaderboard_mask_names filter
	 * or the moksafopoi_leaderboard_mask_names option ('no').
	 */
	public static function display_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		$name = ( $user && '' !== (string) $user->display_name )
			? (string) $user->display_name
			: __( 'Member', 'moksa-points-for-woocommerce' );

		$mask = 'no' !== get_option( 'moksafopoi_leaderboard_mask_names', 'yes' );
		/**
		 * Filter whether leaderboard member names are masked.
		 *
		 * @param bool $mask    Whether to mask (default from option, true).
		 * @param int  $user_id The member being rendered.
		 */
		$mask = (bool) apply_filters( 'moksafopoi_leaderboard_mask_names', $mask, $user_id );

		return $mask ? self::mask_name( $name ) : $name;
	}

	/**
	 * Mask a display name keeping the first and last character, e.g. 「王小明」→「王*明」,
	 * 「Anna」→「A**a」. Single / double-char names get a partial mask. Multibyte-safe.
	 */
	public static function mask_name( string $name ): string {
		$name = trim( $name );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );

		if ( $len <= 1 ) {
			return $name;
		}

		$char_at = static function ( int $i ) use ( $name ): string {
			return function_exists( 'mb_substr' ) ? mb_substr( $name, $i, 1 ) : substr( $name, $i, 1 );
		};

		if ( 2 === $len ) {
			return $char_at( 0 ) . '*';
		}

		$first  = $char_at( 0 );
		$last   = $char_at( $len - 1 );
		$middle = str_repeat( '*', $len - 2 );
		return $first . $middle . $last;
	}

	/** Decorate the top 3 ranks with a medal; the rest get a plain "第 N 名". */
	private static function rank_label( int $rank ): string {
		$medals = array(
			1 => '🥇',
			2 => '🥈',
			3 => '🥉',
		);
		if ( isset( $medals[ $rank ] ) ) {
			return $medals[ $rank ] . ' ' . self::nth_label( $rank );
		}
		return self::nth_label( $rank );
	}

	/** "第 N 名" localized label. */
	private static function nth_label( int $rank ): string {
		return sprintf(
			/* translators: %s: rank position, e.g. "1". */
			__( 'No. %s', 'moksa-points-for-woocommerce' ),
			number_format( $rank )
		);
	}

	/** "N <unit>" localized, thousands-grouped — via the central brand helper. */
	private static function points_label( int $points ): string {
		return \Moksafopoi\Support\Label::format( $points );
	}
}
