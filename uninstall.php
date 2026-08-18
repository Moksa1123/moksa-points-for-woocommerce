<?php
/**
 * Uninstall cleanup. Removes ONLY this plugin's own state (prefix `moksafopoi_` /
 * `_moksafopoi_`) and drops its custom tables. Each plugin sweeps only its own prefix,
 * so a sibling's `_moformember_*` / `_moforcoupon_*` data on a shared object is never
 * touched. Runs only on a real uninstall.
 *
 * MULTISITE: every site in the network gets the same sweep. WordPress calls this file ONCE for a
 * network uninstall, so a single-site sweep would leave every other site's tables and options behind
 * — orphaned rows that no UI can ever reach again. User meta is network-wide and is cleaned once.
 *
 * @package Moksafopoi
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Sweep the state of ONE site: prefixed options, post meta, term meta, and the custom tables.
 * Table and option names are all built from `$wpdb->prefix` plus fixed literals, so switching sites
 * (which repoints `$wpdb->prefix`) is what makes this correct on every site of a network.
 */
function moksafopoi_uninstall_site(): void {
	global $wpdb;

	// 1) Options (and any transients) with our prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'moksafopoi_' ) . '%',
			$wpdb->esc_like( '_transient_moksafopoi_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_moksafopoi_' ) . '%'
		)
	);

	// 2) Post meta with our prefix (per-product earn overrides / buy-with-points price / gift-card
	// and points-pack flags).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_moksafopoi_' ) . '%'
		)
	);

	// 3) Term meta with our prefix (the per-category earn multiplier on product_cat terms).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_moksafopoi_' ) . '%'
		)
	);

	// 4) Drop the custom tables (every table Schema::install creates).
	foreach ( array( 'ledger', 'rules', 'rewards' ) as $moksafopoi_suffix ) {
		$moksafopoi_table = $wpdb->prefix . 'moksafopoi_' . $moksafopoi_suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal custom table name ($wpdb->prefix . 'moksafopoi_' . a fixed suffix literal), not user input; DROP is intentional uninstall cleanup.
		$wpdb->query( "DROP TABLE IF EXISTS `{$moksafopoi_table}`" );
	}
}

if ( is_multisite() ) {
	// Bounded batches: a big network must not be swept in one unbounded query.
	$moksafopoi_paged = 1;
	do {
		$moksafopoi_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 200,
				'offset' => ( $moksafopoi_paged - 1 ) * 200,
			)
		);
		foreach ( $moksafopoi_sites as $moksafopoi_site_id ) {
			switch_to_blog( (int) $moksafopoi_site_id );
			moksafopoi_uninstall_site();
			restore_current_blog();
		}
		++$moksafopoi_paged;
	} while ( count( $moksafopoi_sites ) === 200 );

	// Network-wide options live in sitemeta, not in any site's options table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'moksafopoi_' ) . '%'
		)
	);
} else {
	moksafopoi_uninstall_site();
}

// User meta is shared across a whole network, so it is swept exactly once either way
// (badges, referral codes, quest progress, expiry-reminder fingerprints, cached balances…).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_moksafopoi_' ) . '%'
	)
);
