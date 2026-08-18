<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Ledger;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Ledger module — the core value service every other module depends on. Ensures the
 * schema is current and attaches the points-expiry sweep to the shared daily heartbeat.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'ledger';
	}

	public function label(): string {
		return __( 'Points ledger', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'core';
	}

	public function tagline(): string {
		return __( 'An idempotent points / store-credit ledger (earn, deduct, redeem, expire) — the single source of truth for platform value', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		// Cheap guard so a manual upgrade that skipped activation still gets the tables.
		Schema::maybe_upgrade();
		add_action( 'moksafopoi_daily', array( Expiry::class, 'run' ) );
	}
}
