<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\AdminMenu;

use Moksafopoi\Settings\SettingsUi;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Landing page for the top-level「Moksa 點數」menu — a lightweight management hub with
 * at-a-glance points stats (read straight off the ledger / rewards tables) and quick links
 * to the settings and rewards catalog screens. Skinned with the shared mowp design system
 * (SettingsUi) so the hub matches the settings screens instead of looking like a stray page.
 */
final class Dashboard {

	private const CAP = 'manage_woocommerce';

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$issued      = self::ledger_sum( 'positive' );
		$redeemed    = self::ledger_sum( 'negative' );
		$circulating = self::ledger_sum( 'all' );
		$rewards     = self::active_rewards_count();

		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksa-points-for-woocommerce">';
		echo '<div class="mowp-intro"><h1>' . esc_html__( 'Moksa Points management', 'moksa-points-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'A central place to view the points circulation overview, manage the redemption catalog, and adjust settings.', 'moksa-points-for-woocommerce' ) . '</p></div>';

		self::render_walkthrough();

		// Stat tiles. The points-bearing tile titles carry the (customisable) unit so a rebranded
		// store reads「累計發放金幣」instead of「…點數」, staying in sync with the storefront.
		$unit = \Moksafopoi\Support\Label::unit();
		echo '<div class="mowp-tiles">';
		self::stat_tile(
			sprintf(
				/* translators: %s: the (customisable) points unit, e.g.「點」「金幣」. */
				__( 'Total %s issued', 'moksa-points-for-woocommerce' ),
				$unit
			),
			number_format_i18n( $issued )
		);
		self::stat_tile( __( 'Total redeemed / applied', 'moksa-points-for-woocommerce' ), number_format_i18n( $redeemed ) );
		self::stat_tile(
			sprintf(
				/* translators: %s: the (customisable) points unit, e.g.「點」「金幣」. */
				__( '%s in circulation', 'moksa-points-for-woocommerce' ),
				$unit
			),
			number_format_i18n( $circulating )
		);
		self::stat_tile( __( 'Redemption items', 'moksa-points-for-woocommerce' ), number_format_i18n( $rewards ) );
		echo '</div>';

		self::render_report();
		self::render_forecast();

		// Quick links.
		echo '<div class="mowp-linkcards">';
		self::link_card(
			admin_url( 'admin.php?page=moksafopoi-settings' ),
			__( 'Points settings', 'moksa-points-for-woocommerce' ),
			__( 'Enable / disable each points feature module.', 'moksa-points-for-woocommerce' )
		);
		self::link_card(
			admin_url( 'admin.php?page=moksafopoi-rewards' ),
			__( 'Redemption catalog', 'moksa-points-for-woocommerce' ),
			__( 'Manage items redeemable with points (template, cost, stock, availability).', 'moksa-points-for-woocommerce' )
		);
		self::link_card(
			admin_url( 'admin.php?page=moksafopoi-campaigns' ),
			__( 'Marketing campaigns', 'moksa-points-for-woocommerce' ),
			__( 'A platform-wide campaign hub: limited-time double points, commission multipliers, member discounts.', 'moksa-points-for-woocommerce' )
		);
		echo '</div>';

		echo '</div></div>';
	}

	/* ------------------------------------------------------------------ 首次啟用導覽 */

	/**
	 * 三步驟導覽卡(設匯率 → 開賺點 → 建兌換)— shown until every step is done or the operator
	 * dismisses it. Zero JS: completion is detected off live options, dismissal is a nonce'd GET
	 * back to this page. No wizard framework, just deep links with checkmarks.
	 */
	private static function render_walkthrough(): void {
		// Dismiss handling (nonce'd, cap already gated by render()).
		if ( isset( $_GET['mfp_walkthrough'] ) && 'dismiss' === $_GET['mfp_walkthrough'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next line.
			&& check_admin_referer( 'moksafopoi_walkthrough' ) ) {
			update_option( 'moksafopoi_walkthrough_dismissed', 'yes', false );
		}
		if ( 'yes' === (string) get_option( 'moksafopoi_walkthrough_dismissed', 'no' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=moksafopoi-settings' );
		$rewards_url  = admin_url( 'admin.php?page=moksafopoi-rewards' );

		$step1 = null !== get_option( 'moksafopoi_points_per_currency', null );
		$step2 = false;
		foreach ( array( 'spendrules', 'earntriggers', 'earncoupon' ) as $key ) {
			if ( 'yes' === (string) get_option( 'moksafopoi_' . $key . '_enabled', 'no' ) ) {
				$step2 = true;
				break;
			}
		}
		$step3 = self::active_rewards_count() > 0 || 'yes' === (string) get_option( 'moksafopoi_myaccount_enabled', 'no' );

		if ( $step1 && $step2 && $step3 ) {
			return; // All done — the card retires itself.
		}

		$steps = array(
			array( $step1, __( 'Set the purchase earning rate (points per NT$1)', 'moksa-points-for-woocommerce' ), $settings_url ),
			array( $step2, __( 'Enable at least one earning method (purchase points / behavior rewards)', 'moksa-points-for-woocommerce' ), $settings_url ),
			array( $step3, __( 'Create a redemption item, or enable the My Account "My points" page', 'moksa-points-for-woocommerce' ), $rewards_url ),
		);

		$dismiss = wp_nonce_url( add_query_arg( 'mfp_walkthrough', 'dismiss' ), 'moksafopoi_walkthrough' );

		echo '<div class="mowp-callout">';
		echo '<span class="mowp-callout__title">' . esc_html__( 'Start collecting points in three steps', 'moksa-points-for-woocommerce' ) . '</span>';
		echo '<ol>';
		foreach ( $steps as $step ) {
			list( $done, $label, $url ) = $step;
			echo '<li>';
			if ( $done ) {
				echo '<span class="mowp-callout__done">✓</span> <span class="mowp-callout__doletext">' . esc_html( $label ) . '</span>';
			} else {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol>';
		echo '<a class="mowp-link" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Don\'t show again', 'moksa-points-for-woocommerce' ) . '</a>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ 報表 */

	/**
	 * The operations report below the tiles: a 30-day issued/redeemed dual line (inline SVG,
	 * no chart library), the issued-points source breakdown, and the Top 10 balances. All three
	 * aggregates come from ONE cached bundle (10-minute transient) so the dashboard stays a
	 * cheap page even on a busy ledger.
	 */
	private static function render_report(): void {
		$data = self::report_data();

		echo '<h2 class="mowp-h2">' . esc_html__( 'Points activity in the last 30 days', 'moksa-points-for-woocommerce' ) . '</h2>';

		// Dual line: issued (green) vs redeemed (red).
		$earned   = array_map( 'intval', array_column( $data['series'], 'earned' ) );
		$redeemed = array_map( 'intval', array_column( $data['series'], 'redeemed' ) );
		$max      = max( 1, max( array_merge( $earned, $redeemed ) ) );

		echo '<div class="mowp-panel mowp-panel--wide"><div class="mowp-panel__body">';
		echo '<div class="mowp-panel__legend">'
			. '<span><span style="color:#1a7f37;font-weight:600">▬</span> ' . esc_html__( 'Issued', 'moksa-points-for-woocommerce' ) . '</span>'
			. '<span><span style="color:#b32d2e;font-weight:600">▬</span> ' . esc_html__( 'Redeemed / applied', 'moksa-points-for-woocommerce' ) . '</span>'
			. '<span>' . esc_html(
				sprintf(
					/* translators: %s: the highest single-day value on the chart. */
					__( 'Daily peak %s', 'moksa-points-for-woocommerce' ),
					number_format_i18n( $max )
				)
			) . '</span></div>';
		echo self::svg_lines( array( array( '#1a7f37', $earned ), array( '#b32d2e', $redeemed ) ), $max ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from integers only.
		echo '</div></div>';

		echo '<div class="mowp-panels">';

		// Source share of issued points (30 days).
		echo '<div class="mowp-panel"><div class="mowp-panel__head">' . esc_html__( 'Issuance sources breakdown (30 days)', 'moksa-points-for-woocommerce' ) . '</div><div class="mowp-panel__body">';
		if ( array() === $data['sources'] ) {
			echo '<p class="mowp-panel__empty">' . esc_html__( 'No issuance records in this period.', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			$sum = max( 1, array_sum( array_map( 'intval', array_column( $data['sources'], 'total' ) ) ) );
			echo '<table class="mowp-datatable">';
			foreach ( $data['sources'] as $s ) {
				$pct = round( (int) $s['total'] / $sum * 100, 1 );
				echo '<tr><td><code>' . esc_html( (string) $s['source'] ) . '</code></td>'
					. '<td class="num">' . esc_html( number_format_i18n( (int) $s['total'] ) ) . '</td>'
					. '<td><span class="mowp-bar"><span class="mowp-bar__fill" style="width:' . esc_attr( (string) min( 100, $pct ) ) . '%"></span></span> '
					. esc_html( (string) $pct ) . '%</td></tr>';
			}
			echo '</table>';
		}
		echo '</div></div>';

		// Top 10 balances.
		echo '<div class="mowp-panel"><div class="mowp-panel__head">' . esc_html__( 'Top 10 balances', 'moksa-points-for-woocommerce' ) . '</div><div class="mowp-panel__body">';
		if ( array() === $data['top'] ) {
			echo '<p class="mowp-panel__empty">' . esc_html__( 'No balance data yet.', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			echo '<table class="mowp-datatable">';
			foreach ( $data['top'] as $t ) {
				$uid  = (int) $t['user_id'];
				$user = get_userdata( $uid );
				$name = $user instanceof \WP_User ? $user->display_name : ( '#' . $uid );
				echo '<tr><td><a href="' . esc_url( get_edit_user_link( $uid ) ) . '">' . esc_html( $name ) . '</a></td>'
					. '<td class="num">' . esc_html( number_format_i18n( (int) $t['balance'] ) ) . '</td></tr>';
			}
			echo '</table>';
		}
		echo '</div></div>';

		echo '</div>';
	}

	/**
	 * The report aggregates, cached 10 minutes. `series` always holds exactly 30 day-buckets
	 * (zero-filled), oldest first.
	 *
	 * @return array{series:array<int,array{d:string,earned:int,redeemed:int}>,sources:array<int,array{source:string,total:int}>,top:array<int,array{user_id:int,balance:int}>}
	 */
	private static function report_data(): array {
		$cached = get_transient( 'moksafopoi_dash_report' );
		if ( is_array( $cached ) && isset( $cached['series'], $cached['sources'], $cached['top'] ) ) {
			return $cached;
		}

		global $wpdb;
		$table = Schema::ledger_table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - 29 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); values bound via $wpdb->prepare(); result cached below.
		$daily = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d,
					SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END) AS earned,
					SUM(CASE WHEN points_delta < 0 THEN -points_delta ELSE 0 END) AS redeemed
				FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at)",
				$since
			),
			ARRAY_A
		);
		$sources = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, SUM(points_delta) AS total FROM {$table}
				WHERE points_delta > 0 AND created_at >= %s GROUP BY source ORDER BY total DESC LIMIT 8",
				$since
			),
			ARRAY_A
		);
		$top = (array) $wpdb->get_results(
			"SELECT user_id, SUM(points_delta) AS balance FROM {$table} GROUP BY user_id HAVING balance > 0 ORDER BY balance DESC LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$by_day = array();
		foreach ( $daily as $row ) {
			$by_day[ (string) $row['d'] ] = $row;
		}
		$series = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$d        = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[] = array(
				'd'        => $d,
				'earned'   => (int) ( $by_day[ $d ]['earned'] ?? 0 ),
				'redeemed' => (int) ( $by_day[ $d ]['redeemed'] ?? 0 ),
			);
		}

		$data = array(
			'series'  => $series,
			'sources' => array_map(
				static fn( array $s ): array => array(
					'source' => (string) $s['source'],
					'total'  => (int) $s['total'],
				),
				$sources
			),
			'top'     => array_map(
				static fn( array $t ): array => array(
					'user_id' => (int) $t['user_id'],
					'balance' => (int) $t['balance'],
				),
				$top
			),
		);

		set_transient( 'moksafopoi_dash_report', $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * A minimal inline-SVG multi-polyline chart. $lines = [ [color, values[]], … ]; all series
	 * share $max as the y-scale. Safe by construction — every emitted number is int/float math.
	 *
	 * @param array<int,array{0:string,1:array<int,int>}> $lines
	 */
	private static function svg_lines( array $lines, int $max ): string {
		$w    = 680;
		$h    = 120;
		$pad  = 4;
		$out  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '" preserveAspectRatio="none" role="img">';
		$out .= '<line x1="0" y1="' . ( $h - $pad ) . '" x2="' . $w . '" y2="' . ( $h - $pad ) . '" stroke="#e2e8f0" stroke-width="1"/>';
		foreach ( $lines as $line ) {
			list( $color, $values ) = $line;
			$n = count( $values );
			if ( $n < 2 ) {
				continue;
			}
			$pts = array();
			foreach ( $values as $i => $v ) {
				$x     = $pad + ( $w - 2 * $pad ) * $i / ( $n - 1 );
				$y     = ( $h - $pad ) - ( $h - 2 * $pad ) * min( 1, $v / $max );
				$pts[] = round( $x, 1 ) . ',' . round( $y, 1 );
			}
			$out .= '<polyline fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" points="' . esc_attr( implode( ' ', $pts ) ) . '"/>';
		}
		return $out . '</svg>';
	}

	/**
	 * Sum points_delta off the ledger. $which selects the sign filter:
	 *   'positive' — only credits (issued), 'negative' — only debits returned as a positive
	 *   redeemed total, 'all' — net circulating balance. Fault-tolerant: a missing table or
	 *   null aggregate yields 0 and never fatals.
	 */
	private static function ledger_sum( string $which ): int {
		global $wpdb;

		$table = Schema::ledger_table();

		switch ( $which ) {
			case 'positive':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); value bound via $wpdb->prepare().
				$value = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(points_delta) FROM `{$table}` WHERE points_delta > %d", 0 ) );
				return $value === null ? 0 : (int) $value;

			case 'negative':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); value bound via $wpdb->prepare().
				$value = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(points_delta) FROM `{$table}` WHERE points_delta < %d", 0 ) );
				return $value === null ? 0 : (int) ( -1 * (int) $value );

			default: // 'all' — net circulating.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); static aggregate, no user input.
				$value = $wpdb->get_var( "SELECT SUM(points_delta) FROM `{$table}`" );
				return $value === null ? 0 : (int) $value;
		}
	}

	/** Count the active (active=1) rewards in the catalog. Fault-tolerant: 0 on any failure. */
	private static function active_rewards_count(): int {
		global $wpdb;

		$table = Schema::rewards_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (Schema::rewards_table()); value bound via $wpdb->prepare().
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE active = %d", 1 ) );
		return $value === null ? 0 : (int) $value;
	}

	/** Enqueue the shared mowp design-system CSS inline on the core admin 'common' handle (no raw <style>). */
	public static function enqueue_admin( string $hook = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'moksa-points-for-woocommerce' ) ) {
			wp_add_inline_style( 'common', SettingsUi::css() );
		}
	}

	/**
	 * 負債 / 耗損預測面板 — what the programme owes, what is about to lapse, how much has historically
	 * gone unredeemed, and what the reward catalogue should cost. Every figure is arithmetic over the
	 * ledger ({@see \Moksafopoi\Support\Forecast}), not an estimate typed into a settings field, and
	 * the same snapshot backs the `points/forecast-liability` ability so the dashboard and an AI
	 * assistant can never quote different numbers.
	 */
	private static function render_forecast(): void {
		$snapshot = \Moksafopoi\Support\Forecast::snapshot();
		if ( $snapshot['issued_total'] <= 0 ) {
			return; // Nothing has ever been issued — a forecast would be noise.
		}

		echo '<div class="mowp-panel"><div class="mowp-panel__head">'
			. esc_html__( 'Liability and breakage forecast', 'moksa-points-for-woocommerce' )
			. '</div><div class="mowp-panel__body">';

		echo '<div class="mowp-tiles">';
		self::stat_tile(
			__( 'Outstanding liability (NT$)', 'moksa-points-for-woocommerce' ),
			number_format_i18n( (float) $snapshot['liability_value'], 2 )
		);
		self::stat_tile(
			__( 'Expiring within 30 days', 'moksa-points-for-woocommerce' ),
			number_format_i18n( (int) $snapshot['expiring']['d30'] )
		);
		self::stat_tile(
			__( 'Redeemed of all issued', 'moksa-points-for-woocommerce' ),
			number_format_i18n( (float) $snapshot['redemption_rate_pct'], 2 ) . '%'
		);
		self::stat_tile(
			__( 'Expired unused (breakage)', 'moksa-points-for-woocommerce' ),
			number_format_i18n( (float) $snapshot['breakage_pct'], 2 ) . '%'
		);
		echo '</div>';

		$observations = \Moksafopoi\Support\Forecast::observations();
		if ( array() !== $observations ) {
			echo '<ul style="margin:12px 22px 4px;list-style:disc">';
			foreach ( $observations as $observation ) {
				echo '<li>' . esc_html( (string) $observation['text'] ) . '</li>';
			}
			echo '</ul>';
		}

		$suggestions = \Moksafopoi\Support\Forecast::suggest_rewards();
		if ( array() !== $suggestions ) {
			$names = array(
				'entry'        => __( 'Entry reward', 'moksa-points-for-woocommerce' ),
				'core'         => __( 'Core reward', 'moksa-points-for-woocommerce' ),
				'aspirational' => __( 'Aspirational reward', 'moksa-points-for-woocommerce' ),
			);
			echo '<div style="padding:4px 22px 14px"><table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Suggested reward', 'moksa-points-for-woocommerce' ), __( 'Price (points)', 'moksa-points-for-woocommerce' ), __( 'Members within reach', 'moksa-points-for-woocommerce' ), __( 'Why', 'moksa-points-for-woocommerce' ) ) as $header ) {
				echo '<th>' . esc_html( (string) $header ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $suggestions as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $names[ $row['tier'] ] ?? $row['tier'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) $row['points'] ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) $row['reach_pct'] ) . '%' ) . '</td>';
				echo '<td>' . esc_html( (string) $row['reason'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}

		echo '</div></div>';
	}

	private static function stat_tile( string $label, string $value ): void {
		echo '<div class="mowp-tile"><div class="mowp-tile__num">' . esc_html( $value ) . '</div>'
			. '<div class="mowp-tile__label">' . esc_html( $label ) . '</div></div>';
	}

	private static function link_card( string $url, string $title, string $desc ): void {
		echo '<a class="mowp-linkcard" href="' . esc_url( $url ) . '">'
			. '<span class="mowp-linkcard__t">' . esc_html( $title ) . '</span>'
			. '<span class="mowp-linkcard__d">' . esc_html( $desc ) . '</span></a>';
	}
}
