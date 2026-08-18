<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\RewardAdmin;

use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Thin data-access layer over the rewards table. Every read/write is a prepared statement
 * against our own internal table (id/label/kind/cost_points/payload/active/stock). The payload
 * column is the JSON contract the redeem service consumes: {template,prefix,expiry_days}.
 */
final class Rewards {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()), fully static SQL, no user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY active DESC, id DESC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); id bound via $wpdb->prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update a reward. $data carries already-sanitised scalars.
	 *
	 * @param array{label:string,kind:string,cost_points:int,payload:string,stock:int,active:int} $data
	 * @return int Reward id (0 on failure).
	 */
	public static function save( int $id, array $data ): int {
		global $wpdb;
		$table = Schema::rewards_table();

		$row = array(
			'label'       => $data['label'],
			'kind'        => $data['kind'],
			'cost_points' => $data['cost_points'],
			'payload'     => $data['payload'],
			'stock'       => $data['stock'],
			'active'      => $data['active'],
		);
		$formats = array( '%s', '%s', '%d', '%s', '%d', '%d' );

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table; $wpdb->update prepares values + where.
			$wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) );

			return $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table; $wpdb->insert prepares values.
		$wpdb->insert( $table, $row, $formats );

		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table; $wpdb->delete prepares the where clause.
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	public static function set_active( int $id, int $active ): void {
		if ( $id <= 0 ) {
			return;
		}
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table; $wpdb->update prepares values + where.
		$wpdb->update( $table, array( 'active' => $active ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}
}
