<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * 點數 / 儲值金 CSV 匯入 — how a shop moves off a competitor (or off a spreadsheet) without hand-
 * typing thousands of balances.
 *
 * Two properties make this safe to hand to a merchant:
 *
 *   1. **Idempotent.** Every row is booked through {@see Ledger::record_once()} with the source_ref
 *      `<batch>:<user_id>`, so re-uploading the same file under the same batch id books NOTHING a
 *      second time. A nervous operator can re-run an interrupted import instead of reconciling by
 *      hand — the one thing a balance importer must never get wrong.
 *   2. **Dry-run first.** {@see run()} in preview mode does all the parsing, resolving and validating
 *      and reports exactly what WOULD happen, without a single write.
 *
 * Rows land in the ledger as `adjust` (an operator correction), not `earn`: an import is not
 * spending, so it is deliberately exempt from the earn cap and never triggers earn-side side effects.
 *
 * CSV shape — a header row naming the columns, in any order:
 *
 *   user        required  user id, e-mail, or login name
 *   points      optional  integer points delta (may be negative)
 *   credit      optional  store-credit delta, decimal
 *   note        optional  free text stored on the ledger row
 *   expires_at  optional  YYYY-MM-DD — migrated points keep their original expiry
 *
 * At least one of points/credit must be non-zero, or the row is skipped as a no-op.
 */
final class BalanceImporter {

	/** Upper bound on rows per upload, so one file can never run the request out of time. */
	public const MAX_ROWS = 5000;

	/** Sanity ceiling per row — a stray "100000000" in a spreadsheet must not mint a fortune. */
	public const MAX_POINTS = 10000000;

	/** Sanity ceiling per row for the store-credit lane. */
	public const MAX_CREDIT = 10000000.0;

	/** Accepted column names → canonical field. */
	private const COLUMNS = array(
		'user'       => 'user',
		'user_id'    => 'user',
		'email'      => 'user',
		'login'      => 'user',
		'points'     => 'points',
		'point'      => 'points',
		'credit'     => 'credit',
		'balance'    => 'credit',
		'note'       => 'note',
		'memo'       => 'note',
		'expires_at' => 'expires_at',
		'expiry'     => 'expires_at',
	);

	/**
	 * Parse + (optionally) apply a CSV file.
	 *
	 * @param string $path    Path to a readable CSV file (an uploaded temp file).
	 * @param string $batch   Operator batch id — the idempotency scope. Sanitised to a key.
	 * @param bool   $dry_run True = validate and report only, write nothing.
	 * @return array{ok:bool,error?:string,applied:int,skipped:int,failed:int,rows:int,points:int,credit:float,messages:array<int,string>}
	 */
	public static function run( string $path, string $batch, bool $dry_run ): array {
		$batch = sanitize_key( $batch );
		$out   = array(
			'ok'       => false,
			'applied'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'rows'     => 0,
			'points'   => 0,
			'credit'   => 0.0,
			'messages' => array(),
		);

		if ( '' === $batch ) {
			$out['error'] = __( 'Please enter a batch code (it is what makes re-uploading the same file safe).', 'moksa-points-for-woocommerce' );
			return $out;
		}
		if ( ! is_readable( $path ) ) {
			$out['error'] = __( 'The uploaded file could not be read.', 'moksa-points-for-woocommerce' );
			return $out;
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an uploaded temp file line by line; WP_Filesystem has no streaming CSV reader.
		if ( false === $handle ) {
			$out['error'] = __( 'The uploaded file could not be read.', 'moksa-points-for-woocommerce' );
			return $out;
		}

		$map = null;
		$row_number = 0;

		while ( false !== ( $row = fgetcsv( $handle, 0, ',' ) ) ) {
			++$row_number;

			if ( ! is_array( $row ) || array() === array_filter( array_map( 'strval', $row ), 'strlen' ) ) {
				continue; // Blank line.
			}

			if ( null === $map ) {
				$map = self::header_map( $row );
				if ( null === $map ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
					$out['error'] = __( 'The first row must be a header naming the columns, and must include a "user" column.', 'moksa-points-for-woocommerce' );
					return $out;
				}
				continue;
			}

			if ( $out['rows'] >= self::MAX_ROWS ) {
				$out['messages'][] = sprintf(
					/* translators: %s: the maximum number of rows one upload may contain. */
					__( 'Stopped at the %s-row limit; split the file and import the rest separately.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( self::MAX_ROWS )
				);
				break;
			}
			++$out['rows'];

			$parsed = self::parse_row( $row, $map );
			if ( isset( $parsed['error'] ) ) {
				++$out['failed'];
				if ( count( $out['messages'] ) < 50 ) {
					$out['messages'][] = sprintf(
						/* translators: 1: CSV row number; 2: the reason the row was rejected. */
						__( 'Row %1$s: %2$s', 'moksa-points-for-woocommerce' ),
						number_format_i18n( $row_number ),
						(string) $parsed['error']
					);
				}
				continue;
			}

			$user_id = (int) $parsed['user_id'];
			$points  = (int) $parsed['points'];
			$credit  = (float) $parsed['credit'];
			$ref     = $batch . ':' . $user_id;

			if ( $dry_run ) {
				// Preview must not lie about idempotency: a row already booked under this batch would
				// be a no-op, so report it as skipped rather than as an award.
				if ( self::already_booked( $user_id, $ref ) ) {
					++$out['skipped'];
					continue;
				}
				++$out['applied'];
				$out['points'] += $points;
				$out['credit'] += $credit;
				continue;
			}

			$args = array( 'amount_delta' => $credit );
			if ( '' !== (string) $parsed['note'] ) {
				$args['note'] = (string) $parsed['note'];
			}
			if ( '' !== (string) $parsed['expires_at'] ) {
				$args['expires_at'] = (string) $parsed['expires_at'];
			}

			$written = Ledger::record_once( $user_id, $points, 'adjust', 'import', $ref, $args );
			if ( $written ) {
				++$out['applied'];
				$out['points'] += $points;
				$out['credit'] += $credit;
			} else {
				++$out['skipped']; // Already booked under this batch → correctly did nothing.
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.

		if ( null === $map ) {
			$out['error'] = __( 'The file is empty.', 'moksa-points-for-woocommerce' );
			return $out;
		}

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Map the header row to canonical field names, or null when there is no usable `user` column.
	 *
	 * @param array<int,string|null> $row
	 * @return array<int,string>|null Column index → canonical field.
	 */
	private static function header_map( array $row ): ?array {
		$map = array();
		foreach ( $row as $index => $cell ) {
			// Strip a UTF-8 BOM Excel likes to put in front of the first header cell.
			$name = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cell ) ) );
			$name = str_replace( array( ' ', '-' ), '_', $name );
			if ( isset( self::COLUMNS[ $name ] ) ) {
				$map[ (int) $index ] = self::COLUMNS[ $name ];
			}
		}
		return in_array( 'user', $map, true ) ? $map : null;
	}

	/**
	 * Validate one data row.
	 *
	 * @param array<int,string|null> $row
	 * @param array<int,string>      $map
	 * @return array{user_id?:int,points?:int,credit?:float,note?:string,expires_at?:string,error?:string}
	 */
	private static function parse_row( array $row, array $map ): array {
		$fields = array(
			'user'       => '',
			'points'     => '',
			'credit'     => '',
			'note'       => '',
			'expires_at' => '',
		);
		foreach ( $map as $index => $field ) {
			$fields[ $field ] = trim( (string) ( $row[ $index ] ?? '' ) );
		}

		$user_id = self::resolve_user( $fields['user'] );
		if ( $user_id <= 0 ) {
			return array(
				'error' => sprintf(
					/* translators: %s: the value in the CSV's user column that could not be matched. */
					__( 'no member matched "%s"', 'moksa-points-for-woocommerce' ),
					$fields['user']
				),
			);
		}

		$points = 0;
		if ( '' !== $fields['points'] ) {
			$raw = str_replace( ',', '', $fields['points'] );
			if ( ! is_numeric( $raw ) ) {
				return array( 'error' => __( 'the points value is not a number', 'moksa-points-for-woocommerce' ) );
			}
			$points = (int) round( (float) $raw );
			if ( abs( $points ) > self::MAX_POINTS ) {
				return array( 'error' => __( 'the points value is out of the allowed range', 'moksa-points-for-woocommerce' ) );
			}
		}

		$credit = 0.0;
		if ( '' !== $fields['credit'] ) {
			$raw = str_replace( ',', '', $fields['credit'] );
			if ( ! is_numeric( $raw ) ) {
				return array( 'error' => __( 'the store-credit value is not a number', 'moksa-points-for-woocommerce' ) );
			}
			$credit = round( (float) $raw, 2 );
			if ( abs( $credit ) > self::MAX_CREDIT ) {
				return array( 'error' => __( 'the store-credit value is out of the allowed range', 'moksa-points-for-woocommerce' ) );
			}
		}

		if ( 0 === $points && 0.0 === $credit ) {
			return array( 'error' => __( 'neither points nor store credit was given', 'moksa-points-for-woocommerce' ) );
		}

		$expires = '';
		if ( '' !== $fields['expires_at'] ) {
			$expires = self::parse_date( $fields['expires_at'] );
			if ( '' === $expires ) {
				return array( 'error' => __( 'the expiry date is not in YYYY-MM-DD format', 'moksa-points-for-woocommerce' ) );
			}
		}

		return array(
			'user_id'    => $user_id,
			'points'     => $points,
			'credit'     => $credit,
			'note'       => sanitize_text_field( $fields['note'] ),
			'expires_at' => $expires,
		);
	}

	/** Resolve an id / e-mail / login to a user id (0 when nothing matches). */
	private static function resolve_user( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			$user = get_user_by( 'id', (int) $value );
			return $user ? (int) $user->ID : 0;
		}
		if ( is_email( $value ) ) {
			$user = get_user_by( 'email', $value );
			return $user ? (int) $user->ID : 0;
		}
		$user = get_user_by( 'login', $value );
		return $user ? (int) $user->ID : 0;
	}

	/** Normalise a date cell to 'Y-m-d 23:59:59' (points expire at the end of their last day). */
	private static function parse_date( string $value ): string {
		if ( 1 !== preg_match( '/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', trim( $value ), $m ) ) {
			return '';
		}
		$year  = (int) $m[1];
		$month = (int) $m[2];
		$day   = (int) $m[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, $day );
	}

	/** Has this batch already booked a row for this member? (drives the dry-run's skip count) */
	private static function already_booked( int $user_id, string $ref ): bool {
		global $wpdb;
		$table = Schema::ledger_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, values bound via $wpdb->prepare().
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND source = 'import' AND source_ref = %s",
				$user_id,
				$ref
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $found > 0;
	}
}
