<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Quests;

defined( 'ABSPATH' ) || exit;

/**
 * 任務／成就引擎 — the store of quest definitions and per-member progress.
 *
 * A quest is「做 N 次 X 就拿 Y 點」: complete an order, leave a review, invite a friend, check in,
 * spend a total amount. Definitions live in ONE option (`moksafopoi_quests`, JSON — never a CPT) and
 * progress lives in ONE user-meta array, so the engine adds no tables and no post rows.
 *
 * Awarding is idempotent by construction: the reward is booked through the ledger with the
 * source_ref `quest:<key>:<user_id>`, and a completed quest is stamped in the member's meta, so a
 * replayed hook (or a re-run migration) can never pay twice.
 *
 * Row shape: `{key,label,desc,event,target,reward,enabled}` where `event` is one of {@see EVENTS}
 * and `target` is how many times it must happen (or, for `spend_total`, the currency amount).
 */
final class Quests {

	/** Option holding the JSON quest list. */
	public const OPTION = 'moksafopoi_quests';

	/** User-meta: array<string,int> quest key → progress so far. */
	public const META_PROGRESS = '_moksafopoi_quest_progress';

	/** User-meta: array<int,string> of completed quest keys. */
	public const META_DONE = '_moksafopoi_quest_done';

	/** Ceiling on the number of quests a shop can define (a bounded option, and a bounded UI). */
	public const MAX_QUESTS = 12;

	/** Ceiling on a single quest reward, so a typo cannot mint a fortune. */
	public const MAX_REWARD = 100000;

	/**
	 * The trackable events. Each is fired by {@see Module} from an existing WooCommerce / plugin hook;
	 * `spend_total` accumulates order totals rather than counting occurrences.
	 *
	 * @return array<string,string> event key → human label
	 */
	public static function events(): array {
		return array(
			'order'       => __( 'Complete an order', 'moksa-points-for-woocommerce' ),
			'spend_total' => __( 'Cumulative spend (NT$)', 'moksa-points-for-woocommerce' ),
			'review'      => __( 'Leave an approved product review', 'moksa-points-for-woocommerce' ),
			'referral'    => __( 'A friend you invited completes an order', 'moksa-points-for-woocommerce' ),
			'checkin'     => __( 'Daily check-in', 'moksa-points-for-woocommerce' ),
		);
	}

	/**
	 * Every defined quest, normalised. Malformed rows are dropped.
	 *
	 * @return array<int,array{key:string,label:string,desc:string,event:string,target:int,reward:int,enabled:bool}>
	 */
	public static function all(): array {
		$raw     = get_option( self::OPTION, '' );
		$decoded = null;
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		}
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out    = array();
		$events = self::events();
		foreach ( $decoded as $index => $row ) {
			if ( ! is_array( $row ) || count( $out ) >= self::MAX_QUESTS ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$event = isset( $row['event'] ) ? sanitize_key( (string) $row['event'] ) : '';
			if ( '' === $label || ! isset( $events[ $event ] ) ) {
				continue;
			}
			$key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( '' === $key ) {
				$key = 'quest_' . (int) $index;
			}
			$out[] = array(
				'key'     => $key,
				'label'   => $label,
				'desc'    => isset( $row['desc'] ) ? sanitize_text_field( (string) $row['desc'] ) : '',
				'event'   => $event,
				'target'  => max( 1, (int) ( $row['target'] ?? 1 ) ),
				'reward'  => min( self::MAX_REWARD, max( 0, (int) ( $row['reward'] ?? 0 ) ) ),
				'enabled' => ! empty( $row['enabled'] ),
			);
		}

		return $out;
	}

	/**
	 * The enabled quests listening for one event.
	 *
	 * @return array<int,array{key:string,label:string,desc:string,event:string,target:int,reward:int,enabled:bool}>
	 */
	public static function for_event( string $event ): array {
		$out = array();
		foreach ( self::all() as $quest ) {
			if ( $quest['enabled'] && $quest['event'] === $event ) {
				$out[] = $quest;
			}
		}
		return $out;
	}

	/**
	 * A member's progress on every quest, for display.
	 *
	 * @return array<int,array{key:string,label:string,desc:string,target:int,reward:int,progress:int,done:bool,percent:int}>
	 */
	public static function status( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$progress = self::progress( $user_id );
		$done     = self::completed( $user_id );

		$out = array();
		foreach ( self::all() as $quest ) {
			if ( ! $quest['enabled'] ) {
				continue;
			}
			$have      = (int) ( $progress[ $quest['key'] ] ?? 0 );
			$is_done   = in_array( $quest['key'], $done, true );
			$out[] = array(
				'key'      => $quest['key'],
				'label'    => $quest['label'],
				'desc'     => $quest['desc'],
				'target'   => $quest['target'],
				'reward'   => $quest['reward'],
				'progress' => min( $have, $quest['target'] ),
				'done'     => $is_done,
				'percent'  => $is_done ? 100 : (int) min( 100, floor( $have / max( 1, $quest['target'] ) * 100 ) ),
			);
		}
		return $out;
	}

	/**
	 * Member progress map.
	 *
	 * @return array<string,int>
	 */
	public static function progress( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_PROGRESS, true );
		return is_array( $raw ) ? array_map( 'intval', $raw ) : array();
	}

	/**
	 * Completed quest keys.
	 *
	 * @return array<int,string>
	 */
	public static function completed( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_DONE, true );
		return is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array();
	}

	/**
	 * Add to a member's progress on one quest and return the new total.
	 *
	 * @param int $amount How much to add (1 for a countable event, the order total for spend).
	 */
	public static function bump( int $user_id, string $key, int $amount ): int {
		$progress         = self::progress( $user_id );
		$new              = max( 0, (int) ( $progress[ $key ] ?? 0 ) + $amount );
		$progress[ $key ] = $new;
		update_user_meta( $user_id, self::META_PROGRESS, $progress );
		return $new;
	}

	/** Stamp a quest as completed. Returns false when it already was (the idempotency gate). */
	public static function mark_done( int $user_id, string $key ): bool {
		$done = self::completed( $user_id );
		if ( in_array( $key, $done, true ) ) {
			return false;
		}
		$done[] = $key;
		update_user_meta( $user_id, self::META_DONE, $done );
		return true;
	}

	/** Persist a whole quest list (already-sanitised rows are re-normalised on read). */
	public static function save_all( array $rows ): void {
		$list = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $list ) >= self::MAX_QUESTS ) {
				continue;
			}
			$list[] = $row;
		}
		update_option( self::OPTION, array() === $list ? '' : (string) wp_json_encode( $list ) );
	}
}
