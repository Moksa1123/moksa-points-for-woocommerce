<?php
/**
 * Uninstall cleanup. Removes ONLY this plugin's own state and drops its custom tables, through
 * WordPress APIs (options API, metadata API) — no LIKE sweeps. Each plugin cleans only its own keys,
 * so a sibling's `_moformember_*` / `_moforcoupon_*` data on a shared object is never touched. Runs
 * only on a real uninstall.
 *
 * - Options: every option the plugin wrote is recorded by Support\OptionRegistry; as a safety net the
 *   autoloaded options with our prefix and the known autoload-off ones are swept too.
 * - Meta: every post / user / term meta key is listed in Support\MetaKeys and deleted for all objects.
 * - Redemption catalog: every RewardStore post (wp_delete_post) — before the post meta sweep.
 * - Referral-code index: every term of the private taxonomy (wp_delete_term).
 * - Tables: the ledger (Support\LedgerStore) and the old reward / rule tables (Support\LegacyStorage).
 * - Transients: the fixed-name caches are deleted; per-user ones expire on their own.
 *
 * MULTISITE: every site in the network gets the same sweep. WordPress calls this file ONCE for a
 * network uninstall, so a single-site sweep would leave every other site's tables and options behind.
 * User meta is network-wide and is cleaned once.
 *
 * @package Moksafopoi
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// uninstall.php runs without the plugin's autoloader: load the few classes used here directly.
foreach ( array( 'OptionRegistry', 'MetaKeys', 'LedgerStore', 'LegacyStorage', 'RewardStore', 'RefCodeIndex' ) as $moksafopoi_class ) {
	require_once __DIR__ . '/src/Support/' . $moksafopoi_class . '.php';
}

/**
 * Sweep the state of ONE site: options, post meta, term meta, the custom tables and fixed transients.
 * Table names come from `$wpdb->prefix`, so switching sites (which repoints the prefix) makes this
 * correct on every site of a network.
 */
function moksafopoi_uninstall_site(): void {
	// 1) Options.
	$moksafopoi_options = \Moksafopoi\Support\OptionRegistry::names();
	foreach ( array_keys( wp_load_alloptions() ) as $moksafopoi_name ) {
		if ( is_string( $moksafopoi_name ) && \Moksafopoi\Support\OptionRegistry::ours( $moksafopoi_name ) ) {
			$moksafopoi_options[] = $moksafopoi_name;
		}
	}
	$moksafopoi_options = array_merge( $moksafopoi_options, \Moksafopoi\Support\OptionRegistry::known_unautoloaded() );
	foreach ( array_unique( $moksafopoi_options ) as $moksafopoi_name ) {
		delete_option( $moksafopoi_name );
	}
	delete_option( \Moksafopoi\Support\OptionRegistry::OPTION );

	// 2) Redemption catalog posts and the referral-code index terms (core APIs; types registered just
	//    for this so the APIs accept them).
	\Moksafopoi\Support\RewardStore::register();
	do {
		$moksafopoi_posts = get_posts(
			array(
				'post_type'      => \Moksafopoi\Support\RewardStore::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);
		foreach ( $moksafopoi_posts as $moksafopoi_post_id ) {
			wp_delete_post( (int) $moksafopoi_post_id, true );
		}
		$moksafopoi_more = 100 === count( $moksafopoi_posts );
	} while ( $moksafopoi_more );
	\Moksafopoi\Support\RefCodeIndex::register();
	do {
		$moksafopoi_terms = get_terms(
			array(
				'taxonomy'   => \Moksafopoi\Support\RefCodeIndex::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 500,
			)
		);
		$moksafopoi_terms = is_array( $moksafopoi_terms ) ? $moksafopoi_terms : array();
		foreach ( $moksafopoi_terms as $moksafopoi_term_id ) {
			wp_delete_term( (int) $moksafopoi_term_id, \Moksafopoi\Support\RefCodeIndex::TAXONOMY );
		}
		$moksafopoi_more = 500 === count( $moksafopoi_terms );
	} while ( $moksafopoi_more );

	// 3) Post meta (products; orders on sites without HPOS) and term meta (product categories).
	foreach ( \Moksafopoi\Support\MetaKeys::ALL as $moksafopoi_key ) {
		delete_metadata( 'post', 0, $moksafopoi_key, '', true );
		delete_metadata( 'term', 0, $moksafopoi_key, '', true );
	}

	// 4) Fixed-name caches.
	delete_transient( 'moksafopoi_dash_report' );
	delete_transient( 'moksafopoi_forecast' );

	// 5) Custom tables.
	\Moksafopoi\Support\LedgerStore::drop_table();
	\Moksafopoi\Support\LegacyStorage::drop_tables();
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
		$moksafopoi_more = 200 === count( $moksafopoi_sites );
	} while ( $moksafopoi_more );
} else {
	moksafopoi_uninstall_site();
}

// User meta is shared across a whole network, so it is deleted exactly once either way
// (badges, referral codes, quest progress, expiry-reminder fingerprints, cached balances…).
foreach ( \Moksafopoi\Support\MetaKeys::ALL as $moksafopoi_key ) {
	delete_metadata( 'user', 0, $moksafopoi_key, '', true );
}
