<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\RestApi;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Label;
use Moksafopoi\Support\Tiers;

defined( 'ABSPATH' ) || exit;

/**
 * 顧客範圍 REST — the endpoints a headless storefront (or a mobile app) needs to show a member their
 * own points:
 *
 *   GET points/v1/me            — balance, store credit, tier, next tier
 *   GET points/v1/me/history    — that member's own ledger, paginated
 *
 * The scope is the point: **there is no user_id parameter anywhere**. Each route serves the
 * AUTHENTICATED user and nobody else, so no amount of parameter tampering can read another member's
 * balance or history — the failure mode every "customer API" bolted onto an admin API eventually has.
 * Admin-wide reads stay where they belong, behind the existing capability-gated abilities.
 *
 * Read-only by construction: no route here writes anything.
 */
final class Module extends AbstractModule {

	/** REST namespace. */
	public const NS = 'points/v1';

	/** Page size ceiling for the history route. */
	private const MAX_PER_PAGE = 100;

	public function slug(): string {
		return 'restapi';
	}

	public function label(): string {
		return __( 'Customer REST API', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'admin';
	}

	public function tagline(): string {
		return __( 'Read-only endpoints a headless storefront or app can call for the LOGGED-IN customer\'s own balance, tier and history (no user_id parameter — a member can only ever read themselves)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_me' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			self::NS,
			'/me/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_history' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Rows per page (1-100).', 'moksa-points-for-woocommerce' ),
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Page number, from 1.', 'moksa-points-for-woocommerce' ),
					),
				),
			)
		);
	}

	/**
	 * The only permission rule these routes need: you must be someone. Which member you are is read
	 * from the authenticated session, never from the request.
	 *
	 * @return true|\WP_Error
	 */
	public static function is_logged_in() {
		if ( get_current_user_id() > 0 ) {
			return true;
		}
		return new \WP_Error(
			'moksafopoi_not_logged_in',
			__( 'You must be logged in to read your points.', 'moksa-points-for-woocommerce' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * GET points/v1/me
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_me(): \WP_REST_Response {
		$user_id = get_current_user_id();
		$points  = Api::get_points( $user_id );

		$body = array(
			'points'       => $points,
			'points_label' => Label::format( $points ),
			'credit'       => Api::get_balance( $user_id ),
			'unit'         => Label::unit(),
		);

		$status = Tiers::status( $user_id );
		if ( null !== $status ) {
			$body['tier'] = array(
				'label'     => ( null !== $status['tier'] ) ? (string) $status['tier']['label'] : '',
				'next'      => ( null !== $status['next'] ) ? (string) $status['next']['label'] : '',
				'remaining' => $status['remaining'],   // null when a membership plugin names the tier.
				'progress'  => $status['progress'],
				'lifetime'  => (int) $status['basis'],
			);
		}

		return new \WP_REST_Response( $body, 200 );
	}

	/**
	 * GET points/v1/me/history
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function get_history( $request ): \WP_REST_Response {
		$user_id  = get_current_user_id();
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;

		// One extra row tells us whether another page exists without a COUNT over the whole table.
		$rows     = Api::get_history( $user_id, $per_page + 1, $offset );
		$has_more = count( $rows ) > $per_page;
		$rows     = array_slice( $rows, 0, $per_page );

		$items = array();
		foreach ( $rows as $row ) {
			$row     = (array) $row;
			$items[] = array(
				'date'         => (string) ( $row['created_at'] ?? '' ),
				'points'       => (int) ( $row['points_delta'] ?? 0 ),
				'credit'       => (float) ( $row['amount_delta'] ?? 0 ),
				'type'         => (string) ( $row['type'] ?? '' ),
				'source'       => (string) ( $row['source'] ?? '' ),
				'order_id'     => (int) ( $row['order_id'] ?? 0 ),
				'note'         => (string) ( $row['note'] ?? '' ),
				'balance_after' => isset( $row['balance_after'] ) ? (int) $row['balance_after'] : null,
			);
		}

		return new \WP_REST_Response(
			array(
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => $has_more,
				'items'    => $items,
			),
			200
		);
	}
}
