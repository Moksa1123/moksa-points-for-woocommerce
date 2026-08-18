<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Abilities;

use Moksafopoi\Api;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Abilities API registration for moksafopoi (namespace `points/`). One
 * definition is consumed by the command palette, REST and the self-built MCP server.
 *
 * Read abilities run directly (cap: manage_woocommerce, or view_woocommerce_reports for
 * the report). Destructive abilities (adjust / redeem / expire) are registered ALWAYS but
 * carry the `destructive` annotation, so the MCP gate hides them from the tool list unless
 * `moksafopoi_mcp_expose_destructive='yes'`. Permission callbacks map to real WP caps —
 * never __return_true. Requires WordPress 6.9+ core Abilities API (function_exists guarded).
 */
final class Ability {

	public const CATEGORY = 'moksa-points-for-woocommerce';

	/** Object-level cap for management reads + all writes. */
	public const CAP = 'manage_woocommerce';

	/** Object-level cap for the analytics report (least privilege). */
	public const REPORT_CAP = 'view_woocommerce_reports';

	/** @var array<string,bool> Abilities hidden from MCP this request (filled by the gate). */
	private static array $mcp_hidden = array();

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Moksa Points', 'moksa-points-for-woocommerce' ),
				'description' => __( 'WooCommerce points / store credit balance lookup, reporting, and management capabilities', 'moksa-points-for-woocommerce' ),
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		self::register_reads();
		wp_register_ability(
			'points/find-customer',
			array(
				'label'               => __( 'Find a customer', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Search customers by name / email / username and return matching user_ids (so points lookups work from a name). Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query' => array( 'type' => 'string', 'description' => __( 'Name, email or username fragment to search', 'moksa-points-for-woocommerce' ) ),
						'limit' => array( 'type' => 'integer', 'description' => __( 'Max results (default 10)', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array(  ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'count' => array( 'type' => 'integer' ), 'customers' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'user_id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'email' => array( 'type' => 'string' ) ) ) ) ) ),
				'execute_callback'    => array( self::class, 'execute_find_customer' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/get-active-campaign',
			array(
				'label'               => __( 'Get the active points campaign', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Return the currently active points campaign (limited-time multiplier / discount), or none. Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => (object) array(),
					'required'             => array(  ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'active' => array( 'type' => 'boolean' ), 'campaign' => array( 'type' => 'object' ) ) ),
				'execute_callback'    => array( self::class, 'execute_active_campaign' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/leaderboard-top',
			array(
				'label'               => __( 'Get the points leaderboard', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Return the top customers ranked by total points earned. Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array( 'type' => 'integer', 'description' => __( 'How many top customers (default 10, max 100)', 'moksa-points-for-woocommerce' ) ),
						'period' => array( 'type' => 'string', 'description' => __( 'all / month (default all)', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array(  ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'count' => array( 'type' => 'integer' ), 'leaders' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'rank' => array( 'type' => 'integer' ), 'user_id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'points' => array( 'type' => 'integer' ) ) ) ) ) ),
				'execute_callback'    => array( self::class, 'execute_leaderboard_top' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/forecast-liability',
			array(
				'label'               => __( 'Forecast points liability and breakage', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Return outstanding points, what they are worth, how much expires within 30/60/90 days, the historical breakage and redemption rates, recent issue/redeem velocity, and the balance distribution. Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'outstanding'          => array( 'type' => 'integer' ),
						'liability_value'      => array( 'type' => 'number' ),
						'redeem_rate'          => array( 'type' => 'integer' ),
						'members_with_balance' => array( 'type' => 'integer' ),
						'expiring_30'          => array( 'type' => 'integer' ),
						'expiring_60'          => array( 'type' => 'integer' ),
						'expiring_90'          => array( 'type' => 'integer' ),
						'issued_total'         => array( 'type' => 'integer' ),
						'redeemed_total'       => array( 'type' => 'integer' ),
						'expired_total'        => array( 'type' => 'integer' ),
						'breakage_pct'         => array( 'type' => 'number' ),
						'redemption_rate_pct'  => array( 'type' => 'number' ),
						'observations'         => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'key' => array( 'type' => 'string' ), 'text' => array( 'type' => 'string' ) ) ) ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_forecast_liability' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/suggest-rewards',
			array(
				'label'               => __( 'Suggest reward prices', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Suggest entry / core / aspirational reward prices from what members actually hold, with the reasoning and the share of members each price is within reach of. Read-only; nothing is created.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'       => array( 'type' => 'integer' ),
						'suggestions' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'tier'      => array( 'type' => 'string' ),
									'points'    => array( 'type' => 'integer' ),
									'reach_pct' => array( 'type' => 'integer' ),
									'reason'    => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_suggest_rewards' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/get-badges',
			array(
				'label'               => __( 'Get a customer badges', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Return a customer earned achievement badges, their next badge and total points earned. Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array( 'type' => 'integer', 'description' => __( 'Customer user_id', 'moksa-points-for-woocommerce' ) ),
						'email' => array( 'type' => 'string', 'description' => __( 'Customer email (used when user_id is omitted)', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array(  ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'user_id' => array( 'type' => 'integer' ), 'total_earned' => array( 'type' => 'integer' ), 'earned' => array( 'type' => 'array' ), 'next' => array( 'type' => 'object' ) ) ),
				'execute_callback'    => array( self::class, 'execute_get_badges' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		self::register_writes();
	}

	// === Read abilities ===

	private static function register_reads(): void {
		wp_register_ability(
			'points/get-balance',
			array(
				'label'               => __( 'Get points balance', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Get a customer\'s current points and store credit balance (provide user_id or email). Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => __( 'Customer user_id (or email)', 'moksa-points-for-woocommerce' ),
						),
						'email'   => array(
							'type'        => 'string',
							'description' => __( 'Customer email (used when user_id is unavailable)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array( 'type' => 'integer' ),
						'points'  => array( 'type' => 'integer' ),
						'credit'  => array( 'type' => 'number' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_get_balance' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/get-history',
			array(
				'label'               => __( 'Get points history', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Get a customer\'s points / store credit ledger history (paginated, default 20, max 200). Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => __( 'Customer user_id', 'moksa-points-for-woocommerce' ),
						),
						'email'   => array(
							'type'        => 'string',
							'description' => __( 'Customer email (or user_id)', 'moksa-points-for-woocommerce' ),
						),
						'limit'   => array(
							'type'        => 'integer',
							'description' => __( 'Maximum number of records (default 20, max 200)', 'moksa-points-for-woocommerce' ),
						),
						'offset'  => array(
							'type'        => 'integer',
							'description' => __( 'Offset (for pagination, default 0)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'   => array( 'type' => 'integer' ),
						'history' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'            => array( 'type' => 'integer' ),
									'points_delta'  => array( 'type' => 'integer', 'description' => __( 'Signed points change (+earned / -spent).', 'moksa-points-for-woocommerce' ) ),
									'amount_delta'  => array( 'type' => 'number', 'description' => __( 'Signed store-credit change.', 'moksa-points-for-woocommerce' ) ),
									'balance_after' => array( 'type' => 'integer' ),
									'type'          => array( 'type' => 'string', 'description' => __( 'earn / redeem / expire / adjust / debit.', 'moksa-points-for-woocommerce' ) ),
									'source'        => array( 'type' => 'string' ),
									'note'          => array( 'type' => 'string' ),
									'created_at'    => array( 'type' => 'string', 'description' => __( 'UTC time, Y-m-d H:i:s.', 'moksa-points-for-woocommerce' ) ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_get_history' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/list-rules',
			array(
				'label'               => __( 'List earning rules', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'List configured earning rules (spend N → Y points, multiplier, campaign period). Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'active_only' => array(
							'type'        => 'boolean',
							'description' => __( 'List only enabled rules (default false = all)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count' => array( 'type' => 'integer' ),
						'rules' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'integer' ),
									'label'      => array( 'type' => 'string' ),
									'kind'       => array( 'type' => 'string' ),
									'rate'       => array( 'type' => 'number', 'description' => __( 'Points per 1 currency unit for this rule.', 'moksa-points-for-woocommerce' ) ),
									'min_spend'  => array( 'type' => 'number' ),
									'multiplier' => array( 'type' => 'number' ),
									'priority'   => array( 'type' => 'integer' ),
									'active'     => array( 'type' => 'integer', 'description' => __( '1 = enabled, 0 = disabled.', 'moksa-points-for-woocommerce' ) ),
									'starts_at'  => array( 'type' => 'string' ),
									'ends_at'    => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_list_rules' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/list-rewards',
			array(
				'label'               => __( 'List redemption items', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'List items customers can redeem with points (coupon / cart discount / product) and the points required. Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'active_only' => array(
							'type'        => 'boolean',
							'description' => __( 'List only enabled redemption items (default false = all)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'   => array( 'type' => 'integer' ),
						'rewards' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'integer' ),
									'label'       => array( 'type' => 'string' ),
									'kind'        => array( 'type' => 'string', 'description' => __( 'coupon / cart_discount / product.', 'moksa-points-for-woocommerce' ) ),
									'cost_points' => array( 'type' => 'integer' ),
									'active'      => array( 'type' => 'integer', 'description' => __( '1 = listed, 0 = delisted.', 'moksa-points-for-woocommerce' ) ),
									'stock'       => array( 'type' => 'integer', 'description' => __( 'Remaining stock (-1 = unlimited).', 'moksa-points-for-woocommerce' ) ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_list_rewards' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/points-report',
			array(
				'label'               => __( 'Points operations report', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Summarize total points and store credit issued / redeemed / expired, plus points currently in circulation. Read-only.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'days' => array(
							'type'        => 'integer',
							'description' => __( 'Cover the last N days (omit = all time)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'issued_points'   => array( 'type' => 'integer' ),
						'redeemed_points' => array( 'type' => 'integer' ),
						'expired_points'  => array( 'type' => 'integer' ),
						'outstanding'     => array( 'type' => 'integer' ),
						'credit_issued'   => array( 'type' => 'number' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_report' ),
				'permission_callback' => array( self::class, 'can_view_reports' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'points/get-settings',
			array(
				'label'               => __( 'Get points settings', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Return the current values of the store\'s whitelisted points settings (points per dollar, redemption ratio, points unit name, email appearance, front-end message templates, etc.), including type and description. Read-only; excludes any security / MCP / cap settings.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => (object) array(),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'    => array( 'type' => 'integer' ),
						'settings' => array(
							'type'        => 'array',
							'description' => __( 'Each entry is a whitelisted setting of {key, label, type, value}.', 'moksa-points-for-woocommerce' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'key'   => array( 'type' => 'string', 'description' => __( 'Option key (pass to update-setting).', 'moksa-points-for-woocommerce' ) ),
									'label' => array( 'type' => 'string' ),
									'type'  => array( 'type' => 'string', 'description' => __( 'Value type hint: string / number / boolean.', 'moksa-points-for-woocommerce' ) ),
									'value' => array( 'description' => __( 'Current value (type per the "type" field).', 'moksa-points-for-woocommerce' ) ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_get_settings' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'meta'                => self::read_meta(),
			)
		);
	}

	// === Destructive abilities (registered always; hidden from MCP unless opted-in) ===

	private static function register_writes(): void {
		wp_register_ability(
			'points/adjust-points',
			array(
				'label'               => __( 'Manually adjust points', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Manually add / deduct points for a customer (positive adds, negative deducts). Destructive, idempotent (the same bucket is not posted twice).', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => __( 'Customer user_id', 'moksa-points-for-woocommerce' ),
						),
						'points'  => array(
							'type'        => 'integer',
							'description' => __( 'Points to add / deduct (positive adds, negative deducts)', 'moksa-points-for-woocommerce' ),
						),
						'note'    => array(
							'type'        => 'string',
							'description' => __( 'Reason for the adjustment (shown in the customer\'s history)', 'moksa-points-for-woocommerce' ),
						),
						'bucket'  => array(
							'type'        => 'string',
							'description' => __( 'Idempotency key (the same key is not posted twice; defaults to the current time if omitted)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array( 'user_id', 'points' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_adjust' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/redeem-points',
			array(
				'label'               => __( 'Redeem points', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Redeem a reward with a customer\'s points (coupon / cart discount / product). Destructive — requires the "Redeem" module to be enabled; mints a real coupon when moforcoupon is present, otherwise skips gracefully.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Customer user_id', 'moksa-points-for-woocommerce' ),
						),
						'reward_kind' => array(
							'type'        => 'string',
							'enum'        => array( 'coupon', 'cart_discount', 'product' ),
							'description' => __( 'Redemption type: coupon / cart_discount / product', 'moksa-points-for-woocommerce' ),
						),
						'reward_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Redemption catalog item id (optional)', 'moksa-points-for-woocommerce' ),
						),
						'cost_points' => array(
							'type'        => 'integer',
							'description' => __( 'Points to deduct for this redemption (optional; defaults to the catalog setting)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array( 'user_id', 'reward_kind' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_redeem' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/transfer-points',
			array(
				'label'               => __( 'Transfer points between customers', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Move points from one customer to another (amount-conserving, per-user locked). Destructive.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'from_user_id' => array( 'type' => 'integer', 'description' => __( 'Sender user_id', 'moksa-points-for-woocommerce' ) ),
						'to_user_id'   => array( 'type' => 'integer', 'description' => __( 'Recipient user_id', 'moksa-points-for-woocommerce' ) ),
						'points'       => array( 'type' => 'integer', 'description' => __( 'Points to transfer (positive)', 'moksa-points-for-woocommerce' ) ),
						'note'         => array( 'type' => 'string', 'description' => __( 'Optional note shown in both histories', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array( 'from_user_id', 'to_user_id', 'points' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_transfer_points' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/adjust-credit',
			array(
				'label'               => __( 'Manually adjust store credit', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Manually add or deduct a customer store-credit balance (currency; positive adds, negative deducts). Destructive, idempotent on the bucket.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array( 'type' => 'integer', 'description' => __( 'Customer user_id', 'moksa-points-for-woocommerce' ) ),
						'amount'  => array( 'type' => 'number', 'description' => __( 'Store-credit change (positive adds, negative deducts)', 'moksa-points-for-woocommerce' ) ),
						'note'    => array( 'type' => 'string', 'description' => __( 'Reason (shown in history)', 'moksa-points-for-woocommerce' ) ),
						'bucket'  => array( 'type' => 'string', 'description' => __( 'Idempotency key (defaults to current time)', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array( 'user_id', 'amount' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_adjust_credit' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/create-reward',
			array(
				'label'               => __( 'Create a redemption reward', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Add a new item to the points redemption catalog (label, kind, cost). Destructive.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array( 'type' => 'integer', 'description' => __( 'Reward catalog id (required for update)', 'moksa-points-for-woocommerce' ) ),
						'label' => array( 'type' => 'string', 'description' => __( 'Reward name shown to customers', 'moksa-points-for-woocommerce' ) ),
						'kind' => array( 'type' => 'string', 'description' => __( 'coupon / cart_discount / product', 'moksa-points-for-woocommerce' ) ),
						'cost_points' => array( 'type' => 'integer', 'description' => __( 'Points required to redeem', 'moksa-points-for-woocommerce' ) ),
						'stock' => array( 'type' => 'integer', 'description' => __( 'Remaining stock (-1 = unlimited)', 'moksa-points-for-woocommerce' ) ),
						'active' => array( 'type' => 'integer', 'description' => __( '1 = listed, 0 = delisted', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array( 'label', 'kind', 'cost_points' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_create_reward' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/update-reward',
			array(
				'label'               => __( 'Update a redemption reward', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Update an existing redemption catalog item by id (only provided fields change). Destructive.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array( 'type' => 'integer', 'description' => __( 'Reward catalog id (required for update)', 'moksa-points-for-woocommerce' ) ),
						'label' => array( 'type' => 'string', 'description' => __( 'Reward name shown to customers', 'moksa-points-for-woocommerce' ) ),
						'kind' => array( 'type' => 'string', 'description' => __( 'coupon / cart_discount / product', 'moksa-points-for-woocommerce' ) ),
						'cost_points' => array( 'type' => 'integer', 'description' => __( 'Points required to redeem', 'moksa-points-for-woocommerce' ) ),
						'stock' => array( 'type' => 'integer', 'description' => __( 'Remaining stock (-1 = unlimited)', 'moksa-points-for-woocommerce' ) ),
						'active' => array( 'type' => 'integer', 'description' => __( '1 = listed, 0 = delisted', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_update_reward' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/reverse-earn',
			array(
				'label'               => __( 'Reverse points earned on an order', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Claw back the points a customer earned for a specific order (as on a refund). Balance never goes below zero. Destructive, idempotent.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array( 'type' => 'integer', 'description' => __( 'Order id whose earned points should be reversed', 'moksa-points-for-woocommerce' ) ),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_reverse_earn' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/expire-points',
			array(
				'label'               => __( 'Run points expiry scan', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Run a one-off expiry sweep now (deduct and record points past expires_at). Destructive, irreversible.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array(
							'type'        => 'integer',
							'description' => __( 'Scan only one customer (omit = all customers)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_expire' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);

		wp_register_ability(
			'points/update-setting',
			array(
				'label'               => __( 'Update points settings', 'moksa-points-for-woocommerce' ),
				'description'         => __( 'Update one whitelisted store points setting (key + value). The value is sanitized / validated according to the setting\'s type (numeric clamped to range, yes/no, hex color, string) before saving. Destructive — non-whitelisted keys are always rejected; never accepts any security / MCP / cap settings.', 'moksa-points-for-woocommerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'key'   => array(
							'type'        => 'string',
							'description' => __( 'The setting key to update (must be whitelisted; use points/get-settings first to look up available keys)', 'moksa-points-for-woocommerce' ),
						),
						'value' => array(
							'description' => __( 'New value (per the setting\'s type: number / yes|no / #rrggbb / string)', 'moksa-points-for-woocommerce' ),
						),
					),
					'required'             => array( 'key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::summary_output(),
				'execute_callback'    => array( self::class, 'execute_update_setting' ),
				'permission_callback' => array( self::class, 'can_write' ),
				'meta'                => self::write_meta(),
			)
		);
	}

	// === Read execute callbacks ===

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_get_balance( $input ): array {
		if ( ! self::can_read() ) {
			return array();
		}
		$user_id = self::resolve_user( is_array( $input ) ? $input : array() );
		if ( $user_id <= 0 ) {
			return array();
		}
		return array(
			'user_id' => $user_id,
			'points'  => Api::get_points( $user_id ),
			'credit'  => Api::get_balance( $user_id ),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_get_history( $input ): array {
		if ( ! self::can_read() ) {
			return array(
				'count'   => 0,
				'history' => array(),
			);
		}
		$input   = is_array( $input ) ? $input : array();
		$user_id = self::resolve_user( $input );
		if ( $user_id <= 0 ) {
			return array(
				'count'   => 0,
				'history' => array(),
			);
		}
		$limit  = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 20;
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$rows   = Api::get_history( $user_id, $limit, $offset );
		return array(
			'count'   => count( $rows ),
			'history' => $rows,
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_list_rules( $input ): array {
		if ( ! self::can_read() ) {
			return array(
				'count' => 0,
				'rules' => array(),
			);
		}
		global $wpdb;
		$active_only = is_array( $input ) && ! empty( $input['active_only'] );
		$table       = Schema::rules_table();
		if ( $active_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rules table (Schema::rules_table()); no user input interpolated.
			$rows = $wpdb->get_results( "SELECT id, label, kind, rate, min_spend, multiplier, priority, active, starts_at, ends_at FROM {$table} WHERE active = 1 ORDER BY priority ASC, id ASC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rules table (Schema::rules_table()); no user input interpolated.
			$rows = $wpdb->get_results( "SELECT id, label, kind, rate, min_spend, multiplier, priority, active, starts_at, ends_at FROM {$table} ORDER BY priority ASC, id ASC", ARRAY_A );
		}
		$rows = is_array( $rows ) ? $rows : array();
		return array(
			'count' => count( $rows ),
			'rules' => $rows,
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_list_rewards( $input ): array {
		if ( ! self::can_read() ) {
			return array(
				'count'   => 0,
				'rewards' => array(),
			);
		}
		global $wpdb;
		$active_only = is_array( $input ) && ! empty( $input['active_only'] );
		$table       = Schema::rewards_table();
		if ( $active_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (Schema::rewards_table()); no user input interpolated.
			$rows = $wpdb->get_results( "SELECT id, label, kind, cost_points, active, stock FROM {$table} WHERE active = 1 ORDER BY cost_points ASC, id ASC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (Schema::rewards_table()); no user input interpolated.
			$rows = $wpdb->get_results( "SELECT id, label, kind, cost_points, active, stock FROM {$table} ORDER BY cost_points ASC, id ASC", ARRAY_A );
		}
		$rows = is_array( $rows ) ? $rows : array();
		return array(
			'count'   => count( $rows ),
			'rewards' => $rows,
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_report( $input ): array {
		if ( ! self::can_view_reports() ) {
			return array();
		}
		global $wpdb;
		$table = Schema::ledger_table();
		$input = is_array( $input ) ? $input : array();
		$days  = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 0;

		$where  = '';
		$params = array();
		if ( $days > 0 ) {
			$where    = ' WHERE created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); the only interpolated piece is the static $where clause whose %s value is bound through $params via $wpdb->prepare().
		$sql = "SELECT
				COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END),0) AS issued,
				COALESCE(SUM(CASE WHEN type = 'redeem' AND points_delta < 0 THEN -points_delta ELSE 0 END),0) AS redeemed,
				COALESCE(SUM(CASE WHEN type = 'expire' AND points_delta < 0 THEN -points_delta ELSE 0 END),0) AS expired,
				COALESCE(SUM(points_delta),0) AS outstanding,
				COALESCE(SUM(CASE WHEN amount_delta > 0 THEN amount_delta ELSE 0 END),0) AS credit_issued
			FROM {$table}{$where}";

		$row = array() === $params
			? $wpdb->get_row( $sql, ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'issued_points'   => (int) ( $row['issued'] ?? 0 ),
			'redeemed_points' => (int) ( $row['redeemed'] ?? 0 ),
			'expired_points'  => (int) ( $row['expired'] ?? 0 ),
			'outstanding'     => (int) ( $row['outstanding'] ?? 0 ),
			'credit_issued'   => (float) ( $row['credit_issued'] ?? 0 ),
		);
	}

	/**
	 * Read the whitelisted store settings with their current value, label and type. Only the keys in
	 * {@see settings_whitelist()} are ever returned — security-sensitive options (MCP exposure switches,
	 * caps, module enable toggles) are deliberately NOT in the whitelist, so they can never leak here.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_get_settings( $input ): array {
		if ( ! self::can_read() ) {
			return array(
				'count'    => 0,
				'settings' => array(),
			);
		}
		$rows = array();
		foreach ( self::settings_whitelist() as $key => $spec ) {
			$default = $spec['default'] ?? '';
			$rows[]  = array(
				'key'   => $key,
				'label' => (string) ( $spec['label'] ?? $key ),
				'type'  => (string) ( $spec['type'] ?? 'string' ),
				'value' => get_option( $key, $default ),
			);
		}
		return array(
			'count'    => count( $rows ),
			'settings' => $rows,
		);
	}

	// === Write execute callbacks ===

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_find_customer( $input ): array {
		if ( ! self::can_read() ) { return array( 'count' => 0, 'customers' => array() ); }
		$input = is_array( $input ) ? $input : array();
		$q = isset( $input['query'] ) ? trim( sanitize_text_field( (string) $input['query'] ) ) : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 10;
		if ( '' === $q ) { return array( 'count' => 0, 'customers' => array() ); }
		$users = get_users( array( 'search' => '*' . $q . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ), 'number' => $limit, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
		$rows = array();
		foreach ( $users as $u ) { $rows[] = array( 'user_id' => (int) $u->ID, 'name' => (string) $u->display_name, 'email' => (string) $u->user_email ); }
		return array( 'count' => count( $rows ), 'customers' => $rows );
	}

	/** @param mixed $input @return array<string,mixed> */
	public static function execute_active_campaign( $input ): array {
		if ( ! self::can_read() ) { return array( 'active' => false ); }
		$c = Api::active_campaign();
		return is_array( $c ) ? array( 'active' => true, 'campaign' => $c ) : array( 'active' => false );
	}

	/** @param mixed $input @return array<string,mixed> */
	public static function execute_leaderboard_top( $input ): array {
		if ( ! self::can_read() ) { return array( 'count' => 0, 'leaders' => array() ); }
		$input = is_array( $input ) ? $input : array();
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, (int) $input['limit'] ) ) : 10;
		$period = ( isset( $input['period'] ) && 'month' === $input['period'] ) ? 'month' : 'all';
		$cls = '\\Moksafopoi\\Modules\\Leaderboard\\Module';
		if ( ! class_exists( $cls ) || ! method_exists( $cls, 'top_earners' ) ) { return array( 'count' => 0, 'leaders' => array() ); }
		$rows = (array) $cls::top_earners( $limit, $period );
		$out = array();
		$rank = 0;
		foreach ( $rows as $r ) {
			$rank++;
			$uid = (int) ( $r['user_id'] ?? 0 );
			$out[] = array( 'rank' => $rank, 'user_id' => $uid, 'name' => (string) ( $r['name'] ?? $r['display_name'] ?? '' ), 'points' => (int) ( $r['points'] ?? $r['total'] ?? 0 ) );
		}
		return array( 'count' => count( $out ), 'leaders' => $out );
	}

	/** @param mixed $input @return array<string,mixed> */
	public static function execute_get_badges( $input ): array {
		if ( ! self::can_read() ) { return array( 'user_id' => 0 ); }
		$input = is_array( $input ) ? $input : array();
		$user_id = self::resolve_user( $input );
		if ( $user_id <= 0 ) { return array( 'user_id' => 0 ); }
		$cls = '\\Moksafopoi\\Modules\\Badges\\Module';
		$earned = ( class_exists( $cls ) && method_exists( $cls, 'earned_badges' ) ) ? (array) $cls::earned_badges( $user_id ) : array();
		$next   = ( class_exists( $cls ) && method_exists( $cls, 'next_badge' ) ) ? $cls::next_badge( $user_id ) : null;
		$total  = ( class_exists( $cls ) && method_exists( $cls, 'total_earned' ) ) ? (int) $cls::total_earned( $user_id ) : 0;
		return array( 'user_id' => $user_id, 'total_earned' => $total, 'earned' => $earned, 'next' => is_array( $next ) ? $next : array() );
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_adjust( $input ): array {
		if ( ! self::can_write() ) {
			return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$input   = is_array( $input ) ? $input : array();
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$points  = isset( $input['points'] ) ? (int) $input['points'] : 0;
		$note    = isset( $input['note'] ) ? sanitize_text_field( (string) $input['note'] ) : __( 'Manual adjustment', 'moksa-points-for-woocommerce' );
		$bucket  = isset( $input['bucket'] ) ? sanitize_text_field( (string) $input['bucket'] ) : '';

		if ( $user_id <= 0 || 0 === $points ) {
			return array( 'summary' => __( 'A valid user_id and a non-zero points value are required.', 'moksa-points-for-woocommerce' ) );
		}

		$ok = Api::adjust( $user_id, $points, $note, $bucket );
		if ( ! $ok ) {
			return array( 'summary' => __( 'This adjustment was already recorded (idempotent); it was not posted again.', 'moksa-points-for-woocommerce' ) );
		}
		return array(
			'summary' => sprintf(
				/* translators: 1: signed points, 2: user id, 3: new balance. */
				__( 'Adjusted %1$s point(s) for customer #%2$d; current balance is %3$s point(s).', 'moksa-points-for-woocommerce' ),
				( $points > 0 ? '+' : '' ) . number_format( $points ),
				$user_id,
				number_format( Api::get_points( $user_id ) )
			),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_redeem( $input ): array {
		if ( ! self::can_write() ) {
			return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$input   = is_array( $input ) ? $input : array();
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$kind    = isset( $input['reward_kind'] ) ? sanitize_key( (string) $input['reward_kind'] ) : '';
		if ( $user_id <= 0 || '' === $kind ) {
			return array( 'summary' => __( 'user_id and redemption type are required.', 'moksa-points-for-woocommerce' ) );
		}

		$args   = array();
		if ( isset( $input['reward_id'] ) ) {
			$args['reward_id'] = (int) $input['reward_id'];
		}
		if ( isset( $input['cost_points'] ) ) {
			$args['cost_points'] = (int) $input['cost_points'];
		}

		$result = Api::redeem( $user_id, $kind, $args );
		if ( is_wp_error( $result ) ) {
			return array( 'summary' => $result->get_error_message() );
		}
		return array(
			'summary' => sprintf(
				/* translators: 1: reward kind, 2: remaining points. */
				__( 'Redeemed "%1$s" for the customer; %2$s point(s) remaining.', 'moksa-points-for-woocommerce' ),
				$kind,
				number_format( Api::get_points( $user_id ) )
			),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_transfer_points( $input ): array {
		if ( ! self::can_write() ) {
			return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$input  = is_array( $input ) ? $input : array();
		$from   = isset( $input['from_user_id'] ) ? (int) $input['from_user_id'] : 0;
		$to     = isset( $input['to_user_id'] ) ? (int) $input['to_user_id'] : 0;
		$points = isset( $input['points'] ) ? (int) $input['points'] : 0;
		$note   = isset( $input['note'] ) ? sanitize_text_field( (string) $input['note'] ) : '';
		if ( $from <= 0 || $to <= 0 || $from === $to || $points <= 0 ) {
			return array( 'summary' => __( 'A valid sender, a different recipient, and a positive points value are required.', 'moksa-points-for-woocommerce' ) );
		}
		$svc = '\\Moksafopoi\\Modules\\Transfer\\Service';
		if ( ! class_exists( $svc ) || ! method_exists( $svc, 'transfer' ) ) {
			return array( 'summary' => __( 'The points transfer module is not enabled.', 'moksa-points-for-woocommerce' ) );
		}
		$res = $svc::transfer( $from, $to, $points, $note );
		if ( is_wp_error( $res ) ) {
			return array( 'summary' => $res->get_error_message() );
		}
		return array(
			'summary' => sprintf(
				/* translators: 1: points, 2: sender id, 3: recipient id. */
				__( 'Transferred %1$s point(s) from customer #%2$d to customer #%3$d.', 'moksa-points-for-woocommerce' ),
				number_format( $points ),
				$from,
				$to
			),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_adjust_credit( $input ): array {
		if ( ! self::can_write() ) {
			return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$input   = is_array( $input ) ? $input : array();
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$amount  = isset( $input['amount'] ) ? (float) $input['amount'] : 0.0;
		$note    = isset( $input['note'] ) ? sanitize_text_field( (string) $input['note'] ) : __( 'Manual store-credit adjustment', 'moksa-points-for-woocommerce' );
		$bucket  = isset( $input['bucket'] ) ? sanitize_text_field( (string) $input['bucket'] ) : (string) time();
		if ( $user_id <= 0 || 0.0 === $amount ) {
			return array( 'summary' => __( 'A valid user_id and a non-zero amount are required.', 'moksa-points-for-woocommerce' ) );
		}
		$ok = \Moksafopoi\Modules\Ledger\Ledger::record_once(
			$user_id,
			0,
			'adjust',
			'manual_credit',
			$bucket,
			array( 'amount_delta' => $amount, 'note' => $note, 'created_by' => get_current_user_id() )
		);
		if ( ! $ok ) {
			return array( 'summary' => __( 'This store-credit adjustment was already recorded (idempotent).', 'moksa-points-for-woocommerce' ) );
		}
		return array(
			'summary' => sprintf(
				/* translators: 1: signed amount, 2: user id, 3: new store-credit balance. */
				__( 'Adjusted store credit by %1$s for customer #%2$d; balance is now %3$s.', 'moksa-points-for-woocommerce' ),
				( $amount > 0 ? '+' : '' ) . number_format( $amount, 2 ),
				$user_id,
				number_format( Api::get_balance( $user_id ), 2 )
			),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_create_reward( $input ): array {
		if ( ! self::can_write() ) { return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) ); }
		return self::save_reward( 0, is_array( $input ) ? $input : array() );
	}

	/** @param mixed $input @return array<string,mixed> */
	public static function execute_update_reward( $input ): array {
		if ( ! self::can_write() ) { return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) ); }
		$input = is_array( $input ) ? $input : array();
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) { return array( 'summary' => __( 'A valid reward id is required.', 'moksa-points-for-woocommerce' ) ); }
		return self::save_reward( $id, $input );
	}

	/** Shared reward create/update via RewardAdmin\\Rewards::save. @param int $id @param array<string,mixed> $input @return array<string,mixed> */
	private static function save_reward( int $id, array $input ): array {
		$cls = '\\Moksafopoi\\Modules\\RewardAdmin\\Rewards';
		if ( ! class_exists( $cls ) ) { return array( 'summary' => __( 'The redemption catalog module is not available.', 'moksa-points-for-woocommerce' ) ); }
		$existing = ( $id > 0 && method_exists( $cls, 'find' ) ) ? (array) $cls::find( $id ) : array();
		$allowed_kinds = array( 'coupon', 'cart_discount', 'product' );
		$kind = isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : (string) ( $existing['kind'] ?? 'coupon' );
		if ( ! in_array( $kind, $allowed_kinds, true ) ) { return array( 'summary' => __( 'Invalid reward kind (use coupon / cart_discount / product).', 'moksa-points-for-woocommerce' ) ); }
		$data = array(
			'label'       => isset( $input['label'] ) ? sanitize_text_field( (string) $input['label'] ) : (string) ( $existing['label'] ?? '' ),
			'kind'        => $kind,
			'cost_points' => isset( $input['cost_points'] ) ? max( 0, (int) $input['cost_points'] ) : (int) ( $existing['cost_points'] ?? 0 ),
			'payload'     => isset( $input['payload'] ) ? ( is_scalar( $input['payload'] ) ? (string) $input['payload'] : (string) wp_json_encode( $input['payload'] ) ) : (string) ( $existing['payload'] ?? '' ),
			'stock'       => isset( $input['stock'] ) ? (int) $input['stock'] : (int) ( $existing['stock'] ?? -1 ),
			'active'      => isset( $input['active'] ) ? (int) ( ! empty( $input['active'] ) ) : (int) ( $existing['active'] ?? 1 ),
		);
		if ( '' === $data['label'] ) { return array( 'summary' => __( 'A reward label is required.', 'moksa-points-for-woocommerce' ) ); }
		$new_id = (int) $cls::save( $id, $data );
		return array(
			'summary' => sprintf(
				/* translators: 1: reward id, 2: label, 3: cost points. */
				__( 'Saved reward #%1$d "%2$s" (cost %3$s point(s)).', 'moksa-points-for-woocommerce' ),
				$new_id, $data['label'], number_format( $data['cost_points'] )
			),
		);
	}

	/** @param mixed $input @return array<string,mixed> */
	public static function execute_reverse_earn( $input ): array {
		if ( ! self::can_write() ) { return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) ); }
		$input = is_array( $input ) ? $input : array();
		$order_id = isset( $input['order_id'] ) ? (int) $input['order_id'] : 0;
		if ( $order_id <= 0 ) { return array( 'summary' => __( 'A valid order_id is required.', 'moksa-points-for-woocommerce' ) ); }
		$cls = '\\Moksafopoi\\Modules\\Refund\\Module';
		if ( ! class_exists( $cls ) || ! method_exists( $cls, 'clawback' ) ) { return array( 'summary' => __( 'The refund / reversal module is not available.', 'moksa-points-for-woocommerce' ) ); }
		$cls::clawback( $order_id );
		return array(
			'summary' => sprintf(
				/* translators: %d: order id. */
				__( 'Reversed the points earned on order #%d (idempotent; balance never goes below zero).', 'moksa-points-for-woocommerce' ),
				$order_id
			),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function execute_expire( $input ): array {
		if ( ! self::can_write() ) {
			return array( 'summary' => __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		// The Expiry sweep is owned by the Ledger module; it scans all users with lapsed
		// earn rows (the per-user input is accepted for forward-compat but the proven sweep
		// is store-wide). Run it when present, else no-op gracefully.
		$expiry = '\\Moksafopoi\\Modules\\Ledger\\Expiry';
		if ( class_exists( $expiry ) && method_exists( $expiry, 'run' ) ) {
			$expiry::run();
			return array( 'summary' => __( 'Ran a points expiry scan (expired, unused points were deducted and recorded).', 'moksa-points-for-woocommerce' ) );
		}
		return array( 'summary' => __( 'The expiry scan component is not enabled (enable the points ledger module first).', 'moksa-points-for-woocommerce' ) );
	}

	/**
	 * Update ONE whitelisted store setting. Destructive. Hard rules, in order:
	 *   1. cap check (manage_woocommerce) — never __return_true.
	 *   2. the key MUST be present in {@see settings_whitelist()}; anything else (including the
	 *      security/MCP/cap options, which are not in the list) is rejected with a WP_Error and NO
	 *      option is touched.
	 *   3. the value is coerced/validated by the setting's declared type (number clamp, yes/no, hex
	 *      colour, sanitised string) — an invalid value never reaches the database raw.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute_update_setting( $input ) {
		if ( ! self::can_write() ) {
			return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$input = is_array( $input ) ? $input : array();
		$key   = isset( $input['key'] ) ? sanitize_key( (string) $input['key'] ) : '';

		$whitelist = self::settings_whitelist();
		if ( '' === $key || ! isset( $whitelist[ $key ] ) ) {
			return new \WP_Error(
				'moksafopoi_setting_not_allowed',
				__( 'This setting key cannot be modified (not whitelisted). Use points/get-settings to look up modifiable keys.', 'moksa-points-for-woocommerce' )
			);
		}

		$spec     = $whitelist[ $key ];
		$validated = self::sanitize_setting_value( $spec, $input['value'] ?? null );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$old_value = get_option( $key, null );
		update_option( $key, $validated );
		if ( class_exists( '\Moksafopoi\Support\SettingsAudit' ) ) {
			\Moksafopoi\Support\SettingsAudit::record( $key, $old_value, $validated, 'ability' );
		}

		return array(
			'summary' => sprintf(
				/* translators: 1: setting label, 2: new value. */
				__( 'Updated setting "%1$s" to: %2$s.', 'moksa-points-for-woocommerce' ),
				(string) ( $spec['label'] ?? $key ),
				is_scalar( $validated ) ? (string) $validated : wp_json_encode( $validated )
			),
		);
	}

	// === Settings whitelist (central; shared by get-settings + update-setting) ===

	/**
	 * The ONE allow-list of store settings an AI / MCP client may read and (when destructive exposure
	 * is opted-in) write. Only safe, customer-facing configuration is here. Security-sensitive options
	 * are deliberately ABSENT and therefore unreadable + unwritable through these abilities:
	 *   - moksafopoi_mcp_enabled / moksafopoi_mcp_server_enabled / moksafopoi_mcp_expose_destructive
	 *   - moksafopoi_abilities_enabled and every moksafopoi_*_enabled module toggle
	 * Each entry declares the type that drives sanitisation/validation in {@see sanitize_setting_value()}.
	 *
	 * type semantics:
	 *   number  → float, clamped to [min, max] (max optional). Stored as int when whole.
	 *   bool    → yes/no string.
	 *   color   → #rgb / #rrggbb (sanitize_hex_color); '' allowed only when allow_empty.
	 *   string  → sanitize_text_field; choices[] restricts to an enum when present.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function settings_whitelist(): array {
		return array(
			// --- Earn ---
			'moksafopoi_points_per_currency' => array(
				'label'   => __( 'Points per NT$1', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '1',
				'min'     => 0,
			),
			'moksafopoi_earn_rounding'       => array(
				'label'   => __( 'Points rounding method', 'moksa-points-for-woocommerce' ),
				'type'    => 'string',
				'default' => 'floor',
				'choices' => array( 'floor', 'round', 'ceil' ),
			),
			'moksafopoi_earn_exclude_sale'   => array(
				'label'   => __( 'No points on sale items', 'moksa-points-for-woocommerce' ),
				'type'    => 'bool',
				'default' => 'no',
			),
			'moksafopoi_earn_include_shipping' => array(
				'label'   => __( 'Earning base includes shipping', 'moksa-points-for-woocommerce' ),
				'type'    => 'bool',
				'default' => 'no',
			),
			'moksafopoi_earn_include_tax'    => array(
				'label'   => __( 'Earning base includes tax', 'moksa-points-for-woocommerce' ),
				'type'    => 'bool',
				'default' => 'no',
			),
			'moksafopoi_earn_max_per_order'  => array(
				'label'   => __( 'Per-order earning cap', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '0',
				'min'     => 0,
			),
			'moksafopoi_points_expire_months' => array(
				'label'   => __( 'Points validity (months)', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '0',
				'min'     => 0,
			),
			'moksafopoi_expiry_reminder_days' => array(
				'label'   => __( 'Reminder days before expiry', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '14',
				'min'     => 1,
			),
			// --- Redeem ---
			'moksafopoi_redeem_rate'         => array(
				'label'   => __( 'Points per NT$1 redeemed', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '100',
				'min'     => 1,
			),
			'moksafopoi_redeem_min_points'   => array(
				'label'   => __( 'Minimum redeemable points', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '100',
				'min'     => 0,
			),
			'moksafopoi_redeem_step'         => array(
				'label'   => __( 'Redemption increment (points)', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '100',
				'min'     => 1,
			),
			'moksafopoi_redeem_max_percent'  => array(
				'label'   => __( 'Maximum cart discount %', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '100',
				'min'     => 0,
				'max'     => 100,
			),
			'moksafopoi_redeem_max_fixed'    => array(
				'label'   => __( 'Per-order redemption cap (NT$)', 'moksa-points-for-woocommerce' ),
				'type'    => 'number',
				'default' => '0',
				'min'     => 0,
			),
			// --- Branding / label ---
			'moksafopoi_label'               => array(
				'label'   => __( 'Points unit name', 'moksa-points-for-woocommerce' ),
				'type'    => 'string',
				'default' => __( 'point', 'moksa-points-for-woocommerce' ),
			),
			// --- E-mail look ---
			'moksafopoi_email_accent'        => array(
				'label'       => __( 'Email header background color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '#d4af37',
				'allow_empty' => false,
			),
			'moksafopoi_email_header'        => array(
				'label'   => __( 'Email header text', 'moksa-points-for-woocommerce' ),
				'type'    => 'string',
				'default' => '',
			),
			// --- Display: product/loop/cart/account text colours (no rich HTML; that stays admin-only) ---
			'moksafopoi_disp_single_text_color' => array(
				'label'       => __( 'Product page message text color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '#000000',
				'allow_empty' => false,
			),
			'moksafopoi_disp_single_bg_color' => array(
				'label'       => __( 'Product page message background color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '',
				'allow_empty' => true,
			),
			'moksafopoi_disp_loop_text_color' => array(
				'label'       => __( 'Listing message text color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '#000000',
				'allow_empty' => false,
			),
			'moksafopoi_disp_loop_bg_color'  => array(
				'label'       => __( 'Listing message background color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '',
				'allow_empty' => true,
			),
			'moksafopoi_disp_cart_text_color' => array(
				'label'       => __( 'Cart message text color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '#000000',
				'allow_empty' => false,
			),
			'moksafopoi_disp_cart_bg_color'  => array(
				'label'       => __( 'Cart message background color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '',
				'allow_empty' => true,
			),
			'moksafopoi_disp_account_text_color' => array(
				'label'       => __( 'My Account message text color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '#000000',
				'allow_empty' => false,
			),
			'moksafopoi_disp_account_bg_color' => array(
				'label'       => __( 'My Account message background color', 'moksa-points-for-woocommerce' ),
				'type'        => 'color',
				'default'     => '',
				'allow_empty' => true,
			),
		);
	}

	/**
	 * Coerce + validate a raw value against a whitelist setting spec. Returns the safe value to store,
	 * or a WP_Error when the value cannot be made valid (so the option is left untouched).
	 *
	 * @param array<string,mixed> $spec
	 * @param mixed               $raw
	 * @return string|\WP_Error
	 */
	private static function sanitize_setting_value( array $spec, $raw ) {
		$type = (string) ( $spec['type'] ?? 'string' );

		switch ( $type ) {
			case 'number':
				if ( ! is_scalar( $raw ) || ! is_numeric( (string) $raw ) ) {
					return new \WP_Error( 'moksafopoi_setting_invalid', __( 'This setting requires a numeric value.', 'moksa-points-for-woocommerce' ) );
				}
				$num = (float) $raw;
				$min = isset( $spec['min'] ) ? (float) $spec['min'] : 0.0;
				$num = max( $min, $num );
				if ( isset( $spec['max'] ) ) {
					$num = min( (float) $spec['max'], $num );
				}
				// Store as int when whole, else keep the decimal (mirrors SettingsScreen::handle()).
				return ( floor( $num ) === $num ) ? (string) (int) $num : (string) $num;

			case 'bool':
				if ( is_bool( $raw ) ) {
					return $raw ? 'yes' : 'no';
				}
				$str = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
				return in_array( $str, array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';

			case 'color':
				$str = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( '' === $str ) {
					if ( ! empty( $spec['allow_empty'] ) ) {
						return ''; // transparent / unset is legitimate for this field.
					}
					return new \WP_Error( 'moksafopoi_setting_invalid', __( 'This setting requires a valid #rrggbb color code.', 'moksa-points-for-woocommerce' ) );
				}
				$hex = sanitize_hex_color( $str );
				if ( ! is_string( $hex ) || '' === $hex ) {
					return new \WP_Error( 'moksafopoi_setting_invalid', __( 'Invalid color format (must be #rgb or #rrggbb).', 'moksa-points-for-woocommerce' ) );
				}
				return $hex;

			case 'string':
			default:
				$str = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
				if ( isset( $spec['choices'] ) && is_array( $spec['choices'] ) && ! in_array( $str, $spec['choices'], true ) ) {
					return new \WP_Error(
						'moksafopoi_setting_invalid',
						sprintf(
							/* translators: %s: comma-separated allowed values. */
							__( 'This setting only accepts the following values: %s.', 'moksa-points-for-woocommerce' ),
							implode( ', ', array_map( 'strval', $spec['choices'] ) )
						)
					);
				}
				return $str;
		}
	}

	// === Permission callbacks (real caps; never __return_true) ===

	public static function can_read(): bool {
		return current_user_can( self::CAP );
	}

	public static function can_write(): bool {
		/**
		 * Capability for DESTRUCTIVE points abilities (adjust / redeem / expire / update-setting).
		 * Deliberately STRONGER than the read cap: these mint or mutate customer value and store
		 * settings, and over MCP they run with no confirmation layer — so a leaked shop-manager
		 * (manage_woocommerce) Application Password must not be able to call them. Defaults to
		 * `manage_options` (administrator); filterable for sites that grant a dedicated role.
		 *
		 * @param string $cap The capability required to run destructive points abilities.
		 */
		return current_user_can( (string) apply_filters( 'moksafopoi_write_capability', 'manage_options' ) );
	}

	public static function can_view_reports(): bool {
		return current_user_can( self::REPORT_CAP ) || current_user_can( self::CAP );
	}

	// === Helpers ===

	/**
	 * Resolve a user id from {user_id} or {email}.
	 *
	 * @param array<string,mixed> $input
	 */
	private static function resolve_user( array $input ): int {
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		if ( $user_id > 0 ) {
			return $user_id;
		}
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}
		return 0;
	}

	/** Meta for a read-only ability (safe, idempotent, public over MCP). @return array<string,mixed> */
	private static function read_meta(): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);
	}

	/** Meta for a write/destructive ability. @return array<string,mixed> */
	private static function write_meta(): array {
		return array(
			'show_in_rest' => false,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);
	}

	/** Shared single-summary output schema. @return array<string,mixed> */
	private static function summary_output(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'summary' => array( 'type' => 'string' ) ),
		);
	}

	// === MCP exposure gate (mirrors moforcoupon: hide destructive unless opted-in) ===

	private static function expose_destructive(): bool {
		return 'yes' === get_option( 'moksafopoi_mcp_expose_destructive', 'no' );
	}

	/**
	 * Flip mcp.public off for destructive abilities unless the opt-in option is set. Must be
	 * registered as a wp_register_ability_args filter BEFORE the init action fires.
	 *
	 * @param mixed $args
	 * @param mixed $name
	 * @return mixed
	 */
	public static function gate_mcp_exposure( $args, $name ) {
		if ( ! is_string( $name ) || 0 !== strpos( $name, 'points/' ) || ! is_array( $args ) ) {
			return $args;
		}
		// The settings-mutation tool is NEVER exposed over external MCP — a remote agent changing store
		// configuration unattended (no confirm layer over MCP) is the highest blast radius. It stays
		// available in the in-admin AI chat (with the confirm flow) and REST, just not as an MCP tool.
		if ( 'points/update-setting' === $name ) {
			$args['meta']['mcp']['public'] = false;
			self::$mcp_hidden[ $name ]     = true;
			return $args;
		}
		$destructive = ! empty( $args['meta']['annotations']['destructive'] );
		if ( $destructive && ! self::expose_destructive() ) {
			$args['meta']['mcp']['public'] = false;
			self::$mcp_hidden[ $name ]     = true;
		}
		return $args;
	}

	/**
	 * @param mixed  $included
	 * @param string $ability_id
	 * @return mixed
	 */
	public static function include_in_mcp( $included, $ability_id ) {
		if ( is_string( $ability_id ) && 0 === strpos( $ability_id, 'points/' ) ) {
			return empty( self::$mcp_hidden[ $ability_id ] );
		}
		return $included;
	}

	// === Shared AI assistant bridge (lib/moksa-ai) ============================================
	// Points registers its own abilities + destructive-confirm handlers into the neutral shared
	// chat so the in-dashboard AI assistant can answer/act on points even when moforpoints runs
	// standalone. Reads run directly; every write is intercepted into a propose → human-confirm
	// → apply flow by the shared Agent, and each apply re-checks can_write (the stronger cap).

	/**
	 * @param array<int,string> $names
	 * @return array<int,string>
	 */
	public static function ai_abilities( array $names ): array {
		return array_values(
			array_unique(
				array_merge(
					$names,
					array(
						'points/get-balance',
						'points/get-history',
						'points/list-rules',
						'points/list-rewards',
						'points/points-report',
						'points/get-settings',
						'points/find-customer',
						'points/get-active-campaign',
						'points/leaderboard-top',
						'points/get-badges',
						'points/forecast-liability',
						'points/suggest-rewards',
						'points/adjust-points',
						'points/redeem-points',
						'points/expire-points',
						'points/update-setting',
						'points/transfer-points',
						'points/adjust-credit',
						'points/create-reward',
						'points/update-reward',
						'points/reverse-earn',
					)
				)
			)
		);
	}

	/**
	 * @param array<string,array{prepare:callable,apply:callable}> $handlers
	 * @return array<string,array{prepare:callable,apply:callable}>
	 */
	public static function ai_destructive_handlers( array $handlers ): array {
		$handlers['points/adjust-points']  = array( 'prepare' => array( self::class, 'ai_prep_adjust' ), 'apply' => array( self::class, 'ai_apply_adjust' ) );
		$handlers['points/redeem-points']  = array( 'prepare' => array( self::class, 'ai_prep_redeem' ), 'apply' => array( self::class, 'ai_apply_redeem' ) );
		$handlers['points/expire-points']  = array( 'prepare' => array( self::class, 'ai_prep_expire' ), 'apply' => array( self::class, 'ai_apply_expire' ) );
		$handlers['points/update-setting'] = array( 'prepare' => array( self::class, 'ai_prep_setting' ), 'apply' => array( self::class, 'ai_apply_setting' ) );
		$handlers['points/transfer-points'] = array( 'prepare' => array( self::class, 'ai_prep_transfer' ), 'apply' => array( self::class, 'ai_apply_transfer' ) );
		$handlers['points/adjust-credit'] = array( 'prepare' => array( self::class, 'ai_prep_credit' ), 'apply' => array( self::class, 'ai_apply_credit' ) );
		$handlers['points/create-reward'] = array( 'prepare' => array( self::class, 'ai_prep_reward' ), 'apply' => array( self::class, 'ai_apply_create_reward' ) );
		$handlers['points/update-reward'] = array( 'prepare' => array( self::class, 'ai_prep_reward' ), 'apply' => array( self::class, 'ai_apply_update_reward' ) );
		$handlers['points/reverse-earn'] = array( 'prepare' => array( self::class, 'ai_prep_reverse' ), 'apply' => array( self::class, 'ai_apply_reverse' ) );
		return $handlers;
	}

	public static function ai_system_instruction( string $base ): string {
		$points = __( 'You also manage a WooCommerce points & store-credit program. Read tools: get-balance and get-history (a customer\'s points / store-credit), list-rules and list-rewards (earn rules and the redemption catalog), points-report (issued / redeemed / expired / outstanding), get-settings (current whitelisted settings). Write tools (all "proposed" — the system shows the customer a confirmation card and only applies after they click Confirm, so never ask for confirmation yourself): adjust-points (add or deduct points for a customer, note optional), redeem-points (redeem a reward for a customer), expire-points (run a store-wide expiry sweep), update-setting (change ONE whitelisted setting — always call get-settings first to see the exact key and current value). Points are integers; identify a customer by user_id or email. Reply in the language the user writes in. Always finish with a text answer, never stop on a tool call.', 'moksa-points-for-woocommerce' );
		return '' === trim( $base ) ? $points : $base . "\n\n" . $points;
	}

	// --- prepare (validate + summary) / apply (execute) for each destructive points ability ---

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_adjust( array $args ) {
		if ( ! self::can_write() ) {
			return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$user_id = self::resolve_user( $args );
		$points  = isset( $args['points'] ) ? (int) $args['points'] : 0;
		if ( $user_id <= 0 || 0 === $points ) {
			return new \WP_Error( 'moksafopoi_invalid', __( 'A valid user_id (or email) and a non-zero points value are required.', 'moksa-points-for-woocommerce' ) );
		}
		$note = isset( $args['note'] ) ? sanitize_text_field( (string) $args['note'] ) : __( 'Manual adjustment', 'moksa-points-for-woocommerce' );
		return array(
			'summary' => sprintf(
				/* translators: 1: signed points, 2: user id, 3: current balance, 4: note. */
				__( 'Adjust customer #%2$d by %1$s point(s) (current balance %3$s). Note: %4$s', 'moksa-points-for-woocommerce' ),
				( $points > 0 ? '+' : '' ) . number_format( $points ),
				$user_id,
				number_format( Api::get_points( $user_id ) ),
				$note
			),
			'user_id' => $user_id,
			'points'  => $points,
			'note'    => $note,
			'bucket'  => isset( $args['bucket'] ) ? sanitize_text_field( (string) $args['bucket'] ) : '',
		);
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_adjust( array $p ) {
		$r = self::execute_adjust( $p );
		return (string) ( $r['summary'] ?? '' );
	}

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_redeem( array $args ) {
		if ( ! self::can_write() ) {
			return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$user_id = self::resolve_user( $args );
		$kind    = isset( $args['reward_kind'] ) ? sanitize_key( (string) $args['reward_kind'] ) : '';
		if ( $user_id <= 0 || '' === $kind ) {
			return new \WP_Error( 'moksafopoi_invalid', __( 'A valid user_id (or email) and a redemption type are required.', 'moksa-points-for-woocommerce' ) );
		}
		return array(
			'summary'     => sprintf(
				/* translators: 1: reward kind, 2: user id, 3: current balance. */
				__( 'Redeem "%1$s" for customer #%2$d (current balance %3$s point(s)).', 'moksa-points-for-woocommerce' ),
				$kind,
				$user_id,
				number_format( Api::get_points( $user_id ) )
			),
			'user_id'     => $user_id,
			'reward_kind' => $kind,
			'reward_id'   => isset( $args['reward_id'] ) ? (int) $args['reward_id'] : 0,
			'cost_points' => isset( $args['cost_points'] ) ? (int) $args['cost_points'] : 0,
		);
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_redeem( array $p ) {
		$r = self::execute_redeem( $p );
		return (string) ( $r['summary'] ?? '' );
	}

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_expire( array $args ) {
		if ( ! self::can_write() ) {
			return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		return array(
			'summary' => __( 'Run a store-wide points expiry sweep now: expired, unused points will be deducted and recorded in the ledger.', 'moksa-points-for-woocommerce' ),
		);
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_expire( array $p ) {
		$r = self::execute_expire( $p );
		return (string) ( $r['summary'] ?? '' );
	}

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_setting( array $args ) {
		if ( ! self::can_write() ) {
			return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) );
		}
		$key       = isset( $args['key'] ) ? sanitize_key( (string) $args['key'] ) : '';
		$whitelist = self::settings_whitelist();
		if ( '' === $key || ! isset( $whitelist[ $key ] ) ) {
			return new \WP_Error( 'moksafopoi_setting_not_allowed', __( 'This setting key cannot be modified (not whitelisted). Use points/get-settings to look up modifiable keys.', 'moksa-points-for-woocommerce' ) );
		}
		$spec = $whitelist[ $key ];
		$new  = is_scalar( $args['value'] ?? null ) ? (string) $args['value'] : wp_json_encode( $args['value'] ?? null );
		$old  = get_option( $key, '' );
		return array(
			'summary' => sprintf(
				/* translators: 1: setting label, 2: old value, 3: new value. */
				__( 'Change setting "%1$s" from "%2$s" to "%3$s".', 'moksa-points-for-woocommerce' ),
				(string) ( $spec['label'] ?? $key ),
				is_scalar( $old ) ? (string) $old : wp_json_encode( $old ),
				(string) $new
			),
			'key'     => $key,
			'value'   => $args['value'] ?? null,
		);
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_setting( array $p ) {
		$r = self::execute_update_setting( $p );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return (string) ( $r['summary'] ?? '' );
	}

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_transfer( array $args ) {
		if ( ! self::can_write() ) { return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) ); }
		$from = isset( $args['from_user_id'] ) ? (int) $args['from_user_id'] : 0;
		$to   = isset( $args['to_user_id'] ) ? (int) $args['to_user_id'] : 0;
		$points = isset( $args['points'] ) ? (int) $args['points'] : 0;
		if ( $from <= 0 || $to <= 0 || $from === $to || $points <= 0 ) { return new \WP_Error( 'moksafopoi_invalid', __( 'A valid sender, a different recipient, and a positive points value are required.', 'moksa-points-for-woocommerce' ) ); }
		return array(
			'summary' => sprintf(
				/* translators: 1: points, 2: sender id, 3: recipient id, 4: sender balance. */
				__( 'Transfer %1$s point(s) from customer #%2$d (balance %4$s) to customer #%3$d.', 'moksa-points-for-woocommerce' ),
				number_format( $points ), $from, $to, number_format( Api::get_points( $from ) )
			),
			'from_user_id' => $from,
			'to_user_id'   => $to,
			'points'       => $points,
			'note'         => isset( $args['note'] ) ? sanitize_text_field( (string) $args['note'] ) : '',
		);
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_transfer( array $p ) { $r = self::execute_transfer_points( $p ); return (string) ( $r['summary'] ?? '' ); }

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_credit( array $args ) {
		if ( ! self::can_write() ) { return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) ); }
		$user_id = self::resolve_user( $args );
		$amount  = isset( $args['amount'] ) ? (float) $args['amount'] : 0.0;
		if ( $user_id <= 0 || 0.0 === $amount ) { return new \WP_Error( 'moksafopoi_invalid', __( 'A valid user_id (or email) and a non-zero amount are required.', 'moksa-points-for-woocommerce' ) ); }
		$note = isset( $args['note'] ) ? sanitize_text_field( (string) $args['note'] ) : __( 'Manual store-credit adjustment', 'moksa-points-for-woocommerce' );
		return array(
			'summary' => sprintf(
				/* translators: 1: signed amount, 2: user id, 3: current balance, 4: note. */
				__( 'Adjust store credit for customer #%2$d by %1$s (current balance %3$s). Note: %4$s', 'moksa-points-for-woocommerce' ),
				( $amount > 0 ? '+' : '' ) . number_format( $amount, 2 ), $user_id, number_format( Api::get_balance( $user_id ), 2 ), $note
			),
			'user_id' => $user_id,
			'amount'  => $amount,
			'note'    => $note,
			'bucket'  => isset( $args['bucket'] ) ? sanitize_text_field( (string) $args['bucket'] ) : '',
		);
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_credit( array $p ) { $r = self::execute_adjust_credit( $p ); return (string) ( $r['summary'] ?? '' ); }

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_reward( array $args ) {
		if ( ! self::can_write() ) { return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) ); }
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$label = isset( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : '';
		$cost = isset( $args['cost_points'] ) ? (int) $args['cost_points'] : null;
		if ( $id > 0 ) {
			/* translators: %d: reward id. */
			$summary = sprintf( __( 'Update reward #%d.', 'moksa-points-for-woocommerce' ), $id );
			if ( '' !== $label ) {
				/* translators: %s: new reward label. */
				$summary .= ' ' . sprintf( __( 'New label: %s.', 'moksa-points-for-woocommerce' ), $label );
			}
		} else {
			/* translators: %s: reward label. */
			$summary = sprintf( __( 'Create reward "%s".', 'moksa-points-for-woocommerce' ), $label );
			if ( null !== $cost ) {
				/* translators: %s: cost in points. */
				$summary .= ' ' . sprintf( __( 'Cost: %s point(s).', 'moksa-points-for-woocommerce' ), number_format( max( 0, $cost ) ) );
			}
		}
		$args['summary'] = $summary;
		return $args;
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_create_reward( array $p ) { $r = self::execute_create_reward( $p ); return (string) ( $r['summary'] ?? '' ); }

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_update_reward( array $p ) { $r = self::execute_update_reward( $p ); return (string) ( $r['summary'] ?? '' ); }

	/** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
	public static function ai_prep_reverse( array $args ) {
		if ( ! self::can_write() ) { return new \WP_Error( 'moksafopoi_forbidden', __( 'You do not have permission to do this.', 'moksa-points-for-woocommerce' ) ); }
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		if ( $order_id <= 0 ) { return new \WP_Error( 'moksafopoi_invalid', __( 'A valid order_id is required.', 'moksa-points-for-woocommerce' ) ); }
		/* translators: %d: order id. */
		$summary = sprintf( __( 'Reverse the points earned on order #%d.', 'moksa-points-for-woocommerce' ), $order_id );
		return array( 'summary' => $summary, 'order_id' => $order_id );
	}

	/** @param array<string,mixed> $p @return string|\WP_Error */
	public static function ai_apply_reverse( array $p ) { $r = self::execute_reverse_earn( $p ); return (string) ( $r['summary'] ?? '' ); }

	/**
	 * `points/forecast-liability` — the accountant's view of the programme, straight off the ledger.
	 *
	 * @return array<string,mixed>
	 */
	public static function execute_forecast_liability(): array {
		$snapshot = \Moksafopoi\Support\Forecast::snapshot();

		return array(
			'outstanding'          => (int) $snapshot['outstanding'],
			'liability_value'      => (float) $snapshot['liability_value'],
			'redeem_rate'          => (int) $snapshot['redeem_rate'],
			'members_with_balance' => (int) $snapshot['members_with_balance'],
			'expiring_30'          => (int) $snapshot['expiring']['d30'],
			'expiring_60'          => (int) $snapshot['expiring']['d60'],
			'expiring_90'          => (int) $snapshot['expiring']['d90'],
			'issued_total'         => (int) $snapshot['issued_total'],
			'redeemed_total'       => (int) $snapshot['redeemed_total'],
			'expired_total'        => (int) $snapshot['expired_total'],
			'breakage_pct'         => (float) $snapshot['breakage_pct'],
			'redemption_rate_pct'  => (float) $snapshot['redemption_rate_pct'],
			'observations'         => \Moksafopoi\Support\Forecast::observations(),
		);
	}

	/**
	 * `points/suggest-rewards` — reward pricing advice derived from the live balance distribution.
	 *
	 * @return array<string,mixed>
	 */
	public static function execute_suggest_rewards(): array {
		$suggestions = \Moksafopoi\Support\Forecast::suggest_rewards();

		return array(
			'count'       => count( $suggestions ),
			'suggestions' => $suggestions,
		);
	}
}
