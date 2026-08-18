<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\ExpiryReminder;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 點數到期提醒 — lazy-loaded, boots only when moksafopoi_expiryreminder_enabled is 'yes'.
 * Attaches a daily sweep to the shared `moksafopoi_daily` heartbeat (never schedules its own
 * event). The sweep finds members whose still-unspent points will expire inside the configured
 * reminder window and sends each a single i18n e-mail per expiry batch.
 *
 * No-op when the global expiry window (moksafopoi_points_expire_months) is 0 (points never
 * expire). Idempotent: a per-user fingerprint of the soonest expiry bucket is stored in
 * user_meta so the same batch is never re-mailed on a later daily run.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'expiryreminder';
	}

	public function label(): string {
		return __( 'Points expiry reminder', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'Email members before their points expire (includes the expiring points and the expiry date; idempotent, never re-sent)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'moksafopoi_daily', array( Reminder::class, 'run' ) );
	}
}
