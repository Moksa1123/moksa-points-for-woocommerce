<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\StoreApi;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Tiers;

defined( 'ABSPATH' ) || exit;

/**
 * 店務系統 API — what an ERP / POS needs to keep points in step with the shop, server to server.
 *
 *   wc/v3/customers/{id}          + `moksa_points` field (balance, credit, lifetime, tier). Riding on the
 *                                  WooCommerce customer route means it works with BOTH WooCommerce
 *                                  consumer keys and WordPress Application Passwords, under
 *                                  WooCommerce's own customer-read permission.
 *   GET  points/v1/users/{id}     balance (same shape as the field above)
 *   GET  points/v1/users/{id}/history
 *   POST points/v1/users/{id}/adjust
 *        { points, reference, action?, order_id?, note? }
 *        200 applied:true             written
 *        200 applied:false duplicate  this reference was already booked; the body repeats what the
 *                                     first call booked. A retry must treat this as success.
 *        409 insufficient_points      a deduction larger than the balance; nothing written, do NOT retry
 *        409 reference_conflict       this reference was booked with a different number of points;
 *                                     nothing written, do NOT retry (fix the reference)
 *        503 busy                     the member's lock was held; safe to retry
 *        500 write_failed             nothing written; safe to retry
 *   A reference is 1-60 characters of plain text, used exactly as sent: one that sanitising would
 *   change (tags, line breaks, repeated spaces) is refused, so two references never collapse into one.
 *   References are case-sensitive: "INV-1" and "inv-1" are two different references.
 *
 * The keys in these responses are a contract: new keys may be added, existing ones are never renamed
 * or removed. Off by default. Reads need `moksafopoi_store_api_capability`, writes also need
 * `moksafopoi_store_api_write_capability` (both default `manage_woocommerce`).
 */
final class Module extends AbstractModule {

	public const NS = 'points/v1';

	/** Reference prefix inside the ledger, so an ERP reference can never collide with an admin bucket. */
	private const REF_PREFIX = 'ext:';

	/** Longest reference a caller may send (prefix + reference must fit Api::REF_MAX). */
	public const REF_MAX = 60;

	private const ACTIONS = array( 'earn', 'redeem', 'refund', 'admin' );

	public function slug(): string {
		return 'storeapi';
	}

	public function label(): string {
		return __( 'Store system API (ERP / POS)', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'admin';
	}

	public function tagline(): string {
		return __( 'Server-to-server endpoints for an ERP or POS: read any member\'s balance (also inside the WooCommerce customer API) and add or deduct points with a reference that is never booked twice. Needs a user with the shop manager capability.', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'woocommerce_rest_prepare_customer', array( self::class, 'add_customer_field' ), 10, 2 );
	}

	/** Capability the routes require. */
	public static function capability(): string {
		/**
		 * Capability for the store-system (ERP / POS) points API.
		 *
		 * @param string $cap Default `manage_woocommerce`.
		 */
		return (string) apply_filters( 'moksafopoi_store_api_capability', 'manage_woocommerce' );
	}

	/** Capability the write route also requires (separate, so loosening reads never opens writes). */
	public static function write_capability(): string {
		/**
		 * Capability for adding / deducting points through the store-system API.
		 *
		 * @param string $cap Default `manage_woocommerce`.
		 */
		return (string) apply_filters( 'moksafopoi_store_api_write_capability', 'manage_woocommerce' );
	}

	/** @return true|\WP_Error */
	public static function can_write() {
		if ( current_user_can( self::capability() ) && current_user_can( self::write_capability() ) ) {
			return true;
		}
		return new \WP_Error( 'moksafopoi_forbidden', __( 'You are not allowed to change points through the store system API.', 'moksa-points-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/** @return true|\WP_Error */
	public static function can_use() {
		if ( current_user_can( self::capability() ) ) {
			return true;
		}
		return new \WP_Error( 'moksafopoi_forbidden', __( 'You are not allowed to use the store system points API.', 'moksa-points-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public static function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_user' ),
				'permission_callback' => array( self::class, 'can_use' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_history' ),
				'permission_callback' => array( self::class, 'can_use' ),
				'args'                => $id_arg + array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/adjust',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'post_adjust' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'args'                => $id_arg + array(
					'points'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'reference' => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => self::REF_MAX,
					),
					'action'    => array(
						'type'    => 'string',
						'enum'    => self::ACTIONS,
						'default' => 'admin',
					),
					'order_id'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'note'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * The balance block shared by the customer field and GET users/{id}.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary( int $user_id ): array {
		$out    = array(
			'user_id'  => $user_id,
			'points'   => Api::get_points( $user_id ),
			'credit'   => Api::get_balance( $user_id ),
			'lifetime' => Tiers::basis( $user_id ),
			'tier'     => '',
		);
		$status = Tiers::status( $user_id );
		if ( null !== $status && null !== $status['tier'] ) {
			$out['tier'] = (string) $status['tier']['label'];
		}
		return $out;
	}

	/**
	 * Adds `moksa_points` to the WooCommerce customer response. WooCommerce has already checked that the
	 * caller may read this customer.
	 *
	 * @param \WP_REST_Response $response
	 * @param \WP_User          $customer
	 * @return \WP_REST_Response
	 */
	public static function add_customer_field( $response, $customer ) {
		if ( $response instanceof \WP_REST_Response && $customer instanceof \WP_User && $customer->ID > 0 ) {
			$data                 = (array) $response->get_data();
			$data['moksa_points'] = self::summary( (int) $customer->ID );
			$response->set_data( $data );
		}
		return $response;
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function get_user( \WP_REST_Request $request ) {
		$user_id = self::user_or_error( $request );
		if ( $user_id instanceof \WP_Error ) {
			return $user_id;
		}
		return new \WP_REST_Response( self::summary( $user_id ), 200 );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function get_history( \WP_REST_Request $request ) {
		$user_id = self::user_or_error( $request );
		if ( $user_id instanceof \WP_Error ) {
			return $user_id;
		}
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$rows     = Api::get_history( $user_id, $per_page + 1, ( $page - 1 ) * $per_page );
		$has_more = count( $rows ) > $per_page;
		$items    = array();
		foreach ( array_slice( $rows, 0, $per_page ) as $row ) {
			$row     = (array) $row;
			$items[] = array(
				'id'            => (int) ( $row['id'] ?? 0 ),
				'date'          => (string) ( $row['created_at'] ?? '' ),
				'points'        => (int) ( $row['points_delta'] ?? 0 ),
				'credit'        => (float) ( $row['amount_delta'] ?? 0 ),
				'type'          => (string) ( $row['type'] ?? '' ),
				'source'        => (string) ( $row['source'] ?? '' ),
				'reference'     => self::public_ref( (string) ( $row['source_ref'] ?? '' ) ),
				'order_id'      => (int) ( $row['order_id'] ?? 0 ),
				'note'          => (string) ( $row['note'] ?? '' ),
				'balance_after' => isset( $row['balance_after'] ) ? (int) $row['balance_after'] : null,
			);
		}
		return new \WP_REST_Response(
			array(
				'user_id'  => $user_id,
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => $has_more,
				'items'    => $items,
			),
			200
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function post_adjust( \WP_REST_Request $request ) {
		$user_id = self::user_or_error( $request );
		if ( $user_id instanceof \WP_Error ) {
			return $user_id;
		}
		$points    = (int) $request->get_param( 'points' );
		$raw_ref   = (string) $request->get_param( 'reference' );
		$reference = sanitize_text_field( $raw_ref );
		if ( $reference !== $raw_ref || mb_strlen( $reference ) > self::REF_MAX ) {
			return new \WP_Error( 'moksafopoi_invalid_reference', __( 'reference must be 1-60 characters of plain text, without tags, line breaks or repeated spaces.', 'moksa-points-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$action    = (string) $request->get_param( 'action' );
		$order_id  = absint( $request->get_param( 'order_id' ) );
		$note      = sanitize_text_field( (string) $request->get_param( 'note' ) );
		if ( 0 === $points || '' === $reference ) {
			return new \WP_Error( 'moksafopoi_invalid', __( 'points must be non-zero and reference must not be empty.', 'moksa-points-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( '' === $note ) {
			$note = __( 'Store system adjustment', 'moksa-points-for-woocommerce' );
		}

		$res = Api::adjust_detailed(
			$user_id,
			$points,
			$note,
			self::REF_PREFIX . $reference,
			array(
				'order_id' => $order_id,
				'meta'     => array(
					'action'    => in_array( $action, self::ACTIONS, true ) ? $action : 'admin',
					'reference' => $reference,
				),
			)
		);

		$body = array(
			'applied'   => 'applied' === $res['status'],
			'duplicate' => 'duplicate' === $res['status'],
			'reference' => $reference,
			'points'    => $res['points'],
			'balance'   => $res['balance'],
			'entry_id'  => $res['row_id'],
		);

		switch ( $res['status'] ) {
			case 'applied':
			case 'duplicate':
				return new \WP_REST_Response( $body, 200 );
			case 'insufficient':
				return new \WP_Error(
					'moksafopoi_insufficient_points',
					__( 'The member does not have enough points; nothing was deducted.', 'moksa-points-for-woocommerce' ),
					array(
						'status'    => 409,
						'retryable' => false,
						'balance'   => $res['balance'],
					)
				);
			case 'conflict':
				return new \WP_Error(
					'moksafopoi_reference_conflict',
					__( 'This reference was already used with a different number of points; nothing was changed.', 'moksa-points-for-woocommerce' ),
					array(
						'status'    => 409,
						'retryable' => false,
						'points'    => $res['points'],
						'entry_id'  => $res['row_id'],
					)
				);
			case 'error':
				return new \WP_Error(
					'moksafopoi_write_failed',
					__( 'The adjustment could not be saved; nothing was changed. Please try again.', 'moksa-points-for-woocommerce' ),
					array(
						'status'    => 500,
						'retryable' => true,
					)
				);
			case 'busy':
				return new \WP_Error(
					'moksafopoi_busy',
					__( 'The system is busy, please try again later.', 'moksa-points-for-woocommerce' ),
					array(
						'status'    => 503,
						'retryable' => true,
					)
				);
			default:
				return new \WP_Error( 'moksafopoi_invalid', __( 'Invalid request.', 'moksa-points-for-woocommerce' ), array( 'status' => 400 ) );
		}
	}

	/** A row booked through this API shows the caller's own reference, without the internal prefix. */
	private static function public_ref( string $ref ): string {
		return 0 === strpos( $ref, self::REF_PREFIX ) ? substr( $ref, strlen( self::REF_PREFIX ) ) : $ref;
	}

	/** @return int|\WP_Error */
	private static function user_or_error( \WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'moksafopoi_no_user', __( 'No such member.', 'moksa-points-for-woocommerce' ), array( 'status' => 404 ) );
		}
		return $user_id;
	}
}
