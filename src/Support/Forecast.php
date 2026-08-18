<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 點數負債 / 耗損預測 — the numbers an accountant asks for and almost no loyalty plugin can answer,
 * because almost none keeps a replayable ledger. moforpoints does, so all of this is arithmetic over
 * rows that already exist: no estimates baked into a settings field, no separate reporting table.
 *
 * What it computes:
 *
 *   • **Outstanding liability** — points in circulation × the redeem rate = the NT$ the shop owes.
 *   • **Expiring soon** — how much of that liability lapses inside 30 / 60 / 90 days.
 *   • **Breakage** — the share of issued points that historically expired unused; the single number
 *     that tells a shop whether its expiry policy is doing anything.
 *   • **Velocity** — points issued vs redeemed over the last 30 / 90 days, and the redemption rate.
 *   • **Balance distribution** — the percentiles a reward catalogue should be priced against.
 *
 * Everything is one cached snapshot ({@see CACHE_TTL}) so a dashboard tile, an ability and an AI
 * answer all read the same figures instead of each running its own scan.
 */
final class Forecast {

	/** Snapshot cache lifetime. */
	private const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/** Transient holding the snapshot. */
	private const CACHE_KEY = 'moksafopoi_forecast';

	/**
	 * The full snapshot.
	 *
	 * @return array{outstanding:int,liability_value:float,redeem_rate:int,members_with_balance:int,
	 *               expiring:array{d30:int,d60:int,d90:int},breakage_pct:float,expired_total:int,
	 *               issued_total:int,redeemed_total:int,velocity:array{issued_30:int,redeemed_30:int,issued_90:int,redeemed_90:int},
	 *               redemption_rate_pct:float,distribution:array{p25:int,p50:int,p75:int,p90:int,max:int},generated_at:int}
	 */
	public static function snapshot( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['outstanding'] ) ) {
				return $cached;
			}
		}

		global $wpdb;
		$table = Schema::ledger_table();
		$now   = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); values bound via $wpdb->prepare(); the whole snapshot is cached below.
		$issued = (int) $wpdb->get_var( "SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE points_delta > 0" );

		// Redemptions vs expiries are both negative rows; separating them is what makes breakage real
		// rather than a guess.
		$expired = (int) $wpdb->get_var( "SELECT COALESCE(SUM(-points_delta),0) FROM {$table} WHERE points_delta < 0 AND type = 'expire'" );
		$spent   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(-points_delta),0) FROM {$table} WHERE points_delta < 0 AND type <> 'expire'" );

		$balances = (array) $wpdb->get_col(
			"SELECT SUM(points_delta) AS bal FROM {$table} GROUP BY user_id HAVING bal > 0 ORDER BY bal ASC"
		);

		$since30 = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
		$since90 = gmdate( 'Y-m-d H:i:s', $now - 90 * DAY_IN_SECONDS );

		$issued_30   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE points_delta > 0 AND created_at >= %s", $since30 ) );
		$redeemed_30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(-points_delta),0) FROM {$table} WHERE points_delta < 0 AND type <> 'expire' AND created_at >= %s", $since30 ) );
		$issued_90   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE points_delta > 0 AND created_at >= %s", $since90 ) );
		$redeemed_90 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(-points_delta),0) FROM {$table} WHERE points_delta < 0 AND type <> 'expire' AND created_at >= %s", $since90 ) );

		// Points still live (not yet spent or expired) that carry an expiry date inside the window.
		// The zero-date guard is the same one the expiry engine uses: a row with '0000-00-00' means
		// "never expires", NOT "expired in 1970".
		$expiring = array();
		foreach ( array( 'd30' => 30, 'd60' => 60, 'd90' => 90 ) as $key => $days ) {
			$until           = gmdate( 'Y-m-d H:i:s', $now + $days * DAY_IN_SECONDS );
			$expiring[ $key ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(points_delta),0) FROM {$table}
					WHERE points_delta > 0 AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s",
					gmdate( 'Y-m-d H:i:s', $now ),
					$until
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$balances    = array_map( 'intval', $balances );
		$outstanding = array_sum( $balances );
		$redeem_rate = Rates::redeem_rate();

		$data = array(
			'outstanding'          => $outstanding,
			'redeem_rate'          => $redeem_rate,
			'liability_value'      => round( $outstanding / $redeem_rate, 2 ),
			'members_with_balance' => count( $balances ),
			'expiring'             => $expiring,
			'issued_total'         => $issued,
			'redeemed_total'       => $spent,
			'expired_total'        => $expired,
			'breakage_pct'         => $issued > 0 ? round( $expired / $issued * 100, 2 ) : 0.0,
			'redemption_rate_pct'  => $issued > 0 ? round( $spent / $issued * 100, 2 ) : 0.0,
			'velocity'             => array(
				'issued_30'   => $issued_30,
				'redeemed_30' => $redeemed_30,
				'issued_90'   => $issued_90,
				'redeemed_90' => $redeemed_90,
			),
			'distribution'         => array(
				'p25' => self::percentile( $balances, 0.25 ),
				'p50' => self::percentile( $balances, 0.50 ),
				'p75' => self::percentile( $balances, 0.75 ),
				'p90' => self::percentile( $balances, 0.90 ),
				'max' => $balances ? (int) end( $balances ) : 0,
			),
			'generated_at'         => $now,
		);

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Reward-catalogue price suggestions derived from what members actually hold.
	 *
	 * The logic a shop owner would apply by hand: put an entry reward within reach of the bottom
	 * quartile so newcomers can redeem SOMETHING (the single biggest driver of programme engagement),
	 * a core reward around the median, and an aspirational one near the 90th percentile. Each comes
	 * with the reason, because an unexplained number is not advice.
	 *
	 * @return array<int,array{tier:string,points:int,reach_pct:int,reason:string}>
	 */
	public static function suggest_rewards(): array {
		$snapshot = self::snapshot();
		$dist     = $snapshot['distribution'];
		if ( $snapshot['members_with_balance'] < 1 || $dist['p90'] <= 0 ) {
			return array();
		}

		$rows = array(
			array(
				'tier'      => 'entry',
				'points'    => self::round_price( $dist['p25'] ),
				'reach_pct' => 75,
				'reason'    => __( 'Priced at the bottom quartile so about three quarters of members can already redeem it — a programme where nobody can afford anything is a programme nobody uses.', 'moksa-points-for-woocommerce' ),
			),
			array(
				'tier'      => 'core',
				'points'    => self::round_price( $dist['p50'] ),
				'reach_pct' => 50,
				'reason'    => __( 'Priced at the median balance: the everyday reward half of your members can reach right now.', 'moksa-points-for-woocommerce' ),
			),
			array(
				'tier'      => 'aspirational',
				'points'    => self::round_price( $dist['p90'] ),
				'reach_pct' => 10,
				'reason'    => __( 'Priced near the top decile — something to save towards, which is what keeps balances from being spent the moment they appear.', 'moksa-points-for-woocommerce' ),
			),
		);

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return $row['points'] > 0;
				}
			)
		);
	}

	/**
	 * Plain-language observations about the programme's health, for the dashboard and for an AI
	 * assistant to quote. Each is a fact plus what it implies — never a bare number.
	 *
	 * @return array<int,array{key:string,text:string}>
	 */
	public static function observations(): array {
		$s   = self::snapshot();
		$out = array();

		if ( $s['outstanding'] > 0 ) {
			$out[] = array(
				'key'  => 'liability',
				'text' => sprintf(
					/* translators: 1: points outstanding; 2: their monetary value. */
					__( '%1$s points are in circulation, worth about NT$%2$s if every member redeemed today.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( $s['outstanding'] ),
					number_format_i18n( $s['liability_value'], 2 )
				),
			);
		}

		if ( $s['expiring']['d30'] > 0 ) {
			$out[] = array(
				'key'  => 'expiring',
				'text' => sprintf(
					/* translators: %s: points expiring within 30 days. */
					__( '%s points expire within 30 days — a reminder campaign now converts them into orders instead of into breakage.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( $s['expiring']['d30'] )
				),
			);
		}

		if ( $s['issued_total'] > 0 ) {
			$out[] = array(
				'key'  => 'redemption',
				'text' => sprintf(
					/* translators: 1: redemption rate; 2: breakage rate. */
					__( '%1$s%% of all points issued have been redeemed and %2$s%% expired unused.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( $s['redemption_rate_pct'], 2 ),
					number_format_i18n( $s['breakage_pct'], 2 )
				),
			);

			if ( $s['redemption_rate_pct'] < 20.0 ) {
				$out[] = array(
					'key'  => 'low_redemption',
					'text' => __( 'Redemption is under 20%: members are collecting but not spending. Usually the cheapest reward is still out of reach — check the entry-level suggestion below.', 'moksa-points-for-woocommerce' ),
				);
			}
		}

		if ( $s['velocity']['issued_30'] > 0 && $s['velocity']['redeemed_30'] > $s['velocity']['issued_30'] ) {
			$out[] = array(
				'key'  => 'draining',
				'text' => __( 'More points were redeemed than issued in the last 30 days — the outstanding liability is shrinking, which is healthy, but check that earning is still switched on.', 'moksa-points-for-woocommerce' ),
			);
		}

		return $out;
	}

	/** The value at a percentile of an ASCENDING list (0 for an empty list). */
	private static function percentile( array $sorted, float $p ): int {
		$count = count( $sorted );
		if ( 0 === $count ) {
			return 0;
		}
		$index = (int) floor( ( $count - 1 ) * $p );
		return (int) $sorted[ $index ];
	}

	/** Round a suggested price to a tidy figure a shop would actually print (nearest 50 / 100). */
	private static function round_price( int $points ): int {
		if ( $points <= 0 ) {
			return 0;
		}
		$step = $points >= 1000 ? 100 : 50;
		return (int) ( max( 1, (int) round( $points / $step ) ) * $step );
	}
}
