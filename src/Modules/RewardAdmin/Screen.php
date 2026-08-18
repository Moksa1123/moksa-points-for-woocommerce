<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\RewardAdmin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the 兌換型錄 admin page (list + add/edit form) and processes its admin-post writes
 * (save / delete / toggle). The page slug, capability, nonce and action all live on
 * {@see Module} so the handler and the menu stay in lock-step. Coupon is the only kind today,
 * so the payload form exposes the three fields the redeem service reads.
 */
final class Screen {

	/** Render the list table, or the edit form when ?action=edit. */
	public static function render(): void {
		if ( ! current_user_can( Module::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing of the admin view.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

		echo '<div class="wrap"><div class="mowp-shell" data-ns="moksa-points-for-woocommerce">';
		echo '<div class="mowp-intro">';
		echo '<h1>' . esc_html__( 'Redemption catalog', 'moksa-points-for-woocommerce' ) . '</h1>';

		if ( 'edit' === $view ) {
			echo '<p><a href="' . esc_url( Module::page_url() ) . '" class="button">' . esc_html__( 'Back to list', 'moksa-points-for-woocommerce' ) . '</a></p>';
		} else {
			echo '<p><a href="' . esc_url( add_query_arg( 'view', 'edit', Module::page_url() ) ) . '" class="button button-primary">' . esc_html__( 'Add redemption item', 'moksa-points-for-woocommerce' ) . '</a></p>';
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

	/** Success / error flash, driven by the redirect query arg the handler sets. */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag.
		if ( ! isset( $_GET['moksafopoi_msg'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag.
		$msg = sanitize_key( wp_unslash( $_GET['moksafopoi_msg'] ) );

		$map = array(
			'saved'   => __( 'Redemption item saved.', 'moksa-points-for-woocommerce' ),
			'deleted' => __( 'Redemption item deleted.', 'moksa-points-for-woocommerce' ),
			'toggled' => __( 'Publish status updated.', 'moksa-points-for-woocommerce' ),
		);
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
	}

	private static function render_list(): void {
		$rewards = Rewards::all();

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Points required', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Template coupon', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Stock', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'moksa-points-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rewards ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No redemption items yet; click "Add redemption item" above to start creating one.', 'moksa-points-for-woocommerce' ) . '</td></tr>';
		}

		foreach ( $rewards as $reward ) {
			$id      = (int) $reward['id'];
			$payload = self::decode_payload( (string) ( $reward['payload'] ?? '' ) );
			$stock   = (int) $reward['stock'];
			$active  = (int) $reward['active'];

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
				array( 'active' => $active ? 0 : 1 )
			);
			$delete_url = self::post_url( 'delete', $id );

			// "範本券 / 標的" cell: coupon shows its template; product shows its商品 ID; code shows the code + points.
			if ( 'product' === (string) $reward['kind'] ) {
				$ref_cell = '' !== (string) ( $payload['product_id'] ?? '' )
					? sprintf(
						/* translators: %s: WooCommerce product ID. */
						__( 'Product #%s', 'moksa-points-for-woocommerce' ),
						number_format_i18n( (int) ( $payload['product_id'] ?? 0 ) )
					)
					: '';
			} elseif ( 'code' === (string) $reward['kind'] ) {
				$ref_cell = sprintf(
					/* translators: 1: the redeem code, 2: points it grants. */
					__( '%1$s (+%2$s points)', 'moksa-points-for-woocommerce' ),
					(string) ( $payload['code'] ?? '' ),
					number_format_i18n( (int) ( $payload['points'] ?? 0 ) )
				);
			} else {
				$ref_cell = (string) ( $payload['template'] ?? '' );
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $reward['label'] ) . '</strong></td>';
			echo '<td>' . esc_html( self::kind_label( (string) $reward['kind'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $reward['cost_points'] ) ) . '</td>';
			echo '<td>' . esc_html( $ref_cell ) . '</td>';
			echo '<td>' . esc_html( -1 === $stock ? __( 'No limit', 'moksa-points-for-woocommerce' ) : (string) $stock ) . '</td>';

			if ( $active ) {
				echo '<td><span class="moksafopoi-badge on">' . esc_html__( 'Published', 'moksa-points-for-woocommerce' ) . '</span></td>';
			} else {
				echo '<td><span class="moksafopoi-badge off">' . esc_html__( 'Unpublished', 'moksa-points-for-woocommerce' ) . '</span></td>';
			}

			echo '<td>';
			echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'moksa-points-for-woocommerce' ) . '</a> | ';
			echo '<a href="' . esc_url( $toggle_url ) . '">' . esc_html( $active ? __( 'Unpublish', 'moksa-points-for-woocommerce' ) : __( 'Publish', 'moksa-points-for-woocommerce' ) ) . '</a> | ';
			echo '<a href="' . esc_url( $delete_url ) . '" class="submitdelete" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Are you sure you want to delete this redemption item?', 'moksa-points-for-woocommerce' ) ) ) . ');">' . esc_html__( 'Delete', 'moksa-points-for-woocommerce' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description" style="margin-top:12px">'
			. esc_html__( 'Redemption items are used by the "Points redemption" module: stock -1 means unlimited; the template coupon must be an existing moforcoupon coupon code or ID.', 'moksa-points-for-woocommerce' )
			. '</p>';
	}

	private static function render_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill of the edit form; the write is nonce-checked on submit.
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$reward = $id > 0 ? Rewards::find( $id ) : null;

		$payload     = $reward ? self::decode_payload( (string) ( $reward['payload'] ?? '' ) ) : array();
		$label       = $reward ? (string) $reward['label'] : '';
		$cost        = $reward ? (int) $reward['cost_points'] : 0;
		$stock       = $reward ? (int) $reward['stock'] : -1;
		$active      = $reward ? (int) $reward['active'] : 1;
		$template    = (string) ( $payload['template'] ?? '' );
		$prefix      = (string) ( $payload['prefix'] ?? 'PTS' );
		$expiry_days = (int) ( $payload['expiry_days'] ?? 0 );
		$kind        = $reward ? (string) $reward['kind'] : 'coupon';
		$product_id  = (int) ( $payload['product_id'] ?? 0 );
		$note        = (string) ( $payload['note'] ?? '' );

		echo '<div class="mowp-panel mowp-panel--wide"><div class="mowp-panel__head">'
			. esc_html( $id > 0 ? __( 'Edit redemption item', 'moksa-points-for-woocommerce' ) : __( 'Add redemption item', 'moksa-points-for-woocommerce' ) )
			. '</div><div class="mowp-panel__body">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Module::ACTION ) . '">';
		echo '<input type="hidden" name="op" value="save">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( Module::NONCE );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Label.
		echo '<tr><th scope="row"><label for="moksafopoi-reward-label">' . esc_html__( 'Name', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="label" id="moksafopoi-reward-label" type="text" class="regular-text" required value="' . esc_attr( $label ) . '">';
		echo '<p class="description">' . esc_html__( 'The redemption item name shown to customers.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Kind: coupon (moforcoupon template) or product (physical gift → a NT$0 WC order).
		echo '<tr><th scope="row"><label for="moksafopoi-reward-kind">' . esc_html__( 'Type', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<select name="kind" id="moksafopoi-reward-kind" class="moksafopoi-reward-kind">';
		echo '<option value="coupon"' . selected( 'coupon', $kind, false ) . '>' . esc_html__( 'Coupon (template coupon)', 'moksa-points-for-woocommerce' ) . '</option>';
		echo '<option value="product"' . selected( 'product', $kind, false ) . '>' . esc_html__( 'Physical gift (WooCommerce product)', 'moksa-points-for-woocommerce' ) . '</option>';
		echo '<option value="cart_discount"' . selected( 'cart_discount', $kind, false ) . '>' . esc_html__( 'Store-credit discount (redeem points for store credit)', 'moksa-points-for-woocommerce' ) . '</option>';
		echo '<option value="code"' . selected( 'code', $kind, false ) . '>' . esc_html__( 'Redemption code (member enters a code to claim points)', 'moksa-points-for-woocommerce' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Coupon: copy a moforcoupon template coupon to the customer. Physical gift: after deducting points, create an order with a total of 0 for the customer, pending shipment.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Cost.
		echo '<tr><th scope="row"><label for="moksafopoi-reward-cost">' . esc_html__( 'Points required', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="cost_points" id="moksafopoi-reward-cost" type="number" min="0" step="1" class="small-text" value="' . esc_attr( (string) $cost ) . '">';
		echo '</td></tr>';

		// Payload: template (coupon kind).
		echo '<tr class="moksafopoi-kind-coupon"><th scope="row"><label for="moksafopoi-reward-template">' . esc_html__( 'Template coupon', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_template" id="moksafopoi-reward-template" type="text" class="regular-text" value="' . esc_attr( $template ) . '">';
		echo '<p class="description">' . esc_html__( 'The template coupon code or ID (issued by moforcoupon) to copy to the customer.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Payload: prefix (coupon kind).
		echo '<tr class="moksafopoi-kind-coupon"><th scope="row"><label for="moksafopoi-reward-prefix">' . esc_html__( 'Coupon code prefix', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_prefix" id="moksafopoi-reward-prefix" type="text" class="regular-text" value="' . esc_attr( $prefix ) . '">';
		echo '<p class="description">' . esc_html__( 'The prefix for the dedicated coupon code redeemed, for example PTS.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Payload: expiry_days (coupon kind).
		echo '<tr class="moksafopoi-kind-coupon"><th scope="row"><label for="moksafopoi-reward-expiry">' . esc_html__( 'Valid days', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_expiry_days" id="moksafopoi-reward-expiry" type="number" min="0" step="1" class="small-text" value="' . esc_attr( (string) $expiry_days ) . '">';
		echo '<p class="description">' . esc_html__( 'How many days after the redemption date the redeemed coupon expires; 0 means use the template setting.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Payload: product_id (product kind).
		echo '<tr class="moksafopoi-kind-product"><th scope="row"><label for="moksafopoi-reward-product">' . esc_html__( 'Gift product', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_product_id" id="moksafopoi-reward-product" type="number" min="0" step="1" class="regular-text" value="' . esc_attr( (string) $product_id ) . '">';
		echo '<p class="description">' . esc_html__( 'The WooCommerce product ID to send; upon redemption, an order with a total of 0 is created from this product, pending shipment.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Payload: note (product kind, optional).
		echo '<tr class="moksafopoi-kind-product"><th scope="row"><label for="moksafopoi-reward-note">' . esc_html__( 'Order note', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_note" id="moksafopoi-reward-note" type="text" class="regular-text" value="' . esc_attr( $note ) . '">';
		echo '<p class="description">' . esc_html__( 'Optional: a note attached when creating the gift order.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Payload: code / points / expires (code kind).
		$code_value   = (string) ( $payload['code'] ?? '' );
		$code_points  = (int) ( $payload['points'] ?? 0 );
		$code_expires = (string) ( $payload['expires'] ?? '' );
		echo '<tr class="moksafopoi-kind-code"><th scope="row"><label for="moksafopoi-reward-code">' . esc_html__( 'Redemption code', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_code" id="moksafopoi-reward-code" type="text" class="regular-text" value="' . esc_attr( $code_value ) . '" placeholder="SUMMER100">';
		echo '<p class="description">' . esc_html__( 'The code the member enters in My Account (case-insensitive). Works on package inserts, livestream passwords, LINE groups, and more; each member can redeem the same code only once, and the "Stock" field = the total number of redemptions allowed site-wide (-1 for unlimited).', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';
		echo '<tr class="moksafopoi-kind-code"><th scope="row"><label for="moksafopoi-reward-code-points">' . esc_html__( 'Points claimable', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_points" id="moksafopoi-reward-code-points" type="number" min="1" step="1" class="small-text" value="' . esc_attr( (string) max( 1, $code_points ) ) . '">';
		echo '</td></tr>';
		echo '<tr class="moksafopoi-kind-code"><th scope="row"><label for="moksafopoi-reward-code-expires">' . esc_html__( 'Redemption deadline', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_expires" id="moksafopoi-reward-code-expires" type="date" value="' . esc_attr( $code_expires ) . '">';
		echo '<p class="description">' . esc_html__( 'After this day the code becomes invalid; leave empty = no time limit.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Payload: amount (cart_discount kind).
		$disc_amount = (float) ( $payload['amount'] ?? 0 );
		echo '<tr class="moksafopoi-kind-cart_discount"><th scope="row"><label for="moksafopoi-reward-amount">' . esc_html__( 'Store-credit amount (NT$)', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="payload_amount" id="moksafopoi-reward-amount" type="number" min="0" step="0.01" class="small-text" value="' . esc_attr( (string) $disc_amount ) . '">';
		echo '<p class="description">' . esc_html__( 'The store-credit value granted when the customer redeems this reward (spendable at checkout via the wallet).', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// General: per-customer redemption limit (all kinds).
		$per_user_limit = (int) ( $payload['per_user_limit'] ?? 0 );
		echo '<tr><th scope="row"><label for="moksafopoi-reward-peruser">' . esc_html__( 'Per-customer limit', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="per_user_limit" id="moksafopoi-reward-peruser" type="number" min="0" step="1" class="small-text" value="' . esc_attr( (string) $per_user_limit ) . '">';
		echo '<p class="description">' . esc_html__( 'How many times each customer may redeem this reward (0 = unlimited). The "Stock" field above is the site-wide total.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Stock.
		echo '<tr><th scope="row"><label for="moksafopoi-reward-stock">' . esc_html__( 'Stock', 'moksa-points-for-woocommerce' ) . '</label></th><td>';
		echo '<input name="stock" id="moksafopoi-reward-stock" type="number" min="-1" step="1" class="small-text" value="' . esc_attr( (string) $stock ) . '">';
		echo '<p class="description">' . esc_html__( 'Redeemable quantity; -1 means unlimited.', 'moksa-points-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Active.
		echo '<tr><th scope="row">' . esc_html__( 'Publish', 'moksa-points-for-woocommerce' ) . '</th><td>';
		echo '<label><input name="active" type="checkbox" value="1"' . checked( 1, $active, false ) . '> ' . esc_html__( 'Show in catalog and make redeemable', 'moksa-points-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'moksa-points-for-woocommerce' ) . '</button> ';
		echo '<a href="' . esc_url( Module::page_url() ) . '" class="button">' . esc_html__( 'Cancel', 'moksa-points-for-woocommerce' ) . '</a></p>';
		echo '</form>';
		echo '</div></div>';
	}

	/**
	 * Single admin-post handler for save / delete / toggle. Nonce + capability gate everything,
	 * all input is unslashed + sanitised, and the payload is rebuilt as the JSON the redeem
	 * service reads. Redirects back to the list with a flash flag.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		check_admin_referer( Module::NONCE );

		// Save posts a form; delete/toggle are nonce-protected GET row links — read from $_REQUEST.
		$op = isset( $_REQUEST['op'] ) ? sanitize_key( wp_unslash( $_REQUEST['op'] ) ) : '';
		$id = isset( $_REQUEST['id'] ) ? absint( wp_unslash( $_REQUEST['id'] ) ) : 0;

		$msg = 'saved';

		switch ( $op ) {
			case 'delete':
				Rewards::delete( $id );
				$msg = 'deleted';
				break;

			case 'toggle':
				$active = isset( $_REQUEST['active'] ) ? absint( wp_unslash( $_REQUEST['active'] ) ) : 0;
				Rewards::set_active( $id, $active ? 1 : 0 );
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
	private static function save_from_post( int $id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- this state-changing save runs only from Screen::handle(), which verifies the nonce (check_admin_referer( Module::NONCE )) and the manage_woocommerce capability before delegating here; every value below is unslashed + sanitised.
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		// Kind is constrained to the supported set (coupon, product, code).
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'coupon';
		$kind = in_array( $kind, array( 'coupon', 'product', 'code', 'cart_discount' ), true ) ? $kind : 'coupon';

		$cost = isset( $_POST['cost_points'] ) ? absint( wp_unslash( $_POST['cost_points'] ) ) : 0;

		// Stock: -1 (unlimited) or any non-negative integer.
		$stock_raw = isset( $_POST['stock'] ) ? (int) wp_unslash( $_POST['stock'] ) : -1; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int below.
		$stock     = $stock_raw < 0 ? -1 : $stock_raw;

		$active = isset( $_POST['active'] ) ? 1 : 0;

		// Build the per-kind payload the redeem service reads.
		if ( 'code' === $kind ) {
			$code    = isset( $_POST['payload_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['payload_code'] ) ) ) : '';
			$points  = isset( $_POST['payload_points'] ) ? absint( wp_unslash( $_POST['payload_points'] ) ) : 0;
			$expires = isset( $_POST['payload_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['payload_expires'] ) ) : '';
			$expires = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ? $expires : '';
			$cost    = 0; // A redeem code GRANTS points; it never costs any.
			$payload = wp_json_encode(
				array(
					'code'    => $code,
					'points'  => max( 1, $points ),
					'expires' => $expires,
				)
			);
		} elseif ( 'product' === $kind ) {
			$product_id = isset( $_POST['payload_product_id'] ) ? absint( wp_unslash( $_POST['payload_product_id'] ) ) : 0;
			$note       = isset( $_POST['payload_note'] ) ? sanitize_text_field( wp_unslash( $_POST['payload_note'] ) ) : '';
			$payload    = wp_json_encode(
				array(
					'product_id' => $product_id,
					'note'       => $note,
				)
			);
		} elseif ( 'cart_discount' === $kind ) {
			$amount  = isset( $_POST['payload_amount'] ) ? round( (float) wp_unslash( $_POST['payload_amount'] ), 2 ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to float.
			$payload = wp_json_encode( array( 'amount' => max( 0, $amount ) ) );
		} else {
			$template    = isset( $_POST['payload_template'] ) ? sanitize_text_field( wp_unslash( $_POST['payload_template'] ) ) : '';
			$prefix      = isset( $_POST['payload_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['payload_prefix'] ) ) : '';
			$expiry_days = isset( $_POST['payload_expiry_days'] ) ? absint( wp_unslash( $_POST['payload_expiry_days'] ) ) : 0;
			$payload     = wp_json_encode(
				array(
					'template'    => $template,
					'prefix'      => $prefix,
					'expiry_days' => $expiry_days,
				)
			);
		}

		// Per-customer redemption limit applies to every kind — merge it into the payload (0 = unlimited).
		$per_user_limit = isset( $_POST['per_user_limit'] ) ? absint( wp_unslash( $_POST['per_user_limit'] ) ) : 0;
		if ( $per_user_limit > 0 ) {
			$decoded                   = json_decode( is_string( $payload ) ? $payload : '{}', true );
			$decoded                   = is_array( $decoded ) ? $decoded : array();
			$decoded['per_user_limit'] = $per_user_limit;
			$payload                   = wp_json_encode( $decoded );
		}

		Rewards::save(
			$id,
			array(
				'label'       => $label,
				'kind'        => $kind,
				'cost_points' => $cost,
				'payload'     => is_string( $payload ) ? $payload : '{}',
				'stock'       => $stock,
				'active'      => $active,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/** Build a nonce-protected admin-post link for a state-changing row action. */
	private static function post_url( string $op, int $id, array $extra = array() ): string {
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
	 * @return array<string,mixed>
	 */
	private static function decode_payload( string $json ): array {
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}

	private static function kind_label( string $kind ): string {
		$map = array(
			'coupon'        => __( 'Coupon', 'moksa-points-for-woocommerce' ),
			'product'       => __( 'Physical gift', 'moksa-points-for-woocommerce' ),
			'code'          => __( 'Redemption code', 'moksa-points-for-woocommerce' ),
			'cart_discount' => __( 'Store-credit discount', 'moksa-points-for-woocommerce' ),
		);

		return $map[ $kind ] ?? $kind;
	}

	/**
	 * Enqueue the badge styles on the rewards page only. Must run on admin_enqueue_scripts — the
	 * 'common' stylesheet is already printed to <head> by the time the page callback renders, so a
	 * wp_add_inline_style() inside render() never reaches the output.
	 */
	public static function enqueue_admin(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, Module::PAGE ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.moksafopoi-badge{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;line-height:18px}'
			. '.moksafopoi-badge.on{background:#e6f4ea;color:#137333}'
			. '.moksafopoi-badge.off{background:#f1f1f1;color:#777}'
		);

		// Show only the payload rows that belong to the selected reward kind. Vanilla JS, attached
		// to a core handle so no <script> is echoed. Defaults to coupon when nothing is selected yet.
		wp_enqueue_script( 'wp-dom-ready' );
		wp_add_inline_script(
			'wp-dom-ready',
			'wp.domReady(function(){'
			. 'var sel=document.querySelector(".moksafopoi-reward-kind");'
			. 'if(!sel){return;}'
			. 'function sync(){'
			. 'var k=sel.value;'
			. 'document.querySelectorAll(".moksafopoi-kind-coupon").forEach(function(el){el.style.display=("coupon"===k)?"":"none";});'
			. 'document.querySelectorAll(".moksafopoi-kind-product").forEach(function(el){el.style.display=("product"===k)?"":"none";});'
			. 'document.querySelectorAll(".moksafopoi-kind-code").forEach(function(el){el.style.display=("code"===k)?"":"none";});'
			. 'document.querySelectorAll(".moksafopoi-kind-cart_discount").forEach(function(el){el.style.display=("cart_discount"===k)?"":"none";});'
			. '}'
			. 'sel.addEventListener("change",sync);sync();'
			. '});'
		);
	}
}
