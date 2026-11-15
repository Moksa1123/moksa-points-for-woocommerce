<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Referral-code → member lookup through a private taxonomy on users (owner/總機-approved 2026-11-15,
 * docs/批2-資料庫改寫設計.md §2.G), same idea as Moksa Coupons' CouponIndex.
 *
 * Each member's referral code is a term (slug = the code, which is public anyway: it is printed in the
 * member's share link), related to the user ID. get_term_by( slug ) + get_objects_in_term() are both
 * indexed lookups, unlike a meta_value match on wp_usermeta. Nothing else about the member is stored.
 *
 * Sites that already have codes are back-filled in batches through Action Scheduler (read every user's
 * meta, write the term). Until that finishes, a code missing from the index is looked up the old way
 * (LegacyStorage) and indexed on the spot.
 */
final class RefCodeIndex {

	public const TAXONOMY = 'moksafopoi_ref_code';

	private const READY  = 'moksafopoi_ref_index_ready';
	private const CURSOR = 'moksafopoi_ref_index_page';
	private const HOOK   = 'moksafopoi_ref_index_batch';
	private const BATCH  = 500;

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 5 );
		add_action( 'init', array( self::class, 'maybe_schedule' ), 30 );
		add_action( self::HOOK, array( self::class, 'run_batch' ) );
		add_action( 'deleted_user', array( self::class, 'forget_user' ) );
	}

	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			'user',
			array(
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => false,
				'show_in_menu'      => false,
				'show_in_nav_menus' => false,
				'show_in_rest'      => false,
				'show_tagcloud'     => false,
				'show_admin_column' => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}

	public static function ready(): bool {
		return 'yes' === FreshOption::get( self::READY );
	}

	/** Record (or move) a member's code in the index. */
	public static function index( int $user_id, string $code ): void {
		$code = strtolower( trim( $code ) );
		if ( $user_id <= 0 || '' === $code ) {
			return;
		}
		wp_set_object_terms( $user_id, array( $code ), self::TAXONOMY, false );
	}

	/** Owner of a code from the index, or null. */
	public static function lookup( string $code ): ?int {
		$code = strtolower( trim( $code ) );
		if ( '' === $code ) {
			return null;
		}
		$term = get_term_by( 'slug', sanitize_title( $code ), self::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$ids = get_objects_in_term( (int) $term->term_id, self::TAXONOMY );
		if ( ! is_array( $ids ) || array() === $ids ) {
			return null;
		}
		return (int) min( array_map( 'intval', $ids ) );
	}

	public static function forget_user( $user_id ): void {
		wp_delete_object_term_relationships( (int) $user_id, self::TAXONOMY );
	}

	/** Queue the back-fill once (needs Action Scheduler, which WooCommerce bundles). */
	public static function maybe_schedule(): void {
		if ( self::ready() || ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'moksa-points' );
		}
	}

	/**
	 * One back-fill batch: the next BATCH users by ID, index each one's code, queue the next batch until
	 * a short page says we reached the end.
	 */
	public static function run_batch(): void {
		$page = max( 1, (int) get_option( self::CURSOR, 1 ) );
		$ids  = get_users(
			array(
				'blog_id'     => 0,
				'fields'      => 'ID',
				'number'      => self::BATCH,
				'paged'       => $page,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
			)
		);
		foreach ( $ids as $user_id ) {
			$code = (string) get_user_meta( (int) $user_id, \Moksafopoi\Modules\Referral\Module::META_CODE, true );
			if ( '' !== $code && self::lookup( $code ) !== (int) $user_id ) {
				self::index( (int) $user_id, $code );
			}
		}
		if ( count( $ids ) < self::BATCH ) {
			update_option( self::READY, 'yes', false );
			delete_option( self::CURSOR );
			return;
		}
		update_option( self::CURSOR, $page + 1, false );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'moksa-points' );
		}
	}

	private function __construct() {}
}
