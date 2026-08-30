<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Blocks;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg 區塊 — the points surfaces as blocks, so a site builder can place them from the editor
 * instead of remembering shortcode names.
 *
 * Every block is DYNAMIC and renders by delegating to the shortcode that already exists, so there is
 * exactly one renderer per surface: a block and its shortcode can never drift apart, and a block
 * whose module is switched off renders nothing (the shortcode is simply not registered).
 *
 * The editor script is plain, dependency-light JavaScript — no build step, no bundled framework, so
 * the shipped file is the file that runs (a wp.org review requirement, and simply honest).
 */
final class Module extends AbstractModule {

	/** Editor script handle. */
	private const HANDLE = 'moksafopoi-blocks';

	public function slug(): string {
		return 'blocks';
	}

	public function label(): string {
		return __( 'Editor blocks', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'Place the points balance, tier, quests, how-to-earn guide, leaderboard and redemption mall from the block editor instead of typing shortcodes', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	/**
	 * Block name → the shortcode it renders and how the editor should describe it.
	 *
	 * @return array<string,array{shortcode:string,title:string,description:string}>
	 */
	public static function blocks(): array {
		return array(
			'balance'     => array(
				'shortcode'   => 'moksafopoi_balance',
				'title'       => __( 'Points balance', 'moksa-points-for-woocommerce' ),
				'description' => __( 'The logged-in member\'s current points balance.', 'moksa-points-for-woocommerce' ),
			),
			'tier'        => array(
				'shortcode'   => 'moksafopoi_tier',
				'title'       => __( 'Member tier', 'moksa-points-for-woocommerce' ),
				'description' => __( 'The member\'s current tier and how far the next one is.', 'moksa-points-for-woocommerce' ),
			),
			'quests'      => array(
				'shortcode'   => 'moksafopoi_quests',
				'title'       => __( 'Quests', 'moksa-points-for-woocommerce' ),
				'description' => __( 'The member\'s quest board with progress.', 'moksa-points-for-woocommerce' ),
			),
			'how-to-earn' => array(
				'shortcode'   => 'moksafopoi_how_to_earn',
				'title'       => __( 'How to earn points', 'moksa-points-for-woocommerce' ),
				'description' => __( 'A guide assembled from the earning features you have switched on.', 'moksa-points-for-woocommerce' ),
			),
			'leaderboard' => array(
				'shortcode'   => 'moksafopoi_leaderboard',
				'title'       => __( 'Points leaderboard', 'moksa-points-for-woocommerce' ),
				'description' => __( 'The top members by total points earned.', 'moksa-points-for-woocommerce' ),
			),
			'mall'        => array(
				'shortcode'   => 'moksafopoi_mall',
				'title'       => __( 'Redemption mall', 'moksa-points-for-woocommerce' ),
				'description' => __( 'The catalogue of items members can redeem with points.', 'moksa-points-for-woocommerce' ),
			),
		);
	}

	public function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/** Register the editor script and every dynamic block. */
	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // Classic-editor-only site.
		}

		$asset = 'assets/blocks.js';
		$path  = MOKSAFOPOI_PLUGIN_DIR . $asset;

		wp_register_script(
			self::HANDLE,
			MOKSAFOPOI_PLUGIN_URL . $asset,
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor' ),
			file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOPOI_VERSION,
			true
		);

		// The editor labels come from PHP so they go through the plugin's own translations rather than
		// a second, separate JS translation file.
		$labels = array();
		foreach ( self::blocks() as $name => $spec ) {
			$labels[ $name ] = array(
				'title'       => $spec['title'],
				'description' => $spec['description'],
			);
		}
		wp_add_inline_script(
			self::HANDLE,
			'window.moksafopoiBlocks = ' . wp_json_encode( $labels ) . ';',
			'before'
		);

		foreach ( self::blocks() as $name => $spec ) {
			register_block_type(
				'moksafopoi/' . $name,
				array(
					// Block API v3 (v2 and lower are deprecated as of WordPress 6.9 and log a console
					// warning in 7.1, where the editor canvas is always an iframe). These blocks only
					// render a placeholder built with useBlockProps, so they were v3-ready already.
					'api_version'     => 3,
					'title'           => $spec['title'],
					'description'     => $spec['description'],
					'category'        => 'widgets',
					'icon'            => 'star-filled',
					'editor_script'   => self::HANDLE,
					'render_callback' => static function () use ( $spec ): string {
						// Delegate to the one renderer that already exists. An unregistered shortcode
						// (its module is off) returns its own literal text, so guard on that instead of
						// printing "[moksafopoi_x]" on the page.
						if ( ! shortcode_exists( $spec['shortcode'] ) ) {
							return '';
						}
						return wp_kses_post( (string) do_shortcode( '[' . $spec['shortcode'] . ']' ) );
					},
				)
			);
		}
	}
}
