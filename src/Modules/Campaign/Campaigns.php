<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed store + resolver for marketing campaigns. The whole list lives in ONE option
 * (`moksafopoi_campaigns`) as a JSON array — site-wide rules → options, never a CPT. Each row is
 * `{id,name,start,end,points_mult,commission_mult,member_discount_pct,enabled}` where:
 *
 *   id                   string  — opaque slug-ish id (auto-generated on create).
 *   name                 string  — display name shown on the storefront badge.
 *   start / end          int     — unix seconds (the form posts datetime-local strings → unix).
 *   points_mult          float   — earn-points multiplier, default 1, clamped to [1..MAX].
 *   commission_mult      float   — affiliate-commission multiplier, default 1, clamped to [1..MAX].
 *   member_discount_pct  float   — member discount percentage, clamped to [0..MAX_DISCOUNT_PCT].
 *   enabled              bool    — operator on/off switch.
 *   recurrence           string  — 'none' (a single window) | 'weekly' | 'monthly' | 'yearly'.
 *   days                 array   — the recurrence selector: weekday numbers 0..6 (weekly),
 *                                  month days 1..31 (monthly), or 'MM-DD' dates (yearly).
 *   time_start/time_end  string  — 'HH:MM' daily window applied to each recurring occurrence;
 *                                  blank / equal / reversed means the whole day.
 *
 * A recurring campaign uses `start`/`end` as an OUTER validity range (0 = unbounded) and only runs
 * on the days its selector matches, inside the daily time window. Its `ends_at` is the end of the
 * occurrence running right now, so countdowns and the "soonest end wins" rule stay meaningful.
 *
 * The resolver {@see active()} returns the campaign whose window covers "now" and which is enabled;
 * when several overlap it returns the one ending SOONEST (smallest effective end) so a short flash
 * sale wins over a long-running background campaign — the platform contract everyone reads.
 */
final class Campaigns {

	/** The single option holding the JSON campaign list. */
	public const OPTION = 'moksafopoi_campaigns';

	/** Hard ceiling on the points / commission multipliers (defence against runaway awards). */
	public const MAX_MULTIPLIER = 10.0;

	/** Hard ceiling on the member-discount percentage. */
	public const MAX_DISCOUNT_PCT = 90.0;

	/** The supported recurrence modes. 'none' = the classic single start/end window. */
	public const RECURRENCES = array( 'none', 'weekly', 'monthly', 'yearly' );

	/**
	 * Every stored campaign, normalised. Malformed rows are skipped; field types are coerced and
	 * clamped so callers never see junk.
	 *
	 * @return array<int,array{id:string,name:string,start:int,end:int,points_mult:float,commission_mult:float,member_discount_pct:float,enabled:bool,recurrence:string,days:array<int,string>,time_start:string,time_end:string}>
	 */
	public static function all(): array {
		$raw  = get_option( self::OPTION, '' );
		$list = array();

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$list = $decoded;
			}
		} elseif ( is_array( $raw ) ) {
			$list = $raw;
		}

		$out = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalised = self::normalise( $row );
			if ( null !== $normalised ) {
				$out[] = $normalised;
			}
		}

		return $out;
	}

	/**
	 * Find a single campaign by id.
	 *
	 * @return array{id:string,name:string,start:int,end:int,points_mult:float,commission_mult:float,member_discount_pct:float,enabled:bool,recurrence:string,days:array<int,string>,time_start:string,time_end:string}|null
	 */
	public static function find( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}
		foreach ( self::all() as $campaign ) {
			if ( $campaign['id'] === $id ) {
				return $campaign;
			}
		}
		return null;
	}

	/**
	 * The currently-active campaign, or null when none is live.
	 *
	 * "Active" = enabled AND now ∈ [start, end]. When MULTIPLE campaigns are simultaneously active
	 * the one with the EARLIEST end (smallest `end` / `ends_at`) wins — a short, soon-to-expire flash
	 * sale takes precedence over a long-running background campaign. Returned in the platform contract
	 * shape so {@see \Moksafopoi\Api::active_campaign()} and the global wrapper can hand it
	 * straight to a sibling.
	 *
	 * @return array{id:string,name:string,points_mult:float,commission_mult:float,member_discount_pct:float,ends_at:int}|null
	 */
	public static function active( ?int $now = null ): ?array {
		$now        = $now ?? time();
		$winner     = null;
		$winner_end = 0;

		foreach ( self::all() as $campaign ) {
			if ( ! $campaign['enabled'] ) {
				continue;
			}
			if ( $campaign['start'] > 0 && $now < $campaign['start'] ) {
				continue; // Not started yet (outer range).
			}
			if ( $campaign['end'] > 0 && $now > $campaign['end'] ) {
				continue; // Already ended (outer range).
			}

			// Recurring campaigns must ALSO be inside one of their occurrences right now; the
			// occurrence end (not the outer end) is what counts down and what ranks.
			$effective_end = $campaign['end'];
			if ( 'none' !== $campaign['recurrence'] ) {
				$occurrence = self::occurrence( $campaign, $now );
				if ( null === $occurrence ) {
					continue; // Not a matching day / outside the daily window.
				}
				// A bounded outer end that lands before the occurrence end still wins the countdown.
				$effective_end = ( $campaign['end'] > 0 && $campaign['end'] < $occurrence['end'] )
					? $campaign['end']
					: $occurrence['end'];
			}

			// Multiple active → keep the one ending soonest (smallest end). An end of 0 means
			// "no end" → treated as the latest possible so a bounded campaign always wins over it.
			if ( null === $winner || self::end_rank( $effective_end ) < self::end_rank( $winner_end ) ) {
				$winner     = $campaign;
				$winner_end = $effective_end;
			}
		}

		if ( null === $winner ) {
			return null;
		}

		return array(
			'id'                  => $winner['id'],
			'name'                => $winner['name'],
			'points_mult'         => $winner['points_mult'],
			'commission_mult'     => $winner['commission_mult'],
			'member_discount_pct' => $winner['member_discount_pct'],
			'ends_at'             => $winner_end,
		);
	}

	/**
	 * The occurrence of a recurring campaign that covers `$now`, or null when today is not a
	 * matching day / the clock is outside the daily window.
	 *
	 * Everything is evaluated in the SITE timezone (the operator picks wall-clock days and times),
	 * so a "double points weekend" means Sat–Sun where the shop lives, not in UTC.
	 *
	 * @param array{recurrence:string,days:array<int,string>,time_start:string,time_end:string} $campaign
	 * @return array{start:int,end:int}|null
	 */
	public static function occurrence( array $campaign, int $now ): ?array {
		$recurrence = isset( $campaign['recurrence'] ) ? (string) $campaign['recurrence'] : 'none';
		if ( 'none' === $recurrence ) {
			return null;
		}

		$days = isset( $campaign['days'] ) && is_array( $campaign['days'] ) ? $campaign['days'] : array();
		if ( array() === $days ) {
			return null; // Nothing selected → never runs (fail closed, never award by accident).
		}

		$tz = self::timezone();
		try {
			$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( ! self::matches_day( $recurrence, $days, $today ) ) {
			return null;
		}

		list( $from, $to ) = self::daily_window(
			isset( $campaign['time_start'] ) ? (string) $campaign['time_start'] : '',
			isset( $campaign['time_end'] ) ? (string) $campaign['time_end'] : ''
		);

		$start = $today->setTime( (int) substr( $from, 0, 2 ), (int) substr( $from, 3, 2 ), 0 )->getTimestamp();
		// The window is inclusive of its last minute: 18:00–23:59 runs until 23:59:59.
		$end   = $today->setTime( (int) substr( $to, 0, 2 ), (int) substr( $to, 3, 2 ), 59 )->getTimestamp();

		if ( $now < $start || $now > $end ) {
			return null;
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/** Does the given site-local date match the recurrence selector? */
	private static function matches_day( string $recurrence, array $days, \DateTimeImmutable $date ): bool {
		if ( 'weekly' === $recurrence ) {
			return in_array( $date->format( 'w' ), array_map( 'strval', $days ), true );
		}

		if ( 'monthly' === $recurrence ) {
			$day_of_month = (int) $date->format( 'j' );
			$last         = (int) $date->format( 't' );
			foreach ( $days as $day ) {
				$wanted = (int) $day;
				if ( $wanted === $day_of_month ) {
					return true;
				}
				// A "31st" campaign still runs on the 30th of a 30-day month (and on Feb 28/29)
				// instead of silently skipping that month.
				if ( $wanted > $last && $day_of_month === $last ) {
					return true;
				}
			}
			return false;
		}

		if ( 'yearly' === $recurrence ) {
			return in_array( $date->format( 'm-d' ), array_map( 'strval', $days ), true );
		}

		return false;
	}

	/**
	 * Normalise the daily window to a `[from, to]` pair of 'HH:MM' strings. Blank, invalid, or a
	 * `to` that is not after `from` all mean "the whole day".
	 *
	 * @return array{0:string,1:string}
	 */
	private static function daily_window( string $from, string $to ): array {
		$from = self::clock( $from );
		$to   = self::clock( $to );

		if ( '' === $from && '' === $to ) {
			return array( '00:00', '23:59' );
		}
		if ( '' === $from ) {
			$from = '00:00';
		}
		if ( '' === $to || strcmp( $to, $from ) <= 0 ) {
			$to = '23:59';
		}

		return array( $from, $to );
	}

	/** Coerce a raw time input to a canonical 'HH:MM' string, or '' when unusable. */
	public static function clock( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || 1 !== preg_match( '/^(\d{1,2}):(\d{2})/', $value, $m ) ) {
			return '';
		}
		$hour   = (int) $m[1];
		$minute = (int) $m[2];
		if ( $hour > 23 || $minute > 59 ) {
			return '';
		}
		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/** The site timezone (falls back to UTC outside WordPress). */
	private static function timezone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * Insert or update a campaign and persist the whole list. A blank id creates a new row (with a
	 * freshly-minted id); an existing id replaces that row. Returns the saved campaign's id.
	 *
	 * @param array<string,mixed> $data Raw {name,start,end,points_mult,commission_mult,member_discount_pct,enabled}.
	 */
	public static function save( string $id, array $data ): string {
		$id   = self::sanitize_id( $id );
		$list = self::all();

		$row = self::normalise(
			array(
				'id'                  => '' !== $id ? $id : self::generate_id(),
				'name'                => $data['name'] ?? '',
				'start'               => $data['start'] ?? 0,
				'end'                 => $data['end'] ?? 0,
				'points_mult'         => $data['points_mult'] ?? 1,
				'commission_mult'     => $data['commission_mult'] ?? 1,
				'member_discount_pct' => $data['member_discount_pct'] ?? 0,
				'enabled'             => $data['enabled'] ?? false,
				'recurrence'          => $data['recurrence'] ?? 'none',
				'days'                => $data['days'] ?? array(),
				'time_start'          => $data['time_start'] ?? '',
				'time_end'            => $data['time_end'] ?? '',
			)
		);

		if ( null === $row ) {
			return '';
		}

		$replaced = false;
		foreach ( $list as $index => $existing ) {
			if ( $existing['id'] === $row['id'] ) {
				$list[ $index ] = $row;
				$replaced       = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$list[] = $row;
		}

		self::persist( $list );
		return $row['id'];
	}

	/** Delete a campaign by id and persist the trimmed list. */
	public static function delete( string $id ): void {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return;
		}
		$list = array();
		foreach ( self::all() as $campaign ) {
			if ( $campaign['id'] !== $id ) {
				$list[] = $campaign;
			}
		}
		self::persist( $list );
	}

	/** Flip a campaign's enabled flag and persist. */
	public static function set_enabled( string $id, bool $enabled ): void {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return;
		}
		$list = self::all();
		foreach ( $list as $index => $campaign ) {
			if ( $campaign['id'] === $id ) {
				$list[ $index ]['enabled'] = $enabled;
				break;
			}
		}
		self::persist( $list );
	}

	/* ---------------------------------------------------------------- internals */

	/**
	 * Rank for the "soonest end wins" comparison: an end of 0 (no end) sorts last so a bounded
	 * campaign always beats an open-ended one.
	 */
	private static function end_rank( int $end ): int {
		return $end > 0 ? $end : PHP_INT_MAX;
	}

	/**
	 * Coerce + clamp one raw row into the canonical shape, or null when it is unusable (no name).
	 *
	 * @param array<string,mixed> $row
	 * @return array{id:string,name:string,start:int,end:int,points_mult:float,commission_mult:float,member_discount_pct:float,enabled:bool,recurrence:string,days:array<int,string>,time_start:string,time_end:string}|null
	 */
	private static function normalise( array $row ): ?array {
		$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}

		$id = isset( $row['id'] ) ? self::sanitize_id( (string) $row['id'] ) : '';
		if ( '' === $id ) {
			$id = self::generate_id();
		}

		$start = isset( $row['start'] ) ? max( 0, (int) $row['start'] ) : 0;
		$end   = isset( $row['end'] ) ? max( 0, (int) $row['end'] ) : 0;

		$recurrence = isset( $row['recurrence'] ) ? sanitize_key( (string) $row['recurrence'] ) : 'none';
		if ( ! in_array( $recurrence, self::RECURRENCES, true ) ) {
			$recurrence = 'none';
		}

		return array(
			'id'                  => $id,
			'name'                => $name,
			'start'               => $start,
			'end'                 => $end,
			'points_mult'         => self::clamp_mult( isset( $row['points_mult'] ) ? (float) $row['points_mult'] : 1.0 ),
			'commission_mult'     => self::clamp_mult( isset( $row['commission_mult'] ) ? (float) $row['commission_mult'] : 1.0 ),
			'member_discount_pct' => self::clamp_pct( isset( $row['member_discount_pct'] ) ? (float) $row['member_discount_pct'] : 0.0 ),
			'enabled'             => ! empty( $row['enabled'] ),
			'recurrence'          => $recurrence,
			'days'                => self::normalise_days( $recurrence, $row['days'] ?? array() ),
			'time_start'          => self::clock( isset( $row['time_start'] ) ? (string) $row['time_start'] : '' ),
			'time_end'            => self::clock( isset( $row['time_end'] ) ? (string) $row['time_end'] : '' ),
		);
	}

	/**
	 * Coerce the recurrence selector to the canonical list for its mode, de-duplicated and sorted.
	 * Out-of-range entries are dropped rather than clamped — a typo must not silently become a
	 * different (awarding) day.
	 *
	 * @param mixed $raw Array (or comma string) of selector values.
	 * @return array<int,string>
	 */
	private static function normalise_days( string $recurrence, $raw ): array {
		if ( 'none' === $recurrence ) {
			return array();
		}
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			if ( 'yearly' === $recurrence ) {
				if ( 1 !== preg_match( '/^(\d{2})-(\d{2})$/', $value, $m ) ) {
					continue;
				}
				$month = (int) $m[1];
				$day   = (int) $m[2];
				if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
					continue;
				}
				$out[] = sprintf( '%02d-%02d', $month, $day );
				continue;
			}

			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$number = (int) $value;
			$max    = ( 'weekly' === $recurrence ) ? 6 : 31;
			$min    = ( 'weekly' === $recurrence ) ? 0 : 1;
			if ( $number < $min || $number > $max ) {
				continue;
			}
			$out[] = (string) $number;
		}

		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/** Clamp a multiplier to [1 .. MAX_MULTIPLIER]; anything below 1 (or invalid) snaps to 1. */
	public static function clamp_mult( float $value ): float {
		if ( $value < 1.0 ) {
			return 1.0;
		}
		return min( self::MAX_MULTIPLIER, $value );
	}

	/** Clamp a discount percentage to [0 .. MAX_DISCOUNT_PCT]. */
	public static function clamp_pct( float $value ): float {
		if ( $value < 0.0 ) {
			return 0.0;
		}
		return min( self::MAX_DISCOUNT_PCT, $value );
	}

	private static function sanitize_id( string $id ): string {
		$id = sanitize_key( $id );
		return strlen( $id ) > 32 ? substr( $id, 0, 32 ) : $id;
	}

	private static function generate_id(): string {
		return 'cmp_' . substr( md5( uniqid( 'moksafopoi_cmp', true ) ), 0, 12 );
	}

	/**
	 * Persist the list as a JSON option (empty list clears it).
	 *
	 * @param array<int,array<string,mixed>> $list
	 */
	private static function persist( array $list ): void {
		if ( array() === $list ) {
			update_option( self::OPTION, '' );
			return;
		}
		$json = wp_json_encode( array_values( $list ) );
		update_option( self::OPTION, is_string( $json ) ? $json : '' );
	}
}
