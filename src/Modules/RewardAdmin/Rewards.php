<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\RewardAdmin;

use Moksafopoi\Support\RewardStore;
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
		return RewardStore::all();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		return RewardStore::find( $id );
	}

	/**
	 * Insert or update a reward. $data carries already-sanitised scalars.
	 *
	 * @param array{label:string,kind:string,cost_points:int,payload:string,stock:int,active:int} $data
	 * @return int Reward id (0 on failure).
	 */
	public static function save( int $id, array $data ): int {
		return RewardStore::save(
			$id,
			array(
				'label'       => $data['label'],
				'kind'        => $data['kind'],
				'cost_points' => $data['cost_points'],
				'payload'     => $data['payload'],
				'stock'       => $data['stock'],
				'active'      => $data['active'],
			)
		);
	}

	public static function delete( int $id ): void {
		RewardStore::delete( $id );
	}

	public static function set_active( int $id, int $active ): void {
		RewardStore::set_active( $id, $active );
	}
}
