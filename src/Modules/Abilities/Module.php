<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Abilities;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities module — registers all `points/*` WordPress Abilities (reads + propose-only
 * writes) on `wp_abilities_api_init`, behind a 6.9+ `function_exists` guard so older WP
 * just runs without them. Also wires the two MCP exposure-gate filters BEFORE the init
 * action fires, so destructive abilities are hidden from the MCP tool list unless
 * `moksafopoi_mcp_expose_destructive='yes'`. Mirrors moforcoupon's CouponCore wiring.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'abilities';
	}

	public function label(): string {
		return __( 'Points Abilities (AI / MCP capabilities)', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'integration';
	}

	public function tagline(): string {
		return __( 'Register points lookup / reporting / adjustment as WordPress Abilities for the command palette, REST and MCP', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Abilities require WordPress 6.9+ core Abilities API — degrade gracefully.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( Ability::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Ability::class, 'register' ) );

		// The MCP exposure gate must be added before the init action fires.
		add_filter( 'wp_register_ability_args', array( Ability::class, 'gate_mcp_exposure' ), 10, 2 );
		add_filter( 'woocommerce_mcp_include_ability', array( Ability::class, 'include_in_mcp' ), 10, 2 );

		// Bridge the points abilities into the shared in-dashboard AI assistant (lib/moksa-ai) via its
		// neutral filters, so natural-language points chat + a propose→confirm→apply flow for every
		// points write work even when moforpoints runs standalone (no sibling AI plugin needed).
		add_filter( 'moksa_ai_abilities', array( Ability::class, 'ai_abilities' ) );
		add_filter( 'moksa_ai_destructive_handlers', array( Ability::class, 'ai_destructive_handlers' ) );
		add_filter( 'moksa_ai_system_instruction', array( Ability::class, 'ai_system_instruction' ) );
	}
}
