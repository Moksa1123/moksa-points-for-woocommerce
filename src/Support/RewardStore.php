<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The redemption catalog, stored as a private custom post type (owner-approved 2026-11-15,
 * docs/批2-資料庫改寫設計.md §2.C). Replaces the old `{prefix}moksafopoi_rewards` table; rows keep the
 * same array shape the rest of the plugin already uses:
 *   id (post ID), label, kind, cost_points, payload (JSON string), active (1|0), stock (-1 = unlimited),
 *   plus ref_key — the id used inside ledger refs (see ref_key()).
 *
 * Storage: post_status publish (active) / draft (inactive), everything else in post meta. The label is
 * kept verbatim in meta (post titles go through WordPress' title filters, which would turn "&" into
 * "&amp;" for users without unfiltered_html); post_title holds the same text only for readability.
 * Catalogs are small (an operator hand-builds them), so lists are loaded whole and sorted / filtered in
 * PHP instead of meta queries.
 */
final class RewardStore {

	public const POST_TYPE = 'moksafopoi_reward';

	public const META_LABEL     = '_moksafopoi_reward_label';
	public const META_KIND      = '_moksafopoi_reward_kind';
	public const META_COST      = '_moksafopoi_reward_cost';
	public const META_PAYLOAD   = '_moksafopoi_reward_payload';
	public const META_STOCK     = '_moksafopoi_reward_stock';
	public const META_LEGACY_ID = '_moksafopoi_reward_legacy_id';

	/** post_name prefix of a reward migrated from the old table: reward-legacy-{old id}. */
	public const LEGACY_SLUG_PREFIX = 'reward-legacy-';

	/** Seconds after which a stock lock left by a crashed request may be taken over. */
	private const LOCK_TTL = 30;

	/** @var array<int,array<string,mixed>>|null Per-request cache of every reward, keyed by post ID. */
	private static ?array $cache = null;

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => 'Moksa Points rewards',
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'supports'            => array( 'title' ),
			)
		);
	}

	/* ------------------------------------------------------------------ read */

	/** @return array<int,array<string,mixed>> Every reward, published first, then newest (admin list). */
	public static function all(): array {
		$rows = array_values( self::load() );
		usort(
			$rows,
			static fn( array $a, array $b ): int => array( $b['active'], $b['id'] ) <=> array( $a['active'], $a['id'] )
		);
		return $rows;
	}

	/**
	 * One reward by post ID. A legacy id (from before the catalog moved to a CPT — old links, an AI
	 * agent that cached an id) is also accepted and mapped to its migrated post.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$rows = self::load();
		if ( isset( $rows[ $id ] ) ) {
			return $rows[ $id ];
		}
		foreach ( $rows as $row ) {
			if ( (int) $row['legacy_id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/** The migrated post for an old-table id, or null. */
	public static function find_by_legacy_id( int $legacy_id ): ?array {
		foreach ( self::load() as $row ) {
			if ( (int) $row['legacy_id'] === $legacy_id ) {
				return $row;
			}
		}
		return null;
	}

	public static function count_active(): int {
		return count( array_filter( self::load(), static fn( array $r ): bool => 1 === $r['active'] ) );
	}

	/** @return array<int,array<string,mixed>> For the list-rewards Ability, cheapest first. */
	public static function for_ability( bool $active_only ): array {
		$rows = array_values( self::load() );
		if ( $active_only ) {
			$rows = array_values( array_filter( $rows, static fn( array $r ): bool => 1 === $r['active'] ) );
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $a['cost_points'], $a['id'] ) <=> array( $b['cost_points'], $b['id'] ) );
		return array_map(
			static fn( array $r ): array => array_intersect_key( $r, array_flip( array( 'id', 'label', 'kind', 'cost_points', 'active', 'stock' ) ) ),
			$rows
		);
	}

	/** @return array<int,array<string,mixed>> Spendable rewards for My Account (no 'code' rows, in stock), max 50. */
	public static function spendable_for_account(): array {
		$rows = array_values(
			array_filter(
				self::load(),
				static fn( array $r ): bool => 1 === $r['active'] && 'code' !== $r['kind'] && ( -1 === $r['stock'] || $r['stock'] > 0 )
			)
		);
		usort( $rows, static fn( array $a, array $b ): int => $a['cost_points'] <=> $b['cost_points'] );
		return array_slice( $rows, 0, 50 );
	}

	/** @return array<int,array<string,mixed>> In-stock published rewards for the [moksafopoi_mall] storefront. */
	public static function in_stock_for_mall( int $limit ): array {
		$rows = array_values(
			array_filter(
				self::load(),
				static fn( array $r ): bool => 1 === $r['active'] && ( -1 === $r['stock'] || $r['stock'] > 0 )
			)
		);
		usort( $rows, static fn( array $a, array $b ): int => array( $a['cost_points'], $b['id'] ) <=> array( $b['cost_points'], $a['id'] ) );
		return array_slice( $rows, 0, max( 0, $limit ) );
	}

	/** @return array<int,array<string,mixed>> Published redeem-code rows, newest first, max 200. */
	public static function active_codes(): array {
		$rows = array_values( array_filter( self::load(), static fn( array $r ): bool => 1 === $r['active'] && 'code' === $r['kind'] ) );
		usort( $rows, static fn( array $a, array $b ): int => $b['id'] <=> $a['id'] );
		return array_slice( $rows, 0, 200 );
	}

	public static function any_active_code(): bool {
		return array() !== self::active_codes();
	}

	/**
	 * The id written into ledger refs (`reward:{key}:…`, `code_{key}_{user}`) and used to count a member's
	 * redemptions. A reward migrated from the old table keeps its old id, so per-member limits and
	 * "redeem a code once" carry on exactly where they were; a reward created afterwards uses
	 * "p{post ID}", which can never collide with an old id.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function ref_key( array $row ): string {
		$legacy = (int) ( $row['legacy_id'] ?? 0 );
		return $legacy > 0 ? (string) $legacy : 'p' . (int) $row['id'];
	}

	/* ------------------------------------------------------------------ write */

	/**
	 * Insert ($id = 0) or update a reward. Returns the post ID (0 on failure).
	 *
	 * @param array{label:string,kind:string,cost_points:int,payload:string,stock:int,active:int} $row
	 * @param int                                                                                    $legacy_id Old-table id (migration only).
	 */
	public static function save( int $id, array $row, int $legacy_id = 0 ): int {
		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => (string) $row['label'],
			'post_status' => (int) $row['active'] ? 'publish' : 'draft',
		);
		if ( $legacy_id > 0 ) {
			$postarr['post_name'] = self::LEGACY_SLUG_PREFIX . $legacy_id;
		}
		if ( $id > 0 ) {
			// find() also accepts an old-table id; always write to the post it resolved to, never to the raw
			// number (which would be some unrelated post, page or order with that ID).
			$found = self::find( $id );
			if ( null === $found ) {
				return 0;
			}
			$postarr['ID'] = (int) $found['id'];
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $post_id ) || (int) $post_id <= 0 ) {
			return 0;
		}
		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::META_LABEL, wp_slash( (string) $row['label'] ) );
		update_post_meta( $post_id, self::META_KIND, (string) $row['kind'] );
		update_post_meta( $post_id, self::META_COST, (int) $row['cost_points'] );
		update_post_meta( $post_id, self::META_PAYLOAD, wp_slash( (string) $row['payload'] ) );
		update_post_meta( $post_id, self::META_STOCK, (int) $row['stock'] );
		if ( $legacy_id > 0 ) {
			update_post_meta( $post_id, self::META_LEGACY_ID, $legacy_id );
		}
		self::flush();
		return $post_id;
	}

	public static function delete( int $id ): void {
		if ( null !== self::find_own( $id ) ) {
			wp_delete_post( $id, true );
			self::flush();
		}
	}

	public static function set_active( int $id, int $active ): void {
		if ( null !== self::find_own( $id ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $active ? 'publish' : 'draft',
				)
			);
			self::flush();
		}
	}

	/**
	 * Reserve one unit of a capped reward. Returns true when a unit was taken (or the reward is
	 * unlimited), false when it is sold out or the lock could not be taken. Serialised per reward with
	 * core's lock, and the stock is re-read from the database inside the lock (the per-request / object
	 * cache could hold a value from before another request's decrement).
	 */
	public static function take_stock( int $id ): bool {
		$result = self::with_reward_lock(
			$id,
			static function () use ( $id ): bool {
				$stock = self::fresh_stock( $id );
				if ( null === $stock ) {
					return false;
				}
				if ( -1 === $stock ) {
					return true;
				}
				if ( $stock <= 0 ) {
					return false;
				}
				update_post_meta( $id, self::META_STOCK, $stock - 1 );
				return true;
			}
		);
		self::flush();
		return true === $result;
	}

	/** Hand one reserved unit back to a capped reward (never touches unlimited rewards). */
	public static function give_back_stock( int $id ): void {
		self::with_reward_lock(
			$id,
			static function () use ( $id ): bool {
				$stock = self::fresh_stock( $id );
				if ( null !== $stock && $stock >= 0 ) {
					update_post_meta( $id, self::META_STOCK, $stock + 1 );
				}
				return true;
			}
		);
		self::flush();
	}

	/** Forget the per-request cache (after any write). */
	public static function flush(): void {
		self::$cache = null;
	}

	/* ------------------------------------------------------------------ internals */

	/** @return array<string,mixed>|null A reward that is really ours (by post ID only). */
	private static function find_own( int $id ): ?array {
		$rows = self::load();
		return $id > 0 && isset( $rows[ $id ] ) ? $rows[ $id ] : null;
	}

	/** Current stock straight from the database; null when the post is not one of our rewards. */
	private static function fresh_stock( int $id ): ?int {
		wp_cache_delete( $id, 'post_meta' );
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$raw = get_post_meta( $id, self::META_STOCK, true );
		return '' === $raw ? -1 : (int) $raw;
	}

	/**
	 * @param callable():bool $fn
	 * @return bool|null Null when the lock could not be taken within 5 seconds.
	 */
	private static function with_reward_lock( int $id, callable $fn ): ?bool {
		if ( ! class_exists( 'WP_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		$name     = 'moksafopoi_reward_' . $id;
		$deadline = microtime( true ) + 5;
		while ( ! \WP_Upgrader::create_lock( $name, self::LOCK_TTL ) ) {
			if ( microtime( true ) >= $deadline ) {
				return null;
			}
			usleep( 100000 );
		}
		try {
			return (bool) $fn();
		} finally {
			\WP_Upgrader::release_lock( $name );
		}
	}

	/** @return array<int,array<string,mixed>> Every reward keyed by post ID (loaded once per request). */
	private static function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$rows = array();
		$page = 1;
		do {
			$posts = get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => array( 'publish', 'draft' ),
					'posts_per_page'   => 100,
					'paged'            => $page,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
				)
			);
			foreach ( $posts as $post ) {
				$rows[ (int) $post->ID ] = self::to_row( $post );
			}
			++$page;
			$more = 100 === count( $posts );
		} while ( $more );
		self::$cache = $rows;
		return $rows;
	}

	/** @return array<string,mixed> */
	private static function to_row( \WP_Post $post ): array {
		$stock = get_post_meta( $post->ID, self::META_STOCK, true );
		$label = get_post_meta( $post->ID, self::META_LABEL, true );
		return array(
			'id'          => (int) $post->ID,
			'label'       => '' !== $label ? (string) $label : (string) $post->post_title,
			'kind'        => (string) get_post_meta( $post->ID, self::META_KIND, true ),
			'cost_points' => (int) get_post_meta( $post->ID, self::META_COST, true ),
			'payload'     => (string) get_post_meta( $post->ID, self::META_PAYLOAD, true ),
			'active'      => 'publish' === $post->post_status ? 1 : 0,
			'stock'       => '' === $stock ? -1 : (int) $stock,
			'legacy_id'   => (int) get_post_meta( $post->ID, self::META_LEGACY_ID, true ),
		);
	}

	private function __construct() {}
}
