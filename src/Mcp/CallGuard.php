<?php

declare( strict_types=1 );

namespace Moksafopoi\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * MCP 稽核 + 破壞性節流 — the two things an unattended write channel needs and almost never has.
 *
 * External MCP calls run with NO human in the loop: whatever the agent decides, happens. Two controls
 * make that survivable:
 *
 *   1. **An audit log of every `tools/call`** — who, which tool, with what arguments, and whether it
 *     was allowed. A ledger tells you points moved; this tells you which agent moved them.
 *   2. **A rate limit on destructive tools** — a runaway loop (or a stolen application password) can
 *     issue thousands of calls a minute. The cap turns "the agent minted 400,000 points overnight"
 *     into "the agent was stopped after 20 writes an hour" — and the attempt is in the log.
 *
 * Read-only calls are neither capped nor argument-logged: they cannot change anything, and logging
 * their arguments would just fill the option with noise.
 *
 * Storage is one capped option — no table, no CPT.
 */
final class CallGuard {

	/** Option holding the recent call log (newest first). */
	public const LOG_OPTION = 'moksafopoi_mcp_audit';

	/** How many calls the log keeps. */
	private const LOG_MAX = 100;

	/** Default destructive calls allowed per user per hour. */
	private const DEFAULT_LIMIT = 20;

	/** The rolling window. */
	private const WINDOW = HOUR_IN_SECONDS;

	/**
	 * The configured hourly cap on destructive calls (0 disables the limit entirely — an explicit,
	 * opt-out choice rather than a silent default).
	 */
	public static function limit(): int {
		$limit = get_option( 'moksafopoi_mcp_destructive_limit', self::DEFAULT_LIMIT );
		return max( 0, (int) $limit );
	}

	/**
	 * May this user run one more destructive call right now? Counting happens in {@see record()} so a
	 * refused call is never counted against the caller twice.
	 */
	public static function allow_destructive( int $user_id ): bool {
		$limit = self::limit();
		if ( $limit <= 0 || $user_id <= 0 ) {
			return true;
		}
		return self::count( $user_id ) < $limit;
	}

	/** How many destructive calls this user has made inside the current window. */
	public static function count( int $user_id ): int {
		$bucket = get_transient( self::bucket_key( $user_id ) );
		return is_numeric( $bucket ) ? (int) $bucket : 0;
	}

	/** Count one destructive call against the caller's rolling window. */
	public static function record( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$key   = self::bucket_key( $user_id );
		$count = self::count( $user_id ) + 1;
		set_transient( $key, $count, self::WINDOW );
	}

	/**
	 * Append one call to the audit log.
	 *
	 * Arguments are recorded for DESTRUCTIVE calls only, truncated, and with anything that looks like
	 * a secret removed — an audit trail must never become the place a token leaks.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function log( string $tool, bool $destructive, string $outcome, array $args = array() ): void {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$entry = array(
			't'       => gmdate( 'Y-m-d H:i:s' ),
			'user'    => get_current_user_id(),
			'tool'    => $tool,
			'destr'   => $destructive,
			'outcome' => $outcome,
		);

		if ( $destructive && array() !== $args ) {
			$entry['args'] = mb_substr( (string) wp_json_encode( self::redact( $args ) ), 0, 300 );
		}

		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/**
	 * The recent calls, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Strip anything that looks like a credential out of logged arguments.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private static function redact( array $args ): array {
		$out = array();
		foreach ( $args as $key => $value ) {
			$lower = strtolower( (string) $key );
			if ( false !== strpos( $lower, 'pass' ) || false !== strpos( $lower, 'token' )
				|| false !== strpos( $lower, 'secret' ) || false !== strpos( $lower, 'key' ) ) {
				$out[ $key ] = '***';
				continue;
			}
			$out[ $key ] = is_scalar( $value ) ? $value : '[...]';
		}
		return $out;
	}

	private static function bucket_key( int $user_id ): string {
		return 'moksafopoi_mcp_rl_' . $user_id;
	}
}
