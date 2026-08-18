<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\CategoryPoints;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\CategoryPoints;

defined( 'ABSPATH' ) || exit;

/**
 * 逐分類賺點倍率 — adds a「此分類點數倍率」field to the WooCommerce product category (`product_cat`)
 * add / edit screens. Stored as TERM META (`_moksafopoi_cat_earn_multiplier`, default 1.0) — never
 * a CPT, never an option. The read side lives in {@see CategoryPoints} so SpendRules honours the
 * multiplier per line item even when this admin module is off.
 */
final class Module extends AbstractModule {

	private const NONCE_ACTION = 'moksafopoi_cat_points';
	private const NONCE_FIELD  = 'moksafopoi_cat_points_nonce';

	public function slug(): string {
		return 'categorypoints';
	}

	public function label(): string {
		return __( 'Per-category earning multiplier', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'Set "this category\'s points multiplier" on a product category (default 1); applied per line item when earning, taking the maximum across multiple categories', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		add_action( 'product_cat_add_form_fields', array( self::class, 'render_add_field' ) );
		add_action( 'product_cat_edit_form_fields', array( self::class, 'render_edit_field' ), 10, 1 );
		add_action( 'created_product_cat', array( self::class, 'save' ) );
		add_action( 'edited_product_cat', array( self::class, 'save' ) );
	}

	/** The「新增分類」form (no term yet → no current value). */
	public static function render_add_field(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<div class="form-field term-' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '-wrap">';
		echo '<label for="' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '">'
			. esc_html__( 'This category\'s points multiplier', 'moksa-points-for-woocommerce' ) . '</label>';
		echo '<input type="number" name="' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '" '
			. 'id="' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '" '
			. 'value="1" step="0.1" min="0" max="' . esc_attr( (string) CategoryPoints::MAX_MULTIPLIER ) . '">';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: maximum allowed multiplier. */
				__( 'This category\'s product earning multiplier (default 1, i.e. no boost). Each earning line is multiplied by this factor, up to %s×. When a product belongs to multiple categories, the maximum multiplier is used.', 'moksa-points-for-woocommerce' ),
				(string) CategoryPoints::MAX_MULTIPLIER
			)
		) . '</p>';
		echo '</div>';
	}

	/**
	 * The「編輯分類」form (table row layout).
	 *
	 * @param \WP_Term|mixed $term
	 */
	public static function render_edit_field( $term ): void {
		$term_id = $term instanceof \WP_Term ? (int) $term->term_id : 0;
		$current = $term_id > 0 ? CategoryPoints::term_multiplier( $term_id ) : 1.0;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<tr class="form-field term-' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '-wrap">';
		echo '<th scope="row"><label for="' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '">'
			. esc_html__( 'This category\'s points multiplier', 'moksa-points-for-woocommerce' ) . '</label></th>';
		echo '<td>';
		echo '<input type="number" name="' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '" '
			. 'id="' . esc_attr( CategoryPoints::META_MULTIPLIER ) . '" '
			. 'value="' . esc_attr( self::format( $current ) ) . '" step="0.1" min="0" max="' . esc_attr( (string) CategoryPoints::MAX_MULTIPLIER ) . '">';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: maximum allowed multiplier. */
				__( 'This category\'s product earning multiplier (default 1, i.e. no boost). Each earning line is multiplied by this factor, up to %s×. When a product belongs to multiple categories, the maximum multiplier is used.', 'moksa-points-for-woocommerce' ),
				(string) CategoryPoints::MAX_MULTIPLIER
			)
		) . '</p>';
		echo '</td></tr>';
	}

	/** Persist the multiplier term meta from either the add or edit form. */
	public static function save( int $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! isset( $_POST[ CategoryPoints::META_MULTIPLIER ] ) ) {
			return;
		}

		$raw = sanitize_text_field( wp_unslash( (string) $_POST[ CategoryPoints::META_MULTIPLIER ] ) );
		// Empty or the neutral 1.0 → drop the meta (keeps the table clean; read side defaults to 1.0).
		if ( '' === $raw ) {
			delete_term_meta( $term_id, CategoryPoints::META_MULTIPLIER );
			return;
		}
		$value = CategoryPoints::clamp( (float) $raw );
		if ( 1.0 === $value ) {
			delete_term_meta( $term_id, CategoryPoints::META_MULTIPLIER );
			return;
		}
		update_term_meta( $term_id, CategoryPoints::META_MULTIPLIER, self::format( $value ) );
	}

	/** Format a multiplier compactly (whole numbers without a trailing ".0"). */
	private static function format( float $value ): string {
		return ( floor( $value ) === $value ) ? (string) (int) $value : rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
