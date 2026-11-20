<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Every post / user / term meta key this plugin writes, so uninstall can delete them through the
 * metadata API (delete_metadata with $delete_all) instead of a LIKE sweep. Keys are listed once here;
 * a key that only exists on one object type is harmless to delete on the others (no rows match).
 *
 * When adding a new `_moksafopoi_*` meta key anywhere in the plugin, add it here too.
 */
final class MetaKeys {

	/** @var array<int,string> */
	public const ALL = array(
		// Products.
		'_moksafopoi_earn_override',
		'_moksafopoi_no_earn',
		'_moksafopoi_no_redeem',
		'_moksafopoi_buyable_points',
		'_moksafopoi_giftcard',
		'_moksafopoi_giftcard_amount',
		'_moksafopoi_pointspack',
		'_moksafopoi_pointspack_points',
		// Redemption catalog (RewardStore CPT).
		'_moksafopoi_reward_label',
		'_moksafopoi_reward_kind',
		'_moksafopoi_reward_cost',
		'_moksafopoi_reward_payload',
		'_moksafopoi_reward_stock',
		'_moksafopoi_reward_legacy_id',
		// Orders (post meta on sites without HPOS).
		'_moksafopoi_earned_points',
		'_moksafopoi_earn_clawed',
		'_moksafopoi_earn_clawed_pts',
		'_moksafopoi_cashback_credited',
		'_moksafopoi_giftcard_credited',
		'_moksafopoi_quest_counted',
		'_moksafopoi_redeem_points',
		'_moksafopoi_redeem_pending',
		'_moksafopoi_redeem_ledger_ref',
		'_moksafopoi_redeem_restored',
		'_moksafopoi_wallet_amount',
		'_moksafopoi_wallet_pending',
		'_moksafopoi_wallet_ledger_ref',
		'_moksafopoi_referral_rewarded',
		'_moksafopoi_referral_reversed',
		// Users.
		'_moksafopoi_balance',
		'_moksafopoi_credit_balance',
		'_moksafopoi_badges',
		'_moksafopoi_ref_code',
		'_moksafopoi_referred_by',
		'_moksafopoi_quest_progress',
		'_moksafopoi_quest_done',
		'_moksafopoi_expiry_reminded',
		'_moksafopoi_user_nonce',
		// Product categories (term meta).
		'_moksafopoi_cat_earn_multiplier',
	);

	private function __construct() {}
}
