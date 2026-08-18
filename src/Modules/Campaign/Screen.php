<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the 行銷活動 admin page (list + add/edit form) and processes its admin-post writes
 * (save / delete / toggle). The page slug, capability, nonce and action all live on {@see Module}
 * so the handler and the menu stay in lock-step. Datetime inputs are HTML <input type="datetime-local">
 * (site-local), converted to unix seconds on save and back to local strings for editing.
 *
 * All writes go through one nonce-protected, capability-gated admin-post handler; every output is
 * escaped and every input unslashed + sanitised.
 */
final class Screen {

	/** Render the list table, or the edit form when ?view=edit. */
	public static function render(): void {
		if ( ! current_user_can( Module::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing of the admin view.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksa-points-for-woocommerce">';
		echo '<div class="mowp-intro">';
		echo '<h1>' . esc_html__( 'Marketing campaigns', 'moksa-points-for-woocommerce' ) . '</h1>';

		if ( 'edit' === $view ) {
			echo '<p><a href="' . esc_url( Module::page_url() ) . '" class="button">' . esc_html__( 'Back to list', 'moksa-points-for-woocommerce' ) . '</a></p>';
		} else {
			echo '<p><a href="' . esc_url( add_query_arg( 'view', 'edit', Module::page_url() ) ) . '" class="button button-primary">' . esc_html__( 'Add campaign', 'moksa-points-for-woocommerce' ) . '</a></p>';
		}
		echo '</div>';

		self::notice();

		if ( 'edit' === $view ) {
			self::render_form();
		} else {
			self::render_list();
		}

		echo '</div></div>';
	}

	/** Success flash, driven by the redirect query arg the handler sets. */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag.
		if ( ! isset( $_GET['moksafopoi_msg'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag.
		$msg = sanitize_key( wp_unslash( $_GET['moksafopoi_msg'] ) );

		$map = array(
			'saved'   => __( 'Campaign saved.', 'moksa-points-for-woocommerce' ),
			'deleted' => __( 'Campaign deleted.', 'moksa-points-for-woocommerce' ),
			'toggled' => __( 'Enabled status updated.', 'moksa-points-for-woocommerce' ),
		);
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
	}

	private static function render_list(): void {
		$campaigns = Campaigns::all();
		$active    = Campaigns::active();
		$active_id = null !== $active ? $active['id'] : '';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Start / end time', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Points multiplier', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Commission multiplier', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Member discount', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $campaigns ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No campaigns yet. Click "Add campaign" above to get started.', 'moksa-points-for-woocommerce' ) . '</td></tr>';
		}

		foreach ( $campaigns as $campaign ) {
			$id        = (string) $campaign['id'];
			$is_active = ( $id === $active_id );

			$edit_url   = add_query_arg(
				array(
					'view' => 'edit',
					'id'   => $id,
				),
				Module::page_url()
			);
			$toggle_url = self::post_url(
				'toggle',
				$id,
				array( 'enabled' => $campaign['enabled'] ? 0 : 1 )
			);
			$delete_url = self::post_url( 'delete', $id );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $campaign['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( self::fmt_range( $campaign['start'], $campaign['end'] ) );
			$schedule = self::fmt_schedule( $campaign );
			if ( '' !== $schedule ) {
				echo '<br><span class="description">' . esc_html( $schedule ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( '×' . self::fmt_mult( $campaign['points_mult'] ) ) . '</td>';
			echo '<td>' . esc_html( '×' . self::fmt_mult( $campaign['commission_mult'] ) ) . '</td>';
			echo '<td>' . esc_html( self::fmt_mult( $campaign['member_discount_pct'] ) . '%' ) . '</td>';

			if ( $is_active ) {
				echo '<td><span class="moksafopoi-badge live">' . esc_html__( 'In progress', 'moksa-points-for-woocommerce' ) . '</span></td>';
			} elseif ( $campaign['enabled'] ) {
				echo '<td><span class="moksafopoi-badge on">' . esc_html__( 'Enabled', 'moksa-points-for-woocommerce' ) . '</span></td>';
			} else {
				echo '<td><span class="moksafopoi-badge off">' . esc_html__( 'Disabled', 'moksa-points-for-woocommerce' ) . '</span></td>';
			}

			echo '<td>';
			echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'moksa-points-for-woocommerce' ) . '</a> | ';
			echo '<a href="' . esc_url( $toggle_url ) . '">' . esc_html( $campaign['enabled'] ? __( 'Disabled', 'moksa-points-for-woocommerce' ) : __( 'Enable', 'moksa-points-for-woocommerce' ) ) . '</a> | ';
			echo '<a href="' . esc_url( $delete_url ) . '" class="submitdelete" onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Are you sure you want to delete this campaign?', 'moksa-points-for-woocommerce' ) ) ) . ');">' . esc_html__( 'Delete', 'moksa-points-for-woocommerce' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description" style="margin-top:12px">'
			. esc_html__( 'If multiple campaigns are running at the same time, the system uses the one that ends earliest as the active public campaign. The points multiplier compounds into earning (up to 10×); the commission multiplier and member discount are read and applied by other plugins (affiliate / member).', 'moksa-points-for-woocommerce' )
			. '</p>';
	}

	private static function render_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill of the edit form; the write is nonce-checked on submit.
		$id       = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$campaign = '' !== $id ? Campaigns::find( $id ) : null;

		$name      = $campaign ? (string) $campaign['name'] : '';
		$start     = $campaign ? (int) $campaign['start'] : 0;
		$end       = $campaign ? (int) $campaign['end'] : 0;
		$points    = $campaign ? (float) $campaign['points_mult'] : 1.0;
		$commission = $campaign ? (float) $campaign['commission_mult'] : 1.0;
		$discount  = $campaign ? (float) $campaign['member_discount_pct'] : 0.0;
		$enabled   = $campaign ? (bool) $campaign['enabled'] : true;
		$recurrence = $campaign ? (string) $campaign['recurrence'] : 'none';
		$days       = $campaign ? (array) $campaign['days'] : array();
		$time_start = $campaign ? (string) $campaign['time_start'] : '';
		$time_end   = $campaign ? (string) $campaign['time_end'] : '';

		echo '<div class="mowp-panel mowp-panel--wide"><div class="mowp-panel__head">'
			. esc_html( $campaign ? __( 'Edit campaign', 'moksa-points-for-woocommerce' ) : __( 'Add campaign', 'moksa-points-for-woocommerce' ) )
			. '</div><div class="mowp-panel__body">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Module::ACTION ) . '">';
		echo '<input type="hidden" name="op" value="save">';
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( Module::NONCE );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Name.
		echo '<tr><th scope="row"><label for="moksafopoi-campaign-name">' . esc_html__( 'Campaign name', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="name" id="moksafopoi-campaign-name" type="text" class="regular-text" required value="' . esc_attr( $name ) . '">';
		echo '<p class="description">' . esc_html__( 'The campaign name shown on the store badge and in the admin list, e.g. "11.11 Triple Points".', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Start.
		echo '<tr><th scope="row"><label for="moksafopoi-campaign-start">' . esc_html__( 'Start time', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="start" id="moksafopoi-campaign-start" type="datetime-local" value="' . esc_attr( self::to_local_input( $start ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Leave blank for no start limit.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// End.
		echo '<tr><th scope="row"><label for="moksafopoi-campaign-end">' . esc_html__( 'End time', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="end" id="moksafopoi-campaign-end" type="datetime-local" value="' . esc_attr( self::to_local_input( $end ) ) . '">';
		echo '<p class="description">' . esc_html__( 'The campaign is only active between the start and end times; with a repeat schedule these two act as the overall validity range. Leave blank for no limit.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		self::render_schedule_rows( $recurrence, $days, $time_start, $time_end );

		// Points multiplier.
		echo '<tr><th scope="row"><label for="moksafopoi-campaign-points">' . esc_html__( 'Points multiplier', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="points_mult" id="moksafopoi-campaign-points" type="number" min="1" max="' . esc_attr( (string) Campaigns::MAX_MULTIPLIER ) . '" step="0.1" class="small-text" value="' . esc_attr( self::fmt_mult( $points ) ) . '">';
		echo '<p class="description">' . esc_html__( 'During the campaign, earning is multiplied by this factor (1 = no boost, max 10).', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Commission multiplier.
		echo '<tr><th scope="row"><label for="moksafopoi-campaign-commission">' . esc_html__( 'Commission multiplier', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="commission_mult" id="moksafopoi-campaign-commission" type="number" min="1" max="' . esc_attr( (string) Campaigns::MAX_MULTIPLIER ) . '" step="0.1" class="small-text" value="' . esc_attr( self::fmt_mult( $commission ) ) . '">';
		echo '<p class="description">' . esc_html__( 'The commission multiplier read by the affiliate plugin (moforaffiliate) (1 = no boost, max 10).', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Member discount.
		echo '<tr><th scope="row"><label for="moksafopoi-campaign-discount">' . esc_html__( 'Member discount %', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="member_discount_pct" id="moksafopoi-campaign-discount" type="number" min="0" max="' . esc_attr( (string) Campaigns::MAX_DISCOUNT_PCT ) . '" step="1" class="small-text" value="' . esc_attr( self::fmt_mult( $discount ) ) . '">';
		echo '<p class="description">' . esc_html__( 'The extra campaign discount percentage read by the member plugin (moformember) (0–90).', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Enabled.
		echo '<tr><th scope="row">' . esc_html__( 'Enable', 'moksa-points-for-woocommerce' ) . '</th><td>';
		echo '<label><input name="enabled" type="checkbox" value="1"' . checked( true, $enabled, false ) . '> ' . esc_html__( 'Enable this campaign (still only active within the start and end times)', 'moksa-points-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'moksa-points-for-woocommerce' ) . '</button> ';
		echo '<a href="' . esc_url( Module::page_url() ) . '" class="button">' . esc_html__( 'Cancel', 'moksa-points-for-woocommerce' ) . '</a></p>';
		echo '</form>';
		echo '</div></div>';
	}

	/**
	 * The repeat-schedule block of the form: a recurrence mode plus the selector rows for each mode
	 * and the daily time window. Only the rows for the selected mode are visible (JS toggle attached
	 * in {@see enqueue_admin()}); the handler reads only the field belonging to the chosen mode, so a
	 * stale hidden row can never leak into the saved campaign.
	 *
	 * @param array<int,string> $days
	 */
	private static function render_schedule_rows( string $recurrence, array $days, string $time_start, string $time_end ): void {
		// Repeat mode.
		echo '<tr><th scope="row"><label for="moksafopoi-campaign-recurrence">' . esc_html__( 'Repeat schedule', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<select name="recurrence" id="moksafopoi-campaign-recurrence" class="moksafopoi-campaign-recurrence">';
		$modes = array(
			'none'    => __( 'Do not repeat (a single window)', 'moksa-points-for-woocommerce' ),
			'weekly'  => __( 'Weekly (e.g. double points every weekend)', 'moksa-points-for-woocommerce' ),
			'monthly' => __( 'Monthly (e.g. bonus day on the 5th of every month)', 'moksa-points-for-woocommerce' ),
			'yearly'  => __( 'Yearly (e.g. an anniversary sale)', 'moksa-points-for-woocommerce' ),
		);
		foreach ( $modes as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $recurrence, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'A repeating campaign is only live on the days you pick below, within the daily time window.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Weekly: weekday checkboxes, named in the site language.
		$weekly = ( 'weekly' === $recurrence ) ? $days : array();
		echo '<tr class="moksafopoi-rec-weekly"><th scope="row">' . esc_html__( 'Repeat on these weekdays', 'moksa-points-for-woocommerce' ) . '</th><td>';
		for ( $index = 0; $index < 7; $index++ ) {
			echo '<label style="margin-right:12px"><input type="checkbox" name="days_weekly[]" value="' . esc_attr( (string) $index ) . '"'
				. checked( true, in_array( (string) $index, $weekly, true ), false ) . '> '
				. esc_html( self::weekday_name( $index ) ) . '</label>';
		}
		echo '</td></tr>';

		// Monthly: day-of-month list.
		$monthly = ( 'monthly' === $recurrence ) ? implode( ',', $days ) : '';
		echo '<tr class="moksafopoi-rec-monthly"><th scope="row"><label for="moksafopoi-campaign-monthly">' . esc_html__( 'Repeat on these days of the month', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="days_monthly" id="moksafopoi-campaign-monthly" type="text" class="regular-text" value="' . esc_attr( $monthly ) . '" placeholder="1,15,28">';
		echo '<p class="description">' . esc_html__( 'Comma-separated, 1–31. A day later than the month is long runs on the last day of that month instead (31 → 30 / 28).', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Yearly: MM-DD list.
		$yearly = ( 'yearly' === $recurrence ) ? implode( ',', $days ) : '';
		echo '<tr class="moksafopoi-rec-yearly"><th scope="row"><label for="moksafopoi-campaign-yearly">' . esc_html__( 'Repeat on these dates each year', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="days_yearly" id="moksafopoi-campaign-yearly" type="text" class="regular-text" value="' . esc_attr( $yearly ) . '" placeholder="11-11,12-25">';
		echo '<p class="description">' . esc_html__( 'Comma-separated MM-DD dates, e.g. 11-11,12-25.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Daily time window (shared by every repeating mode).
		echo '<tr class="moksafopoi-rec-window"><th scope="row"><label for="moksafopoi-campaign-time-start">' . esc_html__( 'Daily time window', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="time_start" id="moksafopoi-campaign-time-start" type="time" value="' . esc_attr( $time_start ) . '"> — ';
		echo '<input name="time_end" id="moksafopoi-campaign-time-end" type="time" value="' . esc_attr( $time_end ) . '">';
		echo '<p class="description">' . esc_html__( 'Leave both blank for the whole day. Times are the site timezone, e.g. 20:00–23:59 for an evening flash sale.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';
	}

	/** A localised weekday name (0 = Sunday), falling back to English outside WP locale data. */
	private static function weekday_name( int $index ): string {
		global $wp_locale;
		if ( $wp_locale instanceof \WP_Locale ) {
			return (string) $wp_locale->get_weekday( $index );
		}
		$names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		return $names[ $index ] ?? (string) $index;
	}

	/**
	 * Single admin-post handler for save / delete / toggle. Nonce + capability gate everything, all
	 * input is unslashed + sanitised. Redirects back to the list with a flash flag.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( Module::NONCE );

		// Save posts a form; delete/toggle are nonce-protected GET row links — read from $_REQUEST.
		$op = isset( $_REQUEST['op'] ) ? sanitize_key( wp_unslash( $_REQUEST['op'] ) ) : '';
		$id = isset( $_REQUEST['id'] ) ? sanitize_key( wp_unslash( $_REQUEST['id'] ) ) : '';

		switch ( $op ) {
			case 'delete':
				Campaigns::delete( $id );
				$msg = 'deleted';
				break;

			case 'toggle':
				$enabled = isset( $_REQUEST['enabled'] ) ? absint( wp_unslash( $_REQUEST['enabled'] ) ) : 0;
				Campaigns::set_enabled( $id, 1 === $enabled );
				$msg = 'toggled';
				break;

			case 'save':
			default:
				self::save_from_post( $id );
				$msg = 'saved';
				break;
		}

		wp_safe_redirect( add_query_arg( 'moksafopoi_msg', $msg, Module::page_url() ) );
		exit;
	}

	/** Sanitise the submitted form and persist it. */
	private static function save_from_post( string $id ): void {
		// The sole caller, handle(), gates on capability and check_admin_referer( Module::NONCE )
		// before dispatching here; this method only reads the already-authorised, already-unslashed
		// + sanitised POST payload.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce + capability verified in handle() before this runs.
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		$start_raw = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end_raw   = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';

		$points     = isset( $_POST['points_mult'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['points_mult'] ) ) : 1.0;
		$commission = isset( $_POST['commission_mult'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['commission_mult'] ) ) : 1.0;
		$discount   = isset( $_POST['member_discount_pct'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['member_discount_pct'] ) ) : 0.0;

		$enabled = isset( $_POST['enabled'] );

		$recurrence = isset( $_POST['recurrence'] ) ? sanitize_key( wp_unslash( $_POST['recurrence'] ) ) : 'none';
		if ( ! in_array( $recurrence, Campaigns::RECURRENCES, true ) ) {
			$recurrence = 'none';
		}

		// Read ONLY the selector belonging to the chosen mode, so the hidden rows of the other modes
		// can never leak into the saved campaign.
		$days = array();
		if ( 'weekly' === $recurrence && isset( $_POST['days_weekly'] ) && is_array( $_POST['days_weekly'] ) ) {
			$days = array_map( 'sanitize_text_field', wp_unslash( $_POST['days_weekly'] ) );
		} elseif ( 'monthly' === $recurrence && isset( $_POST['days_monthly'] ) ) {
			$days = explode( ',', sanitize_text_field( wp_unslash( $_POST['days_monthly'] ) ) );
		} elseif ( 'yearly' === $recurrence && isset( $_POST['days_yearly'] ) ) {
			$days = explode( ',', sanitize_text_field( wp_unslash( $_POST['days_yearly'] ) ) );
		}

		$time_start = isset( $_POST['time_start'] ) ? sanitize_text_field( wp_unslash( $_POST['time_start'] ) ) : '';
		$time_end   = isset( $_POST['time_end'] ) ? sanitize_text_field( wp_unslash( $_POST['time_end'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Campaigns::save(
			$id,
			array(
				'name'                => $name,
				'start'               => self::from_local_input( $start_raw ),
				'end'                 => self::from_local_input( $end_raw ),
				'points_mult'         => $points,
				'commission_mult'     => $commission,
				'member_discount_pct' => $discount,
				'enabled'             => $enabled,
				'recurrence'          => $recurrence,
				'days'                => $days,
				'time_start'          => 'none' === $recurrence ? '' : $time_start,
				'time_end'            => 'none' === $recurrence ? '' : $time_end,
			)
		);
	}

	/* ---------------------------------------------------------------- datetime + formatting */

	/**
	 * Convert a unix timestamp to the site-local "Y-m-d\TH:i" string a datetime-local input expects.
	 * Empty / zero → empty string.
	 */
	private static function to_local_input( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}
		// Display in the site's configured timezone so the operator sees local wall-clock time.
		return (string) wp_date( 'Y-m-d\TH:i', $timestamp );
	}

	/**
	 * Convert a datetime-local string (site-local wall-clock) to a unix timestamp. Invalid / empty
	 * → 0. Interprets the string in the site's timezone (matching what to_local_input rendered).
	 */
	private static function from_local_input( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		try {
			$dt = new \DateTimeImmutable( $value, $tz );
		} catch ( \Exception $e ) {
			return 0;
		}
		return $dt->getTimestamp();
	}

	/** Human range for the list, in site-local time. */
	private static function fmt_range( int $start, int $end ): string {
		$fmt   = 'Y-m-d H:i';
		$left  = $start > 0 ? (string) wp_date( $fmt, $start ) : __( 'No limit', 'moksa-points-for-woocommerce' );
		$right = $end > 0 ? (string) wp_date( $fmt, $end ) : __( 'No limit', 'moksa-points-for-woocommerce' );
		return $left . ' ~ ' . $right;
	}

	/**
	 * One-line human summary of a repeat schedule for the list ('' when the campaign does not repeat).
	 *
	 * @param array<string,mixed> $campaign
	 */
	private static function fmt_schedule( array $campaign ): string {
		$recurrence = isset( $campaign['recurrence'] ) ? (string) $campaign['recurrence'] : 'none';
		if ( 'none' === $recurrence ) {
			return '';
		}

		$days = isset( $campaign['days'] ) && is_array( $campaign['days'] ) ? $campaign['days'] : array();
		if ( array() === $days ) {
			return __( 'Repeats, but no days selected yet (not running)', 'moksa-points-for-woocommerce' );
		}

		if ( 'weekly' === $recurrence ) {
			$names = array();
			foreach ( $days as $day ) {
				$names[] = self::weekday_name( (int) $day );
			}
			/* translators: %s: comma-separated weekday names. */
			$text = sprintf( __( 'Every week on %s', 'moksa-points-for-woocommerce' ), implode( '、', $names ) );
		} elseif ( 'monthly' === $recurrence ) {
			/* translators: %s: comma-separated days of the month. */
			$text = sprintf( __( 'Every month on day %s', 'moksa-points-for-woocommerce' ), implode( '、', $days ) );
		} else {
			/* translators: %s: comma-separated MM-DD dates. */
			$text = sprintf( __( 'Every year on %s', 'moksa-points-for-woocommerce' ), implode( '、', $days ) );
		}

		$from = isset( $campaign['time_start'] ) ? (string) $campaign['time_start'] : '';
		$to   = isset( $campaign['time_end'] ) ? (string) $campaign['time_end'] : '';
		if ( '' !== $from || '' !== $to ) {
			$text .= ' ' . ( '' !== $from ? $from : '00:00' ) . '–' . ( '' !== $to ? $to : '23:59' );
		}

		return $text;
	}

	/** Format a multiplier/percentage without a trailing ".0" for whole numbers. */
	private static function fmt_mult( float $value ): string {
		return ( floor( $value ) === $value )
			? (string) (int) $value
			: rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/** Build a nonce-protected admin-post link for a state-changing row action. */
	private static function post_url( string $op, string $id, array $extra = array() ): string {
		$args = array_merge(
			array(
				'action' => Module::ACTION,
				'op'     => $op,
				'id'     => $id,
			),
			$extra
		);

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			Module::NONCE
		);
	}

	/**
	 * Enqueue the badge styles on the campaigns page only (admin_enqueue_scripts — the 'common'
	 * stylesheet is already printed by the time the page callback renders).
	 */
	public static function enqueue_admin(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, Module::PAGE ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.moksafopoi-badge{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:18px}'
			. '.moksafopoi-badge.live{background:#fef7e0;color:#b06000}'
			. '.moksafopoi-badge.on{background:#e6f4ea;color:#137333}'
			. '.moksafopoi-badge.off{background:#f1f1f1;color:#777}'
		);

		// Show only the schedule rows belonging to the selected repeat mode. Vanilla JS attached to
		// a core handle so no <script> is echoed.
		wp_enqueue_script( 'wp-dom-ready' );
		wp_add_inline_script(
			'wp-dom-ready',
			'wp.domReady(function(){'
			. 'var sel=document.querySelector(".moksafopoi-campaign-recurrence");'
			. 'if(!sel){return;}'
			. 'function show(cls,on){document.querySelectorAll(cls).forEach(function(el){el.style.display=on?"":"none";});}'
			. 'function sync(){var m=sel.value;'
			. 'show(".moksafopoi-rec-weekly","weekly"===m);'
			. 'show(".moksafopoi-rec-monthly","monthly"===m);'
			. 'show(".moksafopoi-rec-yearly","yearly"===m);'
			. 'show(".moksafopoi-rec-window","none"!==m);'
			. '}'
			. 'sel.addEventListener("change",sync);sync();'
			. '});'
		);
	}
}
