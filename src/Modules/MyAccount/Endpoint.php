<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\MyAccount;

use Moksafopoi\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The "My points" WooCommerce My Account endpoint: registers the rewrite endpoint + menu item
 * and renders the logged-in customer's points / store-credit balance (a hero card) followed
 * by their paginated ledger history as TW-formatted rows.
 *
 * All dynamic output is escaped; styling is enqueued (never echoed as <style>), gated to
 * is_account_page() with filemtime() cache-busting — the proven moforcoupon pattern.
 */
final class Endpoint {

	/** Endpoint + menu-item key (also the URL segment under /my-account/). */
	public const SLUG = 'my-points';

	/** Page-size for the history list. */
	private const PER_PAGE = 12;

	/** Asset handle for the account-page card CSS. */
	private const HANDLE = 'moksafopoi-card';

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::SLUG, EP_ROOT | EP_PAGES );
	}

	/**
	 * Insert "My points" just before the logout link.
	 *
	 * @param array<string,string> $items
	 * @return array<string,string>
	 */
	public static function add_menu_item( array $items ): array {
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key && ! isset( $out[ self::SLUG ] ) ) {
				$out[ self::SLUG ] = __( 'My points', 'moksa-points-for-woocommerce' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::SLUG ] ) ) {
			$out[ self::SLUG ] = __( 'My points', 'moksa-points-for-woocommerce' );
		}
		return $out;
	}

	/** Render the hero balance card + paginated history inside the account content area. */
	public static function render(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			echo '<p>' . esc_html__( 'Please log in to view your points.', 'moksa-points-for-woocommerce' ) . '</p>';
			return;
		}

		$points = Api::get_points( $user_id );
		$credit = Api::get_balance( $user_id );

		self::render_notice( $user_id );
		self::render_hero( $points, $credit );

		/**
		 * Fires right after the balance hero on the「我的點數」page. The Display layer hangs the
		 * optional 顯示客製化 account message here (moksafopoi_disp_account_msg); other code may too.
		 *
		 * @param int $points The member's current points balance.
		 */
		do_action( 'moksafopoi_after_account_hero', $points );

		self::render_rank( $user_id );
		self::render_badges( $user_id );
		self::render_next_expiry( $user_id, $points );
		self::render_ratio_summary( $points );
		self::render_rewards( $user_id, $points );
		RedeemCode::render( $user_id );
		self::render_history( $user_id );
	}

	/**
	 * "我的排名:第 X 名" — only when the Leaderboard module is enabled and the member actually
	 * ranks (has earned points). Uses the bounded rank query, never a full-table scan.
	 */
	private static function render_rank( int $user_id ): void {
		if ( ! self::module_on( 'leaderboard' ) || ! class_exists( \Moksafopoi\Modules\Leaderboard\Module::class ) ) {
			return;
		}
		$rank = \Moksafopoi\Modules\Leaderboard\Module::rank_for_user( $user_id, 'all' );
		if ( $rank <= 0 ) {
			return;
		}
		echo '<p class="moksafopoi-rank">' . esc_html(
			sprintf(
				/* translators: %s: rank position, e.g. "3". */
				__( 'My rank: No. %s', 'moksa-points-for-woocommerce' ),
				number_format( $rank )
			)
		) . '</p>';
	}

	/**
	 * Earned achievement badges + the next badge's progress. Only when the Badges module is on.
	 */
	private static function render_badges( int $user_id ): void {
		if ( ! self::module_on( 'badges' ) || ! class_exists( \Moksafopoi\Modules\Badges\Module::class ) ) {
			return;
		}
		$badges_class = \Moksafopoi\Modules\Badges\Module::class;

		$earned = $badges_class::earned_badges( $user_id );
		$next   = $badges_class::next_badge( $user_id );

		if ( array() === $earned && null === $next ) {
			return; // No ladder configured / nothing to show.
		}

		echo '<div class="moksafopoi-badges">';
		echo '<h3 class="moksafopoi-badges__title">' . esc_html__( 'My achievement badges', 'moksa-points-for-woocommerce' ) . '</h3>';

		if ( array() === $earned ) {
			echo '<p class="moksafopoi-badges__empty">' . esc_html__( 'No badges earned yet — keep earning points to unlock them!', 'moksa-points-for-woocommerce' ) . '</p>';
		} else {
			echo '<ul class="moksafopoi-badges__list">';
			foreach ( $earned as $badge ) {
				$icon  = (string) ( $badge['icon'] ?? '' );
				$label = (string) ( $badge['label'] ?? '' );
				echo '<li class="moksafopoi-badge">';
				if ( '' !== $icon ) {
					echo '<span class="moksafopoi-badge__icon">' . esc_html( $icon ) . '</span> ';
				}
				echo '<span class="moksafopoi-badge__label">' . esc_html( $label ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( null !== $next ) {
			$badge = $next['badge'];
			$icon  = (string) ( $badge['icon'] ?? '' );
			$label = (string) ( $badge['label'] ?? '' );
			echo '<p class="moksafopoi-badges__next">' . esc_html(
				sprintf(
					/* translators: 1: next badge name (with icon), 2: points still needed. */
					__( '%2$s to go until the next badge "%1$s"', 'moksa-points-for-woocommerce' ),
					trim( $icon . ' ' . $label ),
					self::points_label( (int) $next['remaining'] )
				)
			) . '</p>';
		}

		echo '</div>';
	}

	/** Whether a sibling module's enable option is on (without coupling to ModuleRegistry). */
	private static function module_on( string $key ): bool {
		return 'yes' === get_option( 'moksafopoi_' . $key . '_enabled', 'no' );
	}

	/**
	 * "最近到期" line: the soonest batch of the member's still-held points to expire and its date.
	 * Only rendered when an expiry mechanism is active (moksafopoi_points_expire_months > 0) and
	 * the member actually has future-expiring points. The figure is capped at the current balance so
	 * we never claim more will expire than they hold.
	 */
	private static function render_next_expiry( int $user_id, int $points ): void {
		if ( (int) get_option( 'moksafopoi_points_expire_months', 0 ) <= 0 || $points <= 0 ) {
			return; // Expiry disabled or nothing to expire.
		}

		global $wpdb;
		$table = \Moksafopoi\Support\Schema::ledger_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// Soonest future expiry for this member + how many points expire on (or before) that date.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		$next = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(expires_at) FROM {$table} WHERE user_id = %d AND expires_at IS NOT NULL AND expires_at > %s AND points_delta > 0",
				$user_id,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( '' === $next ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		$expiring = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE user_id = %d AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND points_delta > 0",
				$user_id,
				$now,
				$next
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$expiring = min( $points, $expiring );
		if ( $expiring <= 0 ) {
			return;
		}

		echo '<p class="moksafopoi-next-expiry">' . esc_html(
			sprintf(
				/* translators: 1: points amount (e.g. "300 點"), 2: expiry date (e.g. "2026-07-14"). */
				__( 'Expiring soon: %1$s will expire on %2$s', 'moksa-points-for-woocommerce' ),
				self::points_label( $expiring ),
				self::format_date( $next )
			)
		) . '</p>';
	}

	/**
	 * Plain-language "how points work" summary so a member understands the earn / redeem ratios
	 * (e.g.「消費 NT$1 得 1 點」「100 點 = NT$1」「滿 100 點可折」). All values are options, read
	 * directly (no dependency on the CheckoutRedeem module being on).
	 */
	private static function render_ratio_summary( int $points ): void {
		$per_currency = \Moksafopoi\Support\Rates::earn_rate();
		$redeem_rate  = \Moksafopoi\Support\Rates::redeem_rate();
		$min_points   = max( 0, (int) get_option( 'moksafopoi_redeem_min_points', 100 ) );

		$earn_text = sprintf(
			/* translators: 1: points earned per NT$1 spent (number); 2: the points unit, e.g.「點」. */
			__( 'Earn %1$s %2$s per NT$1 spent', 'moksa-points-for-woocommerce' ),
			self::trim_number( $per_currency ),
			\Moksafopoi\Support\Label::unit()
		);
		$redeem_text = sprintf(
			/* translators: 1: points required to redeem NT$1 (with unit, e.g.「100 點」). */
			__( '%s = NT$1', 'moksa-points-for-woocommerce' ),
			self::points_label( $redeem_rate )
		);

		echo '<div class="moksafopoi-ratio">';
		echo '<h3 class="moksafopoi-ratio__title">' . esc_html__( 'Earning / redemption rate', 'moksa-points-for-woocommerce' ) . '</h3>';
		echo '<ul class="moksafopoi-ratio__list">';
		echo '<li>' . esc_html( $earn_text ) . '</li>';
		echo '<li>' . esc_html( $redeem_text ) . '</li>';
		if ( $min_points > 0 ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: %s: minimum points (with unit) needed to start redeeming, e.g.「100 點」. */
					__( 'Reach %s to redeem at checkout', 'moksa-points-for-woocommerce' ),
					self::points_label( $min_points )
				)
			) . '</li>';
		}
		// Show the member their own current points expressed as money.
		$worth = floor( (float) $points / $redeem_rate );
		if ( $worth > 0 ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: member points (with unit, e.g.「500 點」); 2: their cash value. */
					__( 'Your current %1$s is worth about %2$s', 'moksa-points-for-woocommerce' ),
					self::points_label( $points ),
					'NT$' . number_format( $worth )
				)
			) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	/** Drop a trailing ".00" so an integer rate reads cleanly while decimals survive. */
	private static function trim_number( float $value ): string {
		if ( floor( $value ) === $value ) {
			return number_format( (int) $value );
		}
		return rtrim( rtrim( number_format( $value, 2 ), '0' ), '.' );
	}

	/** One-shot redeem result notice (set by handle_redeem, stored per user transient). */
	private static function render_notice( int $user_id ): void {
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

	/** Reward catalog: each active reward with its cost and a redeem button (gated on affordability). */
	private static function render_rewards( int $user_id, int $points ): void {
		$rewards = self::active_rewards();
		if ( empty( $rewards ) ) {
			return;
		}
		echo '<h3 class="moksafopoi-rewards__title">' . esc_html__( 'Points redemption', 'moksa-points-for-woocommerce' ) . '</h3>';
		echo '<div class="moksafopoi-rewards">';
		foreach ( $rewards as $r ) {
			$cost   = (int) $r['cost_points'];
			$afford = $points >= $cost;
			$out    = 0 === (int) $r['stock'];
			echo '<div class="moksafopoi-reward' . ( $afford && ! $out ? '' : ' is-disabled' ) . '">';
			echo '<div class="moksafopoi-reward__label">' . esc_html( (string) $r['label'] ) . '</div>';
			echo '<div class="moksafopoi-reward__cost">' . esc_html( self::points_label( $cost ) ) . '</div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="moksafopoi_redeem">';
			echo '<input type="hidden" name="reward_id" value="' . esc_attr( (string) $r['id'] ) . '">';
			wp_nonce_field( 'moksafopoi_redeem_' . (int) $r['id'] );
			$label = $out ? __( 'Out of stock', 'moksa-points-for-woocommerce' ) : ( $afford ? __( 'Redeem now', 'moksa-points-for-woocommerce' ) : __( 'Insufficient points', 'moksa-points-for-woocommerce' ) );
			echo '<button type="submit" class="button"' . ( $afford && ! $out ? '' : ' disabled' ) . '>' . esc_html( $label ) . '</button>';
			echo '</form>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Active reward catalog rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_rewards(): array {
		global $wpdb;
		$table = \Moksafopoi\Support\Schema::rewards_table();
		// kind='code' rows are入 lane(輸入代碼領點), never listed as spendable rewards.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); fully static SQL, no user input.
		$rows = $wpdb->get_results( "SELECT id, label, kind, cost_points, stock FROM {$table} WHERE active = 1 AND kind <> 'code' AND ( stock = -1 OR stock > 0 ) ORDER BY cost_points ASC LIMIT 50", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Front-end redeem handler (admin-post; any logged-in customer). Shared by the account-page
	 * reward list AND the [moksafopoi_mall] storefront — both post here with the same per-reward
	 * nonce, so point spending has exactly one entry point. The actual deduction + idempotent
	 * refund-on-failure lives in Api::redeem → Redeem\Service; this handler only gates and routes.
	 */
	public static function handle_redeem(): void {
		$user_id   = get_current_user_id();
		$reward_id = isset( $_POST['reward_id'] ) ? (int) $_POST['reward_id'] : 0;

		// Where to send the member afterwards: the originating mall page (when posted with a safe
		// same-host redirect_to) or the account endpoint. wp_safe_redirect blocks off-host targets.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only to choose a same-host return URL; the state-changing redeem below is nonce-checked.
		$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$fallback    = wc_get_account_endpoint_url( self::SLUG );
		$redirect    = '' !== $redirect_to ? $redirect_to : $fallback;

		if ( $user_id <= 0 ) {
			wp_safe_redirect( $redirect );
			exit;
		}
		check_admin_referer( 'moksafopoi_redeem_' . $reward_id );

		$result = \Moksafopoi\Api::redeem( $user_id, 'reward', array( 'reward_id' => $reward_id ) );
		$notice = is_wp_error( $result )
			? array(
				'error'   => true,
				'message' => $result->get_error_message(),
			)
			: array( 'message' => self::success_message( is_array( $result ) ? $result : array() ) );

		set_transient( 'moksafopoi_redeem_notice_' . $user_id, $notice, 60 );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Build the success notice for whichever reward kind was fulfilled. Coupon shows the minted
	 * code; product shows the created order; anything else gets a generic confirmation.
	 *
	 * @param array<string,mixed> $result
	 */
	private static function success_message( array $result ): string {
		$kind = (string) ( $result['kind'] ?? '' );

		if ( 'product' === $kind ) {
			$order_id = (int) ( $result['order_id'] ?? 0 );
			if ( $order_id > 0 ) {
				return sprintf(
					/* translators: %s: the created order number. */
					__( 'Redemption successful! We have created gift order #%s and will ship it shortly.', 'moksa-points-for-woocommerce' ),
					number_format( $order_id )
				);
			}
			return __( 'Redemption successful! Your gift order has been created and will ship shortly.', 'moksa-points-for-woocommerce' );
		}

		if ( 'coupon' === $kind ) {
			return sprintf(
				/* translators: %s: minted coupon code. */
				__( 'Redemption successful! Your coupon code: %s', 'moksa-points-for-woocommerce' ),
				strtoupper( (string) ( $result['coupon_code'] ?? '' ) )
			);
		}

		return __( 'Redemption successful!', 'moksa-points-for-woocommerce' );
	}

	/** Hero card: current points + currency store-credit. */
	private static function render_hero( int $points, float $credit ): void {
		echo '<div class="moksafopoi-card moksafopoi-card--hero">';
		echo '<div class="moksafopoi-card__metric">';
		echo '<span class="moksafopoi-card__metric-label">' . esc_html__( 'Current points', 'moksa-points-for-woocommerce' ) . '</span>';
		echo '<span class="moksafopoi-card__metric-value">' . esc_html( self::points_label( $points ) ) . '</span>';
		echo '</div>';
		echo '<div class="moksafopoi-card__metric">';
		echo '<span class="moksafopoi-card__metric-label">' . esc_html__( 'Store credit balance', 'moksa-points-for-woocommerce' ) . '</span>';
		echo '<span class="moksafopoi-card__metric-value">' . esc_html( self::money_label( $credit ) ) . '</span>';
		echo '</div>';
		echo '</div>';
	}

	/** Paginated ledger history as a TW-formatted table. */
	private static function render_history( int $user_id ): void {
		$paged  = self::current_page();
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		// Fetch one extra row to know whether a "next" page exists without a COUNT query.
		$rows  = Api::get_history( $user_id, self::PER_PAGE + 1, $offset );
		$has_next = count( $rows ) > self::PER_PAGE;
		$rows  = array_slice( $rows, 0, self::PER_PAGE );

		echo '<h3 class="moksafopoi-history__title">' . esc_html__( 'Points history', 'moksa-points-for-woocommerce' ) . '</h3>';

		if ( array() === $rows ) {
			echo '<p class="moksafopoi-history__empty">' . esc_html__( 'No points history yet.', 'moksa-points-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="moksafopoi-history shop_table shop_table_responsive">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Item', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Points', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Store credit', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Balance', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$points_delta = isset( $row['points_delta'] ) ? (int) $row['points_delta'] : 0;
			$amount_delta = isset( $row['amount_delta'] ) ? (float) $row['amount_delta'] : 0.0;
			$balance      = isset( $row['balance_after'] ) ? (int) $row['balance_after'] : 0;
			$note         = isset( $row['note'] ) && '' !== (string) $row['note']
				? (string) $row['note']
				: self::source_label( (string) ( $row['source'] ?? '' ), (string) ( $row['type'] ?? '' ) );

			echo '<tr>';
			echo '<td data-title="' . esc_attr__( 'Date', 'moksa-points-for-woocommerce' ) . '">' . esc_html( self::format_date( (string) ( $row['created_at'] ?? '' ) ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Item', 'moksa-points-for-woocommerce' ) . '">' . esc_html( $note ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Points', 'moksa-points-for-woocommerce' ) . '" class="' . esc_attr( self::delta_class( $points_delta ) ) . '">' . esc_html( self::signed_points( $points_delta ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Store credit', 'moksa-points-for-woocommerce' ) . '" class="' . esc_attr( self::delta_class( (int) round( $amount_delta ) ) ) . '">' . esc_html( self::signed_money( $amount_delta ) ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Balance', 'moksa-points-for-woocommerce' ) . '">' . esc_html( self::points_label( $balance ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		self::render_pagination( $paged, $has_next );
	}

	/** Prev / next links built from the account-page permalink + the endpoint. */
	private static function render_pagination( int $paged, bool $has_next ): void {
		if ( $paged <= 1 && ! $has_next ) {
			return;
		}
		$base = wc_get_account_endpoint_url( self::SLUG );

		echo '<div class="moksafopoi-history__nav">';
		if ( $paged > 1 ) {
			$prev = 2 === $paged ? $base : add_query_arg( 'mp_page', $paged - 1, $base );
			echo '<a class="button moksafopoi-history__prev" href="' . esc_url( $prev ) . '">' . esc_html__( 'Previous', 'moksa-points-for-woocommerce' ) . '</a>';
		}
		if ( $has_next ) {
			$next = add_query_arg( 'mp_page', $paged + 1, $base );
			echo '<a class="button moksafopoi-history__next" href="' . esc_url( $next ) . '">' . esc_html__( 'Next', 'moksa-points-for-woocommerce' ) . '</a>';
		}
		echo '</div>';
	}

	private static function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging of the viewer's OWN ledger; no state change, value is sanitized to a positive int.
		$raw = isset( $_GET['mp_page'] ) ? absint( wp_unslash( $_GET['mp_page'] ) ) : 1;
		return max( 1, $raw );
	}

	/** Load the account-page card CSS only on the account page (filemtime cache-bust). */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		self::enqueue_card_styles();
	}

	/**
	 * Register + enqueue the shared card stylesheet (account page + mall storefront). Public so the
	 * [moksafopoi_mall] shortcode can pull the same styles in on any page it is placed.
	 */
	public static function enqueue_card_styles(): void {
		$css = 'src/Modules/MyAccount/assets/css/points.css';
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, MOKSAFOPOI_PLUGIN_URL . $css, array(), self::ver( $css ) );
		}
		wp_enqueue_style( self::HANDLE );
	}

	private static function ver( string $rel ): string {
		$path = MOKSAFOPOI_PLUGIN_DIR . $rel;
		return file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOPOI_VERSION;
	}

	/* ---------------- TW formatting helpers ---------------- */

	private static function points_label( int $points ): string {
		// Central brand helper so a rebranded unit (金幣 / 哩程 / P) shows here too.
		return \Moksafopoi\Support\Label::format( $points );
	}

	private static function signed_points( int $points ): string {
		if ( 0 === $points ) {
			return '0';
		}
		$sign = $points > 0 ? '+' : '−';
		return $sign . number_format( abs( $points ) );
	}

	/** NT$ integer money label (TW convention: whole dollars). */
	private static function money_label( float $amount ): string {
		return 'NT$' . number_format( (int) round( $amount ) );
	}

	private static function signed_money( float $amount ): string {
		$int = (int) round( $amount );
		if ( 0 === $int ) {
			return '—';
		}
		$sign = $int > 0 ? '+' : '−';
		return $sign . 'NT$' . number_format( abs( $int ) );
	}

	private static function delta_class( int $delta ): string {
		if ( $delta > 0 ) {
			return 'moksafopoi-history__delta moksafopoi-history__delta--up';
		}
		if ( $delta < 0 ) {
			return 'moksafopoi-history__delta moksafopoi-history__delta--down';
		}
		return 'moksafopoi-history__delta';
	}

	private static function format_date( string $gmt ): string {
		if ( '' === $gmt ) {
			return '—';
		}
		$ts = strtotime( $gmt . ' UTC' );
		if ( false === $ts ) {
			return $gmt;
		}
		// Render in the site's timezone using the site date format.
		return wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
	}

	/** Human-readable fallback label for a ledger row when no note was stored. */
	private static function source_label( string $source, string $type ): string {
		$map = array(
			'coupon_redeemed' => __( 'Add points on coupon use', 'moksa-points-for-woocommerce' ),
			'cashback'        => __( 'Purchase cashback', 'moksa-points-for-woocommerce' ),
			'giftcard'        => __( 'Gift card top-up', 'moksa-points-for-woocommerce' ),
			'spend_rule'      => __( 'Earn points on purchase', 'moksa-points-for-woocommerce' ),
			'manual'          => __( 'Manual adjustment', 'moksa-points-for-woocommerce' ),
			'redeem_coupon'   => __( 'Redeem for a coupon', 'moksa-points-for-woocommerce' ),
			'redeem_product'  => __( 'Redeem for a product', 'moksa-points-for-woocommerce' ),
			'checkout_wallet' => __( 'Checkout redemption', 'moksa-points-for-woocommerce' ),
			'refund'          => __( 'Refund reversal', 'moksa-points-for-woocommerce' ),
			'expiry'          => __( 'Points expiry', 'moksa-points-for-woocommerce' ),
			'migration'       => __( 'Opening balance import', 'moksa-points-for-woocommerce' ),
		);
		if ( isset( $map[ $source ] ) ) {
			return $map[ $source ];
		}
		$types = array(
			'earn'   => __( 'Earned', 'moksa-points-for-woocommerce' ),
			'redeem' => __( 'Redeemed', 'moksa-points-for-woocommerce' ),
			'expire' => __( 'Expired', 'moksa-points-for-woocommerce' ),
			'adjust' => __( 'Adjustment', 'moksa-points-for-woocommerce' ),
			'credit' => __( 'Top-up', 'moksa-points-for-woocommerce' ),
			'debit'  => __( 'Deducted', 'moksa-points-for-woocommerce' ),
		);
		return $types[ $type ] ?? __( 'Change', 'moksa-points-for-woocommerce' );
	}
}
