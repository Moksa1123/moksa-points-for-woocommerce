<?php

declare( strict_types=1 );

namespace Moksafopoi;

defined( 'ABSPATH' ) || exit;

/**
 * Central module registry. Modules are lazy-loaded: a module only boots when its
 * `moksafopoi_<key>_enabled` option is 'yes', so an unchecked module registers no
 * hooks and loads no code. Mirrors moforcoupon's ModuleRegistry.
 */
final class ModuleRegistry {

	/** @var array<string,class-string<Modules\AbstractModule>> */
	private array $modules = array(
		'ledger'       => Modules\Ledger\Module::class,
		'earncoupon'   => Modules\EarnOnCoupon\Module::class,
		'earntriggers' => Modules\EarnTriggers\Module::class,
		'referral'     => Modules\Referral\Module::class,
		'cashback'   => Modules\Cashback\Module::class,
		'giftcard'   => Modules\GiftCard\Module::class,
		'spendrules' => Modules\SpendRules\Module::class,
		'productpoints'  => Modules\ProductPoints\Module::class,
		'categorypoints' => Modules\CategoryPoints\Module::class,
		'wallet'     => Modules\Wallet\Module::class,
		'refund'     => Modules\Refund\Module::class,
		'redeem'      => Modules\Redeem\Module::class,
		'transfer'    => Modules\Transfer\Module::class,
		'buywithpoints' => Modules\BuyWithPoints\Module::class,
		'checkoutredeem' => Modules\CheckoutRedeem\Module::class,
		'rewardadmin' => Modules\RewardAdmin\Module::class,
		'pointsadmin' => Modules\PointsAdmin\Module::class,
		'campaign'    => Modules\Campaign\Module::class,
		'myaccount'   => Modules\MyAccount\Module::class,
		'engage'      => Modules\Engage\Module::class,
		'tierladder'  => Modules\TierLadder\Module::class,
		'quests'      => Modules\Quests\Module::class,
		'webhooks'    => Modules\Webhooks\Module::class,
		'linenotify'  => Modules\LineNotify\Module::class,
		'blocks'      => Modules\Blocks\Module::class,
		'restapi'     => Modules\RestApi\Module::class,
		'leaderboard' => Modules\Leaderboard\Module::class,
		'rewardhub'   => Modules\RewardHub\Module::class,
		'badges'      => Modules\Badges\Module::class,
		'expiryreminder' => Modules\ExpiryReminder\Module::class,
		'emailnotices'   => Modules\EmailNotices\Module::class,
		'abilities'  => Modules\Abilities\Module::class,
		'mcp'        => Modules\Mcp\Module::class,
		'adminmenu'  => Modules\AdminMenu\Module::class,
	);

	/** @var array<string,Modules\AbstractModule> */
	private array $booted = array();

	public function boot(): void {
		foreach ( $this->modules as $key => $class ) {
			if ( ! $this->is_enabled( $key ) || ! class_exists( $class ) ) {
				continue;
			}
			$module = new $class();
			$module->boot();
			$this->booted[ $key ] = $module;
		}

		do_action( 'moksafopoi_modules_booted', $this->booted );
	}

	public function is_enabled( string $key ): bool {
		return get_option( sprintf( 'moksafopoi_%s_enabled', $key ), 'no' ) === 'yes';
	}

	/** @return array<string,class-string<Modules\AbstractModule>> */
	public function all(): array {
		return $this->modules;
	}

	public function booted( string $key ): ?Modules\AbstractModule {
		return $this->booted[ $key ] ?? null;
	}
}
