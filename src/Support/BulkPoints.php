<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use Moksafopoi\Api;

defined( 'ABSPATH' ) || exit;

/**
 * 批次 / 手動加扣點 — the myCred "Bulk Assign" + "Adjust Points" operations the plugin only exposed via an
 * ability (AI / MCP path) with no human UI. This is the engine behind the admin screen: it resolves a
 * target set (a whole role, or a pasted list of ids / emails / logins), then credits or debits each user
 * through the SAME idempotent {@see Api::adjust} → {@see \Moksafopoi\Modules\Ledger\Ledger}
 * write-path, keyed `bulk_<batch>_<uid>` so a re-run / Cron replay never double-applies. A revoke is
 * clamped to the user's current balance (never drives points negative). Large sets are chunked over the
 * shared Cron so a 5,000-member campaign send cannot time out a request. Zero-CPT.
 */
final class BulkPoints {

	/** Users processed per pass (inline first pass + each Cron continuation). */
	private const CHUNK = 200;

	/** The Cron hook a continued batch fires on. */
	public const CRON_HOOK = 'moksafopoi_bulk_run';

	/** Option prefix holding a not-yet-finished batch's remaining work. */
	private const STATE_PREFIX = 'moksafopoi_bulk_';

	/**
	 * Apply a signed delta to ONE user, idempotently. A revoke (delta &lt; 0) is clamped to the user's
	 * current points so it can never go negative; a no-op (0 after clamp) is skipped. Returns the points
	 * actually applied (0 when skipped / already applied).
	 */
	public static function apply_one( int $user_id, int $delta, string $note, string $batch_id ): int {
		if ( $user_id <= 0 || 0 === $delta ) {
			return 0;
		}
		if ( $delta < 0 ) {
			$current = Api::get_points( $user_id );
			$debit   = min( abs( $delta ), $current );
			if ( $debit <= 0 ) {
				return 0; // nothing to revoke.
			}
			$delta = -$debit;
		}
		$ref = 'bulk_' . $batch_id . '_' . $user_id;
		$ok  = Api::adjust( $user_id, $delta, $note, $ref );
		return $ok ? $delta : 0;
	}

	/**
	 * Apply a delta to a set of users. Returns the number of users actually changed.
	 *
	 * @param array<int,int> $user_ids
	 */
	public static function process( array $user_ids, int $delta, string $note, string $batch_id ): int {
		$changed = 0;
		foreach ( $user_ids as $uid ) {
			if ( 0 !== self::apply_one( (int) $uid, $delta, $note, $batch_id ) ) {
				$changed++;
			}
		}
		return $changed;
	}

	/**
	 * Resolve a target set to user ids from either a role or a pasted list of ids / emails / logins.
	 *
	 * @return array<int,int>
	 */
	public static function resolve_targets( string $mode, string $role, string $raw_list ): array {
		$ids = array();
		if ( 'role' === $mode && '' !== $role ) {
			$found = get_users(
				array(
					'role'   => $role,
					'fields' => 'ID',
					'number' => 100000,
				)
			);
			$ids = array_map( 'intval', (array) $found );
		} elseif ( 'list' === $mode ) {
			foreach ( preg_split( '/[\s,;]+/', $raw_list ) ?: array() as $tok ) {
				$tok = trim( $tok );
				if ( '' === $tok ) {
					continue;
				}
				if ( ctype_digit( $tok ) ) {
					$u = get_userdata( (int) $tok );
				} elseif ( is_email( $tok ) ) {
					$u = get_user_by( 'email', $tok );
				} else {
					$u = get_user_by( 'login', $tok );
				}
				if ( $u instanceof \WP_User ) {
					$ids[] = (int) $u->ID;
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Dispatch a batch: process the first chunk inline (so the operator sees immediate progress) and,
	 * when the set is larger than one chunk, persist the remainder and schedule a Cron continuation.
	 * Returns the number of users changed in the FIRST (inline) chunk.
	 *
	 * @param array<int,int> $user_ids
	 */
	public static function dispatch( array $user_ids, int $delta, string $note, string $batch_id ): int {
		$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		$first    = array_slice( $user_ids, 0, self::CHUNK );
		$applied  = self::process( $first, $delta, $note, $batch_id );

		$remaining = array_slice( $user_ids, self::CHUNK );
		if ( ! empty( $remaining ) ) {
			update_option(
				self::STATE_PREFIX . $batch_id,
				array(
					'uids'  => array_values( $remaining ),
					'delta' => $delta,
					'note'  => $note,
				),
				false
			);
			if ( ! wp_next_scheduled( self::CRON_HOOK, array( $batch_id ) ) ) {
				wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( $batch_id ) );
			}
		}
		return $applied;
	}

	/** Cron continuation: process the next chunk of a persisted batch, rescheduling until drained. */
	public static function run_batch( string $batch_id ): void {
		$data = get_option( self::STATE_PREFIX . $batch_id );
		if ( ! is_array( $data ) ) {
			return;
		}
		$uids  = array_map( 'intval', (array) ( $data['uids'] ?? array() ) );
		$delta = (int) ( $data['delta'] ?? 0 );
		$note  = (string) ( $data['note'] ?? '' );

		$chunk = array_slice( $uids, 0, self::CHUNK );
		self::process( $chunk, $delta, $note, $batch_id );

		$remaining = array_slice( $uids, self::CHUNK );
		if ( ! empty( $remaining ) ) {
			update_option(
				self::STATE_PREFIX . $batch_id,
				array(
					'uids'  => array_values( $remaining ),
					'delta' => $delta,
					'note'  => $note,
				),
				false
			);
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( $batch_id ) );
		} else {
			delete_option( self::STATE_PREFIX . $batch_id );
		}
	}

	/** A fresh batch id (stable within one submission for idempotency). */
	public static function new_batch_id(): string {
		return 'b' . gmdate( 'YmdHis' ) . wp_generate_password( 5, false );
	}
}
